<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Schedules backfill jobs for existing images that haven't been processed.
 *
 * Used by Special:VTMOBackfill. Each call to schedule() picks
 * up to N images that have no row in vtmo_image_optimization (or status=pending)
 * and enqueues a job for each.
 *
 * Resilient: jobs are idempotent, so partial failures can be retried.
 */
class BackfillScheduler {

	private ServiceOptions $options;
	private OptimizationRecord $record;
	private IConnectionProvider $connectionProvider;
	private JobQueueGroup $jobQueueGroup;
	private LoggerInterface $logger;

	public function __construct(
		ServiceOptions $options,
		OptimizationRecord $record,
		IConnectionProvider $connectionProvider,
		JobQueueGroup $jobQueueGroup,
		LoggerInterface $logger
	) {
		$this->options = $options;
		$this->record = $record;
		$this->connectionProvider = $connectionProvider;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->logger = $logger;
	}

	/**
	 * Schedule a batch of files to be processed.
	 *
	 * @param int|null $batchSizeOverride If provided, overrides the configured
	 *   batch size. Useful for "schedule all" workflows that pass a very large
	 *   value (e.g. the total eligible count). Set to 0 or null to use config.
	 * @return int Number of jobs actually enqueued (0 if none eligible)
	 */
	public function scheduleBatch( ?int $batchSizeOverride = null ): int {
		if ( $batchSizeOverride !== null && $batchSizeOverride > 0 ) {
			$batchSize = $batchSizeOverride;
		} else {
			$batchSize = (int)$this->options->get( 'VaultTecMediaOptimizerBackfillBatchSize' );
			if ( $batchSize <= 0 ) {
				$batchSize = 20;
			}
		}

		$db = $this->connectionProvider->getReplicaDatabase();

		// LEFT JOIN vtmo_image_optimization to find image rows with no record yet,
		// or whose record is pending (we retry those).
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );

		// "no record OR pending" condition
		$pendingExpr = $db->expr( 'io_status', '=', null )
			->orExpr( $db->expr( 'io_status', '=', OptimizationRecord::STATUS_PENDING ) );

		$queryBuilder = $db->newSelectQueryBuilder()
			->select( 'img_name' )
			->from( 'image' )
			->leftJoin( 'vtmo_image_optimization', null, 'io_img_name = img_name' )
			->where( $pendingExpr )
			->orderBy( 'img_name' )
			->limit( $batchSize )
			->caller( __METHOD__ );

		$mimeExpr = $this->buildMimeExpression( $db, $allowedMimes );
		if ( $mimeExpr !== null ) {
			$queryBuilder->andWhere( $mimeExpr );
		}

		$rows = $queryBuilder->fetchResultSet();

		$jobs = [];
		$count = 0;
		foreach ( $rows as $row ) {
			$imgName = (string)$row->img_name;
			$title = Title::makeTitleSafe( NS_FILE, $imgName );
			if ( !$title ) {
				continue;
			}
			$jobs[] = new JobSpecification(
				'VaultTecMediaOptimizerOptimizeImage',
				[ 'imgName' => $imgName ],
				[ 'removeDuplicates' => true ],
				$title
			);
			$count++;
		}

		if ( $jobs ) {
			// Push in chunks to avoid hitting MySQL max_allowed_packet on
			// huge batches (the "Schedule all" button may push 18k+ jobs).
			// 500 jobs per push keeps each INSERT under a few hundred KB.
			$chunks = array_chunk( $jobs, 500 );
			foreach ( $chunks as $chunk ) {
				$this->jobQueueGroup->push( $chunk );
			}
			$this->logger->info( 'Scheduled {count} optimization jobs', [ 'count' => $count ] );
		}

