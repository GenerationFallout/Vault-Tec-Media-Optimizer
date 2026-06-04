<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer;

use Throwable;

/**
 * Atomic-write helpers shared by the optimizer backends.
 *
 * Two goals, both about safe behaviour under concurrency (e.g. the CLI backfill
 * running while the job queue processes the same file, or several first page
 * views generating the same uncached thumbnail at once):
 *
 *  1. Unique temp names — never a fixed "<file>.tmp" that two concurrent
 *     operations on the same target could write into simultaneously (which could
 *     interleave bytes and, after a rename, corrupt the destination).
 *  2. Atomic publish — write to that temp, then rename() onto the destination.
 *     A reader therefore always sees either the previous complete file or the
 *     new complete file, never a half-written one.
 */
trait AtomicWriteTrait {

	/**
	 * A unique temp path next to $finalPath (same directory => same filesystem,
	 * so the follow-up rename is atomic). Unique per process and call.
	 *
	 * @param string $finalPath
	 * @return string
	 */
	private function uniqueTempPath( string $finalPath ): string {
		try {
			$rand = bin2hex( random_bytes( 6 ) );
		} catch ( Throwable $e ) {
			$rand = (string)mt_rand();
		}
		return $finalPath . '.vtmo.' . getmypid() . '.' . $rand . '.tmp';
	}

	/**
	 * Atomically move $tmp onto $dest. Requires $tmp to be a non-empty file.
	 * On any failure the temp is removed and $dest is left untouched.
	 *
	 * @param string $tmp
	 * @param string $dest
	 * @return bool True if $dest now holds the new content
	 */
	private function publishAtomically( string $tmp, string $dest ): bool {
		if ( !is_file( $tmp ) || filesize( $tmp ) === 0 ) {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp );
			}
			return false;
		}
		if ( !@rename( $tmp, $dest ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}
}
