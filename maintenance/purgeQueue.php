<?php
/**
 * Purge the VaultTecMediaOptimizer job queues.
 *
 * Useful when switching a large wiki to CLI-driven optimization
 * ($wgVaultTecMediaOptimizerUseJobQueue = false): a previous "Schedule all" on
 * Special:VTMOBackfill may have left tens of thousands of jobs queued. Running
 * the CLI optimizer (optimizeImages.php) would then do the work while those jobs
 * later re-run it (idempotently, but wastefully). This clears them in one go.
 *
 * By default only the primary OptimizeImage queue is purged. Use --include-zopfli
 * to also clear the second-pass (zopfli/oxipng) queue.
 *
 * Usage:
 *   php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/purgeQueue.php
 *   php maintenance/run.php .../purgeQueue.php --dry-run
 *   php maintenance/run.php .../purgeQueue.php --include-zopfli
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

use MediaWiki\MediaWikiServices;

class PurgeQueue extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'VaultTecMediaOptimizer' );
		$this->addDescription(
			'Purge VaultTecMediaOptimizer job queues (OptimizeImage by default). '
			. 'Handy before/after a CLI-driven backfill so queued jobs do not redo the work.'
		);
		$this->addOption( 'dry-run', 'Report queued counts and exit without deleting.' );
		$this->addOption( 'include-zopfli', 'Also purge the second-pass (zopfli/oxipng) queue.' );
	}

	public function execute() {
		$group = MediaWikiServices::getInstance()->getJobQueueGroup();

		$queues = [ 'VaultTecMediaOptimizerOptimizeImage' => 'OptimizeImage' ];
		if ( $this->hasOption( 'include-zopfli' ) ) {
			$queues['VaultTecMediaOptimizerZopfliOptimize'] = 'ZopfliOptimize (second pass)';
		}

		$dryRun = $this->hasOption( 'dry-run' );
		$total = 0;

		foreach ( $queues as $type => $label ) {
			$queue = $group->get( $type );
			$size = $queue->getSize();
			$total += $size;

			if ( $dryRun ) {
				$this->output( "[dry-run] $label: $size job(s) would be purged.\n" );
				continue;
			}
			if ( $size === 0 ) {
				$this->output( "$label: already empty.\n" );
				continue;
			}
			try {
				$queue->delete();
				$this->output( "$label: purged $size job(s).\n" );
			} catch ( \Throwable $e ) {
				$this->output( "$label: could not purge ({$e->getMessage()}).\n" );
				$this->output(
					"  The queue backend may not support deletion. The jobs are idempotent, so "
					. "you can also just let them drain (they re-run as no-ops).\n"
				);
			}
		}

		if ( $dryRun ) {
			$this->output( "\nDry run: $total job(s) total would be purged.\n" );
		} else {
			$this->output( "\nDone.\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = PurgeQueue::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
