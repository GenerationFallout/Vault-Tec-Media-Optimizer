<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Hook;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerFactory;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\HtmlRewriter;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PngRecompressorInterface;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWiki\Hook\FileDeleteCompleteHook;
use MediaWiki\Hook\FileTransformedHook;
use MediaWiki\Hook\FileUploadHook;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Output\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Main runtime hook handler for VaultTecMediaOptimizer.
 *
 * - FileUpload: enqueues a background job to optimize the new original file.
 * - FileTransformed: when MediaWiki generates a thumbnail, also produce the
 *   WebP version next to it so the rewriter can serve it.
 * - FileDeleteComplete: cleans up the WebP file and the DB record.
 * - OutputPageBeforeHTML: rewrites <img> -> <picture> for served pages.
 */
class MainHooks implements
	FileUploadHook,
	FileDeleteCompleteHook,
	FileTransformedHook,
	OutputPageBeforeHTMLHook
{
	private HtmlRewriter $htmlRewriter;
	private OptimizationRecord $record;
	private WebPRepo $webpRepo;
	private OptimizerFactory $optimizerFactory;
	private ServiceOptions $options;
	private JobQueueGroup $jobQueueGroup;
	private PngRecompressorInterface $zopfli;
	private LoggerInterface $logger;
	/** Per-request budget for the slow thumbnail second pass (zopfli). Lazy-init. */
	private ?int $thumbExtraBudget = null;

	public function __construct(
		HtmlRewriter $htmlRewriter,
		OptimizationRecord $record,
		WebPRepo $webpRepo,
		OptimizerFactory $optimizerFactory,
		ServiceOptions $options,
		JobQueueGroup $jobQueueGroup,
		PngRecompressorInterface $zopfli
	) {
		$this->htmlRewriter = $htmlRewriter;
		$this->record = $record;
		$this->webpRepo = $webpRepo;
		$this->optimizerFactory = $optimizerFactory;
		$this->options = $options;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->zopfli = $zopfli;
		$this->logger = LoggerFactory::getInstance( 'VaultTecMediaOptimizer' );
	}

	/**
	 * @inheritDoc
	 *
	 * Called after a successful upload. Enqueues a background job to optimize
	 * the new file. We use a job rather than inline processing so the upload
	 * response stays fast.
	 */
	public function onFileUpload( $file, $reupload, $hasDescription ) {
		if ( !$this->options->get( 'VaultTecMediaOptimizerEnabled' ) ) {
			return;
		}

		// Large-wiki mode: when the job queue is disabled, uploads are NOT
		// auto-enqueued. The admin optimizes originals on their own schedule via
		// maintenance/optimizeImages.php; thumbnails still get their WebP
		// on-demand at render time, so visitors keep receiving WebP regardless.
		if ( !$this->options->get( 'VaultTecMediaOptimizerUseJobQueue' ) ) {
			return;
		}

		$imgName = $file->getName();

		// Only enqueue if the file's MIME type matches what we handle.
		// (Cheap optimization to avoid clogging the queue with PDFs etc.)
		$mime = $file->getMimeType();
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );
		if ( !in_array( $mime, $allowedMimes, true ) ) {
			return;
		}

		$title = Title::makeTitleSafe( NS_FILE, $imgName );
		if ( !$title ) {
			return;
		}

		$this->jobQueueGroup->push(
			new JobSpecification(
				'VaultTecMediaOptimizerOptimizeImage',
				[ 'imgName' => $imgName ],
				[ 'removeDuplicates' => true ],
				$title
			)
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Cleans up the WebP file and DB record on deletion of the original.
	 */
	public function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		$imgName = $file->getName();

		// Reconstruct the filesystem path the same way we do in ImageProcessor:
		// $wgUploadDirectory + getRel(). Avoids the mwstore:// URL that
		// $file->getPath() returns, which would not match our WebP lookup.
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$rel = $file->getRel();
		if ( is_string( $rel ) && $rel !== '' ) {
			$path = $uploadDir . '/' . $rel;
			try {
				$this->webpRepo->deleteWebP( $path );
				// Also remove the AVIF copy if any. Safe (and cheap) to call
				// regardless of whether the AVIF feature is enabled: it no-ops
				// when the file is absent.
				$this->webpRepo->deleteAvif( $path );
			} catch ( Throwable $e ) {
				// Best-effort: log and move on. We don't want to block the
				// deletion if derived-file cleanup fails (disk error, perms, etc.).
				$this->logger->warning( 'Derived-file cleanup failed for {name}: {msg}', [
					'name' => $imgName,
					'msg' => $e->getMessage(),
				] );
			}
		}

		$this->record->delete( $imgName );
	}

	/**
	 * @inheritDoc
	 *
	 * Called every time MediaWiki generates a thumbnail. We generate the
	 * matching WebP version next to the thumbnail so the HtmlRewriter can
	 * serve it via <picture>.
	 *
	 * Only the fast WebP encode runs synchronously here (a typical 200KB thumb
	 * is <50ms, and a given thumb triggers this hook only once, when first
	 * generated). The SLOW extras — experimental AVIF and the zopflipng second
	 * pass — are deferred to a POST_SEND update and bounded per request, so they
	 * never add to a visitor's render latency (see below).
	 *
	 * We work entirely with the tmpThumbPath (filesystem path, guaranteed)
	 * and write into our parallel images_webp/ tree.
	 */
	public function onFileTransformed( $file, $thumb, $tmpThumbPath, $thumbPath ) {
		if ( !$this->options->get( 'VaultTecMediaOptimizerEnabled' )
			|| !$this->options->get( 'VaultTecMediaOptimizerProcessThumbnails' )
		) {
			return true;
		}

		// Only handle MIME types we care about
		$mime = $file->getMimeType();
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );
		if ( !in_array( $mime, $allowedMimes, true ) ) {
			return true;
		}

		// We read from tmpThumbPath (always a filesystem path — guaranteed by
		// File::transform's own logic, since it just wrote the file there).
		if ( !is_string( $tmpThumbPath ) || !is_file( $tmpThumbPath ) ) {
			return true;
		}

		// Where do we write the WebP?
		//
		// $thumbPath is the FileBackend STORAGE path. On non-trivial backends
		// (and even on LocalRepo where it returns "mwstore://local-backend/local-thumb/...")
		// it's a virtual URL, NOT a filesystem path. So we can't just substitute
		// $uploadDir with $webpDir.
		//
		// Instead, we rely on the public URL of the thumbnail. MediaTransformOutput::getUrl()
		// returns something like "/images/thumb/a/ab/File.png/220px-File.png" OR
		// "/thumb.php?f=File.png&width=220". Both forms are handled by
		// WebPRepo::getWebPUrlAndPath(), which gives us the matching WebP
		// filesystem destination inside images_webp/.
		$thumbUrl = method_exists( $thumb, 'getUrl' ) ? $thumb->getUrl() : null;
		if ( !is_string( $thumbUrl ) || $thumbUrl === '' ) {
			$this->logger->debug( 'onFileTransformed: no thumb URL for {name}, skipping',
				[ 'name' => $file->getName() ] );
			return true;
		}

		$webpInfo = $this->webpRepo->getWebPUrlAndPath( $thumbUrl );
		if ( $webpInfo === null ) {
			$this->logger->debug( 'onFileTransformed: thumb URL did not resolve to a WebP path: {url}',
				[ 'url' => $thumbUrl ] );
			return true;
		}
		[ , $webpDest ] = $webpInfo;

		// Don't regenerate if already present and non-empty. A 0-byte/truncated
		// leftover is treated as missing so we replace it (atomically) rather than
		// leave a broken WebP that the rewriter would serve with no fallback.
		if ( is_file( $webpDest ) && filesize( $webpDest ) > 0 ) {
			return true;
		}

		if ( !$this->webpRepo->ensureDirFor( $webpDest ) ) {
			$this->logger->warning( 'Could not create WebP thumb directory for {dest}',
				[ 'dest' => $webpDest ] );
			return true;
		}

		try {
			$optimizer = $this->optimizerFactory->getOptimizer();
			$lossless = ( $mime === 'image/png' )
				&& $this->options->get( 'VaultTecMediaOptimizerWebPLosslessForPng' );
			$quality = (int)$this->options->get( 'VaultTecMediaOptimizerWebPQuality' );

			$ok = $optimizer->convertToWebP( $tmpThumbPath, $webpDest, $lossless, $quality );
			if ( !$ok ) {
				$detail = $optimizer->getLastError() ?? 'no detail';
				$this->logger->warning( 'WebP thumb generation failed for {name} ({dest}): {detail}', [
					'name' => $file->getName(),
					'dest' => $webpDest,
					'detail' => $detail,
				] );
			} else {
				$this->logger->debug( 'Generated WebP thumb {dest}',
					[ 'dest' => $webpDest ] );
			}
		} catch ( RuntimeException $e ) {
			$this->logger->warning( 'WebP thumb generation failed for {name}: {msg}', [
				'name' => $file->getName(),
				'msg' => $e->getMessage(),
			] );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Unexpected error generating WebP thumb for {name}: {msg}', [
				'name' => $file->getName(),
				'msg' => $e->getMessage(),
			] );
		}

		// Slow extras (experimental AVIF, and the zopflipng second pass on the
		// thumbnail) are NOT run synchronously here: this hook fires WHILE a
		// visitor's page is being rendered, and zopflipng alone costs seconds per
		// file (see the benchmarks in the guide). Running them inline would make a
		// cold gallery add seconds-to-minutes to one visitor's render. Instead we
		// (a) bound how many thumbnails get the extras per request with the same
		// budget as the on-demand rewriter (OnDemandThumbLimit), and (b) defer the
		// work to a POST_SEND update that runs AFTER the response is flushed, so
		// the visitor never waits. The cheap WebP above stays synchronous (it is
		// the asset the rewriter serves). The deferred pass operates on the STORED
		// thumbnail (by POST_SEND, MediaWiki has copied the temp thumb to its final
		// location), not on the now-discarded $tmpThumbPath.
		$srcThumbStored = $webpInfo[2] ?? null;
		$avifEnabled = (bool)$this->options->get( 'VaultTecMediaOptimizerAvifEnabled' );
		$wantZopfli = ( $mime === 'image/png' ) && $this->zopfli->isAvailable();
		if ( ( $avifEnabled || $wantZopfli ) && is_string( $srcThumbStored ) && $srcThumbStored !== '' ) {
			if ( $this->thumbExtraBudget === null ) {
				$this->thumbExtraBudget = (int)$this->options->get( 'VaultTecMediaOptimizerOnDemandThumbLimit' );
			}
			if ( $this->thumbExtraBudget > 0 ) {
				$this->thumbExtraBudget--;
				$avifDest = null;
				if ( $avifEnabled ) {
					$avifInfo = $this->webpRepo->getAvifUrlAndPath( $thumbUrl );
					$avifDest = $avifInfo[1] ?? null;
				}
				$name = $file->getName();
				DeferredUpdates::addCallableUpdate( function () use ( $srcThumbStored, $avifDest, $webpDest, $mime, $name ) {
					$this->runDeferredThumbExtras( $srcThumbStored, $avifDest, $webpDest, $mime, $name );
				} );
			} else {
				$this->logger->debug(
					'Thumb extras (AVIF/zopfli) skipped for {name}: per-request budget spent',
					[ 'name' => $file->getName() ]
				);
			}
		}

		return true;
	}

	/**
	 * Run the slow thumbnail extras (experimental AVIF + zopflipng second pass)
	 * AFTER the response has been sent (POST_SEND deferred update). It therefore
	 * never adds to a visitor's render latency. Operates on the STORED thumbnail
	 * (final on-disk file), and is bounded per request by the caller's budget.
	 */
	private function runDeferredThumbExtras(
		string $srcThumbStored,
		?string $avifDest,
		string $webpDest,
		string $mime,
		string $name
	): void {
		if ( !is_file( $srcThumbStored ) ) {
			return;
		}
		// Experimental AVIF, kept only if strictly smaller than the WebP (a
		// <picture> offers AVIF first, so a larger AVIF would cost more than WebP).
		// A '.skip' marker avoids re-encoding a not-worth-it AVIF on later renders.
		if ( $avifDest !== null
			&& ( !is_file( $avifDest ) || filesize( $avifDest ) === 0 )
			&& !$this->webpRepo->avifMarkedSkip( $avifDest, $srcThumbStored )
			&& $this->webpRepo->ensureDirFor( $avifDest )
		) {
			try {
				$optimizer = $this->optimizerFactory->getOptimizer();
				if ( $optimizer->supportsAvif() ) {
					$q = (int)$this->options->get( 'VaultTecMediaOptimizerAvifQuality' );
					if ( $optimizer->convertToAvif( $srcThumbStored, $avifDest, $q ) ) {
						$avifSz = filesize( $avifDest );
						$webpSz = is_file( $webpDest ) ? filesize( $webpDest ) : false;
						if ( $webpSz !== false && $webpSz > 0 && $avifSz !== false && $avifSz >= $webpSz ) {
							@unlink( $avifDest );
							$this->webpRepo->setAvifSkip( $avifDest );
						} else {
							$this->webpRepo->clearAvifSkip( $avifDest );
						}
					}
				}
			} catch ( Throwable $e ) {
				$this->logger->debug( 'Deferred AVIF thumb failed for {name}: {msg}',
					[ 'name' => $name, 'msg' => $e->getMessage() ] );
			}
		}
		// zopflipng second pass on the stored PNG thumbnail (disk-only gain).
		if ( $mime === 'image/png' && $this->zopfli->isAvailable() ) {
			try {
				$saved = $this->zopfli->recompress( $srcThumbStored );
				if ( $saved !== null && $saved > 0 ) {
					$this->record->addThumbZopfliSaving( $saved );
				}
			} catch ( Throwable $e ) {
				$this->logger->debug( 'Deferred zopfli thumb failed for {name}: {msg}',
					[ 'name' => $name, 'msg' => $e->getMessage() ] );
			}
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Wraps <img> tags pointing to local images in a <picture> element with
	 * a WebP source. The <img> stays as fallback, preserving compatibility
	 * with MultimediaViewer, lazy-loading, and other JS that targets <img>.
	 *
	 * Only runs on the 'view' action to avoid rewriting previews, diffs, and
	 * edit forms, where users may see in-progress content that doesn't
	 * correspond to existing WebP files (the lookups would all miss and the
	 * regex would run for nothing).
	 */
	public function onOutputPageBeforeHTML( $out, &$text ) {
		// OutputPage extends ContextSource, so getActionName() is directly available.
		$action = $out->getActionName();
		// Skip non-view actions: edit, history, diff, raw, info, etc.
		if ( $action !== 'view' ) {
			return true;
		}
		$this->htmlRewriter->rewrite( $text );
		return true;
	}
}
