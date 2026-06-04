<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;

/**
 * Lossless PNG recompression using the external `oxipng` binary.
 *
 * oxipng is a multi-threaded PNG optimizer written in Rust. It is dramatically
 * faster than zopflipng (seconds vs minutes on large files) for a result that
 * is only marginally larger (typically within a few percent). For bulk work —
 * repairing many files, processing uploads/thumbnails without blocking the job
 * queue — it is the better trade-off. zopflipng remains available for those who
 * want to squeeze out the last few percent.
 *
 * Exposes the same contract as ZopfliRecompressor (isAvailable / recompress /
 * getLastError) so the two are interchangeable behind PngRecompressorFactory.
 *
 * Lossless guarantee: with `--strip safe` oxipng only removes non-critical
 * metadata chunks; pixels, dimensions and transparency are untouched.
 */
class OxipngRecompressor implements PngRecompressorInterface {

	private ServiceOptions $options;
	private LoggerInterface $logger;

	private ?string $lastError = null;
	private ?bool $available = null;
	private ?string $binaryPath = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'oxipng';
	}

	/** @inheritDoc */
	public function getLastError(): ?string {
		return $this->lastError;
	}

	/** @inheritDoc */
	public function isAvailable(): bool {
		if ( $this->available !== null ) {
			return $this->available;
		}

		if ( !$this->options->get( 'VaultTecMediaOptimizerZopfliEnabled' ) ) {
			// The same master toggle governs second-pass recompression
			// regardless of engine.
			$this->available = false;
			return false;
		}

		if ( !$this->shellFunctionsEnabled() ) {
			$this->logger->warning(
				'oxipng unavailable: shell execution functions are disabled in PHP (disable_functions).'
			);
			$this->available = false;
			return false;
		}

		$binary = $this->resolveBinary();
		if ( $binary === null ) {
			$this->logger->warning( 'oxipng unavailable: binary not found or not runnable.' );
			$this->available = false;
			return false;
		}

		$this->binaryPath = $binary;
		$this->available = true;
		return true;
	}

	/**
	 * Check that at least one usable shell execution function is available.
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
	 * Resolve the oxipng binary path. Probes by execution rather than relying
	 * on is_executable() (which can lie under FPM and trips open_basedir
	 * warnings). See ZopfliRecompressor for the rationale.
	 */
	private function resolveBinary(): ?string {
		$configured = (string)$this->options->get( 'VaultTecMediaOptimizerOxipngBinary' );

		if ( $configured !== '' && ( $configured[0] === '/' || $configured[0] === '\\' ) ) {
			return $this->probeBinary( $configured ) ? $configured : null;
		}

		$name = $configured !== '' ? $configured : 'oxipng';
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
	 * if it launches (exit code other than 127 "not found" / -1).
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

	/**
	 * Recompress a PNG in place, losslessly. Returns bytes saved (>= 0), or
	 * null on hard failure. The original is only replaced if the result is
	 * strictly smaller; otherwise it is kept and 0 is returned.
	 *
	 * @param string $path
	 * @return int|null
	 */
	public function recompress( string $path ): ?int {
		$this->lastError = null;

		if ( !$this->isAvailable() ) {
			$this->lastError = 'oxipng not available';
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

		// oxipng optimizes in place with --out, but to keep the "only replace
		// if smaller" guarantee identical to the zopfli path, we write to a
		// temp file and compare ourselves.
		// Unique temp name (pid + uniqid) so two concurrent recompressions of the
		// same PNG never collide on one shared temp. Same directory => the rename
		// below stays atomic.
		$tmp = $path . '.oxipng.' . getmypid() . '.' . uniqid() . '.tmp';
		// Start from a copy so oxipng has something to optimize at $tmp.
		if ( !@copy( $path, $tmp ) ) {
			$this->lastError = 'Could not create temp copy for oxipng';
			return null;
		}

		$level = (string)$this->options->get( 'VaultTecMediaOptimizerOxipngLevel' );
		if ( $level === '' ) {
			$level = '2';
		}

		// oxipng flags:
		//   -o LEVEL        : optimization level (0-6, or "max"). 2 is a good
		//                     speed/size balance; higher is slower.
		//   --strip safe    : remove non-critical metadata, keep color/gamma.
		//   -q              : quiet.
		//   (multi-threaded by default; uses all cores.)
		// We pass $tmp as the file to optimize in place.
		$args = [
			$this->binaryPath,
			'-o', $level,
			'--strip', 'safe',
			'-q',
			$tmp,
		];

		$exitCode = $this->runProcess( $args );

		if ( $exitCode !== 0 ) {
			$this->lastError = "oxipng exited with code $exitCode";
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return null;
		}

		if ( !is_file( $tmp ) ) {
			$this->lastError = 'oxipng produced no output';
			return null;
		}

		$newSize = filesize( $tmp );
		if ( $newSize === false || $newSize === 0 ) {
			$this->lastError = 'Output file empty or unreadable';
			@unlink( $tmp );
			return null;
		}

		// Only replace if strictly smaller.
		if ( $newSize >= $originalSize ) {
			@unlink( $tmp );
			return 0;
		}

		if ( !@rename( $tmp, $path ) ) {
			$this->lastError = 'Could not replace original with recompressed file';
			@unlink( $tmp );
			return null;
		}

		clearstatcache( true, $path );
		return $originalSize - $newSize;
	}

	/**
	 * Run the binary with arguments, return its exit code. Uses proc_open with
	 * an args array (no shell parsing), falling back to exec with escaped args.
	 *
	 * @param string[] $args
	 * @return int
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
				$this->logger->debug( 'oxipng stderr: {err}', [ 'err' => substr( $stderr, 0, 500 ) ] );
			}
			return $code;
		}

		$cmd = implode( ' ', array_map( 'escapeshellarg', $args ) );
		$output = [];
		$code = 0;
		@exec( $cmd . ' 2>&1', $output, $code );
		return $code;
	}
}
