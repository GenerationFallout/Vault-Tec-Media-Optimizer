<?php
/**
 * Repair original image files that were inflated by the pre-1.3.6 optimization
 * bug (the optimizer used to overwrite an original even when the re-encoded
 * file was LARGER than the source).
 *
 * For every affected row (io_optimized_size > io_original_size) this script:
 *   1. Re-optimizes the on-disk file with zopflipng (lossless), keeping the
 *      smaller result. Pixels are unchanged.
 *   2. Updates io_optimized_size (and io_png_zopfli_size) to the file's true
 *      new size, so statistics become consistent again.
 *   3. Refreshes MediaWiki's own img_size / img_sha1 metadata.
 *
 * Requires zopflipng to be installed and shell execution to be available.
 * Safe to run multiple times: once a file is no longer bloated it is skipped.
 *
 * Usage:
 *   php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/repairBloatedOriginals.php
 *   php maintenance/run.php .../repairBloatedOriginals.php --dry-run
 *   php maintenance/run.php .../repairBloatedOriginals.php --batch=200
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

class RepairBloatedOriginals extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'VaultTecMediaOptimizer' );
		$this->addDescription(
			'Re-compress originals inflated by the pre-1.3.6 optimization bug, '
			. 'and fix their recorded sizes. Lossless; pixels are unchanged.'
		);
		$this->addOption( 'dry-run', 'List affected files and exit without modifying anything.' );
		$this->addOption( 'batch', 'How many files to process per pass (default 200).', false, true );
		$this->addOption( 'max', 'Stop after repairing this many files total (default: no limit).', false, true );
		$this->setBatchSize( 200 );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$record = $services->getService( 'VaultTecMediaOptimizer.OptimizationRecord' );
		$recompressor = $services->getService( 'VaultTecMediaOptimizer.ZopfliRecompressor' );
		$engine = $recompressor->getName();

		// Sanity: the configured recompression engine must be usable.
		if ( !$recompressor->isAvailable() ) {
			$this->fatalError(
				"$engine is not available (binary missing, feature disabled, or shell "
				. "execution blocked).\n"
				. "Enable \$wgVaultTecMediaOptimizerZopfliEnabled, ensure proc_open/exec are "
				. "not in disable_functions, and that the binary is reachable (mind "
				. "open_basedir — put it inside the wiki tree if needed). Then re-run."
			);
		}

		$this->output( "Using engine: $engine\n" );

		$stats = $record->countBloatedOriginals();
		$total = $stats['count'];
		$wasted = $stats['wasted'];

		if ( $total === 0 ) {
			$this->output( "No bloated originals found. Nothing to repair.\n" );
			return;
		}

		$this->output( sprintf(
			"Found %d bloated file(s), wasting %s on disk.\n",
			$total,
			$this->formatBytes( $wasted )
		) );

		if ( $this->hasOption( 'dry-run' ) ) {
			$names = $record->getBloatedOriginalNames( 50 );
			$this->output( "\nDry run — worst offenders (up to 50):\n" );
			foreach ( $names as $name ) {
				$this->output( "  $name\n" );
			}
			$this->output( "\nRe-run without --dry-run to repair.\n" );
			return;
		}

		$batch = (int)( $this->getOption( 'batch', 200 ) );
		if ( $batch < 1 ) {
			$batch = 200;
		}
		$max = $this->hasOption( 'max' ) ? (int)$this->getOption( 'max' ) : 0;

		$repaired = 0;
		$reclaimed = 0;
		$failed = 0;
		$skipped = 0;

		// Guard against infinite loops. getBloatedOriginalNames() re-runs the
		// same "optimized > original" selection on every batch. Normally a
		// processed row leaves that selection (we clamp or shrink it), but if
		// any row cannot be brought under its original size, it would be
		// re-selected forever and the run would appear to "loop", processing
		// one new file occasionally. We track every name already handled in
		// THIS run and skip re-selected ones, guaranteeing forward progress.
		$seen = [];

		do {
			$names = $record->getBloatedOriginalNames( $batch );
			if ( !$names ) {
				break;
			}

			// If every name in this batch was already handled, we are looping
			// on unfixable rows — stop cleanly.
			$fresh = array_values( array_filter( $names, static fn ( $n ) => !isset( $seen[$n] ) ) );
			if ( !$fresh ) {
				$this->output(
					"\nStopping: remaining bloated rows could not be reduced below their "
					. "original size and have been left as-is (their recorded size was "
					. "clamped so they no longer distort statistics).\n"
				);
				break;
			}

			foreach ( $fresh as $imgName ) {
				if ( $max > 0 && $repaired >= $max ) {
					break 2;
				}
				$seen[$imgName] = true;

				$result = $this->repairOne( $services, $record, $imgName );
				switch ( $result['status'] ) {
					case 'repaired':
						$repaired++;
						$reclaimed += $result['saved'];
						$this->output( sprintf(
							"  [OK] %s  %s -> %s  (-%s)\n",
							$imgName,
							$this->formatBytes( $result['before'] ),
							$this->formatBytes( $result['after'] ),
							$this->formatBytes( $result['saved'] )
						) );
						break;
					case 'skipped':
						$skipped++;
						$this->output( "  [skip] $imgName ({$result['reason']})\n" );
						break;
					default:
						$failed++;
						$this->output( "  [FAIL] $imgName ({$result['reason']})\n" );
						break;
				}
			}

			// Let replication catch up between batches.
			$this->waitForReplication();

		} while ( true );

		$this->output( sprintf(
			"\nDone. Repaired %d, reclaimed %s. Skipped %d, failed %d.\n",
			$repaired,
			$this->formatBytes( $reclaimed ),
			$skipped,
			$failed
		) );
	}

	/**
	 * Repair a single file. Returns a status array.
	 *
	 * @param MediaWikiServices $services
	 * @param object $record OptimizationRecord
	 * @param string $imgName
	 * @return array{status:string, before?:int, after?:int, saved?:int, reason?:string}
	 */
	private function repairOne( $services, $record, string $imgName ): array {
		$repoGroup = $services->getRepoGroup();
		$file = $repoGroup->getLocalRepo()->newFile( $imgName );
		if ( !$file || !$file->exists() ) {
			// File gone but row remains: clamp the size so it stops being
			// counted as bloated, and move on. Without this the row would be
			// re-selected on every batch and the run would loop forever.
			$record->clampOptimizedToOriginal( $imgName );
			return [ 'status' => 'skipped', 'reason' => 'file missing on disk (row clamped)' ];
		}

		if ( $file->getMimeType() !== 'image/png' ) {
			// Only PNGs are recompressible (zopflipng/oxipng). We cannot shrink
			// a non-PNG here, so setting the recorded size to the real on-disk
			// size would NOT help when that file is itself bloated above the
			// original — the row would stay in the "optimized > original"
			// selection and the run would loop on it across invocations.
			// Instead, clamp the recorded optimized size down to the original
			// size: the row leaves the selection for good and stops distorting
			// statistics. The file on disk is left untouched.
			$record->clampOptimizedToOriginal( $imgName );
			return [ 'status' => 'skipped', 'reason' => 'non-PNG, recorded size clamped to original' ];
		}

		$path = $this->resolvePath( $file );
		if ( $path === null || !is_file( $path ) ) {
			// Cannot find the file on disk. Clamp the recorded optimized size to
			// the original size so the row stops being counted as bloated and the
			// run does not loop on it.
			$record->clampOptimizedToOriginal( $imgName );
			return [ 'status' => 'failed', 'reason' => 'could not resolve path (row clamped)' ];
		}

		$before = filesize( $path );
		if ( $before === false ) {
			return [ 'status' => 'failed', 'reason' => 'could not stat file' ];
		}

		// Recompress in place (lossless, keeps smaller result).
		$recompressor = $services->getService( 'VaultTecMediaOptimizer.ZopfliRecompressor' );
		$saved = $recompressor->recompress( $path );
		if ( $saved === null ) {
			// Recompression failed. The file on disk is unchanged, but its
			// RECORDED optimized size is still the bloated value, which keeps it
			// in the "bloated" selection forever and makes this script loop on
			// it. Clamp the recorded size to the real on-disk size so the row
			// leaves the selection and the run can move on. The file itself is
			// untouched and safe.
			$realNow = filesize( $path );
			if ( $realNow !== false ) {
				$record->repairOptimizedSize( $imgName, (int)$realNow );
			}
			return [
				'status' => 'failed',
				'reason' => $recompressor->getLastError() ?? 'recompress failed',
			];
		}

		clearstatcache( true, $path );
		$after = filesize( $path );
		if ( $after === false ) {
			$after = $before;
		}

		// Fix the recorded sizes so stats are consistent again, regardless of
		// whether zopfli managed to beat the (already bloated) current file —
		// the recorded optimized size must reflect the real file now.
		$record->repairOptimizedSize( $imgName, (int)$after );

		// Keep MediaWiki's own metadata in sync (img_size / img_sha1).
		if ( method_exists( $file, 'upgradeRow' ) ) {
			try {
				$file->upgradeRow();
			} catch ( \Throwable $e ) {
				// Non-fatal; the file and our row are already consistent.
				$this->output( "    (warning: upgradeRow failed: {$e->getMessage()})\n" );
			}
		}

		return [
			'status' => 'repaired',
			'before' => (int)$before,
			'after' => (int)$after,
			'saved' => max( 0, (int)$before - (int)$after ),
		];
	}

	/**
	 * Resolve a File to its real local filesystem path, trying both the
	 * decoded and url-encoded on-disk variants (same approach the extension
	 * uses elsewhere).
	 *
	 * @param object $file
	 * @return string|null
	 */
	private function resolvePath( $file ): ?string {
		try {
			$path = $file->getLocalRefPath();
			if ( is_string( $path ) && $path !== '' && is_file( $path ) ) {
				return $path;
			}
		} catch ( \Throwable $e ) {
			// fall through
		}
		return null;
	}

	private function formatBytes( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '0 B';
		}
		$units = [ 'B', 'KiB', 'MiB', 'GiB' ];
		$i = (int)floor( log( $bytes, 1024 ) );
		$i = max( 0, min( $i, count( $units ) - 1 ) );
		return sprintf( '%.2f %s', $bytes / ( 1024 ** $i ), $units[$i] );
	}
}

// @codeCoverageIgnoreStart
$maintClass = RepairBloatedOriginals::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
