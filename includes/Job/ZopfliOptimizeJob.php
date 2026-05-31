<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Job;

use MediaWiki\Extension\VaultTecMediaOptimizer\Service\ZopfliOriginalProcessor;
use MediaWiki\JobQueue\Job;
use MediaWiki\Title\Title;

/**
 * Background job that applies second-pass zopflipng recompression to a single
 * ORIGINAL PNG and refreshes MediaWiki metadata.
 *
 * Scheduled by the zopfli backfill on Special:VTMOBackfill. Separate from
 * OptimizeImageJob so that the (slow) zopfli pass never blocks the primary
 * WebP optimization queue.
 */
class ZopfliOptimizeJob extends Job {

	private ZopfliOriginalProcessor $processor;

	public function __construct(
		Title $title,
		array $params,
		ZopfliOriginalProcessor $processor
	) {
		parent::__construct( 'VaultTecMediaOptimizerZopfliOptimize', $title, $params );
		$this->processor = $processor;
		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		if ( is_array( $info['params'] ?? null ) ) {
			$info['params'] = [ 'imgName' => $info['params']['imgName'] ?? '' ];
		}
		return $info;
	}

	/** @inheritDoc */
	public function run() {
		$imgName = $this->params['imgName'] ?? null;
		if ( !is_string( $imgName ) || $imgName === '' ) {
			$this->setLastError( 'Missing imgName parameter' );
			return false;
		}
		$this->processor->processByName( $imgName );
		// Always return true: failures are recorded, we don't want to clog the
		// retry queue with files that may simply lack zopflipng.
		return true;
	}
}
