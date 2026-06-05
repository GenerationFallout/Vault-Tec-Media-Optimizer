<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerFactory;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the full optimization pipeline for a single file:
 *
 *  1. Validate (MIME, size, support)
 *  2. Optionally optimize the original losslessly in place
 *  3. Generate a WebP copy in the separate WebP directory
 *  4. Record the result in the database
 *
 * Idempotent: re-processing a file is safe; it will simply overwrite
 * the WebP and update the BDD row.
 */
class ImageProcessor {

	private ServiceOptions $options;
	private OptimizerFactory $optimizerFactory;
	private WebPRepo $webpRepo;
	private OptimizationRecord $record;
	private RepoGroup $repoGroup;
	private GifOptimizer $gifOptimizer;
	private LoggerInterface $logger;

	public function __construct(
		ServiceOptions $options,
		OptimizerFactory $optimizerFactory,
		WebPRepo $webpRepo,
		OptimizationRecord $record,
		RepoGroup $repoGroup,
		GifOptimizer $gifOptimizer,
		LoggerInterface $logger
	) {
		$this->options = $options;
		$this->optimizerFactory = $optimizerFactory;
		$this->webpRepo = $webpRepo;
		$this->record = $record;
		$this->repoGroup = $repoGroup;
		$this->gifOptimizer = $gifOptimizer;
		$this->logger = $logger;
	}

	/**
	 * Process a file by name (the DB key, e.g. "Vault_door.png").
	 *
	 * Used by the JobQueue and the backfill scheduler.
	 *
	 * @param string $imgName
	 * @return bool True on success
	 */
	public function processByName( string $imgName ): bool {
		$file = $this->repoGroup->getLocalRepo()->newFile( $imgName );
		if ( !$file || !$file->exists() ) {
			$this->record->markSkipped( $imgName, 'File not found in repo' );
			return false;
		}
		return $this->process( $file );
	}

