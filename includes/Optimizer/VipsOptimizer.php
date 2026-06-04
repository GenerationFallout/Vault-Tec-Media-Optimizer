<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;

/**
 * Optional libvips-based optimizer, driven through the `vips` command-line
 * binary (no PHP extension required — same external-binary pattern as the
 * zopflipng/oxipng recompressors).
 *
 * Why offer it: libvips encodes WebP and recompresses PNG/JPEG dramatically
 * faster and with far less memory than ImageMagick (it streams instead of
 * decompressing the whole raster). The WebP *quality/size* is essentially the
 * same (both use libwebp); the win is speed and memory at scale — useful for
 * bulk backfill and for the on-demand thumbnail generation at render time.
 *
 * It is selected only when $wgVaultTecMediaOptimizerImageEngine === 'vips' AND
 * the binary is actually usable; otherwise OptimizerFactory falls back to
 * Imagick/GD. Off by default, so the common deployment is unchanged.
 *
 * Scope notes:
 *  - PNG/JPEG first pass: re-encoded losslessly-where-possible and kept only if
 *    strictly smaller (same safety net as the other backends). The real PNG
 *    gains still come from the optional zopfli/oxipng second pass.
 *  - Animated GIF -> animated WebP is supported (loaded with n=-1).
 *  - Metadata stripping is applied on the in-place optimization (guarded by
 *    keep-if-smaller, so a strip option unsupported by an old vips build simply
 *    leaves the original untouched). WebP conversion keeps options minimal for
 *    maximum cross-version robustness.
 */
class VipsOptimizer implements OptimizerInterface {

	use AtomicWriteTrait;

	private ServiceOptions $options;
	private LoggerInterface $logger;
	private ?string $lastError = null;

	/** @var bool|null Memoized availability. */
	private ?bool $available = null;
	/** @var string|null Resolved binary path/name. */
	private ?string $binary = null;
	/** @var bool|null Memoized AVIF (heifsave/av1) availability. */
	private ?bool $avifAvailable = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	public function getName(): string {
		return 'vips';
	}

	public function getLastError(): ?string {
		return $this->lastError;
	}

	/**
	 * WebP support == "the vips binary is usable". libvips is built with WebP in
	 * essentially every distribution; if a build somehow lacks webpsave, the
	 * encode simply fails at runtime and the caller logs and moves on.
	 */
	public function supportsWebP(): bool {
		if ( $this->available !== null ) {
			return $this->available;
		}
		if ( !$this->shellFunctionsEnabled() ) {
			$this->available = false;
			return false;
		}
		$bin = $this->resolveBinary();
		if ( $bin === null ) {
			$this->available = false;
			return false;
		}
		$this->binary = $bin;
		$this->available = true;
		return true;
	}

	public function supportsAnimatedWebP(): bool {
		// libvips reads all GIF frames (n=-1) and webpsave writes animated WebP.
		return $this->supportsWebP();
	}

