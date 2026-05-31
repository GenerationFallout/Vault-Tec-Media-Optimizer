<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Job;

use MediaWiki\Extension\VaultTecMediaOptimizer\Service\ImageProcessor;
use MediaWiki\JobQueue\Job;
use MediaWiki\Title\Title;

/**
 * Background job to optimize a single image.
 *
 * Scheduled by BackfillScheduler. The job receives the image name (DB key)
 * via params and invokes ImageProcessor to do the actual work.
 *
 * Idempotent: re-running a job for a file is safe (will redo or skip
 * depending on current state).
 */
class OptimizeImageJob extends Job {

	private ImageProcessor $processor;

	/**
	 * @param Title $title File title (NS_FILE)
	 * @param array $params Must include 'imgName' (string)
	 * @param ImageProcessor $processor Injected by extension.json
	 */
	public function __construct(
		Title $title,
		array $params,
		ImageProcessor $processor
	) {
		parent::__construct( 'VaultTecMediaOptimizerOptimizeImage', $title, $params );
		$this->processor = $processor;
		// Backfill jobs are safe to deduplicate: scheduling the same image
		// twice yields the same result.
		$this->removeDuplicates = true;
	}

	/**
	 * @inheritDoc
	 */
	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		// Deduplicate only on imgName, not on other params like requestId
		if ( is_array( $info['params'] ?? null ) ) {
			$info['params'] = [ 'imgName' => $info['params']['imgName'] ?? '' ];
		}
		return $info;
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$imgName = $this->params['imgName'] ?? null;
		if ( !is_string( $imgName ) || $imgName === '' ) {
			$this->setLastError( 'Missing imgName parameter' );
			return false;
		}

		$this->processor->processByName( $imgName );
		// We always return true: the processor records failures in its own
		// table, and we don't want failed images to clog the retry queue.
		return true;
	}
}
