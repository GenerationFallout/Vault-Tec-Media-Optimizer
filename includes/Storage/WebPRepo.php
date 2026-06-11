<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Storage;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;

/**
 * Manages the storage directories for the derived image formats (WebP and,
 * experimentally, AVIF).
 *
 * Layout (each format mirrors images/ in its own sibling directory):
 *   $wgUploadDirectory/../images_webp/
 *     a/ab/Original.webp                    <- WebP of File:Original.png
 *     thumb/a/ab/Original.png/
 *       220px-Original.webp                 <- WebP of 220px thumbnail
 *   $wgUploadDirectory/../images_avif/      <- same tree, .avif extension
 *
 * Keeping each format in its own directory makes uninstall a single
 * `rm -rf images_webp/ images_avif/` away.
 *
 * The path logic is identical across formats, so the heavy lifting lives in
 * format-agnostic private helpers parameterized by (extension, directory name);
 * the public WebP/AVIF methods are thin wrappers over them.
 */
class WebPRepo {

	private ServiceOptions $options;
	private LoggerInterface $logger;

	/** @var string|null Memoized, validated WebP directory name. */
	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	/**
	 * Validate a configured derived-tree directory name (WebP or AVIF).
	 *
	 * This name is the ONLY thing separating our derived-file tree (which we
	 * freely create, overwrite and recursively purge — see deleteWebPThumbDir)
	 * from MediaWiki's real upload tree. A careless config can break that
	 * separation:
	 *  - same name as the upload directory's basename → the root IS the upload
	 *    dir, and the reupload purge would delete MediaWiki's REAL thumbnails;
	 *  - empty, '.'/'..' or a value containing path separators → the tree
	 *    lands somewhere malformed or outside the wiki.
	 * Such values are rejected and replaced with a safe default, loudly. Applied
	 * inside rootDirFor()/rootUrlFor() so EVERY derived path — WebP and AVIF —
	 * is validated, regardless of which config key it came from.
	 *
	 * @param string $name Raw configured directory name
	 * @param string $configKey Config key name, for the log message
	 * @param string $fallback Safe default to use when $name is rejected
	 */
	private function validatedDirName( string $name, string $configKey, string $fallback ): string {
		$uploadBase = basename( rtrim( (string)$this->options->get( 'UploadDirectory' ), '/' ) );
		$invalid = $name === '' || $name === '.' || $name === '..'
			|| strpbrk( $name, '/\\' ) !== false
			|| strpos( $name, "\0" ) !== false
			|| $name === $uploadBase;
		if ( !$invalid ) {
			return $name;
		}
		// If the fallback itself collides with the upload basename, suffix it.
		if ( $fallback === $uploadBase ) {
			$fallback .= '_vtmo';
		}
		$this->logger->error(
			'Invalid ${key} {value} (empty, contains a path separator, or collides with the '
				. 'upload directory name {upload}); using {fallback}',
			[ 'key' => $configKey, 'value' => $name, 'upload' => $uploadBase, 'fallback' => $fallback ]
		);
		return $fallback;
	}

	/**
	 * Validated directory name for a given derived-format root (WebP or AVIF),
	 * picking the right config key and fallback from the raw value.
	 */
	private function resolveDirName( string $rawDirName ): string {
		$webpDir = (string)$this->options->get( 'VaultTecMediaOptimizerWebPDirectory' );
		if ( $rawDirName === $webpDir ) {
			return $this->validatedDirName( $rawDirName,
				'wgVaultTecMediaOptimizerWebPDirectory', 'images_webp' );
		}
		return $this->validatedDirName( $rawDirName,
			'wgVaultTecMediaOptimizerAvifDirectory', 'images_avif' );
	}

	/**
	 * Get the root WebP directory (absolute filesystem path).
	 */
	public function getRootDir(): string {
		return $this->rootDirFor( $this->options->get( 'VaultTecMediaOptimizerWebPDirectory' ) );
	}

	/**
	 * Get the root AVIF directory (absolute filesystem path).
	 */
	public function getAvifRootDir(): string {
		return $this->rootDirFor( $this->options->get( 'VaultTecMediaOptimizerAvifDirectory' ) );
	}

	/**
	 * Compute a root directory (sibling of the upload dir) for a derived format.
	 */
	private function rootDirFor( string $dirName ): string {
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$dirName = $this->resolveDirName( $dirName );
		$parent = dirname( $uploadDir );
		if ( $parent === '/' || $parent === '\\' || $parent === '.' ) {
			return '/' . $dirName;
		}
		return $parent . '/' . $dirName;
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
		return $this->rootUrlFor( $this->options->get( 'VaultTecMediaOptimizerWebPDirectory' ) );
	}

