<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use Imagick;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Registration\ExtensionRegistry;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Runs prerequisite checks for the extension.
 *
 * Designed to be safely callable from anywhere (special page, CLI, REST).
 * Returns structured results that the caller renders.
 *
 * Status conventions:
 *   - 'ok'   : everything fine
 *   - 'warn' : non-blocking issue
 *   - 'fail' : blocking issue (extension won't work properly)
 *   - 'info' : neutral diagnostic info
 */
class PrerequisiteChecker {

	public const STATUS_OK = 'ok';
	public const STATUS_WARN = 'warn';
	public const STATUS_FAIL = 'fail';
	public const STATUS_INFO = 'info';

	private ServiceOptions $options;
	private IConnectionProvider $connectionProvider;

	public function __construct(
		ServiceOptions $options,
		IConnectionProvider $connectionProvider
	) {
		$this->options = $options;
		$this->connectionProvider = $connectionProvider;
	}

	/**
	 * Run all checks. Returns a structured report.
	 *
	 * @return array{
	 *     sections: array<string, array<int, array{label_key:string,status:string,value:string,detail:?string}>>,
	 *     summary: array{ok:int,warn:int,fail:int,info:int}
	 * }
	 */
	public function runAll(): array {
		$sections = [
			'system' => $this->runSystemChecks(),
			'libraries' => $this->runLibraryChecks(),
			'filesystem' => $this->runFilesystemChecks(),
			'database' => $this->runDatabaseChecks(),
			'zopfli' => $this->runZopfliChecks(),
			'extensions' => $this->runExtensionChecks(),
			'config' => $this->runConfigChecks(),
		];

		$summary = [ 'ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 ];
		foreach ( $sections as $checks ) {
			foreach ( $checks as $check ) {
				if ( isset( $summary[$check['status']] ) ) {
					$summary[$check['status']]++;
				}
			}
		}

		return [ 'sections' => $sections, 'summary' => $summary ];
	}

	// === System ===

	private function runSystemChecks(): array {
		return [
			$this->checkPhpVersion(),
			$this->checkMediaWikiVersion(),
			$this->checkMemoryLimit(),
			$this->checkMaxExecutionTime(),
		];
	}

	private function checkPhpVersion(): array {
		$v = PHP_VERSION;
		if ( PHP_VERSION_ID < 80100 ) {
			return $this->result( 'vaulttecmediaoptimizer-check-php-version', self::STATUS_FAIL, $v,
				"PHP $v. Required: 8.1+." );
		}
		return $this->result( 'vaulttecmediaoptimizer-check-php-version', self::STATUS_OK, $v, null );
	}

	private function checkMediaWikiVersion(): array {
		$v = MW_VERSION;
		[ $major, $minor ] = array_pad( explode( '.', $v ), 2, '0' );
		if ( (int)$major < 1 || ( (int)$major === 1 && (int)$minor < 43 ) ) {
			return $this->result( 'vaulttecmediaoptimizer-check-mw-version', self::STATUS_FAIL, $v,
				"MediaWiki $v. Required: 1.43+." );
		}
		return $this->result( 'vaulttecmediaoptimizer-check-mw-version', self::STATUS_OK, $v, null );
	}

	private function checkMemoryLimit(): array {
		$limit = ini_get( 'memory_limit' );
		$bytes = $this->parseMemoryLimit( (string)$limit );
		if ( $bytes > 0 && $bytes < 256 * 1024 * 1024 ) {
			return $this->result( 'vaulttecmediaoptimizer-check-memory-limit', self::STATUS_WARN, (string)$limit,
				wfMessage( 'vaulttecmediaoptimizer-msg-memory-low', $limit )->plain() );
		}
		return $this->result( 'vaulttecmediaoptimizer-check-memory-limit', self::STATUS_OK, (string)$limit, null );
	}

	private function checkMaxExecutionTime(): array {
		$time = (int)ini_get( 'max_execution_time' );
		$display = $time === 0 ? 'unlimited' : "{$time}s";
		if ( $time > 0 && $time < 30 ) {
			return $this->result( 'vaulttecmediaoptimizer-check-max-execution-time', self::STATUS_WARN, $display,
				'Low max_execution_time may cause large image processing to time out.' );
		}
		return $this->result( 'vaulttecmediaoptimizer-check-max-execution-time', self::STATUS_OK, $display, null );
	}

	// === Libraries ===

	private function runLibraryChecks(): array {
		$checks = [];

		// Imagick
		if ( extension_loaded( 'imagick' ) ) {
			$imagick = new Imagick();
			$versionInfo = $imagick->getVersion();
			$versionString = $versionInfo['versionString'] ?? 'unknown';

			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-imagick', self::STATUS_OK, $versionString,
				'Imagick available - used as primary backend' );

			$formats = Imagick::queryFormats();
			$hasWebP = in_array( 'WEBP', $formats, true );

			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-imagick-webp',
				$hasWebP ? self::STATUS_OK : self::STATUS_FAIL,
				$hasWebP ? 'supported' : 'NOT supported',
				$hasWebP ? null : wfMessage( 'vaulttecmediaoptimizer-msg-imagick-no-webp' )->plain() );

			$relevantFormats = array_values( array_intersect(
				[ 'PNG', 'JPEG', 'JPG', 'GIF', 'WEBP' ],
				$formats
			) );
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-imagick-formats', self::STATUS_INFO,
				implode( ', ', $relevantFormats ), null );
		} else {
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-imagick', self::STATUS_WARN, 'not installed',
				wfMessage( 'vaulttecmediaoptimizer-msg-imagick-missing' )->plain() );
		}

		// GD
		if ( extension_loaded( 'gd' ) ) {
			$gdInfo = gd_info();
			$gdVersion = $gdInfo['GD Version'] ?? 'unknown';
			$gdHasWebP = !empty( $gdInfo['WebP Support'] );

			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-gd', self::STATUS_OK, $gdVersion,
				'GD available (fallback if Imagick missing)' );

			$imagickWebP = extension_loaded( 'imagick' )
				&& in_array( 'WEBP', Imagick::queryFormats(), true );

			if ( !$gdHasWebP && !$imagickWebP ) {
				$checks[] = $this->result( 'vaulttecmediaoptimizer-check-gd-webp', self::STATUS_FAIL,
					'NOT supported',
					wfMessage( 'vaulttecmediaoptimizer-msg-fatal-no-webp' )->plain() );
			} else {
				$checks[] = $this->result( 'vaulttecmediaoptimizer-check-gd-webp',
					$gdHasWebP ? self::STATUS_OK : self::STATUS_INFO,
					$gdHasWebP ? 'supported' : 'not supported (Imagick handles it)',
					$gdHasWebP ? null : wfMessage( 'vaulttecmediaoptimizer-msg-gd-no-webp' )->plain() );
			}
		} else {
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-gd', self::STATUS_WARN, 'not installed',
				'GD is unusual to be missing. Ensure php-gd is installed.' );
		}

		// AVIF (experimental): whether any backend can actually encode it. Shown
		// as a blocking failure only when the feature is enabled but unusable.
		$avifSupported =
			( extension_loaded( 'imagick' ) && in_array( 'AVIF', Imagick::queryFormats(), true ) )
			|| ( extension_loaded( 'gd' ) && function_exists( 'imageavif' ) && !empty( gd_info()['AVIF Support'] ) );
		$avifEnabled = (bool)$this->options->get( 'VaultTecMediaOptimizerAvifEnabled' );
		$checks[] = $this->result(
			'vaulttecmediaoptimizer-check-avif-support',
			$avifSupported ? self::STATUS_OK : ( $avifEnabled ? self::STATUS_FAIL : self::STATUS_INFO ),
			$avifSupported ? 'supported' : 'NOT supported',
			$avifSupported ? null : ( $avifEnabled
				? 'AVIF is enabled but no backend can encode it. Build Imagick with AVIF (libheif/libaom) or PHP-GD with imageavif(), or disable $wgVaultTecMediaOptimizerAvifEnabled.'
				: 'Optional/experimental. Needs Imagick with AVIF or PHP-GD with imageavif() before enabling $wgVaultTecMediaOptimizerAvifEnabled.' )
		);

		return $checks;
	}

	// === Filesystem ===

	private function runFilesystemChecks(): array {
		$checks = [];

		// Verify the local file repo uses a filesystem backend (FSFileBackend).
		// The extension reads and writes image files directly on disk; on
		// remote backends (Swift, S3, etc.) the upload directory paths are not
		// real filesystem paths and processing would fail. We warn rather than
		// hard-fail so the admin gets a clear diagnostic.
		$checks[] = $this->checkFileBackend();

		$uploadDir = $this->options->get( 'UploadDirectory' );
		$uploadOk = is_dir( $uploadDir ) && is_readable( $uploadDir );
		$checks[] = $this->result( 'vaulttecmediaoptimizer-check-upload-dir',
			$uploadOk ? self::STATUS_OK : self::STATUS_FAIL,
			$uploadDir,
			$uploadOk ? null : 'Upload directory does not exist or is not readable.' );

		$webpDirName = $this->options->get( 'VaultTecMediaOptimizerWebPDirectory' );
		$webpDir = dirname( $uploadDir ) . '/' . $webpDirName;

		if ( !is_dir( $webpDir ) ) {
			$parentDir = dirname( $webpDir );
			$writable = is_writable( $parentDir );
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-webp-dir',
				$writable ? self::STATUS_WARN : self::STATUS_FAIL,
				$webpDir . ' (not yet created)',
				$writable
					? wfMessage( 'vaulttecmediaoptimizer-msg-webp-dir-missing', $webpDir )->plain()
					: "Cannot create $webpDir: parent directory $parentDir is not writable." );
		} else {
			$writable = is_writable( $webpDir );
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-webp-dir',
				$writable ? self::STATUS_OK : self::STATUS_FAIL,
				$webpDir,
				$writable ? null : wfMessage( 'vaulttecmediaoptimizer-msg-webp-dir-not-writable', $webpDir )->plain() );
		}

		$freeBytes = @disk_free_space( $uploadDir );
		if ( $freeBytes !== false ) {
			$freeGb = $freeBytes / ( 1024 ** 3 );
			$status = $freeGb < 1 ? self::STATUS_WARN : self::STATUS_OK;
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-disk-space', $status,
				number_format( $freeGb, 2 ) . ' GB',
				$status === self::STATUS_WARN ? wfMessage( 'vaulttecmediaoptimizer-msg-disk-low' )->plain() : null );
		}

		return $checks;
	}

	/**
	 * Check that the local file repo uses a filesystem-based backend.
	 *
	 * The extension manipulates files directly on disk (is_file, fopen,
	 * Imagick reads/writes). On a remote backend (Swift, S3, ...) the paths
	 * returned by File::getRel() are logical, not real filesystem paths, and
	 * processing would silently fail. We surface this clearly.
	 *
	 * Uses MediaWikiServices directly because this is a one-off diagnostic,
	 * not a hot-path service dependency.
	 */
	private function checkFileBackend(): array {
		try {
			$repo = \MediaWiki\MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
			$backend = $repo->getBackend();
			$className = ( new \ReflectionClass( $backend ) )->getShortName();

			// FSFileBackend (and subclasses) are filesystem-based.
			$isFsBackend = ( $backend instanceof \Wikimedia\FileBackend\FSFileBackend )
				|| str_contains( $className, 'FSFileBackend' );

			if ( $isFsBackend ) {
				return $this->result( 'vaulttecmediaoptimizer-check-filebackend',
					self::STATUS_OK, $className, null );
			}

			return $this->result( 'vaulttecmediaoptimizer-check-filebackend',
				self::STATUS_FAIL, $className,
				wfMessage( 'vaulttecmediaoptimizer-msg-filebackend-remote' )->plain() );
		} catch ( \Throwable $e ) {
			// Older/newer MW namespace for FSFileBackend, or reflection issue.
			// Fall back to a non-blocking info result rather than crashing.
			return $this->result( 'vaulttecmediaoptimizer-check-filebackend',
				self::STATUS_INFO, 'unknown',
				'Could not determine file backend type: ' . $e->getMessage() );
		}
	}

	// === Database ===

	private function runDatabaseChecks(): array {
		// tableExists() is on IMaintainableDatabase, not on the IReadableDatabase
		// returned by getReplicaDatabase(). We use the primary which returns
		// IDatabase + IMaintainableDatabase in practice.
		$db = $this->connectionProvider->getPrimaryDatabase();
		$tableExists = $db->tableExists( 'vtmo_image_optimization', __METHOD__ );
		return [
			$this->result( 'vaulttecmediaoptimizer-check-table',
				$tableExists ? self::STATUS_OK : self::STATUS_FAIL,
				$tableExists ? 'exists' : 'missing',
				$tableExists ? null : wfMessage( 'vaulttecmediaoptimizer-msg-table-missing' )->plain() ),
		];
	}

	// === Third-party extensions ===

	private function runExtensionChecks(): array {
		$checks = [];
		$registry = ExtensionRegistry::getInstance();

		if ( $registry->isLoaded( 'MultimediaViewer' ) ) {
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-multimediaviewer',
				self::STATUS_INFO, 'loaded',
				wfMessage( 'vaulttecmediaoptimizer-msg-multimediaviewer-present' )->plain() );
		}

		if ( $registry->isLoaded( 'MediaUploader' ) ) {
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-mediauploader',
				self::STATUS_INFO, 'loaded',
				'MediaUploader detected. Uploads go through the FileUpload hook, ' .
				'so processing is automatic.' );
		}

		if ( $registry->isLoaded( 'TimedMediaHandler' ) ) {
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-timedmediahandler',
				self::STATUS_INFO, 'loaded',
				'TimedMediaHandler detected. Video/audio formats are correctly filtered out ' .
				'by MIME type matching.' );
		}

		if ( $registry->isLoaded( 'PageImages' ) ) {
			$checks[] = $this->result( 'vaulttecmediaoptimizer-check-pageimages',
				self::STATUS_INFO, 'loaded',
				'PageImages reads originals - fully compatible.' );
		}

		return $checks;
	}

	// === Configuration ===

	private function runConfigChecks(): array {
		$checks = [];

		$enabled = $this->options->get( 'VaultTecMediaOptimizerEnabled' );
		$checks[] = $this->result( 'vaulttecmediaoptimizer-check-enabled',
			$enabled ? self::STATUS_OK : self::STATUS_WARN,
			$enabled ? 'true' : 'false',
			$enabled ? null : 'Master switch is off. Set $wgVaultTecMediaOptimizerEnabled = true;' );

		$quality = (int)$this->options->get( 'VaultTecMediaOptimizerWebPQuality' );
		$qualityOk = ( $quality >= 70 && $quality <= 95 );
		$checks[] = $this->result( 'vaulttecmediaoptimizer-check-webp-quality',
			$qualityOk ? self::STATUS_OK : self::STATUS_WARN,
			(string)$quality,
			$qualityOk ? null : 'Recommended: 80-90' );

		$formats = $this->options->get( 'VaultTecMediaOptimizerFormats' );
		$checks[] = $this->result( 'vaulttecmediaoptimizer-check-mime-types',
			self::STATUS_INFO,
			implode( ', ', $formats ),
			null );

		$avifEnabled = (bool)$this->options->get( 'VaultTecMediaOptimizerAvifEnabled' );
		$checks[] = $this->result( 'vaulttecmediaoptimizer-check-avif-enabled',
			self::STATUS_INFO,
			$avifEnabled ? 'true' : 'false',
			$avifEnabled
				? 'Experimental: AVIF copies are generated and offered before WebP to capable browsers.'
				: null );

		return $checks;
	}

	// === Zopfli (second-pass PNG recompression) ===

	/**
	 * Checks for the optional zopflipng feature. This section is purely
	 * diagnostic: zopflipng is off by default and never required for the core
	 * WebP/optimization features. The checks mirror ZopfliRecompressor's own
	 * availability logic, but run here so the admin can see, on the status
	 * page (which runs as the *web* PHP, the one that matters), whether the
	 * feature can actually be used in this environment.
	 *
	 * @return array<int, array{label_key:string,status:string,value:string,detail:?string}>
	 */
	private function runZopfliChecks(): array {
		$checks = [];

		// 1. Feature toggle.
		$enabled = (bool)$this->options->get( 'VaultTecMediaOptimizerZopfliEnabled' );
		$checks[] = $this->result(
			'vaulttecmediaoptimizer-check-zopfli-enabled',
			self::STATUS_INFO,
			$enabled ? 'true' : 'false',
			$enabled ? null
				: 'Optional. Set $wgVaultTecMediaOptimizerZopfliEnabled = true; to enable lossless PNG recompression.'
		);

		// 1b. Which engine is selected.
		$engine = strtolower( (string)$this->options->get( 'VaultTecMediaOptimizerPngEngine' ) );
		if ( $engine !== 'oxipng' ) {
			$engine = 'zopflipng';
		}
		$checks[] = $this->result(
			'vaulttecmediaoptimizer-check-png-engine',
			self::STATUS_INFO,
			$engine,
			$engine === 'zopflipng'
				? 'Best compression, slow. Switch to oxipng (faster) via $wgVaultTecMediaOptimizerPngEngine.'
				: 'Fast, multi-threaded. Switch to zopflipng for slightly better compression.'
		);

		// 2. Shell execution available (web PHP context).
		$shellOk = $this->shellExecutionAvailable();
		$checks[] = $this->result(
			'vaulttecmediaoptimizer-check-zopfli-shell',
			$shellOk ? self::STATUS_OK : ( $enabled ? self::STATUS_FAIL : self::STATUS_WARN ),
			$shellOk ? 'available' : 'disabled',
			$shellOk ? null
				: 'proc_open/exec are disabled in PHP (disable_functions). Required for PNG recompression.'
		);

		// 3. Binary for the SELECTED engine present and runnable.
		if ( $engine === 'oxipng' ) {
			$configKey = 'VaultTecMediaOptimizerOxipngBinary';
			$defaultName = 'oxipng';
		} else {
			$configKey = 'VaultTecMediaOptimizerZopfliBinary';
			$defaultName = 'zopflipng';
		}
		$binaryPath = $this->locateBinary( $configKey, $defaultName );
		if ( $binaryPath !== null ) {
			$checks[] = $this->result(
				'vaulttecmediaoptimizer-check-zopfli-binary',
				self::STATUS_OK,
				$binaryPath,
				null
			);
		} else {
			$configured = (string)$this->options->get( $configKey );
			if ( $configured === '' ) {
				$configured = $defaultName;
			}

			// Give the most actionable message. The single most common cause of
			// "configured absolute path but not usable" on shared/Plesk hosting
			// is open_basedir excluding the binary's directory (e.g. /usr/bin).
			$isAbsolute = $configured !== '' && ( $configured[0] === '/' || $configured[0] === '\\' );
			if ( $isAbsolute && $this->isOutsideOpenBasedir( $configured ) ) {
				$detail = "'" . $configured . "' is outside PHP's open_basedir ("
					. ini_get( 'open_basedir' ) . "), so PHP cannot run it. "
					. 'Copy the ' . $engine . ' binary into a directory inside open_basedir (e.g. '
					. 'your wiki folder) and point $wg' . ( $engine === 'oxipng' ? 'VaultTecMediaOptimizerOxipngBinary' : 'VaultTecMediaOptimizerZopfliBinary' ) . ' there.';
			} else {
				$install = $engine === 'oxipng'
					? 'Install oxipng (cargo install oxipng, or a prebuilt binary)'
					: 'Install zopflipng (e.g. apt install zopfli)';
				$cfgVar = $engine === 'oxipng'
					? '$wgVaultTecMediaOptimizerOxipngBinary'
					: '$wgVaultTecMediaOptimizerZopfliBinary';
				$detail = "Binary '" . $configured . "' not found or not runnable. "
					. $install . ' or set the full path in ' . $cfgVar . '.';
			}

			$checks[] = $this->result(
				'vaulttecmediaoptimizer-check-zopfli-binary',
				$enabled ? self::STATUS_FAIL : self::STATUS_WARN,
				'not found',
				$detail
			);
		}

		return $checks;
	}

	/**
	 * Whether at least one shell-execution function is usable (not in
	 * disable_functions). Mirrors ZopfliRecompressor::shellFunctionsEnabled().
	 */
	private function shellExecutionAvailable(): bool {
		$disabled = array_map( 'trim', explode( ',', (string)ini_get( 'disable_functions' ) ) );
		foreach ( [ 'proc_open', 'exec' ] as $fn ) {
			if ( function_exists( $fn ) && !in_array( $fn, $disabled, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a binary path the same way the recompressors do: an absolute
	 * path is probed by running it; a bare name is resolved via `command -v`
	 * then probed. We avoid is_executable() because it both lies under some
	 * FPM setups and emits an open_basedir warning when the binary lives
	 * outside the allowed paths. Returns null if not usable.
	 *
	 * @param string $configKey Config key holding the configured binary value
	 * @param string $defaultName Default command name if config is empty
	 * @return string|null
	 */
	private function locateBinary( string $configKey, string $defaultName ): ?string {
		$configured = (string)$this->options->get( $configKey );

		// Absolute path: probe directly.
		if ( $configured !== '' && ( $configured[0] === '/' || $configured[0] === '\\' ) ) {
			return $this->probeBinary( $configured ) ? $configured : null;
		}

		$name = $configured !== '' ? $configured : $defaultName;
		if ( !preg_match( '/^[A-Za-z0-9._-]+$/', $name ) ) {
			return null;
		}

		if ( !$this->shellExecutionAvailable() ) {
			return null;
		}

		// Resolve via command -v, then probe.
		if ( function_exists( 'shell_exec' ) ) {
			$found = @shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' );
			if ( is_string( $found ) ) {
				$found = trim( $found );
				if ( $found !== '' && $this->probeBinary( $found ) ) {
					return $found;
				}
			}
		}

		// Last resort: probe the bare name (shell resolves PATH).
		if ( $this->probeBinary( $name ) ) {
			return $name;
		}

		return null;
	}

	/**
	 * Detect whether a path lies outside the configured open_basedir, which
	 * would make PHP unable to stat or run it. Used to give the admin an
	 * actionable message instead of a generic "not found".
	 *
	 * @param string $path
	 * @return bool True if open_basedir is set AND $path is outside it
	 */
	private function isOutsideOpenBasedir( string $path ): bool {
		$base = (string)ini_get( 'open_basedir' );
		if ( $base === '' ) {
			return false;
		}
		$allowed = preg_split( '/[:;]/', $base ) ?: [];
		foreach ( $allowed as $dir ) {
			$dir = rtrim( trim( $dir ), '/' );
			if ( $dir !== '' && str_starts_with( $path, $dir . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Probe a candidate binary by running "<candidate> --version". Returns true
	 * if it launches (exit code other than 127 "not found" / -1 "couldn't
	 * launch"). Mirrors ZopfliRecompressor::probeBinary().
	 *
	 * @param string $candidate
	 * @return bool
	 */
	private function probeBinary( string $candidate ): bool {
		if ( function_exists( 'proc_open' ) ) {
			$descriptors = [
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			];
			$proc = @proc_open(
				escapeshellarg( $candidate ) . ' --version',
				$descriptors,
				$pipes
			);
			if ( is_resource( $proc ) ) {
				foreach ( $pipes as $p ) {
					if ( is_resource( $p ) ) {
						@fclose( $p );
					}
				}
				$exit = @proc_close( $proc );
				return $exit !== 127 && $exit !== -1;
			}
		}

		if ( function_exists( 'exec' ) ) {
			$out = [];
			$code = 0;
			@exec( escapeshellarg( $candidate ) . ' --version 2>/dev/null', $out, $code );
			return $code !== 127;
		}

		return false;
	}

	// === Helpers ===

	private function result( string $labelKey, string $status, string $value, ?string $detail ): array {
		return [
			'label_key' => $labelKey,
			'status' => $status,
			'value' => $value,
			'detail' => $detail,
		];
	}

	private function parseMemoryLimit( string $limit ): int {
		$limit = trim( $limit );
		if ( $limit === '-1' || $limit === '' ) {
			return -1;
		}
		$last = strtolower( substr( $limit, -1 ) );
		$value = (int)$limit;
		switch ( $last ) {
			case 'g': $value *= 1024;
				// no break - falls through
			case 'm': $value *= 1024;
				// no break - falls through
			case 'k': $value *= 1024;
		}
		return $value;
	}
}
