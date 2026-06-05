<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Job;

use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PngRecompressorInterface;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\Title;
use Throwable;

/**
 * Background job: run the slow lossless second pass (zopflipng) on a freshly
 * generated PNG thumbnail.
 *
 * Why a job rather than inline work in onFileTransformed? zopflipng costs
 * seconds per file (the guide benchmarks it at ~14 s). Running it during a page
 * render would make a cold gallery add seconds-to-minutes to a single visitor's
 * request. Enqueuing is near-instant; the actual recompression then happens
 * out-of-band — ideally with $wgJobRunRate = 0 and runJobs.php on the command
 * line, so no visitor ever pays for it (see the documentation).
 *
 * Operates on the STORED thumbnail path: by the time the job runs, MediaWiki has
 * already copied the temporary thumb to its final location. The recompression is
 * lossless and keep-if-smaller (a file is never enlarged); savings are tracked in
 * the aggregate thumb-stats table. Best-effort and idempotent.
 */
class ThumbnailRecompressJob extends Job {

	private PngRecompressorInterface $zopfli;
	private OptimizationRecord $record;

	/**
	 * @param Title $title File title (NS_FILE)
	 * @param array $params Must include 'srcThumb' (absolute path) and 'mime'
	 * @param PngRecompressorInterface $zopfli Injected by extension.json
	 * @param OptimizationRecord $record Injected by extension.json
	 */
	public function __construct(
		Title $title,
		array $params,
		PngRecompressorInterface $zopfli,
		OptimizationRecord $record
	) {
		parent::__construct( 'VaultTecMediaOptimizerThumbnailRecompress', $title, $params );
		$this->zopfli = $zopfli;
		$this->record = $record;
		// Same stored thumbnail enqueued twice yields the same result.
		$this->removeDuplicates = true;
	}

	/**
	 * @inheritDoc
	 */
	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		if ( is_array( $info['params'] ?? null ) ) {
			// Deduplicate on the thumbnail path only (ignore requestId, etc.).
			$info['params'] = [ 'srcThumb' => $info['params']['srcThumb'] ?? '' ];
		}
		return $info;
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$src = $this->params['srcThumb'] ?? null;
		$mime = $this->params['mime'] ?? '';
		if ( !is_string( $src ) || $src === '' || !is_file( $src ) ) {
			// Thumbnail gone (regenerated/cleaned) — nothing to do.
			return true;
		}
		if ( $mime === 'image/png' && $this->zopfli->isAvailable() ) {
			try {
				$saved = $this->zopfli->recompress( $src );
				if ( $saved !== null && $saved > 0 ) {
					$this->record->addThumbZopfliSaving( $saved );
				}
			} catch ( Throwable $e ) {
				LoggerFactory::getInstance( 'VaultTecMediaOptimizer' )->debug(
					'ThumbnailRecompressJob failed for {src}: {msg}',
					[ 'src' => $src, 'msg' => $e->getMessage() ]
				);
			}
		}
		// Always succeed: a failed disk-only optimization must not clog the queue.
		return true;
	}
}
