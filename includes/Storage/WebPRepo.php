<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Storage;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;

/**
 * Manages the WebP file storage directory.
 *
 * Layout (mirror of images/):
 *   $wgUploadDirectory/../images_webp/
 *     a/ab/Original.webp                    <- WebP of File:Original.png
 *     thumb/a/ab/Original.png/
 *       220px-Original.webp                 <- WebP of 220px thumbnail
 *
 * This isolation makes uninstall a single `rm -rf images_webp/` away.
 */
class WebPRepo {

	private ServiceOptions $options;
	private LoggerInterface $logger;

	/** @var string|null Memoized, validated WebP directory name. */
	private ?string $webpDirName = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	/**
	 * The configured WebP directory name, validated.
	 *
	 * This name is the ONLY thing separating our derived-file tree (which we
	 * freely create, overwrite and recursively purge — see deleteWebPThumbDir)
	 * from MediaWiki's real upload tree. A careless config can break that
	 * separation:
	 *  - same name as the upload directory's basename → getRootDir() IS the
	 *    upload dir, and the reupload purge would delete MediaWiki's REAL
	 *    thumbnails;
	 *  - empty, '.'/'..' or a value containing path separators → the tree
	 *    lands somewhere malformed or outside the wiki.
	 * Such values are rejected and replaced with a safe default, loudly.
	 */
	private function webpDirName(): string {
		if ( $this->webpDirName !== null ) {
			return $this->webpDirName;
		}
		$name = (string)$this->options->get( 'VaultTecMediaOptimizerWebPDirectory' );
		$uploadBase = basename( rtrim( (string)$this->options->get( 'UploadDirectory' ), '/' ) );
		$invalid = $name === '' || $name === '.' || $name === '..'
			|| strpbrk( $name, '/\\' ) !== false
			|| strpos( $name, "\0" ) !== false
			|| $name === $uploadBase;
		if ( $invalid ) {
			$fallback = $uploadBase === 'images_webp' ? 'images_webp_vtmo' : 'images_webp';
			$this->logger->error(
				'Invalid $wgVaultTecMediaOptimizerWebPDirectory {value} (empty, contains a path '
					. 'separator, or collides with the upload directory name {upload}); using {fallback}',
				[ 'value' => $name, 'upload' => $uploadBase, 'fallback' => $fallback ]
			);
			$name = $fallback;
		}
		$this->webpDirName = $name;
		return $name;
	}

	/**
	 * Get the root WebP directory (absolute filesystem path).
	 */
	public function getRootDir(): string {
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$webpDirName = $this->webpDirName();
		$parent = dirname( $uploadDir );
		if ( $parent === '/' || $parent === '\\' || $parent === '.' ) {
			return '/' . $webpDirName;
		}
		return $parent . '/' . $webpDirName;
	}

	/**
	 * Get the root WebP URL prefix (for serving in HTML).
	 *
	 * Care must be taken with dirname() on URL-style paths:
	 * - dirname('/images') returns '/' on Linux
	 * - dirname('/wiki/images') returns '/wiki'
	 *
	 * Naively concatenating '/' + '/' + 'images_webp' yields '//images_webp',
	 * which browsers interpret as a protocol-relative URL pointing to a
	 * host named "images_webp" — guaranteed 404.
	 *
	 * We normalize by ensuring the parent never ends with '/' (except when
	 * it IS just '/').
	 */
	public function getRootUrl(): string {
		$uploadPath = rtrim( $this->options->get( 'UploadPath' ), '/' );
		$webpDirName = $this->webpDirName();
		$parent = dirname( $uploadPath );
		if ( $parent === '/' || $parent === '\\' || $parent === '.' ) {
			return '/' . $webpDirName;
		}
		return $parent . '/' . $webpDirName;
	}

	/**
	 * Compute the WebP filesystem path for a given original file path.
	 *
	 * @param string $originalPath E.g. /var/www/wiki/images/a/ab/File.png
	 * @return string|null E.g. /var/www/wiki/images_webp/a/ab/File.webp
	 *                             null if path is outside the upload directory
	 */
	public function getWebPPath( string $originalPath ): ?string {
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );

