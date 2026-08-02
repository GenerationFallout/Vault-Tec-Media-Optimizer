<?php
/**
 * Remove abandoned temp files left by this extension's optimizers.
 *
 * Why they exist: the optimizers write "<file>.<tool>.<pid>.<rand>.tmp" next to
 * their target and clean up on every normal and error path. A SIGKILL (OOM
 * killer, service stop, container eviction) bypasses all of those. Worse, the
 * heavy optimizers drive an EXTERNAL binary via proc_open: killing the PHP
 * parent does not kill that child, which is reparented and finishes writing its
 * output afterwards — so the temp can appear AFTER the parent died, where no
 * in-process handler could ever have removed it.
 *
 * That is not merely wasted disk. Temps live inside the web-served upload tree,
 * so an orphan is a complete, publicly downloadable copy of an upload that
 * survives deletion of the file it came from, at a guessable URL, with nothing
 * in MediaWiki referencing it.
 *
 * Safe by design: only this extension's own temp name shapes are considered,
 * and only when older than --min-age (default 3600s) so a running encode is
 * never raced. Dry-run unless --apply.
 *
 * Recommended: run from cron, e.g. hourly.
 *
 * Usage:
 *   php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/cleanupTempFiles.php
 *   php maintenance/run.php .../cleanupTempFiles.php --apply
 *   php maintenance/run.php .../cleanupTempFiles.php --apply --min-age=600
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
use MediaWiki\Logger\LoggerFactory;

class CleanupTempFiles extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'VaultTecMediaOptimizer' );
		$this->addDescription(
			'Remove abandoned optimizer temp files (left behind by SIGKILL, OOM, or a '
			. 'reparented external encoder). They are publicly fetchable copies of uploads, '
			. 'so this is a privacy matter, not just disk hygiene. Dry-run unless --apply.'
		);
		$this->addOption( 'apply', 'Actually delete (default: report only).' );
		$this->addOption( 'min-age',
			'Only remove temps older than this many seconds (default 3600). Guards against '
			. 'deleting a temp belonging to a running encode.', false, true );
		$this->addOption( 'verbose', 'List every path considered.' );
	}

	public function execute() {
		$apply = $this->hasOption( 'apply' );
		$verbose = $this->hasOption( 'verbose' );
		$minAge = $this->hasOption( 'min-age' )
			? max( 0, (int)$this->getOption( 'min-age' ) )
			: TempFileSweeper::DEFAULT_MIN_AGE;

		$config = $this->getConfig();
		$uploadDir = rtrim( (string)$config->get( 'UploadDirectory' ), '/' );
		if ( $uploadDir === '' ) {
			$this->fatalError( 'UploadDirectory is empty; nothing to scan.' );
		}

		// Scan the upload tree (where in-place optimizer temps land) and the
		// derived tree (where atomic WebP publishes land).
		$roots = [ $uploadDir ];
		$webpDirName = (string)$config->get( 'VaultTecMediaOptimizerWebPDirectory' );
		if ( $webpDirName !== '' ) {
			$parent = dirname( $uploadDir );
			$derived = ( $parent === '/' || $parent === '.' ? '' : $parent ) . '/' . $webpDirName;
			if ( is_dir( $derived ) ) {
				$roots[] = $derived;
			}
		}

		$sweeper = new TempFileSweeper( LoggerFactory::getInstance( 'VaultTecMediaOptimizer' ) );

		$totalFound = 0;
		$totalRemoved = 0;
		$totalBytes = 0;
		$totalFailed = 0;

		foreach ( $roots as $root ) {
			$r = $sweeper->sweep( $root, $minAge, !$apply );
			$totalFound += $r['found'];
			$totalRemoved += $r['removed'];
			$totalBytes += $r['bytes'];
			$totalFailed += $r['failed'];
			$this->output( sprintf(
				"%-40s found=%d, %s=%d\n",
				$root, $r['found'], $apply ? 'removed' : 'would remove', $r['removed']
			) );
			if ( $verbose ) {
				foreach ( $r['paths'] as $p ) {
					$this->output( "    $p\n" );
				}
			}
		}

		$this->output( "\n=== Summary ===\n" );
		$this->output( "temp files found:        $totalFound\n" );
		$this->output( ( $apply ? "removed:                 " : "would remove:            " )
			. "$totalRemoved\n" );
		$this->output( 'reclaimed:               ' . $this->fmt( $totalBytes ) . "\n" );
		if ( $totalFailed > 0 ) {
			$this->output( "could not remove:        $totalFailed (check permissions)\n" );
		}
		if ( !$apply && $totalRemoved > 0 ) {
			$this->output( "\nThis was a DRY RUN. Re-run with --apply to delete them.\n" );
		}
		if ( $totalFound > $totalRemoved ) {
			$this->output( "\nNote: " . ( $totalFound - $totalRemoved )
				. " temp file(s) are newer than --min-age={$minAge}s and were left alone "
				. "(they may belong to a running optimization).\n" );
		}
	}

	private function fmt( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}
}

// @codeCoverageIgnoreStart
$maintClass = CleanupTempFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
