<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWiki\FileRepo\RepoGroup;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies second-pass zopflipng recompression to an ORIGINAL image file and
 * keeps MediaWiki's metadata (img_size, img_sha1) consistent afterwards.
 *
 * This is the delicate part of the zopfli feature: changing a file on disk
 * without updating the `image` table would make MediaWiki think the file is
 * corrupt or stale (sha1 mismatch), break thumbnail cache invalidation, and
 * potentially confuse re-upload detection. We therefore refresh the file's
 * metadata through the repo after recompression.
 *
 * Lossless guarantee: zopflipng never alters pixels, so the visual content,
 * dimensions, and image properties are unchanged. Only the file's byte size
 * and sha1 change.
 */
class ZopfliOriginalProcessor {

	private PngRecompressorInterface $zopfli;
	private OptimizationRecord $record;
	private WebPRepo $webpRepo;
	private RepoGroup $repoGroup;
	private LoggerInterface $logger;

	public function __construct(
		PngRecompressorInterface $zopfli,
		OptimizationRecord $record,
		WebPRepo $webpRepo,
		RepoGroup $repoGroup,
		LoggerInterface $logger
	) {
		$this->zopfli = $zopfli;
		$this->record = $record;
		$this->webpRepo = $webpRepo;
		$this->repoGroup = $repoGroup;
		$this->logger = $logger;
	}

	/**
	 * Recompress one original PNG by name. Returns true if handled (whether or
	 * not bytes were saved), false on hard error.
	 *
	 * @param string $imgName DB key (e.g. "Vault_door.png")
	 */
	public function processByName( string $imgName ): bool {
		if ( !$this->zopfli->isAvailable() ) {
			// Skip without marking: the row stays pending and will be retried on a
			// later run. Jobs are only scheduled when the engine is available, so
			// this branch is reached only if availability flips (e.g. the binary
			// is removed) after scheduling — in which case retrying later is the
			// right behaviour, not permanently flagging the row as processed.
			$this->logger->debug( 'Zopfli unavailable; skipping original {name}', [ 'name' => $imgName ] );
			return false;
		}

		try {
			$file = $this->repoGroup->getLocalRepo()->newFile( $imgName );
			if ( !$file || !$file->exists() ) {
				$this->logger->debug( 'Zopfli: file not found {name}', [ 'name' => $imgName ] );
				return false;
			}

			// Only PNG.
			$mime = $file->getMimeType();
			if ( $mime !== 'image/png' ) {
				// Mark as processed-with-no-gain so it's not reselected.
				$this->markProcessedNoGain( $imgName );
				return true;
			}

			// Resolve the real filesystem path (same strategy as ImageProcessor).
			$path = $this->resolveLocalPath( $file );
			if ( $path === null ) {
				$this->logger->warning( 'Zopfli: could not resolve path for {name}', [ 'name' => $imgName ] );
				return false;
			}

			$beforeSize = filesize( $path );
			if ( $beforeSize === false || $beforeSize === 0 ) {
				return false;
			}

			$saved = $this->zopfli->recompress( $path );
			if ( $saved === null ) {
				$this->logger->warning( 'Zopfli recompress failed for {name}: {err}', [
					'name' => $imgName,
					'err' => $this->zopfli->getLastError() ?? 'unknown',
				] );
				return false;
			}

			$afterSize = filesize( $path );
			if ( $afterSize === false ) {
				$afterSize = $beforeSize;
			}

			// Keep MediaWiki metadata consistent BEFORE marking the row done.
			// The previous order (mark, then best-effort refresh) meant a failed
			// upgradeRow() left img_sha1/img_size permanently stale on a row that
			// would never be reselected — exactly the desync this class exists to
			// prevent. Now: if the bytes changed and the refresh fails, we do NOT
			// mark; the row stays pending and the whole step is retried later
			// (recompressing an already-optimal file then yields saved=0, and the
			// staleness check below still triggers the refresh).
			$bytesChanged = $saved > 0
				// Retry path: an earlier run shrank the file but its refresh
				// failed before the row was marked. The DB-recorded size then
				// disagrees with the on-disk size.
				|| ( method_exists( $file, 'getSize' ) && $file->getSize() !== $afterSize );

			if ( $bytesChanged ) {
				if ( !$this->refreshFileMetadata( $file ) ) {
					$this->logger->warning(
						'Zopfli: metadata refresh failed for {name}; leaving row pending for retry',
						[ 'name' => $imgName ]
					);
					return false;
				}
				if ( $saved > 0 ) {
					$this->logger->info( 'Zopfli saved {bytes} bytes on original {name}', [
						'bytes' => $saved,
						'name' => $imgName,
					] );
				}
			}

			// Record the zopfli size in our tracking table.
			$this->record->markPngRecompressed( $imgName, (int)$afterSize );

			return true;
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Zopfli original processing error for {name}: {msg}', [
				'name' => $imgName,
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}

	/**
	 * Mark a non-PNG (or no-gain) row as processed so the backfill query stops
	 * selecting it, without distorting statistics.
	 *
	 * Delegates to OptimizationRecord, which sets io_png_zopfli_size equal to
	 * io_optimized_size (a 0-byte "second pass" saving). The previous
	 * implementation stored a literal 1, which COALESCE then treated as the
	 * file's real size and inflated the reported space saved to ~100%.
	 */
	private function markProcessedNoGain( string $imgName ): void {
		$this->record->markPngRecompressedNoGain( $imgName );
	}

	/**
	 * Resolve the on-disk path for a local file, with the same fallback logic
	 * used elsewhere (compute from upload dir, fall back to getLocalRefPath).
	 */
	private function resolveLocalPath( $file ): ?string {
		// Prefer the repo-reported local reference (works across normalization).
		try {
			$ref = $file->getLocalRefPath();
			if ( is_string( $ref ) && $ref !== '' && is_file( $ref ) ) {
				return $ref;
			}
		} catch ( Throwable $e ) {
			// fall through
		}
		return null;
	}

	/**
	 * Refresh MediaWiki's stored metadata for the file after its bytes changed.
	 *
	 * LocalFile::upgradeRow() recomputes size, sha1, width, height and metadata
	 * from the actual file and writes them back to the `image` table. This is
	 * exactly what we need after an in-place lossless recompression.
	 *
	 * @return bool False when the refresh threw — the caller must then NOT mark
	 *  the row as processed, so the file is retried and never left with a stale
	 *  img_sha1 behind a "done" marker.
	 */
	private function refreshFileMetadata( $file ): bool {
		try {
			if ( method_exists( $file, 'purgeCache' ) ) {
				$file->purgeCache();
			}
			// upgradeRow() re-reads the file and updates img_size/img_sha1/etc.
			if ( method_exists( $file, 'upgradeRow' ) ) {
				$file->upgradeRow();
			}
			return true;
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Zopfli: metadata refresh failed for {name}: {msg}', [
				'name' => $file->getName(),
				'msg' => $e->getMessage(),
			] );
			return false;
		}
	}
}