	/**
	 * AVIF support requires the heifsave operation (libvips built with libheif
	 * and an AV1 encoder). We probe the operation list once; if heifsave is
	 * absent the caller skips AVIF gracefully (WebP stays primary). If heifsave
	 * exists but lacks an AV1 encoder, the actual encode fails and is logged.
	 */
	public function supportsAvif(): bool {
		if ( $this->avifAvailable !== null ) {
			return $this->avifAvailable;
		}
		if ( !$this->supportsWebP() ) {
			$this->avifAvailable = false;
			return false;
		}
		$listing = '';
		if ( function_exists( 'proc_open' ) ) {
			$descriptors = [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
			$proc = @proc_open( [ $this->binary, '-l' ], $descriptors, $pipes );
			if ( is_resource( $proc ) ) {
				if ( isset( $pipes[0] ) ) {
					fclose( $pipes[0] );
				}
				// vips prints the operation list to stdout on some versions and
				// stderr on others; capture both.
				if ( isset( $pipes[1] ) ) {
					$listing .= (string)stream_get_contents( $pipes[1] );
					fclose( $pipes[1] );
				}
				if ( isset( $pipes[2] ) ) {
					$listing .= (string)stream_get_contents( $pipes[2] );
					fclose( $pipes[2] );
				}
				proc_close( $proc );
			}
		} elseif ( function_exists( 'exec' ) ) {
			$out = [];
			@exec( escapeshellarg( (string)$this->binary ) . ' -l 2>&1', $out );
			$listing = implode( "\n", $out );
		}
		$this->avifAvailable = ( stripos( $listing, 'heifsave' ) !== false );
		return $this->avifAvailable;
	}

	public function optimizePng( string $path ): bool {
		$this->lastError = null;
		if ( !$this->supportsWebP() ) {
			$this->lastError = 'vips not available';
			return false;
		}
		$tmp = $this->uniqueTempPath( $path );
		if ( !$this->saveWithOptionalStrip( 'pngsave', $path, $tmp, 'compression=9' ) ) {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return false;
		}
		return $this->keepIfSmaller( $path, $tmp );
	}

	public function optimizeJpeg( string $path ): bool {
		$this->lastError = null;
		if ( !$this->supportsWebP() ) {
			$this->lastError = 'vips not available';
			return false;
		}
		// Re-encode at a high quality (like the Imagick path, this is not strictly
		// lossless; true lossless JPEG would need jpegtran/mozjpeg). optimize_coding
		// shrinks the Huffman tables. trellis quant is intentionally NOT requested:
		// it errors on non-mozjpeg vips builds, which would abort the encode.
		$tmp = $this->uniqueTempPath( $path );
		if ( !$this->saveWithOptionalStrip( 'jpegsave', $path, $tmp, 'Q=92,optimize_coding=true' ) ) {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return false;
		}
		return $this->keepIfSmaller( $path, $tmp );
	}

	/**
	 * Run a vips saver into $tmp with the given base options, adding strip=true
	 * when metadata stripping is enabled. If the strip-enabled save fails — recent
	 * libvips (>= 8.15) deprecated the 'strip' option in favour of 'keep', and a
	 * build may reject it — retry once WITHOUT strip: optimizing without stripping
	 * beats skipping the file (and the original is untouched on failure thanks to
	 * keep-if-smaller). Sets lastError on hard failure. Returns true if $tmp was
	 * written.
	 *
	 * @param string $op vips operation ('pngsave'|'jpegsave')
	 * @param string $src Source path
	 * @param string $tmp Destination temp path
	 * @param string $baseOpts Comma-separated vips save options, without strip
	 * @return bool
	 */
	private function saveWithOptionalStrip( string $op, string $src, string $tmp, string $baseOpts ): bool {
		$strip = (bool)$this->options->get( 'VaultTecMediaOptimizerStripMetadata' );
		$opts = $baseOpts . ( $strip ? ',strip=true' : '' );
		$code = $this->runVips( [ $op, $src, $tmp . '[' . $opts . ']' ] );
		if ( $code === 0 ) {
			return true;
		}
		if ( $strip ) {
			// The deprecated 'strip' option may be the culprit; retry without it.
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			$code = $this->runVips( [ $op, $src, $tmp . '[' . $baseOpts . ']' ] );
			if ( $code === 0 ) {
				$this->logger->debug( 'vips {op}: retried without the deprecated strip option', [ 'op' => $op ] );
				return true;
			}
		}
		$this->lastError = "vips $op exited with code $code";
		return false;
	}

	public function convertToWebP( string $sourcePath, string $destPath, bool $lossless, int $quality ): bool {
		$this->lastError = null;
		if ( !$this->supportsWebP() ) {
			$this->lastError = 'vips not available';
			return false;
		}

		// Load all frames for GIF so animated GIFs become animated WebP; for
		// single-frame inputs n=-1 is harmless.
		$srcExt = strtolower( pathinfo( $sourcePath, PATHINFO_EXTENSION ) );
		$loadArg = $sourcePath . ( $srcExt === 'gif' ? '[n=-1]' : '' );

		$opts = $lossless ? 'lossless=true' : ( 'Q=' . $quality );
		$tmp = $this->uniqueTempPath( $destPath );
		$code = $this->runVips( [ 'webpsave', $loadArg, $tmp . '[' . $opts . ']' ] );
		if ( $code !== 0 ) {
			$this->lastError = "vips webpsave exited with code $code";
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return false;
		}
		// Atomic publish so a concurrent reader never sees a partial WebP.
		return $this->publishAtomically( $tmp, $destPath );
	}

	public function convertToAvif( string $sourcePath, string $destPath, int $quality ): bool {
		$this->lastError = null;
		if ( !$this->supportsAvif() ) {
			$this->lastError = 'vips AVIF (heifsave/av1) not available';
			return false;
		}

		$srcExt = strtolower( pathinfo( $sourcePath, PATHINFO_EXTENSION ) );
		$loadArg = $sourcePath . ( $srcExt === 'gif' ? '[n=-1]' : '' );

		// heifsave with the AV1 codec produces AVIF. Q is the quality (0-100).
		$opts = 'compression=av1,Q=' . $quality;
		$tmp = $this->uniqueTempPath( $destPath );
		$code = $this->runVips( [ 'heifsave', $loadArg, $tmp . '[' . $opts . ']' ] );
		if ( $code !== 0 ) {
			$this->lastError = "vips heifsave exited with code $code";
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return false;
		}
		// Atomic publish so a concurrent reader never sees a partial AVIF.
		return $this->publishAtomically( $tmp, $destPath );
	}

	/**
	 * Replace $original with $tmp only if $tmp is valid and strictly smaller;
	 * otherwise keep the original. Guarantees optimization never grows a file.
	 * Always returns true (original intact either way).
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

	// === Binary resolution / execution (mirrors ZopfliRecompressor) ===

	private function shellFunctionsEnabled(): bool {
		$disabled = array_map( 'trim', explode( ',', (string)ini_get( 'disable_functions' ) ) );
		foreach ( [ 'proc_open', 'exec' ] as $fn ) {
			if ( function_exists( $fn ) && !in_array( $fn, $disabled, true ) ) {
				return true;
			}
		}
		return false;
	}

	private function resolveBinary(): ?string {
		$configured = (string)$this->options->get( 'VaultTecMediaOptimizerVipsBinary' );

		if ( $configured !== '' && ( $configured[0] === '/' || $configured[0] === '\\' ) ) {
			return $this->probeBinary( $configured ) ? $configured : null;
		}

		$name = $configured !== '' ? $configured : 'vips';
		if ( !preg_match( '/^[A-Za-z0-9._-]+$/', $name ) ) {
			return null;
		}

		if ( function_exists( 'shell_exec' ) ) {
			$found = @shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' );
			if ( is_string( $found ) ) {
				$found = trim( $found );
				if ( $found !== '' && $this->probeBinary( $found ) ) {
					return $found;
				}
			}
		}

		return $this->probeBinary( $name ) ? $name : null;
	}

	private function probeBinary( string $candidate ): bool {
		if ( function_exists( 'proc_open' ) ) {
			$descriptors = [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
			$proc = @proc_open( escapeshellarg( $candidate ) . ' --version', $descriptors, $pipes );
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

	/**
	 * Run "vips <args...>" and return the exit code. Uses proc_open with an
	 * argument array (no shell parsing), falling back to exec with escaped args.
	 *
	 * @param string[] $args Arguments after the binary (e.g. ['pngsave', $in, $out])
	 * @return int
	 */
	private function runVips( array $args ): int {
		$full = array_merge( [ $this->binary ], $args );

		if ( function_exists( 'proc_open' ) ) {
			$descriptors = [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
			$pipes = [];
			$proc = @proc_open( $full, $descriptors, $pipes );
			if ( !is_resource( $proc ) ) {
				$this->lastError = 'proc_open failed';
				return -1;
			}
			if ( isset( $pipes[0] ) ) {
				fclose( $pipes[0] );
			}
			$stderr = '';
			if ( isset( $pipes[1] ) ) {
				stream_get_contents( $pipes[1] );
				fclose( $pipes[1] );
			}
			if ( isset( $pipes[2] ) ) {
				$stderr = stream_get_contents( $pipes[2] );
				fclose( $pipes[2] );
			}
			$code = proc_close( $proc );
			if ( $code !== 0 && $stderr !== '' ) {
				$this->logger->debug( 'vips stderr: {err}', [ 'err' => substr( $stderr, 0, 500 ) ] );
			}
			return $code;
		}

		$cmd = implode( ' ', array_map( 'escapeshellarg', $full ) );
		$output = [];
		$code = 0;
		@exec( $cmd . ' 2>&1', $output, $code );
		return $code;
	}
}
