<?php
/**
 * Optimize already-uploaded images directly from the CLI, WITHOUT going through
 * the job queue.
 *
 * Special:VTMOBackfill schedules one job per file; on a large wiki that means
 * enqueuing (and later dequeuing/deserializing) tens of thousands of jobs, which
 * is heavy on the queue. This script does the same first-pass optimization +
 * WebP generation (the exact ImageProcessor pipeline that uploads and backfill
 * jobs use), but inline and sequentially over SSH — lighter and faster for bulk
 * runs, and it honours the configured image engine (Imagick/GD or libvips).
 *
 * It processes every eligible file by MIME type that is not already 'complete'
 * or 'skipped' (use --force to reprocess those too). Pagination is by img_name
 * so progress is guaranteed and the run is resumable.
 *
 * Usage:
 *   php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/optimizeImages.php
 *   php maintenance/run.php .../optimizeImages.php --dry-run
 *   php maintenance/run.php .../optimizeImages.php --batch=200 --max=5000
 *   php maintenance/run.php .../optimizeImages.php --force          # redo complete rows too
 *   php maintenance/run.php .../optimizeImages.php --start=Foo.png  # resume from a name
 *
 * To use libvips for this run, set in LocalSettings.php beforehand:
 *   $wgVaultTecMediaOptimizerImageEngine = 'vips';
 *
 * @license GPL-2.0-or-later
 */

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class OptimizeImages extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'VaultTecMediaOptimizer' );
		$this->addDescription(
			'Optimize already-uploaded images inline (first pass + WebP) without using '
			. 'the job queue. Honours $wgVaultTecMediaOptimizerImageEngine (imagick/gd/vips).'
		);
		$this->addOption( 'dry-run', 'Count eligible files and exit without modifying anything.' );
		$this->addOption( 'batch', 'Rows to scan per pass (default 100).', false, true );
		$this->addOption( 'max', 'Stop after optimizing this many files (default: no limit).', false, true );
		$this->addOption( 'force', 'Reprocess files already marked complete/skipped too.' );
		$this->addOption( 'start', 'Resume scanning from this img_name (exclusive).', false, true );
		$this->addOption( 'purge-queue',
			'Empty the VTMO OptimizeImage job queue before scanning, to avoid redundant '
			. 'work after a previous Special:VTMOBackfill scheduling. The zopfli second-pass '
			. 'queue is left intact (the CLI run does not replace it).' );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getService( 'VaultTecMediaOptimizer.Config' );

		if ( !$config->get( 'VaultTecMediaOptimizerEnabled' ) ) {
			$this->fatalError(
				"VaultTecMediaOptimizer is disabled (\$wgVaultTecMediaOptimizerEnabled = false). "
				. "Enable it before running this script."
			);
		}

		// Report (and validate) the backend that will be used.
		$factory = $services->getService( 'VaultTecMediaOptimizer.OptimizerFactory' );
		try {
			$engine = $factory->getOptimizer()->getName();
		} catch ( \Throwable $e ) {
			$this->fatalError( 'No usable image backend: ' . $e->getMessage() );
		}
		$this->output( "Image engine in use: $engine\n" );

		$processor = $services->getService( 'VaultTecMediaOptimizer.ImageProcessor' );
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();

		$allowedMimes = $config->get( 'VaultTecMediaOptimizerFormats' );
		$mimeExpr = $this->buildMimeExpression( $dbr, $allowedMimes );
		if ( $mimeExpr === null ) {
			$this->fatalError( 'No valid MIME types configured in $wgVaultTecMediaOptimizerFormats.' );
		}

		$batch = (int)$this->getOption( 'batch', 100 );
		if ( $batch < 1 ) {
			$batch = 100;
		}
		$max = $this->hasOption( 'max' ) ? (int)$this->getOption( 'max' ) : 0;
		$force = $this->hasOption( 'force' );
		$dryRun = $this->hasOption( 'dry-run' );
		$lastName = (string)$this->getOption( 'start', '' );

		// Optionally clear the queued OptimizeImage jobs first, so a previously
		// scheduled backfill doesn't redo (idempotently but wastefully) what this
		// CLI run is about to do. The zopfli second-pass queue is left untouched.
		if ( $this->hasOption( 'purge-queue' ) ) {
			$this->purgeOptimizeQueue( $services, $dryRun );
		}

		$done = 0;
		$failed = 0;
		$skippedExisting = 0;
		$eligible = 0;

		do {
			$qb = $dbr->newSelectQueryBuilder()
				->select( [ 'img_name', 'io_status' ] )
				->from( 'image' )
				->leftJoin( 'vtmo_image_optimization', null, 'io_img_name = img_name' )
				->where( $mimeExpr )
				->orderBy( 'img_name', SelectQueryBuilder::SORT_ASC )
				->limit( $batch )
				->caller( __METHOD__ );
			if ( $lastName !== '' ) {
				$qb->andWhere( $dbr->expr( 'img_name', '>', $lastName ) );
			}
			$rows = $qb->fetchResultSet();
			if ( !$rows->numRows() ) {
				break;
			}

			foreach ( $rows as $row ) {
				$imgName = (string)$row->img_name;
				// Pagination cursor advances on EVERY scanned row (processed or
				// not), so the run always makes forward progress and terminates,
				// even with --force.
				$lastName = $imgName;

				$status = $row->io_status !== null ? (string)$row->io_status : null;
				$alreadyDone = ( $status === OptimizationRecord::STATUS_COMPLETE
					|| $status === OptimizationRecord::STATUS_SKIPPED );

				if ( !$force && $alreadyDone ) {
					$skippedExisting++;
					continue;
				}

				if ( $dryRun ) {
					$eligible++;
					continue;
				}

				// Isolate each file: an exception escaping ImageProcessor (e.g. from
				// newFile()/exists() which run outside its own try/catch) must not
				// abort the whole run. Log it, count it, and carry on — the run
				// stays resumable via --start regardless.
				try {
					$result = $processor->processByName( $imgName );
				} catch ( \Throwable $e ) {
					$failed++;
					$this->output( "  [ERR]  $imgName ({$e->getMessage()})\n" );
					continue;
				}

				if ( $result ) {
					$done++;
					$this->output( "  [OK]   $imgName\n" );
				} else {
					// ImageProcessor recorded the skip/failure reason in the DB.
					$failed++;
					$this->output( "  [miss] $imgName (skipped or failed; see Special:VTMOStats)\n" );
				}

				if ( $max > 0 && $done >= $max ) {
					$this->output( "\nReached --max=$max; stopping.\n" );
					break 2;
				}
			}

			if ( !$dryRun ) {
				$this->waitForReplication();
			}
		} while ( true );

		if ( $dryRun ) {
			$this->output( "\nDry run: $eligible file(s) would be optimized"
				. ( $force ? ' (--force: includes already-complete rows).' : '.' ) . "\n" );
			return;
		}

		$this->output( sprintf(
			"\nDone. Optimized %d, skipped/failed %d, already-done skipped %d.\n",
			$done,
			$failed,
			$skippedExisting
		) );
	}

	/**
	 * Empty the VTMO OptimizeImage job queue (unclaimed + delayed jobs). Leaves
	 * the separate zopfli second-pass queue intact, since this CLI run does not
	 * perform that pass. In --dry-run we only report the count.
	 *
	 * @param MediaWikiServices $services
	 * @param bool $dryRun
	 */
	private function purgeOptimizeQueue( $services, bool $dryRun ): void {
		$queue = $services->getJobQueueGroup()->get( 'VaultTecMediaOptimizerOptimizeImage' );
		$size = $queue->getSize();

		if ( $dryRun ) {
			$this->output( "Dry run: would purge $size queued OptimizeImage job(s) "
				. "(zopfli second-pass queue left intact).\n" );
			return;
		}
		if ( $size === 0 ) {
			$this->output( "Job queue already empty (no OptimizeImage jobs to purge).\n" );
			return;
		}
		try {
			$queue->delete();
			$this->output( "Purged $size queued OptimizeImage job(s) before the CLI run "
				. "(zopfli second-pass queue left intact).\n" );
		} catch ( \Throwable $e ) {
			$this->fatalError(
				'Could not purge the job queue: ' . $e->getMessage() . "\n"
				. '(The queue backend may not support deletion. You can let the jobs drain '
				. 'instead — they are idempotent and will simply re-run as no-ops.)'
			);
		}
	}

	/**
	 * Build a WHERE expression matching any configured MIME against
	 * image.img_major_mime + image.img_minor_mime. Mirrors BackfillScheduler.
	 *
	 * @param IReadableDatabase $db
	 * @param string[] $allowedMimes
	 * @return \Wikimedia\Rdbms\IExpression|null
	 */
	private function buildMimeExpression( IReadableDatabase $db, array $allowedMimes ) {
		$clauses = [];
		foreach ( $allowedMimes as $mime ) {
			[ $major, $minor ] = array_pad( explode( '/', (string)$mime, 2 ), 2, '' );
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
			return $clauses[0];
		}
		return $db->orExpr( $clauses );
	}
}

// @codeCoverageIgnoreStart
$maintClass = OptimizeImages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
