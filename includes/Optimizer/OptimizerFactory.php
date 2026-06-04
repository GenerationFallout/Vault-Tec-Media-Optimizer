<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer;

use MediaWiki\Config\ServiceOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Selects the best available optimizer backend.
 *
 * Priority: Imagick (full features) > GD (basic fallback).
 * Throws if no backend can produce WebP at all.
 */
class OptimizerFactory {

	private ServiceOptions $options;
	private LoggerInterface $logger;
	private ?OptimizerInterface $instance = null;

	public function __construct( ServiceOptions $options, LoggerInterface $logger ) {
		$this->options = $options;
		$this->logger = $logger;
	}

	/**
	 * Get the best available optimizer.
	 *
	 * @return OptimizerInterface
	 * @throws RuntimeException If no backend supports WebP encoding
	 */
	public function getOptimizer(): OptimizerInterface {
		if ( $this->instance !== null ) {
			return $this->instance;
		}

		// Optional libvips engine. Selected only when explicitly configured AND
		// the binary is usable; otherwise we log and fall through to the default
		// Imagick/GD selection, so a misconfiguration never breaks processing.
		$engine = strtolower( (string)$this->options->get( 'VaultTecMediaOptimizerImageEngine' ) );
		if ( $engine === 'vips' ) {
			$vips = new VipsOptimizer( $this->options, $this->logger );
			if ( $vips->supportsWebP() ) {
				$this->instance = $vips;
				return $vips;
			}
			$this->logger->warning(
				'Image engine "vips" selected but the vips binary is not usable; falling back to Imagick/GD.'
			);
		}

		// Prefer Imagick
		if ( extension_loaded( 'imagick' ) ) {
			$imagick = new ImagickOptimizer( $this->options, $this->logger );
			if ( $imagick->supportsWebP() ) {
				$this->instance = $imagick;
				return $imagick;
			}
			$this->logger->warning(
				'Imagick loaded but lacks WebP support; falling back to GD'
			);
		}

		// Fallback: GD
		if ( extension_loaded( 'gd' ) ) {
			$gd = new GdOptimizer( $this->options, $this->logger );
			if ( $gd->supportsWebP() ) {
				$this->instance = $gd;
				return $gd;
			}
		}

		throw new RuntimeException(
			'No image backend supports WebP encoding. ' .
			'Install Imagick with WebP support (apt install php-imagick libwebp-dev) ' .
			'or recompile GD with WebP support.'
		);
	}

	/**
	 * Check if any optimizer is available without throwing.
	 *
	 * @return bool
	 */
	public function hasOptimizer(): bool {
		try {
			$this->getOptimizer();
			return true;
		} catch ( RuntimeException $e ) {
			return false;
		}
	}
}
