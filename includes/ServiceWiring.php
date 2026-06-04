<?php
/**
 * Service wiring for VaultTecMediaOptimizer.
 *
 * @file
 */

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerFactory;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\BackfillScheduler;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\HtmlRewriter;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\ImageProcessor;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PrerequisiteChecker;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\OxipngRecompressor;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PngRecompressorInterface;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\ZopfliRecompressor;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\ZopfliOriginalProcessor;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'VaultTecMediaOptimizer.Config' => static function ( MediaWikiServices $services ): ServiceOptions {
		return new ServiceOptions(
			[
				'VaultTecMediaOptimizerEnabled',
				'VaultTecMediaOptimizerWebPDirectory',
				'VaultTecMediaOptimizerWebPQuality',
				'VaultTecMediaOptimizerWebPLosslessForPng',
				'VaultTecMediaOptimizerOptimizeOriginals',
				'VaultTecMediaOptimizerZopfliEnabled',
				'VaultTecMediaOptimizerZopfliBinary',
				'VaultTecMediaOptimizerZopfliIterations',
				'VaultTecMediaOptimizerPngEngine',
				'VaultTecMediaOptimizerOxipngBinary',
				'VaultTecMediaOptimizerOxipngLevel',
				'VaultTecMediaOptimizerFormats',
				'VaultTecMediaOptimizerStripMetadata',
				'VaultTecMediaOptimizerProcessThumbnails',
				'VaultTecMediaOptimizerBackfillBatchSize',
				'VaultTecMediaOptimizerMaxFileSize',
				'VaultTecMediaOptimizerOnDemandThumbLimit',
				'VaultTecMediaOptimizerAvifEnabled',
				'VaultTecMediaOptimizerAvifDirectory',
				'VaultTecMediaOptimizerAvifQuality',
				'UploadDirectory',
				'UploadPath',
			],
			$services->getMainConfig()
		);
	},

	'VaultTecMediaOptimizer.OptimizerFactory' => static function ( MediaWikiServices $services ): OptimizerFactory {
		return new OptimizerFactory(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.WebPRepo' => static function ( MediaWikiServices $services ): WebPRepo {
		return new WebPRepo(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	// Returns the active PNG recompressor according to the configured engine.
	// Kept under the historical 'ZopfliRecompressor' service id so existing
	// consumers don't need rewiring; they all type-hint the interface now.
	'VaultTecMediaOptimizer.ZopfliRecompressor' => static function ( MediaWikiServices $services ): PngRecompressorInterface {
		$config = $services->getService( 'VaultTecMediaOptimizer.Config' );
		$logger = LoggerFactory::getInstance( 'VaultTecMediaOptimizer' );

		$engine = strtolower( (string)$config->get( 'VaultTecMediaOptimizerPngEngine' ) );
		if ( $engine === 'oxipng' ) {
			return new OxipngRecompressor( $config, $logger );
		}
		// Default / 'zopflipng'.
		return new ZopfliRecompressor( $config, $logger );
	},

	// Explicit per-engine services, handy for diagnostics or callers that want
	// a specific engine regardless of the configured default.
	'VaultTecMediaOptimizer.PngRecompressor.zopfli' => static function ( MediaWikiServices $services ): ZopfliRecompressor {
		return new ZopfliRecompressor(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.PngRecompressor.oxipng' => static function ( MediaWikiServices $services ): OxipngRecompressor {
		return new OxipngRecompressor(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.ZopfliOriginalProcessor' => static function ( MediaWikiServices $services ): ZopfliOriginalProcessor {
		return new ZopfliOriginalProcessor(
			$services->getService( 'VaultTecMediaOptimizer.ZopfliRecompressor' ),
			$services->getService( 'VaultTecMediaOptimizer.OptimizationRecord' ),
			$services->getService( 'VaultTecMediaOptimizer.WebPRepo' ),
			$services->getRepoGroup(),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.OptimizationRecord' => static function ( MediaWikiServices $services ): OptimizationRecord {
		return new OptimizationRecord(
			$services->getConnectionProvider(),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.ImageProcessor' => static function ( MediaWikiServices $services ): ImageProcessor {
		return new ImageProcessor(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			$services->getService( 'VaultTecMediaOptimizer.OptimizerFactory' ),
			$services->getService( 'VaultTecMediaOptimizer.WebPRepo' ),
			$services->getService( 'VaultTecMediaOptimizer.OptimizationRecord' ),
			$services->getRepoGroup(),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.HtmlRewriter' => static function ( MediaWikiServices $services ): HtmlRewriter {
		return new HtmlRewriter(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			$services->getService( 'VaultTecMediaOptimizer.WebPRepo' ),
			$services->getService( 'VaultTecMediaOptimizer.OptimizerFactory' ),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.BackfillScheduler' => static function ( MediaWikiServices $services ): BackfillScheduler {
		return new BackfillScheduler(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			$services->getService( 'VaultTecMediaOptimizer.OptimizationRecord' ),
			$services->getConnectionProvider(),
			$services->getJobQueueGroup(),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )
		);
	},

	'VaultTecMediaOptimizer.PrerequisiteChecker' => static function ( MediaWikiServices $services ): PrerequisiteChecker {
		return new PrerequisiteChecker(
			$services->getService( 'VaultTecMediaOptimizer.Config' ),
			$services->getConnectionProvider()
		);
	},
];