	/**
	 * Get the root AVIF URL prefix (for serving in HTML).
	 */
	public function getAvifRootUrl(): string {
		return $this->rootUrlFor( $this->options->get( 'VaultTecMediaOptimizerAvifDirectory' ) );
	}

	private function rootUrlFor( string $dirName ): string {
		$uploadPath = rtrim( $this->options->get( 'UploadPath' ), '/' );
		$dirName = $this->resolveDirName( $dirName );
		$parent = dirname( $uploadPath );
		if ( $parent === '/' || $parent === '\\' || $parent === '.' ) {
			return '/' . $dirName;
		}
		return $parent . '/' . $dirName;
	}

	/**
	 * Compute the WebP filesystem path for a given original file path.
	 *
	 * @param string $originalPath E.g. /var/www/wiki/images/a/ab/File.png
	 * @return string|null         E.g. /var/www/wiki/images_webp/a/ab/File.webp
	 *                             null if path is outside the upload directory
	 */
	public function getWebPPath( string $originalPath ): ?string {
		return $this->derivedPathFor( $originalPath, 'webp', $this->getRootDir() );
	}

	/**
	 * Compute the AVIF filesystem path for a given original file path.
	 */
	public function getAvifPath( string $originalPath ): ?string {
		return $this->derivedPathFor( $originalPath, 'avif', $this->getAvifRootDir() );
	}

	/**
	 * Sidecar "do not regenerate this AVIF" marker path.
	 *
	 * AVIF is not always smaller than WebP (it depends on content, quality and
	 * the AV1 encoder). When a generated AVIF turns out to be >= its WebP, we
	 * discard it and serve WebP instead (see ImageProcessor / HtmlRewriter). To
	 * avoid re-encoding-then-discarding it on every page render, we drop a tiny
	 * empty marker next to where the AVIF would live. The marker self-expires
	 * when the source becomes newer than it, so a re-uploaded or regenerated
	 * source is re-evaluated. This is a filesystem rule rather than a DB rule on
	 * purpose: thumbnails have no per-file DB row (there can be millions), so a
	 * sidecar marker is the only mechanism that covers both originals and thumbs.
	 *
	 * @param string $avifPath
	 * @return string
	 */
	public function avifSkipMarker( string $avifPath ): string {
		return $avifPath . '.skip';
	}

	/**
	 * Whether a *fresh* "AVIF not worth it" marker exists for this source. A
	 * marker older than the source is stale (source changed) → removed and
	 * treated as absent so we re-evaluate.
	 *
	 * @param string $avifPath Target AVIF path
	 * @param string $srcPath  Source the AVIF would be generated from
	 * @return bool True if AVIF generation should be skipped
	 */
	public function avifMarkedSkip( string $avifPath, string $srcPath ): bool {
		$marker = $this->avifSkipMarker( $avifPath );
		if ( !is_file( $marker ) ) {
			return false;
		}
		$markerTime = @filemtime( $marker );
		if ( $markerTime === false ) {
			return false;
		}
		$srcTime = @filemtime( $srcPath );
		if ( $srcTime !== false && $srcTime > $markerTime ) {
			// Source is newer than the decision → re-evaluate.
			@unlink( $marker );
			return false;
		}
		return true;
	}

	/**
	 * Record that the AVIF is not worth keeping for this source (don't retry).
	 */
	public function setAvifSkip( string $avifPath ): void {
		@touch( $this->avifSkipMarker( $avifPath ) );
	}

	/**
	 * Clear any skip marker (the AVIF now wins, or AVIF was produced).
	 */
	public function clearAvifSkip( string $avifPath ): void {
		$marker = $this->avifSkipMarker( $avifPath );
		if ( is_file( $marker ) ) {
			@unlink( $marker );
		}
	}

	/**
	 * Compute the derived-format filesystem path for a given original file path.
	 *
	 * @param string $originalPath
	 * @param string $ext Target extension ('webp' | 'avif')
	 * @param string $rootDir Root directory for that format
	 * @return string|null null if the path is outside the upload directory
	 */
	private function derivedPathFor( string $originalPath, string $ext, string $rootDir ): ?string {
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
			if ( strpos( $relative, '..' ) !== false || strpos( $relative, "\0" ) !== false ) {
				return null;
			}
		} else {
			if ( strpos( $realOriginal, $realUploadDir ) !== 0 ) {
				return null;
			}
			$relative = substr( $realOriginal, strlen( $realUploadDir ) );
		}

