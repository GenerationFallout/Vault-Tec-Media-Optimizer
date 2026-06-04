<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerFactory;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use Psr\Log\LoggerInterface;

/**
 * Rewrites <img> tags in the page HTML to <picture> elements with WebP source,
 * for images that have a WebP version available.
 *
 * Operates on the final HTML via OutputPageBeforeHTML hook. This works
 * regardless of skin and remains compatible with MultimediaViewer (the <img>
 * stays the primary node, used as fallback for clients without WebP support
 * and as the click target for the viewer).
 *
 * Output structure:
 *   <picture>
 *     <source srcset="/images_webp/a/ab/Foo.webp" type="image/webp">
 *     <img src="/images/a/ab/Foo.png" ...original attrs...>
 *   </picture>
 *
 * The <img> tag is unchanged so existing JS (lightboxes, lazy loading) keeps
 * working. <a> wrappers around the image are also untouched.
 *
 * Implementation notes
 * --------------------
 * We do regex-based replacement instead of full HTML parsing because
 * (a) MediaWiki's parser output may not be well-formed XML, and (b) parsing
 * with DOMDocument on every page render is too expensive.
 *
 * Idempotency: if the <img> is already inside a <picture>, we skip it.
 * To know the *real* position of each match (not just the first occurrence
 * if duplicates exist), we use preg_match_all + PREG_OFFSET_CAPTURE rather
 * than preg_replace_callback.
 *
 * Correctness: <source srcset> in a <picture> that 404s does NOT fall back
 * to the inner <img> — unlike <img srcset>, a missing <source> in <picture>
 * produces a broken image. So we only emit <picture> when the WebP file
 * exists on disk. The is_file() check is one stat() per image, cached by
 * the OS — negligible cost.
 */
class HtmlRewriter {

	private ServiceOptions $options;
	private WebPRepo $webpRepo;
	private OptimizerFactory $optimizerFactory;
	private LoggerInterface $logger;

	/**
	 * Remaining budget of synchronous on-demand WebP generations for the current
	 * rewrite() call. Reset at the start of each call. Bounds worst-case render
	 * latency when many thumbnails still lack a WebP (cold cache).
	 */
	private int $onDemandRemaining = 0;

	public function __construct(
		ServiceOptions $options,
		WebPRepo $webpRepo,
		OptimizerFactory $optimizerFactory,
		LoggerInterface $logger
	) {
		$this->options = $options;
		$this->webpRepo = $webpRepo;
		$this->optimizerFactory = $optimizerFactory;
		$this->logger = $logger;
	}

