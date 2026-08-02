<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Special;

use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:VTMOStats
 *
 * Read-only view of optimization gains and failures.
 */
class SpecialVTMOStats extends SpecialPage {

	private OptimizationRecord $record;

	public function __construct( OptimizationRecord $record ) {
		// NE PAS "corriger" l'avertissement de dépréciation de MediaWiki 1.46
		// («  constructor parameters $restriction ... deprecated, override
		// getRestriction() instead  ») tant que extension.json autorise 1.44/1.45.
		// Vérifié sur les sources réelles : en 1.44.0 et 1.45.4, userCanExecute()
		// et isRestricted() lisent la propriété privée $mRestriction DIRECTEMENT,
		// et seule la 1.46 est passée par l'accesseur getRestriction(). Retirer ce
		// 2e argument au profit d'une surcharge de getRestriction() laisserait donc
		// $mRestriction vide sur 1.44/1.45 : le contrôle de droit deviendrait
		// userHasRight($user, '') et cette page d'administration serait accessible
		// à TOUT LE MONDE, y compris aux anonymes. La forme dépréciée est ici un
		// choix délibéré ; elle reste fonctionnelle en 1.46 (simple avertissement).
		// À changer uniquement quand le minimum requis passera à 1.46.
		parent::__construct( 'VTMOStats', 'vaulttecmediaoptimizer-admin' );
		$this->record = $record;
	}

	/** @inheritDoc */
	public function doesWrites() {
		return false;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'media';
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();

		$out = $this->getOutput();
		$out->addModuleStyles( [ 'ext.vaultTecMediaOptimizer.dashboard' ] );

		$out->addHTML( Html::element( 'p',
			[ 'class' => 'mw-vtmo-intro' ],
			$this->msg( 'vaulttecmediaoptimizer-stats-intro' )->text()
		) );

		$stats = $this->record->getStats();

		if ( ( $stats['total'] ?? 0 ) === 0 ) {
			$out->addHTML( Html::rawElement( 'div',
				[ 'class' => 'mw-vtmo-summary mw-vtmo-summary-warn' ],
				Html::element( 'strong', [],
					$this->msg( 'vaulttecmediaoptimizer-stats-no-data' )->text() )
			) );
			return;
		}

		$out->addHTML( $this->renderOverview( $stats ) );
		$out->addHTML( $this->renderBandwidthPerPage( $stats ) );
		$out->addHTML( $this->renderDiskBalance( $stats ) );
		$out->addHTML( $this->renderRatios( $stats ) );
		$out->addHTML( $this->renderZopfli() );
		$out->addHTML( $this->renderFormatBreakdown( $stats ) );
		$out->addHTML( $this->renderBackendBreakdown( $stats ) );
		$out->addHTML( $this->renderFailedList() );
	}

