<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Storage;

use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\RawSQLValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Storage layer for the vtmo_image_optimization table.
 *
 * Tracks which files have been processed, gains, and errors.
 *
 * Stats reads are cached via WAN cache for {@see self::STATS_TTL} seconds
 * since the dashboard auto-refreshes and we don't want to hammer the DB.
 */
class OptimizationRecord {

	public const STATUS_PENDING = 'pending';
	public const STATUS_COMPLETE = 'complete';
	public const STATUS_FAILED = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	private const TABLE = 'vtmo_image_optimization';
	private const THUMB_STATS_TABLE = 'vtmo_zopfli_thumb_stats';

	/** Cache TTL for stats queries (seconds). Short enough that the dashboard
	 *  feels live, long enough to shave 80% of DB queries on auto-refresh.
	 */
	private const STATS_TTL = 15;
	private const STATS_CACHE_KEY = 'vtmo-stats-v1';

	private IConnectionProvider $connectionProvider;
	private WANObjectCache $cache;
	private LoggerInterface $logger;

	public function __construct(
		IConnectionProvider $connectionProvider,
		WANObjectCache $cache,
		LoggerInterface $logger
	) {
		$this->connectionProvider = $connectionProvider;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	private function getReplica(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase();
	}

	private function getPrimary(): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase();
	}

