<?php
/**
 * Repair local image *originals* whose physical filename on disk no longer
 * matches the name MediaWiki has in the `image` table.
 *
 * Symptom: the optimizer (and MediaWiki itself) report
 *   "File not found on local filesystem at .../images/a/a6/Name.ext"
 * even though a file *is* physically present in that hash directory — but under
 * a mangled name (classic Unicode/mojibake damage from a past server migration,
 * FTP transfer or backup/restore that mis-encoded non-ASCII bytes). The image is
 * then broken on the wiki too, because MediaWiki computes the clean path and
 * cannot find the mis-named file.
 *
 * Strategy (deliberately conservative — never guesses an encoding):
 *   For each affected name we compute the directory and the expected clean
 *   basename, then look in that directory for a *single* physical file whose
 *   "ASCII skeleton" matches. The skeleton drops every byte >= 0x80 and every
 *   percent-escape (%XX), so "Protections_aiguisées.gif" and a file physically
 *   named "Protections_aiguis<garbage>es.gif" both reduce to
 *   "Protections_aiguises.gif" and match. The rename is applied ONLY when the
 *   match is unambiguous (exactly one candidate) and the clean target does not
 *   already exist. Ambiguous or unmatched cases are reported for manual review.
 *
 * Safe by default: dry-run unless --apply is given. Every applied rename is
 * appended to a log file so it can be reversed.
 *
 * Usage:
 *   php maintenance/run.php extensions/VaultTecMediaOptimizer/maintenance/repairCorruptedFilenames.php
 *   php maintenance/run.php .../repairCorruptedFilenames.php --format=gif
 *   php maintenance/run.php .../repairCorruptedFilenames.php --apply
 *
 * After a successful --apply, re-run optimizeImages.php (failed rows are retried
 * automatically, no --force needed) to optimize the now-reachable originals.
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

class RepairCorruptedFilenames extends Maintenance {

	/** @var bool Whether the reversal-log write failure was already reported. */
	private bool $loggedLogFailure = false;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'VaultTecMediaOptimizer' );
		$this->addDescription(
			'Repair image originals whose on-disk filename drifted from the DB name '
			. '(Unicode/mojibake from a past migration), which makes both MediaWiki and '
			. 'the optimizer report "file not found". Dry-run unless --apply.'
		);
		$this->addOption( 'apply', 'Actually rename the matched files (default: report only).' );
		$this->addOption( 'format',
			'Restrict to one format: gif, png, jpg/jpeg (or a full extension). Default: all.',
			false, true );
		$this->addOption( 'limit', 'Stop after this many repairs.', false, true );
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
		$repo = $services->getRepoGroup()->getLocalRepo();
		$uploadDir = rtrim( (string)$this->getConfig()->get( 'UploadDirectory' ), '/' );
		if ( $uploadDir === '' ) {
			$this->fatalError( 'UploadDirectory is empty; cannot locate originals.' );
		}

		$apply = $this->hasOption( 'apply' );
		$limit = $this->hasOption( 'limit' ) ? (int)$this->getOption( 'limit' ) : 0;
		$extFilter = $this->normaliseFormat( (string)$this->getOption( 'format', '' ) );

		// Source list: our own failures recorded as "File not found".
		$dbr = $this->getReplicaDB();
		$res = $dbr->select(
			'vtmo_image_optimization',
			[ 'io_img_name' ],
			[
				'io_status' => 'failed',
				'io_error' . $dbr->buildLike( 'File not found', $dbr->anyString() ),
			],
			__METHOD__,
			[ 'ORDER BY' => 'io_img_name' ]
		);

		$logPath = sys_get_temp_dir() . '/vtmo-filename-repairs-' . wfTimestampNow() . '.log';

		$scanned = 0;
		$repaired = 0;
		$ambiguous = 0;
		$nomatch = 0;
		$present = 0;

		foreach ( $res as $row ) {
			$name = $row->io_img_name;

			$file = $repo->newFile( $name );
			if ( !$file ) {
				continue;
			}
			$expected = $uploadDir . '/' . $file->getRel();
			$base = basename( $expected );

			// Format filter on the expected extension.
			if ( $extFilter !== null
				&& strtolower( pathinfo( $base, PATHINFO_EXTENSION ) ) !== $extFilter
			) {
				continue;
			}

			$scanned++;

			// Already reachable? Nothing to do (maybe fixed already).
			if ( is_file( $expected ) ) {
				$present++;
				continue;
			}

			$dir = dirname( $expected );
			$candidates = $this->findCandidates( $dir, $base );

			if ( count( $candidates ) === 0 ) {
				$nomatch++;
				$this->output( "[no-match]  $name\n" );
				$this->output( "            expected: $expected (missing, no look-alike on disk)\n" );
				continue;
			}
			if ( count( $candidates ) > 1 ) {
				$ambiguous++;
				$this->output( "[ambiguous] $name -> " . count( $candidates ) . " candidates, skipped:\n" );
				foreach ( $candidates as $c ) {
					$this->output( '              ' . $this->visible( $c ) . "\n" );
				}
				continue;
			}

			$candidate = $candidates[0];
			$from = $dir . '/' . $candidate;

			// Content check: a true mojibake victim is byte-identical to what
			// the DB recorded at upload time, so its sha1 must match img_sha1.
			// This turns a name heuristic into proof — without it, an unrelated
			// file sharing the ASCII skeleton would be installed under the
			// canonical name and served as the wrong image.
			$dbSha1 = method_exists( $file, 'getSha1' ) ? $file->getSha1() : '';
			if ( is_string( $dbSha1 ) && $dbSha1 !== '' ) {
				$diskSha1 = \Wikimedia\base_convert( (string)sha1_file( $from ), 16, 36, 31 );
				if ( $diskSha1 !== $dbSha1 ) {
					$nomatch++;
					$this->output( "[sha1-skip] $name\n" );
					$this->output( '              candidate ' . $this->visible( $candidate )
						. " has different content than the DB row records; not renamed\n" );
					continue;
				}
			}

			if ( !$apply ) {
				$this->output( "[would-fix] $name\n" );
				$this->output( '              from: ' . $this->visible( $candidate ) . "\n" );
				$this->output( "              to:   $base\n" );
				$repaired++;
			} else {
				// Re-check just before renaming: during a long --apply run the
				// clean name may have reappeared (e.g. someone re-uploaded the
				// broken image). rename() silently overwrites; don't clobber it.
				clearstatcache( true, $expected );
				if ( is_file( $expected ) ) {
					$present++;
					$this->output( "[exists]    $name reappeared on disk; candidate left untouched\n" );
					continue;
				}
				if ( @rename( $from, $expected ) ) {
					$repaired++;
					$logged = @file_put_contents( $logPath,
						$from . "\t=>\t" . $expected . "\n", FILE_APPEND );
					if ( $logged === false && !$this->loggedLogFailure ) {
						$this->loggedLogFailure = true;
						$this->output( "[warning]   cannot write the reversal log at $logPath — renames continue but are NOT being recorded\n" );
					}
					$this->output( "[fixed]     $name  <-  " . $this->visible( $candidate ) . "\n" );
				} else {
					$this->output( "[error]     could not rename for $name (permissions?)\n" );
				}
			}

			if ( $limit > 0 && $repaired >= $limit ) {
				$this->output( "Reached --limit=$limit, stopping.\n" );
				break;
			}
		}

		$this->output( "\n=== Summary ===\n" );
		$this->output( "scanned (missing originals): $scanned\n" );
		$this->output( "already present:             $present\n" );
		$this->output( ( $apply ? "renamed:                     " : "would rename:                " ) . "$repaired\n" );
		$this->output( "ambiguous (manual review):   $ambiguous\n" );
		$this->output( "no look-alike on disk:       $nomatch\n" );
		if ( $apply && $repaired > 0 ) {
			$this->output( "\nRename log (for reversal): $logPath\n" );
			$this->output( "Next: re-run optimizeImages.php (failed rows are retried automatically).\n" );
		} elseif ( !$apply && $repaired > 0 ) {
			$this->output( "\nThis was a DRY RUN. Re-run with --apply to perform the renames above.\n" );
		}
	}

	/**
	 * Physical files in $dir whose ASCII skeleton equals the clean basename's,
	 * excluding the clean name itself and anything that is not actually
	 * mis-encoded (a candidate must carry a non-ASCII byte or a %XX escape).
	 *
	 * @return string[] Raw on-disk basenames (byte strings).
	 */
	private function findCandidates( string $dir, string $base ): array {
		if ( !is_dir( $dir ) ) {
			return [];
		}
		$wanted = $this->skeleton( $base );
		$out = [];
		$entries = @scandir( $dir );
		if ( $entries === false ) {
			return [];
		}
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || $entry === $base ) {
				continue;
			}
			if ( !is_file( $dir . '/' . $entry ) ) {
				continue;
			}
			// Must look corrupted (otherwise a clean ASCII name can't share a
			// skeleton with a different ASCII name anyway).
			if ( !preg_match( '/[\x80-\xFF]/', $entry ) && strpos( $entry, '%' ) === false ) {
				continue;
			}
			if ( $this->skeleton( $entry ) === $wanted ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Reduce a filename to its ASCII skeleton: drop every byte >= 0x80 and every
	 * percent-escape (%XX). Operates on raw bytes (no /u), so each stray high
	 * byte of a mojibake'd name is removed individually.
	 */
	private function skeleton( string $name ): string {
		$s = preg_replace( '/%[0-9A-Fa-f]{2}/', '', $name );
		return preg_replace( '/[\x80-\xFF]/', '', (string)$s );
	}

	/** Render a raw byte filename printably (escape high bytes) for the report. */
	private function visible( string $name ): string {
		return preg_replace_callback( '/[\x00-\x1F\x80-\xFF]/',
			static fn ( $m ) => sprintf( '\\x%02X', ord( $m[0] ) ), $name );
	}

	/** Map a --format value to a canonical lowercase extension, or null. */
	private function normaliseFormat( string $fmt ): ?string {
		$fmt = strtolower( trim( $fmt ) );
		if ( $fmt === '' ) {
			return null;
		}
		return $fmt === 'jpeg' ? 'jpg' : $fmt;
	}
}

// @codeCoverageIgnoreStart
$maintClass = RepairCorruptedFilenames::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
