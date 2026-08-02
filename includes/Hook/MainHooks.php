<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Hook;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerFactory;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\HtmlRewriter;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PngRecompressorInterface;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWiki\Hook\FileDeleteCompleteHook;
use MediaWiki\Hook\FileTransformedHook;
use MediaWiki\Hook\FileUploadHook;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Output\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Main runtime hook handler for VaultTecMediaOptimizer.
 *
 * - FileUpload: enqueues a background job to optimize the new original file.
 * - FileTransformed: when MediaWiki generates a thumbnail, also produce the
 *   WebP version next to it so the rewriter can serve it.
 * - FileDeleteComplete: cleans up the WebP file and the DB record.
 * - OutputPageBeforeHTML: rewrites <img> -> <picture> for served pages.
 */
class MainHooks implements
	FileUploadHook,
	FileDeleteCompleteHook,
	FileTransformedHook,
	OutputPageBeforeHTMLHook
{
	private HtmlRewriter $htmlRewriter;
	private OptimizationRecord $record;
	private WebPRepo $webpRepo;
	private OptimizerFactory $optimizerFactory;
	private ServiceOptions $options;
	private JobQueueGroup $jobQueueGroup;
	private PngRecompressorInterface $zopfli;
	private LoggerInterface $logger;

	public function __construct(
		HtmlRewriter $htmlRewriter,
		OptimizationRecord $record,
		WebPRepo $webpRepo,
		OptimizerFactory $optimizerFactory,
		ServiceOptions $options,
		JobQueueGroup $jobQueueGroup,
		PngRecompressorInterface $zopfli
	) {
		$this->htmlRewriter = $htmlRewriter;
		$this->record = $record;
		$this->webpRepo = $webpRepo;
		$this->optimizerFactory = $optimizerFactory;
		$this->options = $options;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->zopfli = $zopfli;
		$this->logger = LoggerFactory::getInstance( 'VaultTecMediaOptimizer' );
	}

	/**
	 * @inheritDoc
	 *
	 * Called after a successful upload. Enqueues a background job to optimize
	 * the new file. We use a job rather than inline processing so the upload
	 * response stays fast.
	 */
	public function onFileUpload( $file, $reupload, $hasDescription ) {
		if ( !$this->options->get( 'VaultTecMediaOptimizerEnabled' ) ) {
			return;
		}

		$imgName = $file->getName();

		// PURGE FIRST, unconditionally.
		//
		// On reupload MediaWiki regenerates thumbnails at the SAME paths, so
		// every derived file we already hold now depicts the PREVIOUS image.
		// Both serving guards (onFileTransformed's skip-if-exists and
		// HtmlRewriter's exists-and-smaller fast path) would happily keep
		// serving those stale pixels forever.
		//
		// This block must run BEFORE every early return below:
		//  - before the UseJobQueue check, because in large-wiki mode no job
		//    ever runs to fix it up, so a stale derivative would be permanent
		//    (the docblock's promise that "thumbnails still get their WebP
		//    on-demand" is exactly what breaks);
		//  - before the MIME/Title checks, since a file we once optimized may
		//    no longer match a narrowed $wgVaultTecMediaOptimizerFormats.
		//
		// We also drop the ORIGINAL's WebP, not just the thumbnails. Leaving it
		// for the job to overwrite means it keeps being served — with the old
		// pixels — for the whole window until the queue is processed, which is
		// unbounded under the $wgJobRunRate = 0 setup this extension's own docs
		// recommend. No derivative is always better than a wrong derivative.
		if ( $reupload ) {
			try {
				$this->webpRepo->deleteWebPThumbDir( $imgName );
				$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
				$rel = $file->getRel();
				if ( is_string( $rel ) && $rel !== '' ) {
					$this->webpRepo->deleteWebP( $uploadDir . '/' . $rel );
				}
				// Drop the tracking row too: new pixels mean a new baseline.
				// markComplete() deliberately preserves io_original_size and the
				// second-pass marker on update, so without this the row would
				// keep describing the PREVIOUS version of the file.
				$this->record->delete( $imgName );
			} catch ( Throwable $e ) {
				$this->logger->warning( 'WebP purge on reupload failed for {name}: {msg}',
					[ 'name' => $imgName, 'msg' => $e->getMessage() ] );
			}
		}

		// Large-wiki mode: when the job queue is disabled, uploads are NOT
		// auto-enqueued. The admin optimizes originals on their own schedule via
		// maintenance/optimizeImages.php; thumbnails still get their WebP
		// on-demand at render time, so visitors keep receiving WebP regardless.
		if ( !$this->options->get( 'VaultTecMediaOptimizerUseJobQueue' ) ) {
			return;
		}

		// Only enqueue if the file's MIME type matches what we handle.
		// (Cheap optimization to avoid clogging the queue with PDFs etc.)
		$mime = $file->getMimeType();
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );
		if ( !in_array( $mime, $allowedMimes, true ) ) {
			return;
		}

		$title = Title::makeTitleSafe( NS_FILE, $imgName );
		if ( !$title ) {
			return;
		}

		// lazyPush + try/catch: this hook runs inside the upload's PRESEND
		// AutoCommitUpdate, and core performs its own thumbnail/CDN purges right
		// after it. A queue backend outage (JobQueueError from a synchronous
		// push) must not abort those follow-ups — worst case our job is simply
		// not enqueued and the file is picked up by the next backfill.
		try {
			$this->jobQueueGroup->lazyPush(
				new JobSpecification(
					'VaultTecMediaOptimizerOptimizeImage',
					[ 'imgName' => $imgName ],
					[ 'removeDuplicates' => true ],
					$title
				)
			);
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Could not enqueue optimization for {name}: {msg}',
				[ 'name' => $imgName, 'msg' => $e->getMessage() ] );
		}
	}

	/**
	 * @inheritDoc
	 *
	 * Cleans up the WebP file and DB record on deletion of the original.
	 */
	public function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		// $oldimage is non-null when only an OLD version of the file was
		// deleted: the current file still exists and keeps being served, so its
		// WebP and optimization record must stay. (Archived versions never have
		// WebP derivatives — we only ever generate for the current name.)
		if ( $oldimage !== null ) {
			return;
		}

		$imgName = $file->getName();

		// Reconstruct the filesystem path the same way we do in ImageProcessor:
		// $wgUploadDirectory + getRel(). Avoids the mwstore:// URL that
		// $file->getPath() returns, which would not match our WebP lookup.
		$uploadDir = rtrim( $this->options->get( 'UploadDirectory' ), '/' );
		$rel = $file->getRel();
		if ( is_string( $rel ) && $rel !== '' ) {
			$path = $uploadDir . '/' . $rel;
			try {
				$this->webpRepo->deleteWebP( $path );
				// Also drop the per-size WebP thumbnails; otherwise a later
				// reupload under the same name would serve them (stale pixels)
				// thanks to the skip-if-exists guard in onFileTransformed.
				$this->webpRepo->deleteWebPThumbDir( $imgName );
			} catch ( Throwable $e ) {
				// Best-effort: log and move on. We don't want to block the
				// deletion if WebP cleanup fails (disk error, perms, etc.).
				$this->logger->warning( 'WebP cleanup failed for {name}: {msg}', [
					'name' => $imgName,
					'msg' => $e->getMessage(),
				] );
			}
		}

		$this->record->delete( $imgName );
	}

	/**
	 * @inheritDoc
	 *
	 * Called every time MediaWiki generates a thumbnail. We generate the
	 * matching WebP version next to the thumbnail so the HtmlRewriter can
	 * serve it via <picture>.
	 *
	 * Only the fast WebP encode runs synchronously here (a typical 200KB thumb
	 * is <50ms, and a given thumb triggers this hook only once, when first
	 * generated). The SLOW zopflipng second pass is enqueued as a background
	 * job (processed out-of-band — ideally via runJobs.php with $wgJobRunRate=0;
	 * see the docs), so it never blocks a visitor's render.
	 *
	 * We work entirely with the tmpThumbPath (filesystem path, guaranteed)
	 * and write into our parallel images_webp/ tree.
	 */
	public function onFileTransformed( $file, $thumb, $tmpThumbPath, $thumbPath ) {
		if ( !$this->options->get( 'VaultTecMediaOptimizerEnabled' )
			|| !$this->options->get( 'VaultTecMediaOptimizerProcessThumbnails' )
		) {
			return true;
		}

		// Only handle MIME types we care about
		$mime = $file->getMimeType();
		$allowedMimes = $this->options->get( 'VaultTecMediaOptimizerFormats' );
		if ( !in_array( $mime, $allowedMimes, true ) ) {
			return true;
		}

		// We read from tmpThumbPath (always a filesystem path — guaranteed by
		// File::transform's own logic, since it just wrote the file there).
		if ( !is_string( $tmpThumbPath ) || !is_file( $tmpThumbPath ) ) {
			return true;
		}

		// Where do we write the WebP?
		//
		// $thumbPath is the FileBackend STORAGE path. On non-trivial backends
		// (and even on LocalRepo where it returns "mwstore://local-backend/local-thumb/...")
		// it's a virtual URL, NOT a filesystem path. So we can't just substitute
		// $uploadDir with $webpDir.
		//
		// Instead, we rely on the public URL of the thumbnail. MediaTransformOutput::getUrl()
		// returns something like "/images/thumb/a/ab/File.png/220px-File.png" OR
		// "/thumb.php?f=File.png&width=220". Both forms are handled by
		// WebPRepo::getWebPUrlAndPath(), which gives us the matching WebP
		// filesystem destination inside images_webp/.
		$thumbUrl = method_exists( $thumb, 'getUrl' ) ? $thumb->getUrl() : null;
		if ( !is_string( $thumbUrl ) || $thumbUrl === '' ) {
			$this->logger->debug( 'onFileTransformed: no thumb URL for {name}, skipping',
				[ 'name' => $file->getName() ] );
			return true;
		}

		$webpInfo = $this->webpRepo->getWebPUrlAndPath( $thumbUrl );
		if ( $webpInfo === null ) {
			$this->logger->debug( 'onFileTransformed: thumb URL did not resolve to a WebP path: {url}',
				[ 'url' => $thumbUrl ] );
			return true;
		}
		[ , $webpDest ] = $webpInfo;

		// Don't regenerate if already present, non-empty and not stale. A
		// 0-byte/truncated leftover is treated as missing so we replace it
		// (atomically) rather than leave a broken WebP that the rewriter would
		// serve with no fallback.
		//
		// The staleness test matters because MediaWiki regenerates thumbnails at
		// the SAME paths: a WebP older than the thumbnail it was made from
		// depicts the previous image. That happens whenever a different image
		// lands on a name we already hold derivatives for without the reupload
		// purge running (rename then re-upload under the freed name, a
		// deleteBatch.php deletion then re-upload, …).
		$storedSrc = $webpInfo[2] ?? null;
		$isStale = false;
		if ( is_string( $storedSrc ) && is_file( $storedSrc ) && is_file( $webpDest ) ) {
			$srcTime = filemtime( $storedSrc );
			$destTime = filemtime( $webpDest );
			$isStale = $srcTime !== false && $destTime !== false && $srcTime > $destTime;
		}
		if ( is_file( $webpDest ) && filesize( $webpDest ) > 0 && !$isStale ) {
			return true;
		}

		// A derived basename too long for the filesystem can never be written;
		// attempting it just emits a PHP warning on every thumbnail render.
		if ( !$this->webpRepo->isWritablePathLength( $webpDest ) ) {
			$this->logger->debug( 'Skipping {dest}: derived filename too long for the filesystem',
				[ 'dest' => $webpDest ] );
			return true;
		}

		if ( !$this->webpRepo->ensureDirFor( $webpDest ) ) {
			$this->logger->warning( 'Could not create WebP thumb directory for {dest}',
				[ 'dest' => $webpDest ] );
			return true;
		}

		try {
			$optimizer = $this->optimizerFactory->getOptimizer();
			$lossless = ( $mime === 'image/png' )
				&& $this->options->get( 'VaultTecMediaOptimizerWebPLosslessForPng' );
			// Clamp to libwebp's 0-100 range (GD throws on negatives; vips
			// silently clamps; a non-numeric value casts to 0).
			$quality = min( 100, max( 0,
				(int)$this->options->get( 'VaultTecMediaOptimizerWebPQuality' ) ) );

			$ok = $optimizer->convertToWebP( $tmpThumbPath, $webpDest, $lossless, $quality );
			if ( !$ok ) {
				$detail = $optimizer->getLastError() ?? 'no detail';
				$this->logger->warning( 'WebP thumb generation failed for {name} ({dest}): {detail}', [
					'name' => $file->getName(),
					'dest' => $webpDest,
					'detail' => $detail,
				] );
			} else {
				// A WebP that came out LARGER than the thumbnail is deliberately
				// kept on disk: the skip-if-exists guard above then prevents
				// re-encoding it on every regeneration, and HtmlRewriter's
				// strictly-smaller serve guard guarantees it is never referenced
				// in <picture> — the visitor always receives the smaller asset.
				$this->logger->debug( 'Generated WebP thumb {dest}',
					[ 'dest' => $webpDest ] );
			}
		} catch ( RuntimeException $e ) {
			$this->logger->warning( 'WebP thumb generation failed for {name}: {msg}', [
				'name' => $file->getName(),
				'msg' => $e->getMessage(),
			] );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Unexpected error generating WebP thumb for {name}: {msg}', [
				'name' => $file->getName(),
				'msg' => $e->getMessage(),
			] );
		}

		// Second pass (zopflipng) on the new PNG thumbnail: a disk-only gain that
		// is FAR too slow to run while a visitor's page renders — zopflipng costs
		// seconds per file (see the benchmarks). We do NOT run it inline; instead
		// we enqueue a background job that recompresses the STORED thumbnail (by
		// the time the job runs, MediaWiki has copied the temp thumb to its final
		// location). Process the queue out-of-band (runJobs.php with $wgJobRunRate
		// = 0) so no visitor ever pays for it — see the documentation. In large-wiki
		// mode (UseJobQueue = false) we skip it; the admin recompresses on their own
		// schedule. The cheap WebP above stays synchronous (it is the served asset).
		$srcThumbStored = $webpInfo[2] ?? null;
		if ( $mime === 'image/png' && $this->zopfli->isAvailable()
			&& is_string( $srcThumbStored ) && $srcThumbStored !== ''
			&& $this->options->get( 'VaultTecMediaOptimizerUseJobQueue' )
		) {
			$jobTitle = Title::makeTitleSafe( NS_FILE, $file->getName() );
			if ( $jobTitle ) {
				$this->jobQueueGroup->lazyPush(
					new JobSpecification(
						'VaultTecMediaOptimizerThumbnailRecompress',
						[ 'srcThumb' => $srcThumbStored, 'mime' => $mime ],
						[ 'removeDuplicates' => true ],
						$jobTitle
					)
				);
			}
		}

		return true;
	}

	/**
	 * @inheritDoc
	 *
	 * Wraps <img> tags pointing to local images in a <picture> element with
	 * a WebP source. The <img> stays as fallback, preserving compatibility
	 * with MultimediaViewer, lazy-loading, and other JS that targets <img>.
	 *
	 * Only runs on the 'view' action to avoid rewriting previews, diffs, and
	 * edit forms, where users may see in-progress content that doesn't
	 * correspond to existing WebP files (the lookups would all miss and the
	 * regex would run for nothing).
	 */
	public function onOutputPageBeforeHTML( $out, &$text ) {
		// OutputPage extends ContextSource, so getActionName() is directly available.
		$action = $out->getActionName();
		// Skip non-view actions: edit, history, diff, raw, info, etc.
		if ( $action !== 'view' ) {
			return true;
		}
		$this->htmlRewriter->rewrite( $text );
		return true;
	}
}