	/**
	 * Has the given image already been processed (any status)?
	 */
	public function exists( string $imgName ): bool {
		$row = $this->getReplica()->newSelectQueryBuilder()
			->select( 'io_img_name' )
			->from( self::TABLE )
			->where( [ 'io_img_name' => $imgName ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row !== false;
	}

	/**
	 * Get the status of a given image, or null if not yet recorded.
	 */
	public function getStatus( string $imgName ): ?string {
		$status = $this->getReplica()->newSelectQueryBuilder()
			->select( 'io_status' )
			->from( self::TABLE )
			->where( [ 'io_img_name' => $imgName ] )
			->caller( __METHOD__ )
			->fetchField();
		return $status === false ? null : (string)$status;
	}

	/**
	 * Mark an image as successfully processed.
	 */
	public function markComplete(
		string $imgName,
		int $originalSize,
		int $optimizedSize,
		int $webpSize,
		string $backend
	): void {
		$db = $this->getPrimary();
		// Upsert rather than REPLACE: REPLACE is a DELETE+INSERT, so every column
		// we don't list is silently reset to its schema default. We list all of
		// them here, including explicitly clearing io_png_zopfli_size — a freshly
		// (re)optimized original has not been second-pass recompressed yet, so it
		// must go back to "pending zopfli". Making that explicit avoids relying on
		// REPLACE's reset-to-default side effect.
		$row = [
			'io_status' => self::STATUS_COMPLETE,
			'io_original_size' => $originalSize,
			'io_optimized_size' => $optimizedSize,
			'io_webp_size' => $webpSize,
			'io_processed_at' => $db->timestamp(),
			'io_backend' => $backend,
			'io_error' => null,
			'io_png_zopfli_size' => null,
		];
		// On UPDATE, preserve two columns that must NOT be clobbered by a
		// re-processing of an already-known file:
		//
		//  - io_original_size: $originalSize is the size on disk RIGHT NOW,
		//    which for a file we already optimized is the *optimized* size.
		//    Overwriting would erase the true pre-optimization baseline and make
		//    Special:VTMOStats report ~0 savings for a file that really did save.
		//  - io_png_zopfli_size: resetting it to NULL puts the row back into the
		//    "pending second pass" selection, so a file that already went through
		//    zopflipng is recompressed again on every backfill wave — ~31 s per
		//    file for 0 bytes gained, forever.
		//
		// Both were reproduced by running the optimize job after the zopfli job.
		// A genuine NEW version is handled elsewhere: the reupload path drops the
		// row entirely, so the next markComplete() inserts a fresh baseline.
		$update = $row;
		unset( $update['io_original_size'], $update['io_png_zopfli_size'] );

		$db->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( [ 'io_img_name' => $imgName ] + $row )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'io_img_name' ] )
			->set( $update )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Mark an image as failed with an error message.
	 */
	public function markFailed( string $imgName, string $error ): void {
		$db = $this->getPrimary();
		// Truncate error to fit BLOB (65530)
		if ( strlen( $error ) > 65000 ) {
			$error = substr( $error, 0, 65000 ) . '...';
		}
		// Upsert (not REPLACE): preserve any previously recorded sizes/backend so
		// a later failure doesn't erase the diagnostic history of a row that had
		// already been processed. Only status, error and timestamp change.
		$set = [
			'io_status' => self::STATUS_FAILED,
			'io_processed_at' => $db->timestamp(),
			'io_error' => $error,
		];
		$db->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( [ 'io_img_name' => $imgName ] + $set )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'io_img_name' ] )
			->set( $set )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Mark an image as skipped (e.g. unsupported format).
	 */
	public function markSkipped( string $imgName, string $reason ): void {
		$db = $this->getPrimary();
		// Upsert (not REPLACE): same rationale as markFailed() — keep any
		// previously recorded sizes/backend instead of wiping them to NULL.
		$set = [
			'io_status' => self::STATUS_SKIPPED,
			'io_processed_at' => $db->timestamp(),
			'io_error' => $reason,
		];
		$db->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( [ 'io_img_name' => $imgName ] + $set )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'io_img_name' ] )
			->set( $set )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Remove a record (used on file deletion).
	 */
	public function delete( string $imgName ): void {
		$this->getPrimary()->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'io_img_name' => $imgName ] )
			->caller( __METHOD__ )
			->execute();
		// Discrete, user-triggered action (a file was deleted): invalidating is
		// cheap here and the dashboard should reflect it at once. Contrast with
		// the per-file mark* methods below, which deliberately do NOT invalidate.
		$this->invalidateStatsCache();
	}

	/**
	 * Reset all 'failed' rows back to 'pending' so they get retried by the
	 * next backfill batch. Returns the number of rows reset.
	 *
	 * Skipped rows are NOT reset (they were skipped for a stable reason like
	 * unsupported MIME type).
	 */
	public function resetFailedToPending(): int {
		$db = $this->getPrimary();
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'io_status' => self::STATUS_PENDING,
				'io_error' => null,
			] )
			->where( [ 'io_status' => self::STATUS_FAILED ] )
			->caller( __METHOD__ )
			->execute();
		$count = $db->affectedRows();
		$this->invalidateStatsCache();
		return $count;
	}

	/**
	 * Get global stats for the dashboard. Cached for {@see self::STATS_TTL}
	 * seconds to avoid hammering the DB on dashboard auto-refresh.
	 *
	 * @return array{
	 *     total: int, complete: int, failed: int, skipped: int, pending: int,
	 *     total_original_size: int, total_optimized_size: int, total_webp_size: int,
	 *     by_backend: array<string, int>
	 * }
	 */
	public function getStats(): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( self::STATS_CACHE_KEY ),
			self::STATS_TTL,
			function () {
				return $this->computeStats();
			}
		);
	}

	/**
	 * Invalidate the stats cache.
	 *
	 * Called ONLY from discrete, user-triggered actions (a file deletion, the
	 * "reset failed" button) — never from the per-file write path.
	 *
	 * Why not on every write: markComplete/markFailed/markSkipped (and the
	 * second-pass markers) run once per FILE during a backfill, i.e. thousands
	 * of times in a row. WANObjectCache::delete() is a broadcast purge that also
	 * opens a hold-off window during which getWithSetCallback refuses to store a
	 * value — so invalidating there would keep the cache permanently held off
	 * for the whole duration of a backfill, and the auto-refreshing dashboard
	 * would recompute the full aggregate queries on every single load. That is
	 * the exact opposite of what this cache is for. The {@see self::STATS_TTL}
	 * second TTL is what keeps those numbers fresh, and it is enough: the
	 * dashboard is a progress indicator, not an accounting ledger.
	 */
	public function invalidateStatsCache(): void {
		$this->cache->delete( $this->cache->makeKey( self::STATS_CACHE_KEY ) );
	}

	/**
	 * The actual stats computation (uncached). Queries: counts by status,
	 * sums of sizes for completed rows, counts by backend, and gains grouped
	 * by file format. All queries hit indexed columns or simple aggregates,
	 * so they stay in the millisecond range even on tens of thousands of rows.
	 * Nothing here touches the filesystem.
	 */
	private function computeStats(): array {
		$db = $this->getReplica();

		$counts = $db->newSelectQueryBuilder()
			->select( [ 'io_status', 'cnt' => 'COUNT(*)' ] )
			->from( self::TABLE )
			->groupBy( 'io_status' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$result = [
			'total' => 0,
			'complete' => 0,
			'failed' => 0,
			'skipped' => 0,
			'pending' => 0,
			'total_original_size' => 0,
			'total_optimized_size' => 0,
			'total_webp_size' => 0,
			'total_final_size' => 0,
			'by_backend' => [],
			'by_format' => [],
			'lossless_saved' => 0,
			'secondpass_saved' => 0,
			'total_saved' => 0,
			'lossless_ratio' => 0.0,
			'total_ratio' => 0.0,
			'webp_ratio' => 0.0,
			'webp_bandwidth_saved' => 0,
		];

		foreach ( $counts as $row ) {
			$status = (string)$row->io_status;
			$cnt = (int)$row->cnt;
			$result['total'] += $cnt;
			if ( isset( $result[$status] ) ) {
				$result[$status] = $cnt;
			}
		}

		// Sum sizes only on complete rows. We also sum the REAL current size of
		// each file: io_png_zopfli_size when a second pass ran, otherwise
		// io_optimized_size. COALESCE is supported by MariaDB/MySQL/SQLite/PostgreSQL.
		$sums = $db->newSelectQueryBuilder()
			->select( [
				'orig' => 'SUM(io_original_size)',
				'opt' => 'SUM(io_optimized_size)',
				'webp' => 'SUM(io_webp_size)',
				'final' => 'SUM(COALESCE(io_png_zopfli_size, io_optimized_size))',
			] )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $sums ) {
			$result['total_original_size'] = (int)$sums->orig;
			$result['total_optimized_size'] = (int)$sums->opt;
			$result['total_webp_size'] = (int)$sums->webp;
			$result['total_final_size'] = (int)$sums->final;
		}

		// Derived metrics — pure arithmetic on the sums above, no extra query.
		$orig = $result['total_original_size'];
		$opt = $result['total_optimized_size'];
		$webp = $result['total_webp_size'];
		$final = $result['total_final_size'];

		// Three complementary savings figures, each measuring a distinct stage:
		//   - first pass (upload -> Imagick):     original - optimized
		//   - second pass (Imagick -> oxipng/zopfli): optimized - final
		//   - TOTAL to date (upload -> real file): original - final
		// 1st pass
		$result['lossless_saved'] = max( 0, $orig - $opt );
		// 2nd pass
		$result['secondpass_saved'] = max( 0, $opt - $final );
		// total
		$result['total_saved'] = max( 0, $orig - $final );
		$result['lossless_ratio'] = $orig > 0 ? round( $opt / $orig, 4 ) : 0.0;
		$result['total_ratio'] = $orig > 0 ? round( $final / $orig, 4 ) : 0.0;
		$result['webp_ratio'] = $opt > 0 ? round( $webp / $opt, 4 ) : 0.0;
		$result['webp_bandwidth_saved'] = max( 0, $opt - $webp );

		$byBackend = $db->newSelectQueryBuilder()
			->select( [ 'io_backend', 'cnt' => 'COUNT(*)' ] )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->groupBy( 'io_backend' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $byBackend as $row ) {
			$backend = (string)$row->io_backend;
			if ( $backend !== '' ) {
				$result['by_backend'][$backend] = (int)$row->cnt;
			}
		}

		// Gains grouped by file format. We deliberately do NOT compute the
		// extension in SQL: the previous SUBSTRING_INDEX() approach is
		// MySQL/MariaDB-only — it throws on SQLite and is non-standard on
		// PostgreSQL, which broke this entire stats page on non-MySQL backends.
		// Instead we read the completed rows and group by extension in PHP, which
		// is portable across every backend MediaWiki supports. Cost stays modest:
		// we only ever scan rows that are already 'complete', the same set the
		// aggregate queries above touch.
		$formatRows = $db->newSelectQueryBuilder()
			->select( [ 'io_img_name', 'io_original_size', 'io_optimized_size', 'io_webp_size' ] )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $formatRows as $row ) {
			$ext = strtolower( pathinfo( (string)$row->io_img_name, PATHINFO_EXTENSION ) );
			if ( $ext === '' ) {
				continue;
			}
			$fmt = $this->normalizeFormatLabel( $ext );
			if ( !isset( $result['by_format'][$fmt] ) ) {
				$result['by_format'][$fmt] = [
					'count' => 0, 'original' => 0, 'optimized' => 0, 'webp' => 0,
				];
			}
			$result['by_format'][$fmt]['count'] += 1;
			$result['by_format'][$fmt]['original'] += (int)$row->io_original_size;
			$result['by_format'][$fmt]['optimized'] += (int)$row->io_optimized_size;
			$result['by_format'][$fmt]['webp'] += (int)$row->io_webp_size;
		}

		return $result;
	}

	/**
	 * Map a raw file extension to a normalized format label for grouping.
	 */
	private function normalizeFormatLabel( string $ext ): string {
		switch ( $ext ) {
			case 'jpg':
			case 'jpeg':
			case 'jpe':
				return 'JPEG';
			case 'png':
			case 'apng':
				return 'PNG';
			case 'gif':
				return 'GIF';
			default:
				return strtoupper( $ext );
		}
	}

	/**
	 * Record the result of a second-pass zopflipng recompression on an
	 * ORIGINAL file (tracked per-file in the main table).
	 *
	 * @param string $imgName
	 * @param int $zopfliSize Size of the PNG after zopflipng (bytes)
	 */
	public function markPngRecompressed( string $imgName, int $zopfliSize ): void {
		$db = $this->getPrimary();
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'io_png_zopfli_size' => $zopfliSize ] )
			->where( [ 'io_img_name' => $imgName ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Mark a row as second-pass-processed but with no gain, without distorting
	 * statistics.
	 *
	 * Sets io_png_zopfli_size equal to io_optimized_size so the backfill query
	 * (which selects io_png_zopfli_size IS NULL) stops re-selecting the row,
	 * while keeping SUM(COALESCE(io_png_zopfli_size, io_optimized_size)) neutral
	 * — the second pass then contributes a 0-byte saving. A literal sentinel such
	 * as 1 would instead make COALESCE pick 1 and massively inflate the reported
	 * space saved. The column-to-column assignment uses RawSQLValue (a fixed
	 * column name, never user input). If io_optimized_size is somehow NULL the
	 * row simply stays selectable, which is harmless.
	 *
	 * @param string $imgName
	 */
	public function markPngRecompressedNoGain( string $imgName ): void {
		$db = $this->getPrimary();
		$optCol = new RawSQLValue( 'io_optimized_size' );
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'io_png_zopfli_size' => $optCol ] )
			->where( [ 'io_img_name' => $imgName ] )
			->andWhere( $db->expr( 'io_png_zopfli_size', '=', null ) )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Repair a bloated row after re-optimizing the on-disk file: set both the
	 * optimized size and the zopfli size to the file's true current size, so
	 * statistics become consistent again. Used by the repair maintenance
	 * script for files that were previously inflated by the (now fixed)
	 * unconditional-overwrite optimization bug.
	 *
	 * @param string $imgName
	 * @param int $trueSize Actual current byte size of the file on disk
	 */
	public function repairOptimizedSize( string $imgName, int $trueSize ): void {
		$db = $this->getPrimary();
		// Anti-inflation invariant: the recorded optimized size must never end
		// up larger than the original size, otherwise the row stays in the
		// "optimized > original" (bloated) selection and the repair script
		// loops on it. SQLite has NO LEAST() function (its scalar equivalent is
		// multi-argument MIN(), verified: "no such function: LEAST"), so we
		// read io_original_size and compute the clamp in PHP — two statements
		// on a single-row repair path, portable everywhere.
		$origSize = $db->newSelectQueryBuilder()
			->select( 'io_original_size' )
			->from( self::TABLE )
			->where( [ 'io_img_name' => $imgName ] )
			->caller( __METHOD__ )
			->fetchField();
		$clamped = $origSize !== false && $origSize !== null
			? min( (int)$trueSize, (int)$origSize )
			: (int)$trueSize;
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'io_optimized_size' => $clamped,
				'io_png_zopfli_size' => $clamped,
			] )
			->where( [ 'io_img_name' => $imgName ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Clamp the recorded optimized size down to the original size, so the row
	 * is no longer counted as "bloated" (optimized > original). Used by the
	 * repair script for rows it cannot otherwise fix (file missing, path
	 * unresolved, recompression impossible) — without this clamp, such rows
	 * stay in the bloated selection and the repair run loops on them forever.
	 *
	 * Uses a single UPDATE with a column-to-column assignment so it works
	 * regardless of the actual sizes, and touches nothing on disk.
	 *
	 * @param string $imgName
	 */
	public function clampOptimizedToOriginal( string $imgName ): void {
		$db = $this->getPrimary();
		// Set io_optimized_size = io_original_size (and keep the zopfli column
		// in sync) for this row. RawSQLValue is required for a column-to-column
		// assignment; it is safe here because the value is a fixed column name,
		// never user input.
		$origCol = new \Wikimedia\Rdbms\RawSQLValue( 'io_original_size' );
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'io_optimized_size' => $origCol,
				'io_png_zopfli_size' => $origCol,
			] )
			->where( [ 'io_img_name' => $imgName ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Fetch the names of rows whose recorded optimized size is larger than the
	 * original — i.e. files inflated by the old bug. Returned in descending
	 * order of waste so the worst offenders are fixed first.
	 *
	 * @param int $limit Max rows to return
	 * @return string[] List of io_img_name values
	 */
	public function getBloatedOriginalNames( int $limit = 1000 ): array {
		$db = $this->getReplica();
		$res = $db->newSelectQueryBuilder()
			->select( 'io_img_name' )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->andWhere( $db->expr( 'io_optimized_size', '>', new RawSQLValue( 'io_original_size' ) ) )
			->orderBy( 'io_optimized_size - io_original_size', SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchFieldValues();
		return array_map( 'strval', $res );
	}

	/**
	 * Count rows inflated by the old bug (optimized size > original size).
	 *
	 * @return array{count:int, wasted:int}
	 */
	public function countBloatedOriginals(): array {
		$db = $this->getReplica();
		$row = $db->newSelectQueryBuilder()
			->select( [
				'cnt' => 'COUNT(*)',
				'wasted' => 'SUM(io_optimized_size - io_original_size)',
			] )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->andWhere( $db->expr( 'io_optimized_size', '>', new RawSQLValue( 'io_original_size' ) ) )
			->caller( __METHOD__ )
			->fetchRow();
		return [
			'count' => $row ? (int)$row->cnt : 0,
			'wasted' => $row ? (int)$row->wasted : 0,
		];
	}

	/**
	 * Get a batch of original PNG files that have been WebP-optimized but not
	 * yet recompressed with zopflipng. Used by the zopfli backfill.
	 *
	 * Selects completed rows whose filename ends in .png/.apng and where
	 * io_png_zopfli_size IS NULL.
	 *
	 * @param int $limit
	 * @return string[] List of image names.
	 */
	public function getPngPendingZopfli( int $limit ): array {
		$db = $this->getReplica();
		$rows = $db->newSelectQueryBuilder()
			->select( 'io_img_name' )
			->from( self::TABLE )
			->where( [
				'io_status' => self::STATUS_COMPLETE,
				'io_png_zopfli_size' => null,
			] )
			->andWhere( $this->pngFilenameCondition( $db ) )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchFieldValues();
		return array_map( 'strval', $rows );
	}

	/**
	 * Build a WHERE condition matching PNG filenames (.png / .apng).
	 *
	 * We deliberately do NOT wrap the column in LOWER(): passing a SQL function
	 * as the field name to $db->expr() is rejected by the Rdbms layer as a
	 * possible injection vector. Instead we LIKE-match the raw column against
	 * the common extension casings. MediaWiki normalizes uploaded filenames so
	 * lowercase extensions dominate; we also include uppercase for safety.
	 *
	 * Note: LIKE in MySQL/MariaDB is case-insensitive by default for the
	 * standard collations used by MediaWiki (utf8mb4_*_ci / binary varies),
	 * so '.png' will typically also match '.PNG'. The explicit uppercase
	 * variants are a belt-and-braces fallback for binary collations.
	 *
	 * Returns an OR expression group usable in andWhere().
	 *
	 * @param IReadableDatabase $db
	 * @return IExpression
	 */
	private function pngFilenameCondition( IReadableDatabase $db ) {
		return $db->orExpr( [
			$db->expr( 'io_img_name', IExpression::LIKE,
				new LikeValue( $db->anyString(), '.png' ) ),
			$db->expr( 'io_img_name', IExpression::LIKE,
				new LikeValue( $db->anyString(), '.PNG' ) ),
			$db->expr( 'io_img_name', IExpression::LIKE,
				new LikeValue( $db->anyString(), '.apng' ) ),
		] );
	}

	/**
	 * Count original PNGs still pending zopflipng recompression, and how many
	 * are already done. Cheap aggregate, cached with the rest of the stats.
	 *
	 * @return array{pending:int, done:int, saved:int}
	 */
	public function getZopfliOriginalsProgress(): array {
		$db = $this->getReplica();

		$pngCond = $this->pngFilenameCondition( $db );

		$done = (int)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->andWhere( $pngCond )
			->andWhere( $db->expr( 'io_png_zopfli_size', '!=', null ) )
			->caller( __METHOD__ )
			->fetchField();

		$pending = (int)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->where( [
				'io_status' => self::STATUS_COMPLETE,
				'io_png_zopfli_size' => null,
			] )
			->andWhere( $pngCond )
			->caller( __METHOD__ )
			->fetchField();

		// Bytes saved on originals = optimized - zopfli, summed where recompressed.
		$saved = (int)$db->newSelectQueryBuilder()
			->select( 'SUM(io_optimized_size - io_png_zopfli_size)' )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->andWhere( $db->expr( 'io_png_zopfli_size', '!=', null ) )
			->caller( __METHOD__ )
			->fetchField();

		return [ 'pending' => $pending, 'done' => $done, 'saved' => max( 0, $saved ) ];
	}

	/**
	 * Add to the aggregate thumbnail zopfli stats (single-row table).
	 * Called each time a thumbnail PNG is recompressed.
	 *
	 * @param int $bytesSaved Bytes saved on this thumbnail (>= 0)
	 */
	public function addThumbZopfliSaving( int $bytesSaved ): void {
		if ( $bytesSaved < 0 ) {
			$bytesSaved = 0;
		}
		$db = $this->getPrimary();
		// Upsert the single row (zts_id = 1), incrementing counters.
		$db->newInsertQueryBuilder()
			->insertInto( self::THUMB_STATS_TABLE )
			->row( [
				'zts_id' => 1,
				'zts_thumb_count' => 1,
				'zts_bytes_saved' => $bytesSaved,
				'zts_updated_at' => $db->timestamp(),
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'zts_id' ] )
			->set( [
				'zts_thumb_count' => new RawSQLValue( 'zts_thumb_count + 1' ),
				'zts_bytes_saved' => new RawSQLValue( 'zts_bytes_saved + ' . (int)$bytesSaved ),
				'zts_updated_at' => $db->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
		// Note: we intentionally do NOT invalidate the stats cache on every
		// thumbnail (could be very frequent). The 15s TTL handles freshness.
	}

	/**
	 * Get aggregate thumbnail zopfli stats.
	 *
	 * @return array{count:int, saved:int}
	 */
	public function getThumbZopfliStats(): array {
		$row = $this->getReplica()->newSelectQueryBuilder()
			->select( [ 'zts_thumb_count', 'zts_bytes_saved' ] )
			->from( self::THUMB_STATS_TABLE )
			->where( [ 'zts_id' => 1 ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return [ 'count' => 0, 'saved' => 0 ];
		}
		return [
			'count' => (int)$row->zts_thumb_count,
			'saved' => (int)$row->zts_bytes_saved,
		];
	}

	/**
	 * Get the list of failed files (for diagnostics).
	 *
	 * @param int $limit
	 * @return array<int, array{name: string, error: string, processed_at: string}>
	 */
	public function getFailedFiles( int $limit = 50 ): array {
		$rows = $this->getReplica()->newSelectQueryBuilder()
			->select( [ 'io_img_name', 'io_error', 'io_processed_at' ] )
			->from( self::TABLE )
			->where( [ 'io_status' => self::STATUS_FAILED ] )
			->orderBy( 'io_processed_at', 'DESC' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$result = [];
		foreach ( $rows as $row ) {
			$result[] = [
				'name' => (string)$row->io_img_name,
				'error' => (string)$row->io_error,
				'processed_at' => (string)$row->io_processed_at,
			];
		}
		return $result;
	}

	/**
	 * Reset the second-pass marker (io_png_zopfli_size = NULL) on completed PNG
	 * rows so they become "pending" again and can be re-processed by the
	 * currently configured engine. Used by the recompressOriginals maintenance
	 * script to run a second engine over files already processed by a first one
	 * (e.g. oxipng first for speed, then zopflipng to squeeze the last percent).
	 *
	 * The actual recompression keeps the result only if it is smaller (the
	 * recompressor's built-in keep-if-smaller guard), so re-processing never
	 * enlarges a file: at worst a row is re-marked with the same size.
	 *
	 * @return int Number of rows reset
	 */
	public function resetZopfliMarker(): int {
		$db = $this->getPrimary();
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'io_png_zopfli_size' => null ] )
			->where( [ 'io_status' => self::STATUS_COMPLETE ] )
			->andWhere( $db->expr( 'io_png_zopfli_size', '!=', null ) )
			->andWhere( $this->pngFilenameCondition( $db ) )
			->caller( __METHOD__ )
			->execute();
		$affected = $db->affectedRows();
		$this->invalidateStatsCache();
		return $affected;
	}
}