	/**
	 * Process a File object directly.
	 *
	 * @param File $file
	 * @return bool True on success
	 */
	public function process( File $file ): bool {
		if ( !$this->options->get( 'VaultTecMediaOptimizerEnabled' ) ) {
			return false;
		}

		$imgName = $file->getName();

		try {
			// 1. Validate
			$skipReason = $this->shouldSkip( $file );
			if ( $skipReason !== null ) {
				$this->record->markSkipped( $imgName, $skipReason );
				$this->logger->debug( 'Skipped {name}: {reason}', [
					'name' => $imgName,
					'reason' => $skipReason,
				] );
				return false;
			}

			// Calculate the on-disk filesystem path directly from
			// $wgUploadDirectory + the file's relative path (e.g. "a/ab/File.png").
			//
			// NOT using $file->getPath(): it returns a virtual FileBackend URL
			// like "mwstore://local-backend/local-public/a/ab/File.png" which is
			// not a filesystem path.
			//
			// Computing it from $wgUploadDirectory + getRel() works for the
			// common case of LocalRepo backed by a filesystem (the only setup
			// the Fallout wiki uses).
			//
			// Fallback: if the computed path doesn't exist (often due to
			// Unicode normalization differences between the DB and the
			// filesystem — NFC vs NFD vs URL-encoded), try getLocalRefPath()
			// which delegates to the FileBackend to find the real file. On
			// LocalRepo+FS this returns the actual on-disk path (not a temp
			// copy), so in-place writing remains safe in practice — we are
			// going against the letter of the API documentation but matching
			// its real-world behavior on filesystem repos.
			$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
			$path = $uploadDir . '/' . $file->getRel();

			if ( !is_file( $path ) ) {
				// Fallback via FileBackend
				$resolved = $file->getLocalRefPath();
				if ( is_string( $resolved ) && $resolved !== '' && is_file( $resolved ) ) {
					$path = $resolved;
				} else {
					$this->record->markFailed( $imgName,
						"File not found on local filesystem at $path (tried fallback via FileBackend too)" );
					return false;
				}
			}
			if ( !is_writable( $path ) ) {
				$this->record->markFailed( $imgName,
					"File not writable: $path (check ownership/permissions)" );
				return false;
			}

			$mime = $file->getMimeType();
			$originalSize = filesize( $path );
			if ( $originalSize === false ) {
				$this->record->markFailed( $imgName, 'Could not stat file' );
				return false;
			}

			$optimizer = $this->optimizerFactory->getOptimizer();

			// 2. Optionally optimize original
			$optimizedSize = $originalSize;
			if ( $this->options->get( 'VaultTecMediaOptimizerOptimizeOriginals' ) ) {
				$success = false;
				switch ( $mime ) {
					case 'image/png':
						$success = $optimizer->optimizePng( $path );
						break;
					case 'image/jpeg':
						$success = $optimizer->optimizeJpeg( $path );
						break;
					case 'image/gif':
						// GIF first pass: optional, lossless, animation-safe
						// structural optimization via the external gifsicle
						// binary (-O3). It never resizes and never alters frame
						// pixels/timing/loop; the rendered animation is identical
						// (keep-if-smaller guard inside the optimizer).
						//
						// We always report success=true so WebP generation
						// proceeds regardless: when gifsicle is disabled or
						// unavailable, optimize() is a harmless no-op (returns
						// null) and we simply leave the original untouched.
						$saved = $this->gifOptimizer->optimize( $path );
						if ( $saved === null && $this->gifOptimizer->isAvailable() ) {
							$this->logger->debug(
								'GIF optimization skipped for {name}: {err}',
								[ 'name' => $imgName, 'err' => $this->gifOptimizer->getLastError() ?? 'no detail' ]
							);
						}
						$success = true;
						break;
				}
				if ( $success ) {
					clearstatcache( true, $path );
					$newSize = filesize( $path );
					if ( $newSize !== false ) {
						$optimizedSize = $newSize;
					}
				} else {
					$this->logger->warning(
						'Optimization of original failed for {name}, continuing to WebP step',
						[ 'name' => $imgName ]
					);
				}
			}

			// 3. Generate WebP
			$webpPath = $this->webpRepo->getWebPPath( $path );
			if ( $webpPath === null ) {
				$this->record->markFailed( $imgName, 'Cannot compute WebP path (file outside upload dir?)' );
				return false;
			}
			if ( !$this->webpRepo->ensureDirFor( $webpPath ) ) {
				$this->record->markFailed( $imgName, 'Cannot create WebP directory' );
				return false;
			}

			$lossless = ( $mime === 'image/png' )
				&& $this->options->get( 'VaultTecMediaOptimizerWebPLosslessForPng' );
			$quality = (int)$this->options->get( 'VaultTecMediaOptimizerWebPQuality' );

			$ok = $optimizer->convertToWebP( $path, $webpPath, $lossless, $quality );
			if ( !$ok ) {
				$detail = $optimizer->getLastError();
				$msg = $detail !== null
					? "WebP conversion failed: $detail"
					: 'WebP conversion failed (no detail available)';
				$this->record->markFailed( $imgName, $msg );
				return false;
			}

			$webpSize = filesize( $webpPath );
			if ( $webpSize === false ) {
				$webpSize = 0;
			}

			// 4. Record success
			$this->record->markComplete(
				$imgName,
				$originalSize,
				$optimizedSize,
				$webpSize,
				$optimizer->getName()
			);

			$saved = $originalSize - $webpSize;
			$this->logger->info( 'Processed {name}: orig={orig} opt={opt} webp={webp} saved={saved}', [
				'name' => $imgName,
				'orig' => $originalSize,
				'opt' => $optimizedSize,
				'webp' => $webpSize,
				'saved' => $saved,
			] );

			return true;
		} catch ( RuntimeException $e ) {
			// No backend available
			$this->record->markFailed( $imgName, $e->getMessage() );
			return false;
		} catch ( Throwable $e ) {
			$this->logger->error( 'Exception processing {name}: {msg}', [
				'name' => $imgName,
				'msg' => $e->getMessage(),
				'exception' => $e,
			] );
			$this->record->markFailed( $imgName, 'Exception: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Reason to skip, or null if the file should be processed.
	 */
	private function shouldSkip( File $file ): ?string {
		$mime = $file->getMimeType();
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );
		if ( !in_array( $mime, $allowedMimes, true ) ) {
			return "Unsupported MIME type: $mime";
		}

		$maxSize = (int)$this->options->get( 'VaultTecMediaOptimizerMaxFileSize' );
		if ( $maxSize > 0 && $file->getSize() > $maxSize ) {
			return "File too large: " . $file->getSize() . " > $maxSize";
		}

		return null;
	}
}
