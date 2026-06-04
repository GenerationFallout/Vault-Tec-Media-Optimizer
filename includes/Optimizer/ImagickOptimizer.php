<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer;

use Imagick;
use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imagick-based optimizer. Preferred backend when available.
 *
 * Handles PNG, JPEG, GIF (including animated GIF -> animated WebP).
 */
class ImagickOptimizer implements OptimizerInterface {

	private ServiceOptions $options;
	private LoggerInterface $logger;
	private ?string $lastError = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	public function getName(): string {
		return 'imagick';
	}

	public function getLastError(): ?string {
		return $this->lastError;
	}

	public function supportsWebP(): bool {
		if ( !extension_loaded( 'imagick' ) ) {
			return false;
		}
		return in_array( 'WEBP', Imagick::queryFormats(), true );
	}

	public function supportsAvif(): bool {
		if ( !extension_loaded( 'imagick' ) ) {
			return false;
		}
		// Imagick exposes AVIF when built against libheif with an AV1 encoder.
		return in_array( 'AVIF', Imagick::queryFormats(), true );
	}

	public function supportsAnimatedWebP(): bool {
		// Imagick handles animated WebP via coalesceImages + format change
		return $this->supportsWebP();
	}

	public function optimizePng( string $path ): bool {
		$this->lastError = null;
		$tmp = null;
		try {
			$img = new Imagick();
			$img->readImage( $path );

			// Lossless PNG recompression. We let ImageMagick choose the optimal
			// filter; level 9 = best DEFLATE. We DO NOT use png:exclude-chunk
			// here because the syntax is permissive — 'all,trns,bkgd' means
			// "exclude all, exclude trns AND exclude bkgd", which would drop
			// the transparency chunk from indexed-color PNGs and break
			// transparent images. stripImage() below already removes the
			// metadata chunks we want gone, and respects tRNS.
			$img->setImageFormat( 'png' );
			$img->setOption( 'png:compression-level', '9' );
			$img->setOption( 'png:compression-strategy', '2' );

			if ( $this->options->get( 'VaultTecMediaOptimizerStripMetadata' ) ) {
				// Strip metadata but preserve color profile.
				// stripImage() removes EXIF, iCCP, sRGB, tEXt, zCCP, zTXt, date;
				// it correctly preserves gAMA (gamma) and tRNS (transparency).
				$profiles = $img->getImageProfiles( 'icc', true );
				$img->stripImage();
				if ( !empty( $profiles ) ) {
					$img->profileImage( 'icc', $profiles['icc'] );
				}
			}

			// CRITICAL: write to a temp file first and only replace the original
			// if the result is STRICTLY SMALLER. ImageMagick can produce a much
			// larger PNG than the source (e.g. when the original was encoded by
			// a stronger optimizer like oxipng/pngquant, or for some complex
			// images with compression-strategy 2). Blindly overwriting would
			// bloat originals — which is the opposite of optimization.
			$tmp = $path . '.vtmo-opt.tmp';
			$img->writeImage( $tmp );
			$img->clear();
			$img->destroy();

			return $this->keepIfSmaller( $path, $tmp );
		} catch ( Throwable $e ) {
			if ( $tmp !== null && is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			$this->lastError = $e->getMessage();
			$this->logger->warning( 'ImagickOptimizer::optimizePng failed for {path}: {msg}', [
				'path' => $path,
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}

	public function optimizeJpeg( string $path ): bool {
		$this->lastError = null;
		try {
			$img = new Imagick();
			$img->readImage( $path );

			// Note: Imagick always re-encodes JPEGs on writeImage(), so this
			// optimization is technically not lossless. We preserve the
			// original quality setting (or fall back to 92, a high default)
			// to minimize visible loss. For truly lossless JPEG optimization,
			// jpegtran/mozjpeg would be needed (out of scope here).
			$originalQuality = $img->getImageCompressionQuality();
			if ( $originalQuality === 0 ) {
				// 0 means "not set" — Imagick will pick its default (75-ish).
				// Force a high value to keep visual quality.
				$originalQuality = 92;
			}
			$img->setImageCompressionQuality( $originalQuality );
			$img->setImageFormat( 'jpeg' );
			$img->setInterlaceScheme( Imagick::INTERLACE_PLANE );
			// Optimize Huffman tables for smaller output (true lossless step)
			$img->setOption( 'jpeg:optimize-coding', 'true' );

			if ( $this->options->get( 'VaultTecMediaOptimizerStripMetadata' ) ) {
				$profiles = $img->getImageProfiles( 'icc', true );
				$img->stripImage();
				if ( !empty( $profiles ) ) {
					$img->profileImage( 'icc', $profiles['icc'] );
				}
			}

			// Same safety net as PNG: write to temp, keep only if smaller.
			// Re-encoding can produce a larger file (e.g. progressive scheme
			// overhead on small images, or an already-optimized source).
			$tmp = $path . '.vtmo-opt.tmp';
			$img->writeImage( $tmp );
			$img->clear();
			$img->destroy();

			return $this->keepIfSmaller( $path, $tmp );
		} catch ( Throwable $e ) {
			if ( isset( $tmp ) && is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			$this->lastError = $e->getMessage();
			$this->logger->warning( 'ImagickOptimizer::optimizeJpeg failed for {path}: {msg}', [
				'path' => $path,
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Decide whether to keep a freshly-optimized temp file.
	 *
	 * Replaces $original with $tmp only if $tmp is valid and STRICTLY SMALLER.
	 * Otherwise the original is left untouched and the temp file is discarded.
	 * This guarantees optimization can never increase a file's size — the bug
	 * that previously bloated ~half of the wiki's PNGs.
	 *
	 * Always returns true (the original is intact either way); the caller can
	 * re-stat the file to learn the resulting size.
	 *
	 * @param string $original Path to the original file (to be replaced or kept)
	 * @param string $tmp Path to the candidate optimized file
	 * @return bool
	 */
	private function keepIfSmaller( string $original, string $tmp ): bool {
		if ( !is_file( $tmp ) ) {
			// Nothing was written; keep the original.
			return true;
		}

		$origSize = filesize( $original );
		$newSize = filesize( $tmp );

		// If we can't compare, or the new file is empty, or not smaller,
		// discard the temp and keep the original.
		if ( $origSize === false || $newSize === false
			|| $newSize === 0 || $newSize >= $origSize
		) {
			@unlink( $tmp );
			return true;
		}

		// New file is strictly smaller: atomically replace the original.
		if ( !@rename( $tmp, $original ) ) {
			@unlink( $tmp );
			$this->lastError = 'Could not replace original with optimized file';
			// Original is still intact, so this is not a hard failure.
			return true;
		}

		clearstatcache( true, $original );
		return true;
	}

	public function convertToWebP( string $sourcePath, string $destPath, bool $lossless, int $quality ): bool {
		$this->lastError = null;
		try {
			$img = new Imagick();
			$img->readImage( $sourcePath );

			$isAnimated = $img->getNumberImages() > 1;
			if ( $isAnimated ) {
				// coalesceImages returns a new Imagick with one frame per
				// final rendered frame (no inter-frame delta dependencies).
				$img = $img->coalesceImages();
			}

			// Apply per-frame settings: format, quality, and metadata stripping.
			// These methods affect only the *current* image in a multi-frame
			// Imagick (the one pointed to by the internal iterator), so we
			// must iterate explicitly for animated images.
			$strip = (bool)$this->options->get( 'VaultTecMediaOptimizerStripMetadata' );
			foreach ( $img as $frame ) {
				$frame->setImageFormat( 'webp' );
				if ( !$lossless ) {
					$frame->setImageCompressionQuality( $quality );
				}
				if ( $strip ) {
					$frame->stripImage();
				}
			}

			// Wand-level options: these affect every frame on write.
			$img->setOption( 'webp:method', '6' );
			if ( $lossless ) {
				$img->setOption( 'webp:lossless', 'true' );
			}

			if ( $isAnimated ) {
				// adjoin=true → write all frames into one animated WebP.
				$img->writeImages( $destPath, true );
			} else {
				$img->writeImage( $destPath );
			}

			$img->clear();
			$img->destroy();

			return file_exists( $destPath ) && filesize( $destPath ) > 0;
		} catch ( Throwable $e ) {
			$this->lastError = $e->getMessage();
			$this->logger->warning( 'ImagickOptimizer::convertToWebP failed for {src}: {msg}', [
				'src' => $sourcePath,
				'msg' => $e->getMessage(),
			] );
			if ( file_exists( $destPath ) ) {
				@unlink( $destPath );
			}
			return false;
		}
	}

	public function convertToAvif( string $sourcePath, string $destPath, int $quality ): bool {
		$this->lastError = null;
		try {
			$img = new Imagick();
			$img->readImage( $sourcePath );

			$isAnimated = $img->getNumberImages() > 1;
			if ( $isAnimated ) {
				$img = $img->coalesceImages();
			}

			// Per-frame settings (format, quality, optional metadata strip). AVIF
			// is encoded lossy; alpha is preserved by the codec.
			$strip = (bool)$this->options->get( 'VaultTecMediaOptimizerStripMetadata' );
			foreach ( $img as $frame ) {
				$frame->setImageFormat( 'avif' );
				$frame->setImageCompressionQuality( $quality );
				if ( $strip ) {
					$frame->stripImage();
				}
			}

			if ( $isAnimated ) {
				// adjoin=true -> single animated AVIF (when the encoder supports it).
				$img->writeImages( $destPath, true );
			} else {
				$img->writeImage( $destPath );
			}

			$img->clear();
			$img->destroy();

			return file_exists( $destPath ) && filesize( $destPath ) > 0;
		} catch ( Throwable $e ) {
			$this->lastError = $e->getMessage();
			$this->logger->warning( 'ImagickOptimizer::convertToAvif failed for {src}: {msg}', [
				'src' => $sourcePath,
				'msg' => $e->getMessage(),
			] );
			if ( file_exists( $destPath ) ) {
				@unlink( $destPath );
			}
			return false;
		}
	}
}
