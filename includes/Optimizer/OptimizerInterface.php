<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer;

/**
 * Contract for image optimization backends (Imagick, GD, ...).
 *
 * Each method returns true on success, false on failure (and should log
 * the error via the injected logger). They MUST be idempotent: calling
 * twice on the same file is safe.
 */
interface OptimizerInterface {

	/**
	 * Optimize a PNG file losslessly in place.
	 * Recompresses, strips metadata (if configured), but does not alter pixels.
	 *
	 * @param string $path Absolute path to PNG file
	 * @return bool True on success
	 */
	public function optimizePng( string $path ): bool;

	/**
	 * Optimize a JPEG file losslessly in place.
	 * Reorganizes DCT coefficients, strips metadata, no re-encoding.
	 *
	 * @param string $path Absolute path to JPEG file
	 * @return bool True on success
	 */
	public function optimizeJpeg( string $path ): bool;

	/**
	 * Convert an image to WebP at the given destination.
	 *
	 * @param string $sourcePath Absolute path to source image (any supported format)
	 * @param string $destPath Absolute path where the WebP file should be written
	 * @param bool $lossless True for lossless encoding, false for lossy
	 * @param int $quality Quality for lossy mode (0-100); ignored if lossless=true
	 * @return bool True on success
	 */
	public function convertToWebP( string $sourcePath, string $destPath, bool $lossless, int $quality ): bool;

	/**
	 * Check if this backend can encode WebP at all.
	 *
	 * @return bool
	 */
	public function supportsWebP(): bool;

	/**
	 * Check if this backend supports animated GIF -> animated WebP.
	 *
	 * @return bool
	 */
	public function supportsAnimatedWebP(): bool;

	/**
	 * Identifier string for logging and stats.
	 *
	 * @return string One of: 'imagick', 'gd'
	 */
	public function getName(): string;

	/**
	 * Return the last error message captured by this backend (typically the
	 * exception message from Imagick/GD), or null if the last operation
	 * succeeded. Reset to null at the start of each operation.
	 *
	 * @return string|null
	 */
	public function getLastError(): ?string;
}