		$relative = ltrim( $relative, '/' );
		$derivedRelative = $this->replaceExtension( $relative, $ext );
		return $rootDir . '/' . $derivedRelative;
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
		return $this->derivedUrlAndPath(
			$originalUrl, 'webp', $this->options->get( 'VaultTecMediaOptimizerWebPDirectory' )
		);
	}

	/**
	 * Compute both the AVIF URL and on-disk path for an original file URL.
	 *
	 * @param string $originalUrl
	 * @return array{0: string, 1: string, 2?: string}|null [avifUrl, avifDiskPath, srcThumbPath]
	 */
	public function getAvifUrlAndPath( string $originalUrl ): ?array {
		return $this->derivedUrlAndPath(
			$originalUrl, 'avif', $this->options->get( 'VaultTecMediaOptimizerAvifDirectory' )
		);
	}

	/**
	 * Format-agnostic core of {@see getWebPUrlAndPath}/{@see getAvifUrlAndPath}.
	 *
	 * @param string $originalUrl
	 * @param string $ext Target extension ('webp' | 'avif')
	 * @param string $dirName Directory name for that format
	 * @return array{0: string, 1: string, 2?: string}|null
	 */
	private function derivedUrlAndPath( string $originalUrl, string $ext, string $dirName ): ?array {
		// Branch 1: thumb.php?f=NAME&width=W (or &w=W) — common when
		// $wgGenerateThumbnailOnParse is disabled and apache rewrites
		// requests to thumb.php
		$thumbPhpResult = $this->resolveThumbPhpUrl( $originalUrl, $ext, $dirName );
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
		// relative path is used to build a source path we read from and a derived
		// path we write to, so "%2e%2e/" style escapes must not slip through.
		if ( strpos( $relativeDecoded, '..' ) !== false
			|| strpos( $relativeDecoded, "\0" ) !== false
		) {
			return null;
		}

		$derivedRelativeDecoded = $this->replaceExtension( $relativeDecoded, $ext );
		$derivedRelativeEncoded = $this->encodePath( $derivedRelativeDecoded );

		$rootDir = $this->rootDirFor( $dirName );
		$derivedDiskRelative = $this->resolveDiskVariant( $rootDir, $derivedRelativeDecoded, $derivedRelativeEncoded );

		// URL — must be percent-encoded. The origin prefix ("https://host") is
		// whatever precedes $path in the original URL. Length arithmetic against
		// $originalUrl is WRONG here: a stripped query string would inflate the
		// computed length and corrupt the prefix (e.g. "/images/F.png?x=1"
		// yielded "/ima"). $path is the exact substring that follows the origin,
		// so the origin is simply $originalUrl up to the first occurrence of $path.
		$pathPos = strpos( $originalUrl, $path );
		if ( $pathPos !== false && $pathPos > 0 ) {
			$origin = substr( $originalUrl, 0, $pathPos );
			$derivedUrl = $origin . $this->rootUrlFor( $dirName ) . '/' . $derivedRelativeEncoded;
		} else {
			$derivedUrl = $this->rootUrlFor( $dirName ) . '/' . $derivedRelativeEncoded;
		}

		// Disk path — resolved to whichever encoding exists on this backend
		$derivedDiskPath = $rootDir . '/' . $derivedDiskRelative;

		// Source path: the original file/thumbnail under the upload directory,
		// same relative path, original extension. Used for on-demand generation
		// when the derived file is missing.
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$srcRelative = $this->resolveDiskVariant( $uploadDir, $relativeDecoded, $this->encodePath( $relativeDecoded ) );
		$srcThumbPath = $uploadDir . '/' . $srcRelative;

		return [ $derivedUrl, $derivedDiskPath, $srcThumbPath ];
	}

	/**
	 * Try to resolve a thumb.php URL into a derived url + disk path.
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
	 * @param string $url
	 * @param string $ext Target extension ('webp' | 'avif')
	 * @param string $dirName Directory name for that format
	 * @return array{0: string, 1: string, 2?: string}|null [derivedUrl, derivedDiskPath, srcThumbPath]
	 */
	private function resolveThumbPhpUrl( string $url, string $ext, string $dirName ): ?array {
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
		// generation), a traversal sequence here could touch files
		// outside the upload tree. Reject defensively.
		if ( strpos( $fileName, '/' ) !== false
			|| strpos( $fileName, '\\' ) !== false
			|| strpos( $fileName, '..' ) !== false
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

		$derivedThumbDecoded = $this->replaceExtension( $width . 'px-' . $decodedName, $ext );
		$derivedThumbEncoded = $this->replaceExtension( $width . 'px-' . $encodedName, $ext );

		$relDecoded = 'thumb/' . $hashDir . '/' . $decodedName . '/' . $derivedThumbDecoded;
		$relEncoded = 'thumb/' . $hashDir . '/' . $encodedName . '/' . $derivedThumbEncoded;

		$rootDir = $this->rootDirFor( $dirName );
		$derivedRelative = $this->resolveDiskVariant( $rootDir, $relDecoded, $relEncoded );

		// For the URL exposed to browsers, special characters must always be
		// percent-encoded so the URL is valid, regardless of the disk layout.
		$encodedDerivedThumbName = rawurlencode( $this->replaceExtension( $width . 'px-' . $fileName, $ext ) );
		$derivedUrlRelative = 'thumb/' . $hashDir . '/' . $encodedName . '/' . $encodedDerivedThumbName;

		$derivedUrl = $this->rootUrlFor( $dirName ) . '/' . $derivedUrlRelative;
		$derivedDiskPath = $rootDir . '/' . $derivedRelative;

		// Also compute the path to the SOURCE thumbnail (the PNG/JPEG/GIF the
		// browser is currently being served), so callers can generate the derived
		// file on demand if it is missing. The source thumbnail lives under the
		// upload directory, in the same thumb/<hash>/<name>/ layout, but with
		// the ORIGINAL extension.
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$srcThumbDecoded = $width . 'px-' . $decodedName;
		$srcThumbEncoded = $width . 'px-' . $encodedName;
		$srcRelDecoded = 'thumb/' . $hashDir . '/' . $decodedName . '/' . $srcThumbDecoded;
		$srcRelEncoded = 'thumb/' . $hashDir . '/' . $encodedName . '/' . $srcThumbEncoded;
		$srcRelative = $this->resolveDiskVariant( $uploadDir, $srcRelDecoded, $srcRelEncoded );
		$srcThumbPath = $uploadDir . '/' . $srcRelative;

		return [ $derivedUrl, $derivedDiskPath, $srcThumbPath ];
	}

	/**
	 * Given two candidate relative paths (decoded vs URL-encoded filename),
	 * return whichever exists on disk under $rootDir. If neither exists yet
	 * (e.g. the derived file hasn't been generated), fall back to the decoded
	 * form, which is what MediaWiki uses on the majority of filesystem backends.
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
	 * Ensure the parent directory of a derived file exists.
	 * Returns true on success.
	 */
	public function ensureDirFor( string $derivedPath ): bool {
		$dir = dirname( $derivedPath );
		if ( is_dir( $dir ) ) {
			return true;
		}
		// Use the same perm scheme as MediaWiki's upload dir
		$ok = @mkdir( $dir, 0755, true );
		if ( !$ok && !is_dir( $dir ) ) {
			$this->logger->error( 'Failed to create derived directory {dir}', [ 'dir' => $dir ] );
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
			return [ false, "Directory exists but is not writable: $dir (check permissions, should be 755 or 775 owned by the web server user)." ];
		}

		// Check that the parent dir is writable BEFORE trying mkdir, so we
		// can give a useful error message instead of a generic mkdir failure.
		$parent = dirname( $dir );
		if ( !is_dir( $parent ) ) {
			return [ false, "Parent directory does not exist: $parent. This shouldn't happen — check your wiki installation." ];
		}
		if ( !is_writable( $parent ) ) {
			return [ false, "Parent directory $parent is not writable by the web server. Either change its permissions, or create $dir manually via FTP (chmod 755)." ];
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
			|| strpos( $imgName, '..' ) !== false
			|| strpos( $imgName, "\0" ) !== false
		) {
			return false;
		}
		$hash = md5( $imgName );
		$hashDir = substr( $hash, 0, 1 ) . '/' . substr( $hash, 0, 2 );
		$ok = true;
		// Purge both derived trees (WebP and the experimental AVIF), each in its
		// decoded and URL-encoded on-disk name variant.
		$roots = array_unique( [ $this->getRootDir(), $this->getAvifRootDir() ] );
		foreach ( $roots as $root ) {
			foreach ( array_unique( [ $imgName, rawurlencode( $imgName ) ] ) as $dirName ) {
				$dir = $root . '/thumb/' . $hashDir . '/' . $dirName;
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
					// Flat directory of per-size derived files; never recurse further.
					if ( !@unlink( $dir . '/' . $entry ) ) {
						$ok = false;
					}
				}
				@rmdir( $dir );
			}
		}
		return $ok;
	}

	/**
	 * Delete the WebP file (and its parent dir if empty) for a given original.
	 * Used on file deletion to keep storage tidy.
	 */
	public function deleteWebP( string $originalPath ): bool {
		return $this->deleteDerived( $originalPath, 'webp', $this->getRootDir() );
	}

	/**
	 * Delete the AVIF file (and its parent dir if empty) for a given original.
	 */
	public function deleteAvif( string $originalPath ): bool {
		return $this->deleteDerived( $originalPath, 'avif', $this->getAvifRootDir() );
	}

	private function deleteDerived( string $originalPath, string $ext, string $rootDir ): bool {
		$path = $this->derivedPathFor( $originalPath, $ext, $rootDir );
		if ( $path === null || !file_exists( $path ) ) {
			return true;
		}
		$ok = @unlink( $path );
		if ( $ok ) {
			// Try to remove parent dir if empty (will silently fail if not)
			@rmdir( dirname( $path ) );
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