		return $count;
	}

	/**
	 * Count of files still needing processing (estimate, may be slow on huge wikis).
	 */
	public function countPending(): int {
		$db = $this->connectionProvider->getReplicaDatabase();
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );

		$pendingExpr = $db->expr( 'io_status', '=', null )
			->orExpr( $db->expr( 'io_status', '=', OptimizationRecord::STATUS_PENDING ) );

		$queryBuilder = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'image' )
			->leftJoin( 'vtmo_image_optimization', null, 'io_img_name = img_name' )
			->where( $pendingExpr )
			->caller( __METHOD__ );

		$mimeExpr = $this->buildMimeExpression( $db, $allowedMimes );
		if ( $mimeExpr !== null ) {
			$queryBuilder->andWhere( $mimeExpr );
		}

		return (int)$queryBuilder->fetchField();
	}

	/**
	 * Count of files total eligible (regardless of status) by MIME.
	 */
	public function countTotalEligible(): int {
		$db = $this->connectionProvider->getReplicaDatabase();
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );

		$queryBuilder = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'image' )
			->caller( __METHOD__ );

		$mimeExpr = $this->buildMimeExpression( $db, $allowedMimes );
		if ( $mimeExpr !== null ) {
			$queryBuilder->where( $mimeExpr );
		}

		return (int)$queryBuilder->fetchField();
	}

	/**
	 * Build a SQL expression matching any of the given image MIME types
	 * against image.img_major_mime + image.img_minor_mime.
	 *
	/**
	 * Build a WHERE expression matching any of the configured MIME types.
	 *
	 * Generates SQL like:
	 *   (img_major_mime='image' AND img_minor_mime='png') OR (img_major_mime='image' AND img_minor_mime='jpeg') ...
	 *
	 * Note: we cannot use ->orExpr() on the AndExpressionGroup returned by
	 * ->and() — that method only exists on Expression and OrExpressionGroup.
	 * Instead we collect each (major AND minor) clause into an array and pass
	 * it to $db->orExpr(), which builds a top-level OR group from a list of
	 * IExpression instances.
	 *
	 * @param \Wikimedia\Rdbms\IReadableDatabase $db
	 * @param string[] $allowedMimes
	 * @return \Wikimedia\Rdbms\IExpression|null Null if no valid MIMEs given
	 */
	private function buildMimeExpression( $db, array $allowedMimes ) {
		$clauses = [];
		foreach ( $allowedMimes as $mime ) {
			[ $major, $minor ] = array_pad( explode( '/', $mime, 2 ), 2, '' );
			if ( $major === '' || $minor === '' ) {
				continue;
			}
			$clauses[] = $db->expr( 'img_major_mime', '=', $major )
				->and( 'img_minor_mime', '=', $minor );
		}

		if ( $clauses === [] ) {
			return null;
		}
		if ( count( $clauses ) === 1 ) {
			// Single clause: no OR wrapper needed
			return $clauses[0];
		}
		return $db->orExpr( $clauses );
	}

	/**
	 * Get number of currently queued jobs (not yet processed).
	 */
	public function getQueuedJobCount(): int {
		$queue = $this->jobQueueGroup->get( 'VaultTecMediaOptimizerOptimizeImage' );
		return $queue->getSize();
	}

	/**
	 * Schedule a batch of second-pass zopflipng recompression jobs for
	 * ORIGINAL PNGs that have been WebP-optimized but not yet recompressed.
	 *
	 * Distinct from scheduleBatch() (the WebP backfill): these jobs use a
	 * separate, slower queue so they never block WebP generation.
	 *
	 * @param int|null $batchSizeOverride Large value to schedule everything.
	 * @return int Number of jobs enqueued.
	 */
	public function scheduleZopfliBatch( ?int $batchSizeOverride = null ): int {
		if ( $batchSizeOverride !== null && $batchSizeOverride > 0 ) {
			$batchSize = $batchSizeOverride;
		} else {
			$batchSize = (int)$this->options->get( 'VaultTecMediaOptimizerBackfillBatchSize' );
			if ( $batchSize <= 0 ) {
				$batchSize = 20;
			}
		}

		// Pull eligible PNG originals (completed, not yet zopfli-recompressed).
		$names = $this->record->getPngPendingZopfli( $batchSize );

		$jobs = [];
		$count = 0;
		foreach ( $names as $imgName ) {
			$title = Title::makeTitleSafe( NS_FILE, $imgName );
			if ( !$title ) {
				continue;
			}
			$jobs[] = new JobSpecification(
				'VaultTecMediaOptimizerZopfliOptimize',
				[ 'imgName' => $imgName ],
				[ 'removeDuplicates' => true ],
				$title
			);
			$count++;
		}

		if ( $jobs ) {
			$chunks = array_chunk( $jobs, 500 );
			foreach ( $chunks as $chunk ) {
				$this->jobQueueGroup->push( $chunk );
			}
			$this->logger->info( 'Scheduled {count} zopfli recompression jobs', [ 'count' => $count ] );
		}

		return $count;
	}

	/**
	 * Count original PNGs still pending zopflipng recompression.
	 */
	public function countZopfliPending(): int {
		return $this->record->getZopfliOriginalsProgress()['pending'];
	}

	/**
	 * Get number of currently queued zopfli jobs.
	 */
	public function getQueuedZopfliJobCount(): int {
		$queue = $this->jobQueueGroup->get( 'VaultTecMediaOptimizerZopfliOptimize' );
		return $queue->getSize();
	}
}
