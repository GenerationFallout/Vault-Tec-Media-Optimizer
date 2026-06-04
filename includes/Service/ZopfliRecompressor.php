<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;

/**
 * Second-pass lossless PNG recompression using the external `zopflipng`
 * binary (https://github.com/google/zopfli).
 *
 * This runs AFTER Imagick's first-pass optimization. zopflipng squeezes an
 * extra 8–25% out of PNGs by trying many DEFLATE strategies. It is strictly
 * lossless: it re-encodes the same pixels, never altering the image.
 *
 * The binary is invoked via shell. If `zopflipng` is missing, not executable,
 * or shell functions are disabled, the service degrades gracefully and
 * reports itself unavailable (callers should check isAvailable() first).
 */
class ZopfliRecompressor implements PngRecompressorInterface {

	private ServiceOptions $options;
	private LoggerInterface $logger;

	/** @var bool|null Memoized availability check. */
	private ?bool $available = null;

	/** @var string|null Resolved absolute path to the binary. */
	private ?string $binaryPath = null;

	private ?string $lastError = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		// Note: we receive the extension's shared ServiceOptions object, which
		// holds all config keys. We deliberately do NOT call
		// assertRequiredOptions() here (unlike a service with its own dedicated
		// options object), because that method requires an exact match between
		// declared and present keys, and the shared object has many more keys
		// than this service uses. All other services in this extension follow
		// the same pattern: take the shared options and read keys via get().
		$this->options = $options;
		$this->logger = $logger;
	}

	public function getLastError(): ?string {
		return $this->lastError;
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'zopflipng';
	}

	/**
	 * Whether zopflipng can actually be used in this environment.
	 *
	 * Checks, in order: the feature toggle, that shell execution functions
	 * are not disabled by PHP config, and that the binary exists and is
	 * executable. Result is memoized for the request.
	 */
	public function isAvailable(): bool {
		if ( $this->available !== null ) {
			return $this->available;
		}

		if ( !$this->options->get( 'VaultTecMediaOptimizerZopfliEnabled' ) ) {
			$this->available = false;
			return false;
		}

		if ( !$this->shellFunctionsEnabled() ) {
			$this->logger->warning(
				'Zopfli unavailable: shell execution functions are disabled in PHP (disable_functions).'
			);
			$this->available = false;
			return false;
		}

		$binary = $this->resolveBinary();
		if ( $binary === null ) {
			$this->logger->warning( 'Zopfli unavailable: binary not found or not executable.' );
			$this->available = false;
			return false;
		}

		$this->binaryPath = $binary;
		$this->available = true;
		return true;
	}

	/**
	 * Check that at least one usable shell execution function is available
	 * and not listed in disable_functions.
	 */
	private function shellFunctionsEnabled(): bool {
		$disabled = array_map(
			'trim',
			explode( ',', (string)ini_get( 'disable_functions' ) )
		);
		// We use proc_open (preferred) but fall back to exec.
		foreach ( [ 'proc_open', 'exec' ] as $fn ) {
			if ( function_exists( $fn ) && !in_array( $fn, $disabled, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the zopflipng binary path.
	 *
	 * We deliberately do NOT rely on is_executable(): under some PHP-FPM/Plesk
	 * setups it returns false for a perfectly runnable binary (the file is
	 * fine — `which` finds it, the CLI runs it — but the FPM worker's view of
	 * the filesystem makes the stat-based check lie). The only reliable test
	 * is to actually run the binary and see whether it responds.
	 *
	 * Strategy:
	 *   - Absolute path  -> probe it directly by running "<path> --version".
	 *   - Bare name      -> resolve via `command -v`, then probe the result.
	 *   - As a last resort for a bare name, probe the name as-is (relying on
	 *     PATH), in case `command -v` itself misbehaves.
	 */
	private function resolveBinary(): ?string {
		$configured = (string)$this->options->get( 'VaultTecMediaOptimizerZopfliBinary' );

		// Absolute path provided: probe it directly.
		if ( $configured !== '' && ( $configured[0] === '/' || $configured[0] === '\\' ) ) {
			return $this->probeBinary( $configured ) ? $configured : null;
		}

		// Bare command name: sanity-check, then try to locate it.
		$name = $configured !== '' ? $configured : 'zopflipng';
		if ( !preg_match( '/^[A-Za-z0-9._-]+$/', $name ) ) {
			return null;
		}

		// Try `command -v` to get an absolute path.
		if ( function_exists( 'shell_exec' ) ) {
			$found = @shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' );
			if ( is_string( $found ) ) {
				$found = trim( $found );
				if ( $found !== '' && $this->probeBinary( $found ) ) {
					return $found;
				}
			}
		}

		// Last resort: probe the bare name, letting the shell resolve PATH.
		if ( $this->probeBinary( $name ) ) {
			return $name;
		}

		return null;
	}

	/**
	 * Probe a candidate binary by actually executing it with a harmless flag.
	 * Returns true if it runs (any exit code that isn't "command not found").
	 *
	 * zopflipng prints usage to stderr and exits non-zero when given no input,
	 * so we can't rely on exit code 0. Instead we treat "the shell could launch
	 * it at all" as success: a missing command yields exit code 127, while a
	 * launched binary yields something else (0, 1, 2, ...).
	 *
	 * @param string $candidate Absolute path or bare command name
	 * @return bool
	 */
	private function probeBinary( string $candidate ): bool {
		if ( !function_exists( 'proc_open' ) && !function_exists( 'exec' ) ) {
			return false;
		}

		// Prefer proc_open so we can read the real exit code cleanly.
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
				// 127 = command not found; -1 = couldn't launch. Anything else
				// means the binary actually ran.
				return $exit !== 127 && $exit !== -1;
			}
			// proc_open failed entirely; fall through to exec.
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
	 * Recompress a PNG file in place, losslessly.
	 *
	 * Writes to a temporary file first, and only replaces the original if the
	 * result is valid AND strictly smaller. Returns the number of bytes saved
	 * (0 if no improvement), or null on error.
	 *
	 * @param string $path Absolute filesystem path to a PNG file.
	 * @return int|null Bytes saved, or null on failure.
	 */
	public function recompress( string $path ): ?int {
		$this->lastError = null;

		if ( !$this->isAvailable() ) {
			$this->lastError = 'zopflipng not available';
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

		// Unique temp name (pid + uniqid) so two concurrent recompressions of the
		// same PNG never write into one shared temp and rename a corrupted result
		// over the original. Same directory => the rename below stays atomic.
		$tmp = $path . '.zopfli.' . getmypid() . '.' . uniqid() . '.tmp';
		$iterations = (int)$this->options->get( 'VaultTecMediaOptimizerZopfliIterations' );
		if ( $iterations < 1 ) {
			$iterations = 15;
		}

		// zopflipng flags:
		//   -y                : overwrite output without asking
		//   --iterations=N    : more iterations = smaller, slower
		//   --lossy_transparent is NOT used (we stay strictly lossless)
		//   --filters=0me     : try filter strategies (0=none, m=minsum, e=entropy)
		$args = [
			$this->binaryPath,
			'-y',
			'--iterations=' . $iterations,
			'--filters=0me',
			$path,
			$tmp,
		];

		$exitCode = $this->runProcess( $args );

		if ( $exitCode !== 0 ) {
			$this->lastError = "zopflipng exited with code $exitCode";
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return null;
		}

		if ( !is_file( $tmp ) ) {
			$this->lastError = 'zopflipng produced no output';
			return null;
		}

		$newSize = filesize( $tmp );
		if ( $newSize === false || $newSize === 0 ) {
			$this->lastError = 'Output file empty or unreadable';
			@unlink( $tmp );
			return null;
		}

		// Only replace if strictly smaller. zopflipng occasionally produces a
		// slightly larger file on already-optimal PNGs; in that case we keep
		// the original and report 0 bytes saved.
		if ( $newSize >= $originalSize ) {
			@unlink( $tmp );
			return 0;
		}

		// Atomically replace the original.
		if ( !@rename( $tmp, $path ) ) {
			$this->lastError = 'Could not replace original with recompressed file';
			@unlink( $tmp );
			return null;
		}

		return $originalSize - $newSize;
	}

	/**
	 * Run the binary with arguments, return its exit code.
	 *
	 * Uses proc_open when available (clean, no shell injection surface since
	 * args are passed as an array), falling back to exec with escaped args.
	 */
	private function runProcess( array $args ): int {
		if ( function_exists( 'proc_open' ) ) {
			$descriptors = [
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			];
			$pipes = [];
			// Passing an array to proc_open (PHP 7.4+) avoids shell parsing.
			$proc = @proc_open( $args, $descriptors, $pipes );
			if ( !is_resource( $proc ) ) {
				$this->lastError = 'proc_open failed';
				return -1;
			}
			// Close stdin, drain stdout/stderr to avoid deadlock.
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
				$this->logger->debug( 'zopflipng stderr: {err}', [ 'err' => substr( $stderr, 0, 500 ) ] );
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
