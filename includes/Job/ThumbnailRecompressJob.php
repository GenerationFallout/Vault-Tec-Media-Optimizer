<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Job;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\VaultTecMediaOptimizer\Optimizer\OptimizerFactory;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PngRecompressorInterface;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\Title;
use Throwable;

/**
 * Background job: run the slow per-thumbnail extras off the visitor's request —
 * the experimental AVIF copy and the zopflipng lossless second pass — on a
 * freshly generated thumbnail.
 *
 * Why a job rather than inline work in onFileTransformed? These passes cost
 * seconds per file (zopflipng ≈ 14 s; AVIF encoding is slow too). Running them
 * during a page render would make a cold gallery add seconds-to-minutes to a
 * single visitor's request. Enqueuing is near-instant; the work then happens
 * out-of-band — ideally with $wgJobRunRate = 0 and runJobs.php on the command
 * line, so no visitor ever pays for it (see the documentation).
 *
 * Operates on the STORED thumbnail: by the time the job runs, MediaWiki has
 * copied the temporary thumb to its final location. Best-effort and idempotent.
 */
class ThumbnailRecompressJob extends Job {

	private PngRecompressorInterface $zopfli;
	private OptimizationRecord $record;
	private OptimizerFactory $optimizerFactory;
	private WebPRepo $webpRepo;
	private ServiceOptions $options;

	/**
	 * @param Title $title File title (NS_FILE)
	 * @param array $params 'srcThumb' (stored thumb path), 'mime', optional
	 *                      'avifDest' and 'webpDest' for the AVIF keep-if-smaller step
	 * @param PngRecompressorInterface $zopfli Injected by extension.json
	 * @param OptimizationRecord $record Injected
	 * @param OptimizerFactory $optimizerFactory Injected
	 * @param WebPRepo $webpRepo Injected
	 * @param ServiceOptions $options Injected
	 */
	public function __construct(
		Title $title,
		array $params,
		PngRecompressorInterface $zopfli,
		OptimizationRecord $record,
		OptimizerFactory $optimizerFactory,
		WebPRepo $webpRepo,
		ServiceOptions $options
	) {
		parent::__construct( 'VaultTecMediaOptimizerThumbnailRecompress', $title, $params );
		$this->zopfli = $zopfli;
		$this->record = $record;
		$this->optimizerFactory = $optimizerFactory;
		$this->webpRepo = $webpRepo;
		$this->options = $options;
		$this->removeDuplicates = true;
	}

	/**
	 * @inheritDoc
	 */
	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		if ( is_array( $info['params'] ?? null ) ) {
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
		$avifDest = $this->params['avifDest'] ?? null;
		$webpDest = $this->params['webpDest'] ?? null;
		if ( !is_string( $src ) || $src === '' || !is_file( $src ) ) {
			return true;
		}
		$logger = LoggerFactory::getInstance( 'VaultTecMediaOptimizer' );

		// Experimental AVIF, kept only if strictly smaller than the WebP (a
		// <picture> offers AVIF first, so a larger AVIF would cost more than WebP).
		// A '.skip' marker avoids re-encoding a not-worth-it AVIF on later renders.
		if ( is_string( $avifDest ) && $avifDest !== ''
			&& ( !is_file( $avifDest ) || filesize( $avifDest ) === 0 )
			&& !$this->webpRepo->avifMarkedSkip( $avifDest, $src )
			&& $this->webpRepo->ensureDirFor( $avifDest )
		) {
			try {
				$optimizer = $this->optimizerFactory->getOptimizer();
				if ( $optimizer->supportsAvif() ) {
					$q = (int)$this->options->get( 'VaultTecMediaOptimizerAvifQuality' );
					if ( $optimizer->convertToAvif( $src, $avifDest, $q ) ) {
						$avifSz = filesize( $avifDest );
						$webpSz = ( is_string( $webpDest ) && is_file( $webpDest ) ) ? filesize( $webpDest ) : false;
						if ( $webpSz !== false && $webpSz > 0 && $avifSz !== false && $avifSz >= $webpSz ) {
							@unlink( $avifDest );
							$this->webpRepo->setAvifSkip( $avifDest );
						} else {
							$this->webpRepo->clearAvifSkip( $avifDest );
						}
					}
				}
			} catch ( Throwable $e ) {
				$logger->debug( 'ThumbnailRecompressJob AVIF failed for {src}: {msg}',
					[ 'src' => $src, 'msg' => $e->getMessage() ] );
			}
		}

		// zopflipng second pass on the stored PNG thumbnail (disk-only gain).
		if ( $mime === 'image/png' && $this->zopfli->isAvailable() ) {
			try {
				$saved = $this->zopfli->recompress( $src );
				if ( $saved !== null && $saved > 0 ) {
					$this->record->addThumbZopfliSaving( $saved );
				}
			} catch ( Throwable $e ) {
				$logger->debug( 'ThumbnailRecompressJob zopfli failed for {src}: {msg}',
					[ 'src' => $src, 'msg' => $e->getMessage() ] );
			}
		}
		// Always succeed: a failed best-effort optimization must not clog the queue.
		return true;
	}
}