	/**
	 * Render second-pass zopflipng recompression stats (originals + thumbnails),
	 * if any recompression has happened. Combines the per-file originals
	 * progress and the aggregate thumbnail savings.
	 */
	private function renderZopfli(): string {
		$orig = $this->record->getZopfliOriginalsProgress();
		$thumb = $this->record->getThumbZopfliStats();

		$totalSaved = (int)$orig['saved'] + (int)$thumb['saved'];

		// Nothing to show if zopfli never ran.
		if ( $orig['done'] === 0 && $thumb['count'] === 0 ) {
			return '';
		}

		$lang = $this->getLanguage();

		$rows = [
			[ $this->msg( 'vaulttecmediaoptimizer-stats-zopfli-total-saved' )->text(),
				$lang->formatSize( max( 0, $totalSaved ) ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-zopfli-originals' )->text(),
				$lang->formatNum( (int)$orig['done'] ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-zopfli-thumbs' )->text(),
				$lang->formatNum( (int)$thumb['count'] ) ],
		];

		// Progress line for originals still pending, if any.
		$pendingLine = '';
		if ( (int)$orig['pending'] > 0 ) {
			$done = (int)$orig['done'];
			$total = $done + (int)$orig['pending'];
			$pct = $total > 0 ? round( $done / $total * 100, 1 ) : 0;
			$pendingLine = Html::element( 'p',
				[ 'class' => 'mw-vtmo-intro' ],
				$this->msg( 'vaulttecmediaoptimizer-stats-zopfli-progress' )
					->numParams( $done, $total )
					->params( $lang->formatNum( $pct ) )
					->text()
			);
		}

		return $this->renderSection(
			$this->msg( 'vaulttecmediaoptimizer-stats-zopfli-heading' )->text(),
			$this->kvTable( $rows ) . $pendingLine
		);
	}

	private function renderOverview( array $stats ): string {
		$lang = $this->getLanguage();

		// upload -> Imagick
		$savedFirstPass = (int)( $stats['lossless_saved'] ?? 0 );
		// Imagick -> oxipng/zopfli
		$savedSecondPass = (int)( $stats['secondpass_saved'] ?? 0 );
		// upload -> real file
		$savedTotal = (int)( $stats['total_saved'] ?? 0 );
		$webpSize = (int)$stats['total_webp_size'];
		$bandwidthSaved = (int)( $stats['webp_bandwidth_saved'] ?? 0 );

		$rows = [
			[ $this->msg( 'vaulttecmediaoptimizer-stats-total-processed' )->text(),
				$lang->formatNum( (int)$stats['complete'] ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-space-saved-total' )->text(),
				$lang->formatSize( max( 0, $savedTotal ) ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-space-saved-originals' )->text(),
				$lang->formatSize( max( 0, $savedFirstPass ) ) ],
		];
		if ( $savedSecondPass > 0 ) {
			$rows[] = [ $this->msg( 'vaulttecmediaoptimizer-stats-space-saved-secondpass' )->text(),
				$lang->formatSize( $savedSecondPass ) ];
		}
		$rows[] = [ $this->msg( 'vaulttecmediaoptimizer-stats-space-webp' )->text(),
			$lang->formatSize( $webpSize ) ];
		$rows[] = [ $this->msg( 'vaulttecmediaoptimizer-stats-bandwidth-saved' )->text(),
			$lang->formatSize( max( 0, $bandwidthSaved ) ) ];

		return $this->renderSection(
			$this->msg( 'vaulttecmediaoptimizer-stats-overview-heading' )->text(),
			$this->kvTable( $rows )
		);
	}

	/**
	 * Build a two-column "indicator / value" table from rows of [label, value].
	 *
	 * @param array<int, array{0:string,1:string}> $rows
	 * @return string
	 */
	private function kvTable( array $rows ): string {
		$body = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-col-indicator' )->text() ) .
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-col-value' )->text() )
		);
		foreach ( $rows as $r ) {
			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $r[0] ) .
				Html::element( 'td', [ 'class' => 'mw-vtmo-num' ], $r[1] )
			);
		}
		return Html::rawElement( 'table',
			[ 'class' => 'wikitable mw-vtmo-table' ],
			Html::rawElement( 'tbody', [], $body )
		);
	}

	/**
	 * Wrap content in a titled section.
	 */
	private function renderSection( string $title, string $content ): string {
		return Html::rawElement( 'section',
			[ 'class' => 'mw-vtmo-section' ],
			Html::element( 'h2', [], $title ) . $content
		);
	}

	/**
	 * Disk-space balance: the extension SAVES space by optimizing originals but
	 * ADDS the generated WebP files. The net result on disk is usually negative
	 * (WebP files weigh more than the optimization gain) — the benefit is visitor
	 * bandwidth, not disk. We compute the balance on originals (the data we track
	 * reliably) and note that thumbnail WebP files add further storage.
	 */
	private function renderDiskBalance( array $stats ): string {
		$lang = $this->getLanguage();
		// optimization gain (originals)
		$saved = (int)( $stats['total_saved'] ?? 0 );
		// WebP added (originals)
		$webp = (int)( $stats['total_webp_size'] ?? 0 );
		// signed
		$balance = $saved - $webp;

		$balanceText = ( $balance >= 0 ? '+' : '−' )
			. $lang->formatSize( abs( $balance ) );

		$rows = [
			[ $this->msg( 'vaulttecmediaoptimizer-stats-disk-saved' )->text(),
				'+' . $lang->formatSize( max( 0, $saved ) ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-disk-webp' )->text(),
				'−' . $lang->formatSize( $webp ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-disk-balance' )->text(),
				$balanceText ],
		];

		$noteKey = $balance < 0
			? 'vaulttecmediaoptimizer-stats-disk-note-negative'
			: 'vaulttecmediaoptimizer-stats-disk-note-positive';

		return $this->renderSection(
			$this->msg( 'vaulttecmediaoptimizer-stats-disk-heading' )->text(),
			$this->kvTable( $rows )
			. Html::element( 'p', [ 'class' => 'mw-vtmo-intro' ],
				$this->msg( $noteKey )->text() )
		);
	}

	/**
	 * Bandwidth saved on a typical page. A wiki page serves thumbnails, not full
	 * originals. We illustrate with a page showing a fixed number of thumbnails
	 * of a reference size, and apply the observed average WebP reduction ratio.
	 * The assumptions are shown explicitly so the figure is understood as an
	 * illustration, not a measurement (the extension does not track per-page views).
	 */
	private function renderBandwidthPerPage( array $stats ): string {
		$lang = $this->getLanguage();
		// webp / optimized
		$webpRatio = (float)( $stats['webp_ratio'] ?? 0.0 );
		if ( $webpRatio <= 0 || $webpRatio >= 1 ) {
			return '';
		}

		// Illustration assumptions.
		$thumbsPerPage = 10;
		// representative size of a ~220px article thumbnail
		$thumbKiB = 50;
		$origBytes = $thumbsPerPage * $thumbKiB * 1024;
		$webpBytes = (int)round( $origBytes * $webpRatio );
		$savedBytes = $origBytes - $webpBytes;
		$savingPct = ( 1 - $webpRatio ) * 100;

		$rows = [
			[ $this->msg( 'vaulttecmediaoptimizer-stats-bw-assumption' )->text(),
				$this->msg( 'vaulttecmediaoptimizer-stats-bw-assumption-val' )
					->numParams( $thumbsPerPage )->params( $lang->formatSize( $thumbKiB * 1024 ) )->text() ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-bw-original' )->text(),
				$lang->formatSize( $origBytes ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-bw-webp' )->text(),
				$lang->formatSize( $webpBytes ) ],
			[ $this->msg( 'vaulttecmediaoptimizer-stats-bw-saved' )->text(),
				$lang->formatSize( $savedBytes ) . ' (−' . $lang->formatNum( round( $savingPct, 1 ) ) . '%)' ],
		];

		return $this->renderSection(
			$this->msg( 'vaulttecmediaoptimizer-stats-bw-heading' )->text(),
			$this->kvTable( $rows )
			. Html::element( 'p', [ 'class' => 'mw-vtmo-intro' ],
				$this->msg( 'vaulttecmediaoptimizer-stats-bw-note' )->text() )
		);
	}

	/**
	 * Render compression ratio bars: how light the optimized originals and
	 * the WebP versions are compared to the source. Pure CSS bars, no JS.
	 */
	private function renderRatios( array $stats ): string {
		$losslessRatio = (float)( $stats['lossless_ratio'] ?? 0.0 );
		$webpRatio = (float)( $stats['webp_ratio'] ?? 0.0 );

		if ( $losslessRatio <= 0 && $webpRatio <= 0 ) {
			return '';
		}

		$lang = $this->getLanguage();

		// lossless: optimized / original. Saving = 1 - ratio.
		$losslessSavingPct = $losslessRatio > 0 ? ( 1 - $losslessRatio ) * 100 : 0;
		// webp: webp / optimized. Saving = 1 - ratio.
		$webpSavingPct = $webpRatio > 0 ? ( 1 - $webpRatio ) * 100 : 0;

		$body = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-col-indicator' )->text() ) .
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-ratio-col-saving' )->text() ) .
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-ratio-col-relative' )->text() )
		);

		if ( $losslessRatio > 0 ) {
			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [],
					$this->msg( 'vaulttecmediaoptimizer-stats-ratio-lossless' )->text() ) .
				Html::element( 'td', [ 'class' => 'mw-vtmo-num' ],
					$this->msg( 'vaulttecmediaoptimizer-stats-ratio-saving',
						$lang->formatNum( round( $losslessSavingPct, 1 ) ) )->text() ) .
				Html::element( 'td', [ 'class' => 'mw-vtmo-num' ],
					$lang->formatNum( round( $losslessRatio * 100, 1 ) ) . '%' )
			);
		}

		if ( $webpRatio > 0 ) {
			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [],
					$this->msg( 'vaulttecmediaoptimizer-stats-ratio-webp' )->text() ) .
				Html::element( 'td', [ 'class' => 'mw-vtmo-num' ],
					$this->msg( 'vaulttecmediaoptimizer-stats-ratio-saving',
						$lang->formatNum( round( $webpSavingPct, 1 ) ) )->text() ) .
				Html::element( 'td', [ 'class' => 'mw-vtmo-num' ],
					$lang->formatNum( round( $webpRatio * 100, 1 ) ) . '%' )
			);
		}

