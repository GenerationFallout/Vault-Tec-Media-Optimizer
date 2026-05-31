<?php
/**
 * Run the second-pass PNG recompression over ORIGINAL files with the currently
 * configured engine (oxipng or zopflipng).
 *
 * Two intended uses:
 *
 *  1. First pass — recompress every PNG original that has never been through
 *     the second pass yet (io_png_zopfli_size IS NULL). This is what the
 *     "Start recompression" button on Special:VTMOBackfill schedules, exposed
 *     here as a blocking CLI run for convenience.
 *
 *  2. Chained engines — recompress with one engine, then re-run with another to
 *     squeeze out the last few percent. Because PNG is lossless, every engine
 *     re-encodes from the decoded pixels, so chaining does NOT accumulate: the
 *     final size is decided by the engine that writes last. Running oxipng
 *     (fast) and then zopflipng (slow, denser DEFLATE) therefore yields the same
 *     result as zopflipng alone, but lets you clear the bulk quickly first and
 *     finish with the stronger compressor only where it actually helps. Pass
 *     --reset to mark already-processed PNGs as pending again so the now-current
 *     engine reprocesses them.
 *
 * The recompressor keeps the new file ONLY if it is strictly smaller (built-in
 * keep-if-smaller guard), so a re-run can never enlarge a file: at worst the
 * row is re-marked with the same size. Pixels are never altered.
 *
 * This script handles ORIGINALS only. Thumbnails are recompressed on the fly by
 * the FileTransformed hook when MediaWiki (re)generates them; to re-run a new
 * engine over existing thumbnails, purge them so they regenerate (see the
 * "Maintenance" section of the documentation). There is intentionally no bulk
 * thumbnail re-walk here, as it would depend on fragile thumbnail enumeration.
 *
 * Usage:
 *   # First pass with the configured engine (e.g. oxipng):
 *   php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/recompressOriginals.php
 *
 *   # Later, switch the engine to zopflipng in LocalSettings, then finish:
 *   php maintenance/run.php .../recompressOriginals.php --reset
 *
 *   # Preview only:
 *   php maintenance/run.php .../recompressOriginals.php --dry-run
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

class RecompressOriginals extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'VaultTecMediaOptimizer' );
		$this->addDescription(
			'Second-pass lossless PNG recompression of ORIGINAL files with the '
			. 'configured engine (oxipng/zopflipng). Use --reset to re-run a '
			. 'different engine over files already processed by a previous one.'
		);
		$this->addOption(
			'reset',
			'Mark already-processed PNG originals as pending again, so the '
			. 'currently configured engine reprocesses them (for chaining engines).'
		);
		$this->addOption( 'dry-run', 'Report what would happen and exit without modifying anything.' );
		$this->addOption( 'batch', 'How many files to process per pass (default 100).', false, true );
		$this->addOption( 'max', 'Stop after processing this many files (default: no limit).', false, true );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$record = $services->getService( 'VaultTecMediaOptimizer.OptimizationRecord' );
		$recompressor = $services->getService( 'VaultTecMediaOptimizer.ZopfliRecompressor' );
		$processor = $services->getService( 'VaultTecMediaOptimizer.ZopfliOriginalProcessor' );
		$engine = $recompressor->getName();

		if ( !$recompressor->isAvailable() ) {
			$this->fatalError(
				"$engine is not available (binary missing, feature disabled, or shell "
				. "execution blocked).\n"
				. "Enable \$wgVaultTecMediaOptimizerZopfliEnabled, set "
				. "\$wgVaultTecMediaOptimizerPngEngine to the engine you want, ensure "
				. "proc_open/exec are not in disable_functions, and that the binary is "
				. "reachable (mind open_basedir — put it inside the wiki tree if needed)."
			);
		}

		$this->output( "Active engine: $engine\n" );

		// Optional: requeue already-processed PNGs for a second engine.
		if ( $this->hasOption( 'reset' ) ) {
			if ( $this->hasOption( 'dry-run' ) ) {
				$progress = $record->getZopfliOriginalsProgress();
				$this->output( sprintf(
					"[dry-run] --reset would re-mark %d already-processed PNG original(s) "
					. "as pending for re-processing by '%s'.\n",
					(int)$progress['done'],
					$engine
				) );
			} else {
				$n = $record->resetZopfliMarker();
				$this->output( sprintf(
					"Reset %d PNG original(s) to pending for re-processing by '%s'.\n",
					$n,
					$engine
				) );
			}
		}

		$progress = $record->getZopfliOriginalsProgress();
		$pending = (int)$progress['pending'];
		$this->output( sprintf(
			"%d PNG original(s) pending second-pass recompression.\n",
			$pending
		) );

		if ( $pending === 0 ) {
			$this->output( "Nothing to do.\n" );
			return;
		}

		if ( $this->hasOption( 'dry-run' ) ) {
			$sample = $record->getPngPendingZopfli( 20 );
			$this->output( "\nDry run — sample of files that would be processed (up to 20):\n" );
			foreach ( $sample as $name ) {
				$this->output( "  $name\n" );
			}
			$this->output( "\nRe-run without --dry-run to process.\n" );
			return;
		}

		$batch = (int)( $this->getOption( 'batch', 100 ) );
		if ( $batch < 1 ) {
			$batch = 100;
		}
		$max = $this->hasOption( 'max' ) ? (int)$this->getOption( 'max' ) : 0;

		$processed = 0;
		$failed = 0;

		// Snapshot of saved bytes before we start, so we can report the delta.
		$savedBefore = (int)$progress['saved'];

		// Guard against looping: processByName always marks the row (success
		// writes the new size; non-PNG/no-gain marks no-gain), so a processed
		// file leaves the pending selection. We still track names handled this
		// run as a belt-and-braces stop condition.
		$seen = [];

		do {
			$names = $record->getPngPendingZopfli( $batch );
			if ( !$names ) {
				break;
			}

			$fresh = array_values( array_filter( $names, static fn ( $n ) => !isset( $seen[$n] ) ) );
			if ( !$fresh ) {
				$this->output(
					"\nStopping: the remaining pending rows were already handled this run "
					. "but did not leave the selection (unexpected). Check the logs.\n"
				);
				break;
			}

			foreach ( $fresh as $imgName ) {
				if ( $max > 0 && $processed >= $max ) {
					break 2;
				}
				$seen[$imgName] = true;

				$ok = $processor->processByName( $imgName );
				if ( $ok ) {
					$processed++;
					if ( $processed % 50 === 0 ) {
						$this->output( "  …$processed processed\n" );
					}
				} else {
					$failed++;
					$this->output( "  [skip/fail] $imgName\n" );
				}
			}

			$this->waitForReplication();

		} while ( true );

		$after = $record->getZopfliOriginalsProgress();
		$reclaimedNow = max( 0, (int)$after['saved'] - $savedBefore );

		$this->output( sprintf(
			"\nDone with engine '%s'. Processed %d, skipped/failed %d.\n"
			. "Bytes reclaimed this run: %s. Cumulative second-pass savings: %s.\n",
			$engine,
			$processed,
			$failed,
			$this->formatBytes( $reclaimedNow ),
			$this->formatBytes( (int)$after['saved'] )
		) );

		if ( $this->hasOption( 'reset' ) ) {
			$this->output(
				"\nTip: to also refresh thumbnails with this engine, purge the affected "
				. "pages/files so MediaWiki regenerates their thumbnails (the "
				. "FileTransformed hook recompresses them with the current engine).\n"
			);
		}
	}

	/**
	 * Human-readable byte size.
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function formatBytes( int $bytes ): string {
		$units = [ 'B', 'KiB', 'MiB', 'GiB', 'TiB' ];
		$i = 0;
		$val = (float)$bytes;
		while ( $val >= 1024 && $i < count( $units ) - 1 ) {
			$val /= 1024;
			$i++;
		}
		return sprintf( '%.2f %s', $val, $units[$i] );
	}
}

// @codeCoverageIgnoreStart
$maintClass = RecompressOriginals::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
