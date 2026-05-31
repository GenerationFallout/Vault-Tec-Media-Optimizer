<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

/**
 * Common contract for second-pass lossless PNG recompressors (zopflipng,
 * oxipng, …). Implementations recompress a PNG in place and keep the result
 * only if it is strictly smaller than the input.
 */
interface PngRecompressorInterface {

	/**
	 * Short engine name for logs and the status page (e.g. "zopflipng").
	 *
	 * @return string
	 */
	public function getName(): string;

	/**
	 * Whether this recompressor can actually run (feature enabled, shell
	 * available, binary present and runnable).
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Recompress the file in place, losslessly. Returns the number of bytes
	 * saved (>= 0; 0 means "no improvement, original kept"), or null on a hard
	 * failure. Implementations must never leave the original larger than it
	 * started.
	 *
	 * @param string $path Absolute path to the PNG file
	 * @return int|null
	 */
	public function recompress( string $path ): ?int;

	/**
	 * Last error message, or null if the last operation succeeded.
	 *
	 * @return string|null
	 */
	public function getLastError(): ?string;
}
