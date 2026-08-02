<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Service;

use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Garbage-collects the extension's own temp files.
 *
 * Why this is needed at all
 * ------------------------
 * Every in-place optimizer writes "<file>.<tool>.<pid>.<rand>.tmp" next to its
 * target and unlinks it on all normal and error paths. That is not enough:
 *
 *  - A SIGKILL (OOM killer, `systemctl stop`, container eviction, deploy
 *    restart) skips every PHP cleanup path, shutdown functions included.
 *  - Worse, the heavy optimizers run an EXTERNAL binary through proc_open.
 *    Killing the PHP parent does not kill that child: it is reparented and
 *    keeps going, then writes its output. The temp therefore materialises
 *    AFTER the parent is already dead, so no in-process handler could ever
 *    have removed it. Measured: ~99 MB of orphans from 15 kills of 2 files.
 *
 * That matters beyond disk usage. Temps sit inside the web-served upload tree,
 * so an orphan is a complete, publicly fetchable copy of an upload — and it
 * survives deletion of the file it came from, at a guessable URL, with nothing
 * in MediaWiki referencing it. Deleting a media file for copyright or privacy
 * reasons would not actually withdraw its bytes.
 *
 * Safety
 * ------
 * Only paths matching this extension's own temp suffixes are ever considered,
 * and only when older than a minimum age (default one hour) so a running
 * encode — zopflipng can legitimately take tens of seconds on a large PNG — is
 * never raced. Anything else in the tree is left strictly alone.
 */
class TempFileSweeper {

	/**
	 * Matches exactly the four temp shapes this extension creates:
	 *   <name>.vtmo.<pid>.<hex>.tmp       (AtomicWriteTrait)
	 *   <name>.gifsicle.<pid>.<uniqid>.tmp
	 *   <name>.zopfli.<pid>.<uniqid>.tmp
	 *   <name>.oxipng.<pid>.<uniqid>.tmp
	 */
	private const TEMP_PATTERN = '/\.(?:vtmo|gifsicle|zopfli|oxipng)\.\d+\.[0-9a-zA-Z.]+\.tmp$/';

	/** Default minimum age before a temp is considered abandoned (seconds). */
	public const DEFAULT_MIN_AGE = 3600;

	private LoggerInterface $logger;

	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Whether a basename is one of our temp files.
	 */
	public static function isOwnTempName( string $name ): bool {
		return (bool)preg_match( self::TEMP_PATTERN, $name );
	}

	/**
	 * Remove abandoned temp files under $rootDir.
	 *
	 * @param string $rootDir Directory tree to scan (e.g. $wgUploadDirectory)
	 * @param int $minAgeSeconds Only delete temps older than this
	 * @param bool $dryRun Report without deleting
	 * @return array{found:int,removed:int,bytes:int,failed:int,paths:string[]}
	 */
	public function sweep(
		string $rootDir,
		int $minAgeSeconds = self::DEFAULT_MIN_AGE,
		bool $dryRun = false
	): array {
		$result = [ 'found' => 0, 'removed' => 0, 'bytes' => 0, 'failed' => 0, 'paths' => [] ];
		if ( $rootDir === '' || !is_dir( $rootDir ) ) {
			return $result;
		}
		$cutoff = time() - max( 0, $minAgeSeconds );

		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$rootDir,
					FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
				),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Temp sweep could not scan {dir}: {msg}',
				[ 'dir' => $rootDir, 'msg' => $e->getMessage() ] );
			return $result;
		}

		foreach ( $it as $fileInfo ) {
			try {
				if ( !$fileInfo->isFile() ) {
					continue;
				}
				$name = $fileInfo->getFilename();
				if ( !self::isOwnTempName( $name ) ) {
					continue;
				}
				$result['found']++;
				// Never race a live encode.
				if ( $fileInfo->getMTime() > $cutoff ) {
					continue;
				}
				$path = $fileInfo->getPathname();
				$size = (int)$fileInfo->getSize();
				$result['paths'][] = $path;
				if ( $dryRun ) {
					$result['removed']++;
					$result['bytes'] += $size;
					continue;
				}
				if ( @unlink( $path ) ) {
					$result['removed']++;
					$result['bytes'] += $size;
					$this->logger->info( 'Removed abandoned temp {path} ({bytes} bytes)',
						[ 'path' => $path, 'bytes' => $size ] );
				} else {
					$result['failed']++;
					$this->logger->warning( 'Could not remove abandoned temp {path}',
						[ 'path' => $path ] );
				}
			} catch ( Throwable $e ) {
				// A file vanishing mid-scan (another sweeper, or the owning
				// process finishing) is normal; keep going.
				continue;
			}
		}

		return $result;
	}
}
