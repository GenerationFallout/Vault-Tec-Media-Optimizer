<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;

/**
 * First-pass lossless GIF optimization using the external `gifsicle` binary
 * (https://www.lcdf.org/gifsicle/).
 *
 * Unlike the PNG zopfli/oxipng recompressors (which run as a *second* pass on
 * top of Imagick), gifsicle is the GIF first pass: Imagick/GD/libvips do not
 * meaningfully shrink GIFs, so we hand the original straight to gifsicle when
 * the feature is enabled. It runs inline in ImageProcessor on upload and during
 * the CLI backfill — there is no separate second-pass job for GIF.
 *
 * Strictly lossless and animation-safe. We only ever pass `-O{level}` (a
 * structural optimization: better LZW packing, inter-frame transparency /
 * minimal-bounding-box frames). We NEVER pass any of gifsicle's destructive
 * options:
 *   - no --resize / --scale / --resize-* : dimensions are preserved;
 *   - no --lossy                         : frame pixels are preserved;
 *   - no --colors / --dither             : the palette is preserved.
 * Frame count, per-frame delays, disposal and the loop counter are all kept, so
 * the rendered animation is byte-identical to the source — only its on-disk
 * representation gets smaller.
 *
 * The binary is invoked via proc_open/exec. If `gifsicle` is missing, not
 * executable, or shell functions are disabled, the service degrades gracefully
 * and reports itself unavailable (callers check isAvailable() first, and treat
 * "unavailable" as a harmless no-op so WebP generation still proceeds).
 */
class GifOptimizer {

	private ServiceOptions $options;
	private LoggerInterface $logger;

	/** @var bool|null Memoized availability check. */
	private ?bool $available = null;

	/** @var string|null Resolved absolute path to the binary. */
	private ?string $binaryPath = null;

