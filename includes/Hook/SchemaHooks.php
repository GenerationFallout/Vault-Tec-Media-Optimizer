<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Hook;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Schema hook handler. Separate from MainHooks because LoadExtensionSchemaUpdates
 * is called in a context where services are not yet wired up.
 *
 * Design note on idempotency:
 * addExtensionTable( $table, $file ) executes $file IN FULL when $table is
 * missing. Therefore every file passed here must contain ONLY the CREATE
 * statement for the matching table. If we passed a single multi-table file
 * (e.g. tables-generated.sql, which holds both tables), then creating the
 * second table on an install that already has the first would re-run the
 * first table's CREATE and fail with "table already exists" (Error 1050).
 * Each table therefore has its own dedicated, single-table SQL file.
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$sqlDir = dirname( __DIR__, 2 ) . '/sql';
		$type = $updater->getDB()->getType();

		// Per-RDBMS directory with fallback to mysql for unsupported engines.
		$dbDir = $sqlDir . '/' . $type;
		if ( !is_dir( $dbDir ) ) {
			$dbDir = $sqlDir . '/mysql';
		}

		// Helper: resolve a per-RDBMS file, falling back to the mysql copy.
		$resolve = static function ( string $relative ) use ( $dbDir, $sqlDir ): string {
			$path = $dbDir . '/' . $relative;
			if ( !file_exists( $path ) ) {
				$path = $sqlDir . '/mysql/' . $relative;
			}
			return $path;
		};

		// 1. Main optimization table. Dedicated single-table file.
		$updater->addExtensionTable(
			'vtmo_image_optimization',
			$resolve( 'tables-image-optimization.sql' )
		);

		// 2. Aggregate thumbnail zopfli stats table. Dedicated single-table file.
		$updater->addExtensionTable(
			'vtmo_zopfli_thumb_stats',
			$resolve( 'tables-zopfli-thumb-stats.sql' )
		);

		// 3. Upgrade path for pre-1.3 installs: add the zopflipng column to the
		//    already-existing main table. addExtensionField() is a no-op if the
		//    column is already present, so this is safe on new installs too
		//    (where step 1 already created the column).
		$patch = $resolve( 'patches/patch-add-io_png_zopfli_size.sql' );
		if ( file_exists( $patch ) ) {
			$updater->addExtensionField(
				'vtmo_image_optimization',
				'io_png_zopfli_size',
				$patch
			);
		}
	}
}