		// Normalize and check the original is inside the upload directory
		$realOriginal = $this->safeRealpath( $originalPath );
		$realUploadDir = $this->safeRealpath( $uploadDir );

		if ( $realOriginal === null || $realUploadDir === null ) {
			// Not yet created? fall back to string manipulation
			if ( strpos( $originalPath, $uploadDir ) !== 0 ) {
				return null;
			}
			$relative = substr( $originalPath, strlen( $uploadDir ) );
			// Unlike the realpath branch above, nothing has normalized this
			// path yet: a "../" segment would map to (and via deleteWebP()
			// potentially unlink) a file OUTSIDE images_webp. Reject it.
			if ( self::hasTraversalSegment( $relative ) || strpos( $relative, "\0" ) !== false ) {
				return null;
			}
		} else {
			if ( strpos( $realOriginal, $realUploadDir ) !== 0 ) {
				return null;
			}
			$relative = substr( $realOriginal, strlen( $realUploadDir ) );
		}

		$relative = ltrim( $relative, '/' );
		$webpRelative = $this->replaceExtension( $relative, 'webp' );
		return $this->getRootDir() . '/' . $webpRelative;
	}

	/**
	 * Compute the WebP URL for a given original file URL.
	 *
	 * @param string $originalUrl E.g. /images/a/ab/File.png or full URL
	 * @return string|null
	 */
	public function getWebPUrl( string $originalUrl ): ?string {
		$info = $this->getWebPUrlAndPath( $originalUrl );
		return $info === null ? null : $info[0];
	}

	/**
	 * Compute both the WebP URL and the corresponding on-disk path for an
	 * original file URL. Useful when the caller needs to check existence
	 * before emitting the URL in HTML.
	 *
	 * @param string $originalUrl E.g. /images/a/ab/File.png, /images/thumb/a/ab/File.png/220px-File.png,
	 *                            /thumb.php?f=File.png&width=220, or a full URL containing those.
	 * @return array{0: string, 1: string, 2: string}|null
	 *         [webpUrl, webpDiskPath, srcThumbPath], or null if the URL is not
	 *         from the upload area
	 */
	public function getWebPUrlAndPath( string $originalUrl ): ?array {
		// Branch 1: thumb.php?f=NAME&width=W (or &w=W) — common when
		// $wgGenerateThumbnailOnParse is disabled and apache rewrites
		// requests to thumb.php
		$thumbPhpResult = $this->resolveThumbPhpUrl( $originalUrl );
		if ( $thumbPhpResult !== null ) {
			return $thumbPhpResult;
		}

		// Branch 2: direct /images/... URL
		$uploadPath = rtrim( $this->options->get( 'UploadPath' ), '/' );

		// Extract path portion if full URL
		$path = $originalUrl;
		if ( preg_match( '#^https?://[^/]+(/.*)$#i', $originalUrl, $m ) ) {
			$path = $m[1];
		}

		// Strip query string if present
		$qPos = strpos( $path, '?' );
		if ( $qPos !== false ) {
			$path = substr( $path, 0, $qPos );
		}

		if ( strpos( $path, $uploadPath ) !== 0 ) {
			return null;
		}

		$relative = substr( $path, strlen( $uploadPath ) );
		$relative = ltrim( $relative, '/' );
		// $relative may still contain percent-encoded sequences (e.g. %C3%A9
		// for é). Different FileBackend configs store files either decoded or
		// URL-encoded on disk, so we build both candidates and pick whichever
		// exists (see resolveDiskVariant). The browser URL is always encoded.
		$relativeDecoded = $this->decodePath( $relative );

		// Security: after decoding, reject any traversal sequence. The decoded
		// relative path is used to build a source path we read from and a WebP
		// path we write to, so "%2e%2e/" style escapes must not slip through.
		if ( self::hasTraversalSegment( $relativeDecoded )
			|| strpos( $relativeDecoded, "\0" ) !== false
		) {
			return null;
		}

		$webpRelativeDecoded = $this->replaceExtension( $relativeDecoded, 'webp' );
		$webpRelativeEncoded = $this->encodePath( $webpRelativeDecoded );

		$rootDir = $this->getRootDir();
		$webpDiskRelative = $this->resolveDiskVariant( $rootDir, $webpRelativeDecoded, $webpRelativeEncoded );

		// URL — must be percent-encoded. The origin prefix ("https://host") is
		// whatever precedes $path in the original URL. Length arithmetic against
		// $originalUrl is WRONG here: a stripped query string would inflate the
		// computed length and corrupt the prefix (e.g. "/images/F.png?x=1"
		// yielded "/ima"). $path is the exact substring that follows the origin,
		// so "everything before $path's length, counted from the end" is exact —
		// but simplest and provably right: the origin is $originalUrl up to the
		// first occurrence of $path.
		$pathPos = strpos( $originalUrl, $path );
		if ( $pathPos !== false && $pathPos > 0 ) {
			$origin = substr( $originalUrl, 0, $pathPos );
			$webpUrl = $origin . $this->getRootUrl() . '/' . $webpRelativeEncoded;
		} else {
			$webpUrl = $this->getRootUrl() . '/' . $webpRelativeEncoded;
		}

		// Disk path — resolved to whichever encoding exists on this backend
		$webpDiskPath = $rootDir . '/' . $webpDiskRelative;

		// Source path: the original file/thumbnail under the upload directory,
		// same relative path, original extension. Used for on-demand WebP
		// generation when the WebP is missing.
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$srcRelative = $this->resolveDiskVariant( $uploadDir, $relativeDecoded, $this->encodePath( $relativeDecoded ) );
		$srcThumbPath = $uploadDir . '/' . $srcRelative;

		return [ $webpUrl, $webpDiskPath, $srcThumbPath ];
	}

	/**
	 * Try to resolve a thumb.php URL into a WebP url + disk path.
	 *
	 * Expected forms:
	 *   /thumb.php?f=FILENAME&width=W
	 *   /thumb.php?f=FILENAME&w=W
	 *   /something/thumb.php?f=FILENAME&height=H&width=W
	 *
	 * The thumbnail file on disk lives at:
	 *   images/thumb/<a>/<ab>/<FILENAME>/<W>px-<FILENAME>
	 * where <a> and <ab> are derived from md5(FILENAME).
	 *
	 * @return array{0: string, 1: string}|null [webpUrl, webpDiskPath]
	 */
	private function resolveThumbPhpUrl( string $url ): ?array {
		// Quick gate
		if ( strpos( $url, 'thumb.php' ) === false ) {
			return null;
		}

		// Parse the URL to get the query string
		$parts = parse_url( $url );
		if ( !is_array( $parts ) || !isset( $parts['query'] ) ) {
			return null;
		}
		parse_str( $parts['query'], $params );

		$fileName = $params['f'] ?? '';
		if ( !is_string( $fileName ) || $fileName === '' ) {
			return null;
		}

		// Security: reject anything that could escape the thumb directory.
		// MediaWiki file names never contain slashes or "..". Since $fileName
		// is now used to build a path we read from and write to (on-demand
		// WebP generation), a traversal sequence here could touch files
		// outside the upload tree. Reject defensively.
		if ( strpos( $fileName, '/' ) !== false
			|| strpos( $fileName, '\\' ) !== false
			|| $fileName === '.' || $fileName === '..'
			|| strpos( $fileName, "\0" ) !== false
		) {
			return null;
		}

		// Width: 'width' or 'w'; strip trailing 'px' if present
		$width = $params['width'] ?? ( $params['w'] ?? null );
		if ( !is_string( $width ) && !is_int( $width ) ) {
			return null;
		}
		$width = (string)$width;
		if ( str_ends_with( $width, 'px' ) ) {
			$width = substr( $width, 0, -2 );
		}
		if ( !ctype_digit( $width ) ) {
			return null;
		}

		// Compute md5 hash directory prefix (matches FileRepo::getHashPathForLevel).
		// MediaWiki hashes the DECODED filename (the logical DB key, with spaces
		// as underscores, but parentheses/commas/accents as-is).
		$hash = md5( $fileName );
		$hashDir = substr( $hash, 0, 1 ) . '/' . substr( $hash, 0, 2 );

		// The on-disk layout of thumbnail directories depends on the wiki's
		// FileBackend configuration. Most setups store the DECODED filename
		// (e.g. "Foo_(Bar,_Baz).jpg"), but some URL-encode it on disk
		// (e.g. "Foo_%28Bar%2C_Baz%29.jpg"). Rather than assume one form, we
		// build both candidates and let resolveDiskVariant() pick whichever
		// actually exists. This keeps the extension portable across wikis.
		$decodedName = $fileName;
		$encodedName = rawurlencode( $fileName );

		$webpThumbDecoded = $this->replaceExtension( $width . 'px-' . $decodedName, 'webp' );
		$webpThumbEncoded = $this->replaceExtension( $width . 'px-' . $encodedName, 'webp' );

		$relDecoded = 'thumb/' . $hashDir . '/' . $decodedName . '/' . $webpThumbDecoded;
		$relEncoded = 'thumb/' . $hashDir . '/' . $encodedName . '/' . $webpThumbEncoded;

		$rootDir = $this->getRootDir();
		$webpRelative = $this->resolveDiskVariant( $rootDir, $relDecoded, $relEncoded );

		// For the URL exposed to browsers, special characters must always be
		// percent-encoded so the URL is valid, regardless of the disk layout.
		$encodedWebpThumbName = rawurlencode( $this->replaceExtension( $width . 'px-' . $fileName, 'webp' ) );
		$webpUrlRelative = 'thumb/' . $hashDir . '/' . $encodedName . '/' . $encodedWebpThumbName;

		$webpUrl = $this->getRootUrl() . '/' . $webpUrlRelative;
		$webpDiskPath = $rootDir . '/' . $webpRelative;

		// Also compute the path to the SOURCE thumbnail (the PNG/JPEG/GIF the
		// browser is currently being served), so callers can generate the WebP
		// on demand if it is missing. The source thumbnail lives under the
		// upload directory, in the same thumb/<hash>/<name>/ layout, but with
		// the ORIGINAL extension (not .webp).
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$srcThumbDecoded = $width . 'px-' . $decodedName;
		$srcThumbEncoded = $width . 'px-' . $encodedName;
		$srcRelDecoded = 'thumb/' . $hashDir . '/' . $decodedName . '/' . $srcThumbDecoded;
		$srcRelEncoded = 'thumb/' . $hashDir . '/' . $encodedName . '/' . $srcThumbEncoded;
		$srcRelative = $this->resolveDiskVariant( $uploadDir, $srcRelDecoded, $srcRelEncoded );
		$srcThumbPath = $uploadDir . '/' . $srcRelative;

		return [ $webpUrl, $webpDiskPath, $srcThumbPath ];
	}

	/**
	 * Given two candidate relative paths (decoded vs URL-encoded filename),
	 * return whichever exists on disk under $rootDir. If neither exists yet
	 * (e.g. the WebP hasn't been generated), fall back to the decoded form,
	 * which is what MediaWiki uses on the majority of filesystem backends.
	 *
	 * This makes path resolution robust across different FileBackend
	 * configurations without requiring per-wiki tuning.
	 */
	private function resolveDiskVariant( string $rootDir, string $relDecoded, string $relEncoded ): string {
		if ( $relDecoded === $relEncoded ) {
			// No special characters; both forms identical.
			return $relDecoded;
		}
		if ( is_file( $rootDir . '/' . $relDecoded ) ) {
			return $relDecoded;
		}
		if ( is_file( $rootDir . '/' . $relEncoded ) ) {
			return $relEncoded;
		}
		// Neither present yet: default to decoded (most common backend layout).
		return $relDecoded;
	}

	/**
	 * Check if a WebP file exists for the given original.
	 */
	public function hasWebP( string $originalPath ): bool {
		$webpPath = $this->getWebPPath( $originalPath );
		return $webpPath !== null && is_file( $webpPath );
	}

	/**
	 * Whether a relative path contains a real ".." SEGMENT (i.e. traversal).
	 *
	 * Testing for the substring instead is both wrong and harmful: MediaWiki
	 * legitimately allows ".." inside a title, so a file named "v..2.png" was
	 * being refused a servable derivative entirely — while a genuine traversal
	 * is only ever expressed as a standalone segment.
	 */
	private static function hasTraversalSegment( string $relative ): bool {
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( $segment === '..' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a derived file can physically be created at this path.
	 *
	 * Filesystems cap a single path COMPONENT at 255 bytes (NAME_MAX on
	 * ext4/xfs/btrfs). Our writes need headroom on top of the final basename,
	 * because the atomic publish first creates "<name>.vtmo.<pid>.<hex>.tmp".
	 * MediaWiki happily accepts titles long enough to blow through that once
	 * the derived name is built — and ".webp" is one byte longer than ".png",
	 * so a legal upload can tip over purely by being converted.
	 *
	 * Without this check the encode is attempted, fails with "File name too
	 * long", and — because no file ever lands on disk — the failure is never
	 * negatively cached. It is then retried on EVERY page view, consuming a
	 * unit of the per-render on-demand budget each time. Measured on a live
	 * wiki: five long-named images ate the entire default budget of 5 on every
	 * render, so the healthy images on the same page were never encoded at all.
	 *
	 * @param string $path Absolute destination path of the derived file
	 * @return bool False if the write cannot possibly succeed
	 */
	public function isWritablePathLength( string $path ): bool {
		// '.vtmo.' + pid + '.' + 12 hex + '.tmp' — sized generously so the
		// guard never lets through a name the temp step would reject.
		$tempOverhead = 30;
		return strlen( basename( $path ) ) + $tempOverhead <= 255;
	}

	/**
	 * Ensure the parent directory of a WebP file exists.
	 * Returns true on success.
	 */
	public function ensureDirFor( string $webpPath ): bool {
		$dir = dirname( $webpPath );
		if ( is_dir( $dir ) ) {
			return true;
		}
		// Use the same perm scheme as MediaWiki's upload dir
		$ok = @mkdir( $dir, 0755, true );
		if ( !$ok && !is_dir( $dir ) ) {
			$this->logger->error( 'Failed to create WebP directory {dir}', [ 'dir' => $dir ] );
			return false;
		}
		return true;
	}

	/**
	 * Try to create the root WebP directory (e.g. /var/www/wiki/images_webp).
	 * Returns a [success, message] tuple where message is human-readable
	 * (used by Special:VTMOStatus for the "Create directory now" button).
	 *
	 * @return array{0: bool, 1: string}
	 */
	public function createRootDir(): array {
		$dir = $this->getRootDir();

		if ( is_dir( $dir ) ) {
			if ( is_writable( $dir ) ) {
				return [ true, 'Directory already exists and is writable.' ];
			}
			return [ false, "Directory exists but is not writable: $dir "
				. "(check permissions, should be 755 or 775 owned by the web server user)." ];
		}

		// Check that the parent dir is writable BEFORE trying mkdir, so we
		// can give a useful error message instead of a generic mkdir failure.
		$parent = dirname( $dir );
		if ( !is_dir( $parent ) ) {
			return [ false, "Parent directory does not exist: $parent. "
				. "This shouldn't happen — check your wiki installation." ];
		}
		if ( !is_writable( $parent ) ) {
			return [ false, "Parent directory $parent is not writable by the web server. "
				. "Either change its permissions, or create $dir manually via FTP (chmod 755)." ];
		}

		$ok = @mkdir( $dir, 0755, false );
		if ( !$ok ) {
			$err = error_get_last();
			$errMsg = $err['message'] ?? 'unknown error';
			return [ false, "mkdir() failed for $dir: $errMsg" ];
		}

		return [ true, "Directory $dir created successfully." ];
	}

	/**
	 * Delete every per-size WebP thumbnail of a file, i.e. the whole
	 * images_webp/thumb/<a>/<ab>/<imgName>/ directory.
	 *
	 * Called on reupload and on full deletion: thumbnails are regenerated at
	 * the SAME paths, so without this purge the skip-if-exists guard in
	 * onFileTransformed would keep serving WebP rendered from the previous
	 * image's pixels forever.
	 *
	 * The directory name on disk may be stored decoded or URL-encoded depending
	 * on the FileBackend config (same duality as resolveDiskVariant), so both
	 * variants are removed.
	 *
	 * @param string $imgName DB key, e.g. "Vault_door.png"
	 * @return bool True if nothing was left behind (or nothing existed)
	 */
	public function deleteWebPThumbDir( string $imgName ): bool {
		// Defensive: a path separator or traversal in a DB key should be
		// impossible, but this method recursively deletes a directory.
		if ( $imgName === '' || strpos( $imgName, '/' ) !== false
			|| strpos( $imgName, '\\' ) !== false
			|| $imgName === '.' || $imgName === '..'
			|| strpos( $imgName, "\0" ) !== false
		) {
			return false;
		}
		$hash = md5( $imgName );
		$hashDir = substr( $hash, 0, 1 ) . '/' . substr( $hash, 0, 2 );
		$ok = true;
		foreach ( array_unique( [ $imgName, rawurlencode( $imgName ) ] ) as $dirName ) {
			$dir = $this->getRootDir() . '/thumb/' . $hashDir . '/' . $dirName;
			if ( !is_dir( $dir ) ) {
				continue;
			}
			$entries = @scandir( $dir );
			if ( $entries === false ) {
				$ok = false;
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( $entry === '.' || $entry === '..' ) {
					continue;
				}
				// Flat directory of per-size .webp files; never recurse further.
				if ( !@unlink( $dir . '/' . $entry ) ) {
					$ok = false;
				}
			}
			@rmdir( $dir );
		}
		return $ok;
	}

	/**
	 * Delete the WebP file (and its parent dir if empty) for a given original.
	 * Used on file deletion to keep storage tidy.
	 */
	public function deleteWebP( string $originalPath ): bool {
		$webpPath = $this->getWebPPath( $originalPath );
		if ( $webpPath === null || !file_exists( $webpPath ) ) {
			return true;
		}
		$ok = @unlink( $webpPath );
		if ( $ok ) {
			// Try to remove parent dir if empty (will silently fail if not)
			@rmdir( dirname( $webpPath ) );
		}
		return $ok;
	}

	/**
	 * Replace the extension of a relative path with the given new extension.
	 */
	public function replaceExtension( string $path, string $newExt ): string {
		$dot = strrpos( $path, '.' );
		if ( $dot === false ) {
			return $path . '.' . $newExt;
		}
		return substr( $path, 0, $dot + 1 ) . $newExt;
	}

	private function safeRealpath( string $path ): ?string {
		$real = @realpath( $path );
		return $real === false ? null : $real;
	}

	/**
	 * Decode percent-encoded sequences in a relative path while preserving
	 * the path separators. E.g. "a/b/%C3%A9toile.png" → "a/b/étoile.png".
	 */
	private function decodePath( string $relative ): string {
		$segments = explode( '/', $relative );
		foreach ( $segments as $i => $segment ) {
			$segments[$i] = rawurldecode( $segment );
		}
		return implode( '/', $segments );
	}

	/**
	 * Percent-encode each segment of a relative path while preserving the
	 * path separators. E.g. "a/b/étoile.png" → "a/b/%C3%A9toile.png".
	 */
	private function encodePath( string $relative ): string {
		$segments = explode( '/', $relative );
		foreach ( $segments as $i => $segment ) {
			$segments[$i] = rawurlencode( $segment );
		}
		return implode( '/', $segments );
	}
}