	private ?string $lastError = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		// We receive the extension's shared ServiceOptions object (all keys).
		// As with the recompressors we deliberately do NOT call
		// assertRequiredOptions(): the shared object holds far more keys than
		// this service reads, and that method demands an exact match.
		$this->options = $options;
		$this->logger = $logger;
	}

	public function getLastError(): ?string {
		return $this->lastError;
	}

	public function getName(): string {
		return 'gifsicle';
	}

	/**
	 * Whether gifsicle can actually be used in this environment.
	 *
	 * Checks, in order: the feature toggle, that shell execution functions are
	 * not disabled by PHP config, and that the binary exists and is runnable.
	 * Result is memoized for the request.
	 */
	public function isAvailable(): bool {
		if ( $this->available !== null ) {
			return $this->available;
		}

		if ( !$this->options->get( 'VaultTecMediaOptimizerGifsicleEnabled' ) ) {
			$this->available = false;
			return false;
		}

		if ( !$this->shellFunctionsEnabled() ) {
			$this->logger->warning(
				'gifsicle unavailable: shell execution functions are disabled in PHP (disable_functions).'
			);
			$this->available = false;
			return false;
		}

		$binary = $this->resolveBinary();
		if ( $binary === null ) {
			$this->logger->warning( 'gifsicle unavailable: binary not found or not executable.' );
			$this->available = false;
			return false;
		}

		$this->binaryPath = $binary;
		$this->available = true;
		return true;
	}

	/**
	 * Optimize a GIF file in place, losslessly.
	 *
	 * Writes to a unique temporary file first, and only replaces the original if
	 * the result is valid AND strictly smaller (keep-if-smaller anti-bloat
	 * guard). Returns the number of bytes saved (0 if no improvement, e.g. an
	 * already-optimal GIF), or null on error.
	 *
	 * Animation, dimensions, frame timing and loop count are preserved (see the
	 * class docblock) — this is a representation-only optimization.
	 *
	 * @param string $path Absolute filesystem path to a GIF file.
	 * @return int|null Bytes saved, or null on failure.
	 */
	public function optimize( string $path ): ?int {
		$this->lastError = null;

		if ( !$this->isAvailable() ) {
			$this->lastError = 'gifsicle not available';
			return null;
		}

		if ( !is_file( $path ) || !is_readable( $path ) ) {
			$this->lastError = "File not readable: $path";
			return null;
		}
		if ( !is_writable( $path ) ) {
			$this->lastError = "File not writable: $path";
			return null;
		}

		$originalSize = filesize( $path );
		if ( $originalSize === false || $originalSize === 0 ) {
			$this->lastError = 'Could not stat file or file empty';
			return null;
		}

		// Unique temp name (pid + uniqid) in the same directory, so two
		// concurrent optimizations of the same GIF never share one temp and the
		// follow-up rename stays atomic.
		$tmp = $path . '.gifsicle.' . getmypid() . '.' . uniqid() . '.tmp';

		$level = (int)$this->options->get( 'VaultTecMediaOptimizerGifsicleLevel' );
		if ( $level < 1 || $level > 3 ) {
			$level = 3;
		}

		// gifsicle flags:
		//   --no-warnings : keep stderr quiet on harmless quirks (still lossless)
		//   -O{level}     : structural optimization only (1..3); -O3 tries the
		//                   most strategies. NO resize/lossy/colors flags ever.
		//   -o <tmp>      : output file
		//   --            : end of options; everything after is an input path,
		//                   so a GIF whose name starts with '-' can't be parsed
		//                   as a flag.
		$args = [
			$this->binaryPath,
			'--no-warnings',
			'-O' . $level,
			'-o', $tmp,
			'--',
			$path,
		];

		$exitCode = $this->runProcess( $args );

		if ( $exitCode !== 0 ) {
			$this->lastError = "gifsicle exited with code $exitCode";
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return null;
		}

		if ( !is_file( $tmp ) ) {
			$this->lastError = 'gifsicle produced no output';
			return null;
		}

		$newSize = filesize( $tmp );
		if ( $newSize === false || $newSize === 0 ) {
			$this->lastError = 'Output file empty or unreadable';
			@unlink( $tmp );
			return null;
		}

		// Only replace if strictly smaller. An already-optimal GIF can come out
		// the same size or marginally larger; in that case keep the original and
		// report 0 bytes saved.
		if ( $newSize >= $originalSize ) {
			@unlink( $tmp );
			return 0;
		}

		// Atomically replace the original.
		if ( !@rename( $tmp, $path ) ) {
			$this->lastError = 'Could not replace original with optimized GIF';
			@unlink( $tmp );
			return null;
		}

		return $originalSize - $newSize;
	}

	/**
	 * Check that at least one usable shell execution function is available and
	 * not listed in disable_functions.
	 */
	private function shellFunctionsEnabled(): bool {
		$disabled = array_map(
			'trim',
			explode( ',', (string)ini_get( 'disable_functions' ) )
		);
		foreach ( [ 'proc_open', 'exec' ] as $fn ) {
			if ( function_exists( $fn ) && !in_array( $fn, $disabled, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the gifsicle binary path.
	 *
	 * Mirrors the recompressors: an absolute path is probed by running it; a
	 * bare name is resolved via `command -v` then probed; as a last resort the
	 * bare name is probed directly (PATH). We avoid is_executable() because it
	 * lies under some FPM setups and emits open_basedir warnings.
	 */
	private function resolveBinary(): ?string {
		$configured = (string)$this->options->get( 'VaultTecMediaOptimizerGifsicleBinary' );

		// Absolute path provided: probe it directly.
		if ( $configured !== '' && ( $configured[0] === '/' || $configured[0] === '\\' ) ) {
			return $this->probeBinary( $configured ) ? $configured : null;
		}

		// Bare command name: sanity-check, then try to locate it.
		$name = $configured !== '' ? $configured : 'gifsicle';
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

		if ( $this->probeBinary( $name ) ) {
			return $name;
		}

		return null;
	}

	/**
	 * Probe a candidate binary by running "<candidate> --version". Returns true
	 * if it launches (exit code other than 127 "not found" / -1 "couldn't
	 * launch"). gifsicle --version exits 0, but we stay tolerant for parity with
	 * the recompressors.
	 *
	 * @param string $candidate Absolute path or bare command name
	 * @return bool
	 */
	private function probeBinary( string $candidate ): bool {
		if ( !function_exists( 'proc_open' ) && !function_exists( 'exec' ) ) {
			return false;
		}

		if ( function_exists( 'proc_open' ) ) {
			$descriptors = [
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			];
			$cmd = escapeshellarg( $candidate ) . ' --version';
			$proc = @proc_open( $cmd, $descriptors, $pipes );
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
	 * Run the binary with arguments (array form, no shell parsing), return its
	 * exit code. Mirrors ZopfliRecompressor::runProcess().
	 */
	private function runProcess( array $args ): int {
		if ( function_exists( 'proc_open' ) ) {
			$descriptors = [
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			];
			$pipes = [];
			$proc = @proc_open( $args, $descriptors, $pipes );
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
				$this->logger->debug( 'gifsicle stderr: {err}', [ 'err' => substr( $stderr, 0, 500 ) ] );
			}
			return $code;
		}

		// Fallback: exec with manually escaped arguments.
		$cmd = implode( ' ', array_map( 'escapeshellarg', $args ) );
		$output = [];
		$code = 0;
		@exec( $cmd . ' 2>&1', $output, $code );
		return $code;
	}
}