		return $this->renderSection(
			$this->msg( 'vaulttecmediaoptimizer-stats-compression-ratios' )->text(),
			Html::rawElement( 'table',
				[ 'class' => 'wikitable mw-vtmo-table' ],
				Html::rawElement( 'tbody', [], $body )
			)
		);
	}

	/**
	 * Render gains broken down by file format (PNG / JPEG / GIF / ...).
	 */
	private function renderFormatBreakdown( array $stats ): string {
		if ( empty( $stats['by_format'] ) ) {
			return '';
		}

		$lang = $this->getLanguage();

		// Sort formats by original size descending (biggest first).
		$formats = $stats['by_format'];
		uasort( $formats, static fn ( $a, $b ) => $b['original'] <=> $a['original'] );

		$headerCells =
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-fmt-format' )->text() ) .
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-fmt-count' )->text() ) .
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-fmt-original' )->text() ) .
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-fmt-webp' )->text() ) .
			Html::element( 'th', [], $this->msg( 'vaulttecmediaoptimizer-stats-fmt-saving' )->text() );

		$rows = Html::rawElement( 'tr', [], $headerCells );

		foreach ( $formats as $fmt => $data ) {
			$orig = (int)$data['original'];
			$webp = (int)$data['webp'];
			$savingPct = $orig > 0 ? ( 1 - $webp / $orig ) * 100 : 0;

			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], (string)$fmt ) .
				Html::element( 'td', [], $lang->formatNum( (int)$data['count'] ) ) .
				Html::element( 'td', [], $lang->formatSize( $orig ) ) .
				Html::element( 'td', [], $lang->formatSize( $webp ) ) .
				Html::element( 'td', [],
					$this->msg( 'vaulttecmediaoptimizer-stats-ratio-saving',
						$lang->formatNum( round( $savingPct, 1 ) ) )->text() )
			);
		}

		return Html::rawElement( 'section',
			[ 'class' => 'mw-vtmo-section' ],
			Html::element( 'h2', [],
				$this->msg( 'vaulttecmediaoptimizer-stats-by-format' )->text() ) .
			Html::rawElement( 'table',
				[ 'class' => 'wikitable mw-vtmo-table' ],
				Html::rawElement( 'tbody', [], $rows )
			)
		);
	}

	private function renderBackendBreakdown( array $stats ): string {
		if ( empty( $stats['by_backend'] ) ) {
			return '';
		}

		$rows = '';
		foreach ( $stats['by_backend'] as $backend => $count ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $backend ) .
				Html::element( 'td', [], (string)$count )
			);
		}

		return Html::rawElement( 'section',
			[ 'class' => 'mw-vtmo-section' ],
			Html::element( 'h2', [],
				$this->msg( 'vaulttecmediaoptimizer-stats-by-backend' )->text() ) .
			Html::rawElement( 'table',
				[ 'class' => 'wikitable mw-vtmo-table' ],
				Html::rawElement( 'tbody', [], $rows )
			)
		);
	}

	private function renderFailedList(): string {
		$failed = $this->record->getFailedFiles( 20 );
		if ( !$failed ) {
			return '';
		}

		$rows = '';
		foreach ( $failed as $f ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $f['name'] ) .
				Html::element( 'td',
					[ 'class' => 'mw-vtmo-detail' ],
					$f['error']
				)
			);
		}

		return Html::rawElement( 'section',
			[ 'class' => 'mw-vtmo-section' ],
			Html::element( 'h2', [],
				$this->msg( 'vaulttecmediaoptimizer-stats-failed-files' )->text() ) .
			Html::rawElement( 'table',
				[ 'class' => 'wikitable mw-vtmo-table' ],
				Html::rawElement( 'tbody', [], $rows )
			)
		);
	}
}
