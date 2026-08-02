<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides HOW a WebP derivative is encoded, in one place.
 *
 * PNG is the interesting case. `$wgVaultTecMediaOptimizerWebPLosslessForPng`
 * used to be a plain boolean applied to every PNG alike, but the right answer
 * depends entirely on the content:
 *
 *   content            PNG      WebP lossless   WebP q85
 *   flat UI            1 162          2 606       1 718   -> PNG wins outright
 *   smooth gradient      940          5 614       1 700   -> PNG wins outright
 *   photographic     287 072         82 586      47 968   -> lossy wins by 42%
 *
 * So on a wiki whose PNGs are mostly screenshots, the lossless default serves
 * 42% more bytes than necessary; and on flat UI assets neither encoding beats
 * the PNG, which the never-serve-larger guard already handles. A single global
 * boolean cannot express that.
 *
 * The 'auto' mode therefore encodes BOTH and keeps whichever is smaller. It
 * costs one extra WebP encode (~0.16 s for a 600x450 image, and it runs in the
 * job/CLI path, never in a visitor's request) and never produces a worse
 * result than either fixed mode.
 *
 * Values accepted for the config:
 *   true    (default) always lossless — pixel-exact, safest for crisp UI art
 *   false             always lossy at $wgVaultTecMediaOptimizerWebPQuality
 *   'auto'            encode both, keep the smaller
 */
class WebPEncodePolicy {

	private ServiceOptions $options;
	private LoggerInterface $logger;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	/**
	 * Quality to use for lossy encodes, clamped to libwebp's range.
	 *
	 * GD throws a ValueError on a negative quality and a non-numeric config
	 * casts to 0, so a careless value must not break every encode.
	 */
	public function quality(): int {
		return min( 100, max( 0,
			(int)$this->options->get( 'VaultTecMediaOptimizerWebPQuality' ) ) );
	}

	/**
	 * Whether this MIME type is encoded losslessly under the current config.
	 * Only meaningful for the fixed modes; 'auto' is resolved by encodeBest().
	 */
	public function isLossless( string $mime ): bool {
		return $mime === 'image/png' && $this->rawPngMode() === true;
	}

	/**
	 * Encode $sourcePath to $destPath, choosing the encoding per the policy.
	 *
	 * @param OptimizerInterface $optimizer
	 * @param string $sourcePath
	 * @param string $destPath
	 * @param string $mime MIME type of the SOURCE
	 * @return bool True when $destPath holds a WebP
	 */
	public function encodeBest(
		OptimizerInterface $optimizer,
		string $sourcePath,
		string $destPath,
		string $mime
	): bool {
		$quality = $this->quality();

		// Non-PNG, or a fixed mode: a single encode, exactly as before.
		if ( $mime !== 'image/png' || $this->rawPngMode() !== 'auto' ) {
			return $optimizer->convertToWebP(
				$sourcePath, $destPath, $this->isLossless( $mime ), $quality
			);
		}

		// 'auto': encode both candidates and keep the smaller. The lossy one
		// goes to a sibling path so the two never race for the destination;
		// whichever wins is moved into place and the loser removed.
		$lossyCandidate = $destPath . '.lossy';

		$losslessOk = $optimizer->convertToWebP( $sourcePath, $destPath, true, $quality );
		$lossyOk = $optimizer->convertToWebP( $sourcePath, $lossyCandidate, false, $quality );

		$losslessSize = $losslessOk && is_file( $destPath ) ? filesize( $destPath ) : false;
		$lossySize = $lossyOk && is_file( $lossyCandidate ) ? filesize( $lossyCandidate ) : false;

		// Lossy wins only when it actually produced a strictly smaller file.
		if ( $lossySize !== false && $lossySize > 0
			&& ( $losslessSize === false || $lossySize < $losslessSize )
		) {
			if ( @rename( $lossyCandidate, $destPath ) ) {
				$this->logger->debug(
					'WebP auto: lossy kept for {src} ({lossy} B < lossless {lossless} B)',
					[ 'src' => $sourcePath, 'lossy' => $lossySize,
						'lossless' => $losslessSize === false ? 'n/a' : $losslessSize ]
				);
				return true;
			}
			// Rename failed: fall through and keep the lossless one if we have it.
			@unlink( $lossyCandidate );
			return $losslessSize !== false;
		}

		if ( is_file( $lossyCandidate ) ) {
			@unlink( $lossyCandidate );
		}
		return $losslessSize !== false;
	}

	/**
	 * The raw configured PNG mode: true, false, or the string 'auto'.
	 *
	 * @return bool|string
	 */
	private function rawPngMode() {
		$value = $this->options->get( 'VaultTecMediaOptimizerWebPLosslessForPng' );
		if ( is_string( $value ) && strtolower( $value ) === 'auto' ) {
			return 'auto';
		}
		return (bool)$value;
	}
}
