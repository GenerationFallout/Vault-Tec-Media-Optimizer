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

use MediaWiki\Extension\VaultTecMediaOptimizer\Service\TempFileSweeper;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Logger\LoggerFactory;
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
		$this->addOption( 'format',
			'Comma-separated formats to process this run (png, jpg/jpeg, gif, webp, or full '
			. 'MIME types). Restricts to the intersection with $wgVaultTecMediaOptimizerFormats. '
			. 'Default: all configured formats. Example: --format=gif',
			false, true );
		$this->addOption( 'batch', 'Rows to scan per pass (default 100).', false, true );
		$this->addOption( 'max', 'Stop after optimizing this many files (default: no limit).', false, true );
		$this->addOption( 'force', 'Reprocess files already marked complete/skipped too.' );
		$this->addOption( 'start', 'Resume scanning from this img_name (exclusive).', false, true );
		$this->addOption( 'no-temp-sweep',
			'Skip the opportunistic cleanup of temp files abandoned by a previously '
			. 'killed run (see maintenance/cleanupTempFiles.php).' );
		$this->addOption( 'purge-queue',
			'Empty the VTMO OptimizeImage job queue before scanning, to avoid redundant '
			. 'work after a previous Special:VTMOBackfill scheduling. The zopfli second-pass '
			. 'queue is left intact (the CLI run does not replace it).' );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		// Files this run rewrites in place (temp + rename) inherit THIS
		// process's owner. Run as root and every optimized file becomes
		// root-owned: the web server can then no longer rewrite it (future
		// optimizations and even MediaWiki reuploads fail with permission
		// errors, weeks later and silently). Warn loudly up front.
		if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ) {
			$this->output( "WARNING: running as root. Files written by this run will be owned by\n" );
			$this->output( "root and the web server will no longer be able to rewrite them.\n" );
			$this->output( "Run as the web server user instead, e.g.: sudo -u www-data php ...\n\n" );
		}
		$services = MediaWikiServices::getInstance();
		$config = $services->getService( 'VaultTecMediaOptimizer.Config' );

		// Opportunistic GC of temps abandoned by a previous killed run. They are
		// publicly fetchable copies of uploads that survive deletion of the file
		// they came from, so leaving them around is a privacy matter — and no
		// in-process handler can remove them (an external encoder reparented by
		// a SIGKILL writes its output after the parent is gone). Only our own
		// temp shapes older than an hour are touched; --no-temp-sweep skips it.
		if ( !$this->hasOption( 'no-temp-sweep' ) ) {
			$sweeper = new TempFileSweeper( LoggerFactory::getInstance( 'VaultTecMediaOptimizer' ) );
			$swept = $sweeper->sweep(
				rtrim( (string)$config->get( 'UploadDirectory' ), '/' )
			);
			if ( $swept['removed'] > 0 ) {
				$this->output( sprintf(
					"Reclaimed %d abandoned temp file(s) from a previous interrupted run (%d bytes).\n\n",
					$swept['removed'], $swept['bytes']
				) );
			}
		}

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

		// Optional --format filter: restrict this run to a subset of the
		// configured formats (e.g. only catch up GIFs). The intersection keeps
		// us from ever processing a MIME type the admin hasn't enabled.
		if ( $this->hasOption( 'format' ) ) {
			$requested = $this->parseFormatFilter( (string)$this->getOption( 'format' ) );
			if ( $requested === [] ) {
				$this->fatalError(
					"Invalid --format value. Use a comma-separated list of: png, jpg, jpeg, "
					. "gif, webp (or full MIME types like image/png)."
				);
			}
			$filtered = array_values( array_intersect( $allowedMimes, $requested ) );
			if ( $filtered === [] ) {
				$this->fatalError(
					"None of the requested --format types are enabled in "
					. "\$wgVaultTecMediaOptimizerFormats (" . implode( ', ', $allowedMimes ) . ").\n"
					. "Either pick from those, or add the type to the config first."
				);
			}
			$allowedMimes = $filtered;
			$this->output( 'Format filter: ' . implode( ', ', $allowedMimes ) . "\n" );
		}

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
	 * Parse the --format value into a list of canonical MIME types.
	 *
	 * Accepts short names (png, jpg, jpeg, gif, webp) and full MIME types
	 * (image/png, ...). Unknown tokens are ignored; an empty result signals an
	 * entirely invalid value to the caller.
	 *
	 * @param string $value Comma-separated list
	 * @return string[] Canonical MIME types (deduplicated)
	 */
	private function parseFormatFilter( string $value ): array {
		$aliases = [
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
		];
		$out = [];
		foreach ( explode( ',', $value ) as $token ) {
			$token = strtolower( trim( $token ) );
			if ( $token === '' ) {
				continue;
			}
			if ( isset( $aliases[$token] ) ) {
				$out[] = $aliases[$token];
			} elseif ( strpos( $token, '/' ) !== false ) {
				// Full MIME type passed through as-is.
				$out[] = $token;
			}
			// Anything else is ignored (invalid token).
		}
		return array_values( array_unique( $out ) );
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
