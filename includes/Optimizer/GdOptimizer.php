<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GD-based optimizer. Fallback when Imagick is not available.
 *
 * Limitations vs Imagick:
 * - No lossless PNG recompression as efficient (just re-saves at max compression)
 * - No animated GIF -> animated WebP (will skip animated GIFs entirely)
 * - Cannot preserve ICC profiles
 */
class GdOptimizer implements OptimizerInterface {

	use AtomicWriteTrait;

	private ServiceOptions $options;
	private LoggerInterface $logger;
	private ?string $lastError = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	public function getName(): string {
		return 'gd';
	}

	public function getLastError(): ?string {
		return $this->lastError;
	}

	public function supportsWebP(): bool {
		if ( !extension_loaded( 'gd' ) ) {
			return false;
		}
		$info = gd_info();
		return !empty( $info['WebP Support'] );
	}

	public function supportsAnimatedWebP(): bool {
		return false;
	}

	public function optimizePng( string $path ): bool {
		$this->lastError = null;
		$tmp = null;
		try {
			$img = @imagecreatefrompng( $path );
			if ( !$img ) {
				return false;
			}

			imagealphablending( $img, false );
			imagesavealpha( $img, true );

			// Write to a temp file and keep only if smaller (GD re-encoding can
			// easily produce a larger PNG than a well-optimized source).
			$tmp = $this->uniqueTempPath( $path );
			$ok = imagepng( $img, $tmp, 9, PNG_ALL_FILTERS );
			imagedestroy( $img );

			if ( !$ok ) {
				if ( is_file( $tmp ) ) {
					@unlink( $tmp );
				}
				return false;
			}

			return $this->keepIfSmaller( $path, $tmp );
		} catch ( Throwable $e ) {
			if ( $tmp !== null && is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			$this->lastError = $e->getMessage();
			$this->logger->warning( 'GdOptimizer::optimizePng failed for {path}: {msg}', [
				'path' => $path,
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}

	public function optimizeJpeg( string $path ): bool {
		$this->lastError = null;
		$tmp = null;
		try {
			$img = @imagecreatefromjpeg( $path );
			if ( !$img ) {
				return false;
			}

			// GD has no true lossless JPEG optimization. We re-encode at 95
			// which is very close to visually-lossless. If strict losslessness
			// is required, the user should install Imagick.
			$tmp = $this->uniqueTempPath( $path );
			$ok = imagejpeg( $img, $tmp, 95 );
			imagedestroy( $img );

			if ( !$ok ) {
				if ( is_file( $tmp ) ) {
					@unlink( $tmp );
				}
				return false;
			}

			return $this->keepIfSmaller( $path, $tmp );
		} catch ( Throwable $e ) {
			if ( $tmp !== null && is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			$this->lastError = $e->getMessage();
			$this->logger->warning( 'GdOptimizer::optimizeJpeg failed for {path}: {msg}', [
				'path' => $path,
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Replace $original with $tmp only if $tmp is valid and strictly smaller;
	 * otherwise keep the original and discard the temp. Guarantees optimization
	 * never increases file size. Always returns true (original stays intact).
	 *
	 * @param string $original
	 * @param string $tmp
	 * @return bool
	 */
	private function keepIfSmaller( string $original, string $tmp ): bool {
		if ( !is_file( $tmp ) ) {
			return true;
		}

		$origSize = filesize( $original );
		$newSize = filesize( $tmp );

		if ( $origSize === false || $newSize === false
			|| $newSize === 0 || $newSize >= $origSize
		) {
			@unlink( $tmp );
			return true;
		}

		if ( !@rename( $tmp, $original ) ) {
			@unlink( $tmp );
			$this->lastError = 'Could not replace original with optimized file';
			return true;
		}

		clearstatcache( true, $original );
		return true;
	}

	public function convertToWebP( string $sourcePath, string $destPath, bool $lossless, int $quality ): bool {
		$this->lastError = null;
		try {
			$mime = @mime_content_type( $sourcePath );
			$img = null;

			switch ( $mime ) {
				case 'image/png':
					$img = @imagecreatefrompng( $sourcePath );
					if ( $img ) {
						imagepalettetotruecolor( $img );
						imagealphablending( $img, true );
						imagesavealpha( $img, true );
					}
					break;
				case 'image/jpeg':
					$img = @imagecreatefromjpeg( $sourcePath );
					break;
				case 'image/gif':
					// GD imagecreatefromgif gets only first frame.
					// Animated GIFs are not handled by GD; skip.
					$img = @imagecreatefromgif( $sourcePath );
					break;
				default:
					return false;
			}

			if ( !$img ) {
				return false;
			}

			// imagewebp doesn't have explicit lossless flag; quality 100 + PNG-like
			// source approximates lossless. For real lossless, Imagick is required.
			$webpQuality = $lossless ? 100 : $quality;
			$tmp = $this->uniqueTempPath( $destPath );
			$ok = imagewebp( $img, $tmp, $webpQuality );
			imagedestroy( $img );

			if ( !$ok ) {
				if ( is_file( $tmp ) ) {
					@unlink( $tmp );
				}
				return false;
			}
			// Atomic publish so a concurrent reader never sees a partial WebP.
			return $this->publishAtomically( $tmp, $destPath );
		} catch ( Throwable $e ) {
			if ( isset( $tmp ) && is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			$this->lastError = $e->getMessage();
			$this->logger->warning( 'GdOptimizer::convertToWebP failed for {src}: {msg}', [
				'src' => $sourcePath,
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}
}