	/**
	 * Rewrite the given HTML in place. Modifies $text by reference.
	 */
	public function rewrite( string &$text ): void {
		if ( !$this->options->get( 'VaultTecMediaOptimizerEnabled' )
			|| !$this->options->get( 'VaultTecMediaOptimizerProcessThumbnails' )
		) {
			return;
		}

		$uploadPath = $this->options->get( 'UploadPath' );
		if ( !is_string( $uploadPath ) || $uploadPath === '' ) {
			return;
		}

		// Quick gate: skip pages with no images
		if ( strpos( $text, '<img' ) === false ) {
			return;
		}

		// Reset the per-render budget for synchronous on-demand WebP generation.
		// Existing WebP files are always served; this only caps how many *missing*
		// ones we encode inline during this single page render.
		$this->onDemandRemaining = (int)$this->options->get( 'VaultTecMediaOptimizerOnDemandThumbLimit' );

		// Find all <img ... src="..." ...> with their offsets in the original text.
		// We capture both the full tag and the src URL, plus the byte offset of
		// each match (PREG_OFFSET_CAPTURE).
		//
		// The (?<![-\w]) lookbehind prevents matching `src` inside attributes
		// like `data-src` or `xlink:src`. `\b` alone would not work because
		// `-` is a non-word character, so `\bsrc` matches inside `data-src`.
		// Similarly we don't want to match `src` inside `srcset` — handled
		// by the `=` requirement (srcset would have `srcset=` not `src=`,
		// but the lookbehind guard belt-and-braces this).
		$pattern = '/<img\s[^>]*?(?<![-\w])src\s*=\s*(["\'])([^"\']+)\1[^>]*>/i';
		$matchCount = preg_match_all(
			$pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( !$matchCount ) {
			return;
		}

		// Pre-compute the byte ranges of every existing <picture> block in one
		// linear pass, so the idempotency check below is both correct and cheap
		// (no per-image rescans). This replaces the old fixed 500-byte lookback,
		// which could miss a wrapper whose <source>/attributes pushed the inner
		// <img> past the window and then double-wrap it.
		$pictureRanges = $this->buildPictureRanges( $text );

		// AVIF is experimental and off by default. When on, it is offered BEFORE
		// WebP so AVIF-capable browsers pick it and everyone else falls back to
		// WebP (then to the original <img>).
		$avifEnabled = (bool)$this->options->get( 'VaultTecMediaOptimizerAvifEnabled' );

		// Process matches in REVERSE order so that replacement offsets earlier
		// in the string remain valid as we splice in <picture> wrappers.
		$replacements = []; // list of [offset, length, replacement] tuples
		foreach ( $matches as $m ) {
			[ $tag, $offset ] = $m[0];
			$src = $m[2][0];

			// The src attribute in the HTML stream uses HTML entities (e.g.
			// `&amp;` for `&`). PHP's parse_str doesn't decode those, so a
			// URL like "...?f=X&amp;width=200" would parse as ['f'=>'X',
			// 'amp;width'=>'200'] — missing the 'width' key entirely.
			// Decode entities so the URL parser sees the real characters.
			$src = htmlspecialchars_decode( $src, ENT_QUOTES | ENT_HTML5 );

			if ( !$this->srcLooksLocal( $src, $uploadPath ) ) {
				continue;
			}

			// Idempotency: skip if this <img> is already inside a <picture>.
			if ( $this->offsetInsidePictureRange( $offset, $pictureRanges ) ) {
				continue;
			}

			// Build one <source> per derived format, each combining the 1x (src)
			// and all Retina/HiDPI variants (srcset of the original <img>). A
			// candidate is only included if its derived file exists on disk (a
			// <source> that 404s in <picture> shows a broken image instead of
			// falling back). AVIF first (when enabled), then WebP, then the
			// untouched <img> as the universal fallback.
			$sources = '';

			if ( $avifEnabled ) {
				$avifParts = $this->collectDerivedSrcset( $src, $tag, $uploadPath, 'avif' );
				if ( $avifParts ) {
					$sources .= '<source srcset="'
						. htmlspecialchars( implode( ', ', $avifParts ), ENT_QUOTES )
						. '" type="image/avif">';
				}
			}

			$webpParts = $this->collectDerivedSrcset( $src, $tag, $uploadPath, 'webp' );
			if ( $webpParts ) {
				$sources .= '<source srcset="'
					. htmlspecialchars( implode( ', ', $webpParts ), ENT_QUOTES )
					. '" type="image/webp">';
			}

			// If no derived format exists for any variant, leave the <img> alone.
			if ( $sources === '' ) {
				continue;
			}

			$replacement = '<picture>' . $sources . $tag . '</picture>';

			$replacements[] = [ $offset, strlen( $tag ), $replacement ];
		}

		if ( !$replacements ) {
			return;
		}

		// Apply in reverse offset order so earlier offsets remain valid.
		usort( $replacements, static fn ( $a, $b ) => $b[0] <=> $a[0] );
		foreach ( $replacements as [ $offset, $length, $replacement ] ) {
			$text = substr_replace( $text, $replacement, $offset, $length );
		}
	}

	/**
	 * Build the srcset parts for one derived format ('webp'|'avif'), combining
	 * the 1x candidate (the <img> src) and any HiDPI variants from the <img>
	 * srcset. Each candidate is included only if its derived file exists on disk
	 * (generated on demand when missing, within the render budget).
	 *
	 * @param string $src The (entity-decoded) <img> src
	 * @param string $tag The full <img> tag (to read its srcset, if any)
	 * @param string $uploadPath
	 * @param string $ext 'webp' | 'avif'
	 * @return list<string> srcset entries, e.g. ["/images_avif/.../x.avif 2x"]
	 */
	private function collectDerivedSrcset( string $src, string $tag, string $uploadPath, string $ext ): array {
		$parts = [];

		// 1x candidate from src
		$info = $this->derivedInfo( $src, $ext );
		if ( $info !== null && $this->ensureDerived( $info, $ext ) ) {
			$parts[] = $info[0];
		}

		// HiDPI candidates from srcset
		if ( preg_match( '/\ssrcset\s*=\s*(["\'])([^"\']+)\1/i', $tag, $sm ) ) {
			$srcsetRaw = htmlspecialchars_decode( $sm[2], ENT_QUOTES | ENT_HTML5 );
			foreach ( $this->parseSrcset( $srcsetRaw ) as [ $candidateUrl, $descriptor ] ) {
				if ( !$this->srcLooksLocal( $candidateUrl, $uploadPath ) ) {
					continue;
				}
				$info = $this->derivedInfo( $candidateUrl, $ext );
				if ( $info === null || !$this->ensureDerived( $info, $ext ) ) {
					continue;
				}
				$parts[] = $info[0] . ( $descriptor !== '' ? ' ' . $descriptor : '' );
			}
		}

		return $parts;
	}

	/**
	 * Resolve the derived-format url/disk/source triple for a URL.
	 *
	 * @return array{0:string,1:string,2?:string}|null
	 */
	private function derivedInfo( string $url, string $ext ): ?array {
		return $ext === 'avif'
			? $this->webpRepo->getAvifUrlAndPath( $url )
			: $this->webpRepo->getWebPUrlAndPath( $url );
	}

	/**
	 * Ensure a derived file (WebP or AVIF) exists for a resolved image,
	 * generating it on demand if it is missing but the source thumbnail is
	 * present on disk.
	 *
	 * This is the fix for the core gap: FileTransformed only fires when
	 * MediaWiki *creates* a thumbnail, never when it serves a pre-existing one.
	 * As a result, thumbnails already on disk (e.g. infobox sizes generated
	 * before this extension was installed, or sizes MediaWiki doesn't purge)
	 * would never get a derived copy. By generating here — at render time, in the
	 * same flow that already serves the page — every thumbnail size that pages
	 * actually request gets its WebP/AVIF, whether it was created before or after
	 * the extension, and whether or not it is ever regenerated.
	 *
	 * Only the sizes pages truly use are generated (no blind disk sweep). The
	 * result is cached on disk, so generation happens at most once per size.
	 * Synchronous encodes are capped per render by the P1 budget; AVIF support is
	 * checked first so an unsupported backend never spends budget.
	 *
	 * @param array{0:string,1:string,2?:string} $info [derivedUrl, derivedDiskPath, srcThumbPath]
	 * @param string $ext 'webp' | 'avif'
	 * @return bool True if the derived file exists (already or after generation)
	 */
	private function ensureDerived( array $info, string $ext ): bool {
		$destPath = $info[1];

		// Already present AND non-empty — serve it (the common case after
		// warm-up). A leftover 0-byte/truncated derived file (e.g. from an
		// interrupted pre-atomic write, disk-full, or tampering) is treated as
		// missing: a <source> pointing at it would break the image with no
		// fallback, so we regenerate instead.
		if ( is_file( $destPath ) && filesize( $destPath ) > 0 ) {
			return true;
		}

		// We need the source thumbnail path to generate from. Older callers or
		// URL shapes that don't resolve a source can't be generated on demand.
		$srcThumbPath = $info[2] ?? null;
		if ( $srcThumbPath === null || !is_file( $srcThumbPath ) || !is_readable( $srcThumbPath ) ) {
			return false;
		}

		// Determine MIME from the source thumbnail extension. We only handle
		// the formats this extension targets; anything else is left as-is.
		$srcExt = strtolower( pathinfo( $srcThumbPath, PATHINFO_EXTENSION ) );
		$mimeByExt = [
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
		];
		if ( !isset( $mimeByExt[$srcExt] ) ) {
			return false;
		}
		$mime = $mimeByExt[$srcExt];

		$allowed = $this->options->get( 'VaultTecMediaOptimizerFormats' );
		if ( !in_array( $mime, $allowed, true ) ) {
			return false;
		}

		// Resolve the backend up front so we can check AVIF support before
		// spending any of the render budget.
		try {
			$optimizer = $this->optimizerFactory->getOptimizer();
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( $ext === 'avif' && !$optimizer->supportsAvif() ) {
			return false;
		}

		// Make sure the destination directory exists.
		if ( !$this->webpRepo->ensureDirFor( $destPath ) ) {
			$this->logger->warning( 'On-demand {ext}: cannot create directory for {dest}',
				[ 'ext' => $ext, 'dest' => $destPath ] );
			return false;
		}

		// Budget guard (P1): cap how many missing derived files we encode inline
		// during this single render. Once spent, leave the <img> untouched — the
		// derived file will be generated on a later view (or by FileTransformed
		// when MediaWiki next regenerates the thumbnail), so the cache still warms
		// up without front-loading the cost onto one unlucky visitor.
		if ( $this->onDemandRemaining <= 0 ) {
			return false;
		}
		// We are committing to an encode: spend one unit of the render budget.
		$this->onDemandRemaining--;

		try {
			if ( $ext === 'avif' ) {
				$quality = (int)$this->options->get( 'VaultTecMediaOptimizerAvifQuality' );
				$ok = $optimizer->convertToAvif( $srcThumbPath, $destPath, $quality );
			} else {
				$lossless = ( $mime === 'image/png' )
					&& (bool)$this->options->get( 'VaultTecMediaOptimizerWebPLosslessForPng' );
				$quality = (int)$this->options->get( 'VaultTecMediaOptimizerWebPQuality' );
				$ok = $optimizer->convertToWebP( $srcThumbPath, $destPath, $lossless, $quality );
			}
			if ( !$ok ) {
				$this->logger->debug( 'On-demand {ext} generation failed for {src}: {err}', [
					'ext' => $ext,
					'src' => $srcThumbPath,
					'err' => $optimizer->getLastError() ?? 'no detail',
				] );
				return false;
			}
			$this->logger->debug( 'On-demand {ext} generated: {dest}', [ 'ext' => $ext, 'dest' => $destPath ] );
			return is_file( $destPath );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'On-demand {ext} generation error for {src}: {msg}', [
				'ext' => $ext,
				'src' => $srcThumbPath,
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Parse an HTML srcset attribute value into a list of [url, descriptor] pairs.
	 *
	 * Per HTML spec, srcset is a comma-separated list of "URL descriptor"
	 * tuples where descriptor is optional and looks like "1.5x" or "300w".
	 * URLs themselves can contain commas (e.g. in thumb.php query strings
	 * like "C.H._Monthly,_October"), but in practice MediaWiki separates
	 * candidates with ", " (comma + space) and the URL itself doesn't
	 * contain that exact sequence. We split on "\s*,\s+" to be safe and
	 * handle the edge case where descriptors are missing.
	 *
	 * @param string $srcset Raw srcset value (after HTML entity decoding)
	 * @return list<array{0: string, 1: string}> [url, descriptor]
	 */
	private function parseSrcset( string $srcset ): array {
		$result = [];
		// Split on ", " (a comma followed by whitespace) which is what
		// MediaWiki always emits. This avoids breaking URLs that contain
		// commas without a trailing space (filenames like "X,_Y.jpg").
		$candidates = preg_split( '/,\s+/', trim( $srcset ) );
		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( $candidate === '' ) {
				continue;
			}
			// Split into URL and descriptor on the first whitespace
			if ( preg_match( '/^(\S+)(?:\s+(.+))?$/', $candidate, $m ) ) {
				$result[] = [ $m[1], $m[2] ?? '' ];
			}
		}
		return $result;
	}

	/**
	 * Compute the byte ranges spanned by every <picture>...</picture> block in
	 * $text, in a single linear pass.
	 *
	 * Nested <picture> is invalid HTML and never emitted, so we treat the markup
	 * as flat: each <picture ...> opens a region that the next </picture> closes.
	 * The returned ranges are in ascending order of start offset.
	 *
	 * @param string $text
	 * @return list<array{0:int,1:int}> [openStart, closeEnd) byte ranges
	 */
	private function buildPictureRanges( string $text ): array {
		if ( stripos( $text, '<picture' ) === false ) {
			return [];
		}
		if ( !preg_match_all( '/<picture[\s>]|<\/picture\s*>/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}
		$ranges = [];
		$openStart = null;
		foreach ( $m[0] as [ $token, $pos ] ) {
			// "</picture>" starts with "</"; "<picture " / "<picture>" do not.
			$isClose = isset( $token[1] ) && $token[1] === '/';
			if ( !$isClose ) {
				// Flat model: only the first open of an unclosed run counts.
				if ( $openStart === null ) {
					$openStart = $pos;
				}
			} elseif ( $openStart !== null ) {
				$ranges[] = [ $openStart, $pos + strlen( $token ) ];
				$openStart = null;
			}
		}
		return $ranges;
	}

	/**
	 * Whether a byte offset falls inside one of the given <picture> ranges.
	 *
	 * @param int $offset
	 * @param list<array{0:int,1:int}> $ranges Ascending [start, end) ranges
	 * @internal
	 */
	public function offsetInsidePictureRange( int $offset, array $ranges ): bool {
		foreach ( $ranges as [ $start, $end ] ) {
			if ( $offset >= $start && $offset < $end ) {
				return true;
			}
			// Ranges are sorted: once a range starts past the offset, stop.
			if ( $start > $offset ) {
				break;
			}
		}
		return false;
	}

	/**
	 * Check if the byte offset is inside a <picture>...</picture> wrapper.
	 *
	 * Correct for wrappers of any size (no fixed lookback window). Standalone
	 * callers pay a single O(n) scan; the hot path in rewrite() pre-computes the
	 * ranges once and calls {@see offsetInsidePictureRange} directly.
	 *
	 * @internal
	 */
	public function isInsidePicture( string $text, int $offset ): bool {
		return $this->offsetInsidePictureRange( $offset, $this->buildPictureRanges( $text ) );
	}

	/**
	 * Check if a src URL/path appears to point to our local upload directory
	 * (either directly via /images/... or indirectly via the thumb.php
	 * thumbnailing entry point).
	 *
	 * @internal
	 */
	public function srcLooksLocal( string $src, string $uploadPath ): bool {
		// Relative or absolute URL pointing to thumb.php (the 404 thumbnailer)
		if ( strpos( $src, 'thumb.php' ) !== false ) {
			return true;
		}
		// Relative path starting with the upload path
		if ( strpos( $src, $uploadPath ) === 0 ) {
			return true;
		}
		// Absolute URL containing the upload path
		if ( preg_match( '#^https?://[^/]+(' . preg_quote( $uploadPath, '#' ) . ')#', $src ) ) {
			return true;
		}
		return false;
	}
}
