<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

/**
 * Tiny, dependency-free detector for animated GIFs.
 *
 * Needed because some WebP backends (notably PHP-GD) can only encode the FIRST
 * frame of a GIF. Converting an *animated* GIF with such a backend would yield
 * a still WebP which, once served in place of the original, silently destroys
 * the animation. Callers use this to refuse that conversion and keep serving
 * the original animated GIF instead. (Imagick and libvips do produce animated
 * WebP and never need this guard.)
 *
 * Detection is structural and never decodes pixels: it counts Graphic Control
 * Extension (GCE) blocks in the GIF byte stream. A still GIF has at most one
 * such block; an animated GIF has one per rendered frame, i.e. more than one.
 */
class GifAnimationDetector {

	/**
	 * Whether the given file is an animated (multi-frame) GIF.
	 *
	 * Conservative: returns false for still GIFs, unreadable files and non-GIF
	 * input. The only caller uses a true result to *refuse* an action, so a
	 * false negative merely falls back to existing behaviour rather than
	 * breaking anything.
	 *
	 * @param string $path Absolute filesystem path to a GIF file.
	 * @return bool
	 */
	public static function isAnimated( string $path ): bool {
		$data = @file_get_contents( $path );
		if ( $data === false || $data === '' ) {
			return false;
		}

		// A Graphic Control Extension introduces (almost) every rendered frame:
		//   00 21 F9 04 <4 bytes> 00 [2C|21]
		// where the trailing 2C is an Image Descriptor and 21 a following
		// extension. The pattern is fixed-length (no unbounded quantifier), so
		// the scan is linear with no catastrophic backtracking. More than one
		// such block means the GIF carries more than one frame, i.e. animates.
		return preg_match_all( '/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $data ) > 1;
	}
}
