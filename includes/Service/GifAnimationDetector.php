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
 * Detection walks the GIF block structure (like MediaWiki core's
 * GIFMetadataExtractor) and counts Image Descriptors (0x2C): two or more
 * rendered frames means the GIF animates. It streams the file and stops at the
 * second descriptor, so memory use is constant regardless of file size.
 *
 * The previous implementation grepped for Graphic Control Extension byte
 * patterns, which required a 0x00 byte BEFORE each GCE: the first frame's GCE
 * is preceded by the last Global Color Table byte (arbitrary), so animated
 * GIFs without a NETSCAPE loop extension — and multi-frame GIFs with no GCEs
 * at all, which are legal — were reported as still and got flattened under GD.
 * Verified against real files; the structural walk has no such blind spot.
 */
class GifAnimationDetector {

	/**
	 * Whether the given file is an animated (multi-frame) GIF.
	 *
	 * Returns false for still GIFs, unreadable files, non-GIF input and
	 * structurally corrupt files (which the GD decoder would reject anyway,
	 * so no WebP gets written for them either way).
	 *
	 * @param string $path Absolute filesystem path to a GIF file.
	 * @return bool
	 */
	public static function isAnimated( string $path ): bool {
		$fh = @fopen( $path, 'rb' );
		if ( $fh === false ) {
			return false;
		}
		try {
			// Header (6) + Logical Screen Descriptor (7).
			$header = fread( $fh, 13 );
			if ( !is_string( $header ) || strlen( $header ) < 13
				|| ( strncmp( $header, 'GIF87a', 6 ) !== 0 && strncmp( $header, 'GIF89a', 6 ) !== 0 )
			) {
				return false;
			}

			// Skip the Global Color Table if the LSD flags announce one.
			$flags = ord( $header[10] );
			if ( $flags & 0x80 ) {
				fseek( $fh, 3 * ( 2 << ( $flags & 0x07 ) ), SEEK_CUR );
			}

			$frames = 0;
			while ( true ) {
				$b = fread( $fh, 1 );
				if ( !is_string( $b ) || $b === '' ) {
					// truncated
					break;
				}
				$b = ord( $b );

				if ( $b === 0x3B ) {
					// trailer — end of GIF
					break;
				}

				if ( $b === 0x21 ) {
					// Extension: 1 label byte, then length-prefixed sub-blocks.
					fread( $fh, 1 );
					if ( !self::skipSubBlocks( $fh ) ) {
						break;
					}
				} elseif ( $b === 0x2C ) {
					// Image Descriptor — one per rendered frame.
					$frames++;
					if ( $frames > 1 ) {
						return true;
					}
					// left(2) top(2) width(2) height(2) flags(1)
					$desc = fread( $fh, 9 );
					if ( !is_string( $desc ) || strlen( $desc ) < 9 ) {
						break;
					}
					$localFlags = ord( $desc[8] );
					if ( $localFlags & 0x80 ) {
						// Local Color Table
						fseek( $fh, 3 * ( 2 << ( $localFlags & 0x07 ) ), SEEK_CUR );
					}
					// LZW minimum code size byte, then the image data sub-blocks.
					fread( $fh, 1 );
					if ( !self::skipSubBlocks( $fh ) ) {
						break;
					}
				} else {
					// unknown block — corrupt; stop parsing
					break;
				}
			}
			return false;
		} finally {
			fclose( $fh );
		}
	}

	/**
	 * Skip a chain of length-prefixed data sub-blocks up to and including the
	 * 0x00 block terminator.
	 *
	 * @param resource $fh
	 * @return bool False if the stream ended prematurely.
	 */
	private static function skipSubBlocks( $fh ): bool {
		while ( true ) {
			$len = fread( $fh, 1 );
			if ( !is_string( $len ) || $len === '' ) {
				return false;
			}
			$len = ord( $len );
			if ( $len === 0 ) {
				return true;
			}
			fseek( $fh, $len, SEEK_CUR );
		}
	}
}
