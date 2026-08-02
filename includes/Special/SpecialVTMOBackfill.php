<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Special;

use MediaWiki\Extension\VaultTecMediaOptimizer\Service\BackfillScheduler;
use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PngRecompressorInterface;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\OptimizationRecord;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:VTMOBackfill
 *
 * Allows admins to schedule and monitor backfill of existing images.
 * Each form submission schedules one batch of jobs; the actual processing
 * happens asynchronously via MediaWiki's job queue.
 *
 * Uses the Post-Redirect-Get pattern: every POST redirects to GET with a
 * query parameter carrying the result, so page reload (auto-refresh) never
 * re-submits the form by accident.
 */
class SpecialVTMOBackfill extends SpecialPage {

	private BackfillScheduler $scheduler;
	private OptimizationRecord $record;
	private PngRecompressorInterface $zopfli;

	public function __construct(
		BackfillScheduler $scheduler,
		OptimizationRecord $record,
		PngRecompressorInterface $zopfli
	) {
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
		parent::__construct( 'VTMOBackfill', 'vaulttecmediaoptimizer-admin' );
		$this->scheduler = $scheduler;
		$this->record = $record;
		$this->zopfli = $zopfli;
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'media';
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->checkReadOnly();

		$request = $this->getRequest();
		$user = $this->getUser();

		// === Handle POST actions via PRG pattern ===
		if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$result = null;
			if ( $request->getCheck( 'wpScheduleBatch' ) ) {
				$count = $this->scheduler->scheduleBatch();
				$result = "scheduled:$count";
			} elseif ( $request->getCheck( 'wpScheduleAll' ) ) {
				// Enqueue all eligible files in one go. Capped at 100k to avoid
				// pathological situations (the wiki shouldn't have more, but
				// just in case).
				$count = $this->scheduler->scheduleBatch( 100000 );
				$result = "scheduledall:$count";
			} elseif ( $request->getCheck( 'wpResetFailed' ) ) {
				$count = $this->record->resetFailedToPending();
				$result = "reset:$count";
			} elseif ( $request->getCheck( 'wpScheduleZopfli' ) ) {
				// Schedule second-pass zopflipng recompression of original PNGs.
				$count = $this->scheduler->scheduleZopfliBatch( 100000 );
				$result = "zopfli:$count";
			}
			if ( $result !== null ) {
				// Post-Redirect-Get: redirect to GET with the result in a query
				// parameter, so reload never re-POSTs.
				$this->getOutput()->redirect(
					$this->getPageTitle()->getLocalURL( [ 'result' => $result ] )
				);
				return;
			}
		}

		$out = $this->getOutput();
		$out->addModuleStyles( [ 'ext.vaultTecMediaOptimizer.dashboard' ] );
		$out->addModules( [ 'ext.vaultTecMediaOptimizer.dashboard' ] );

		// Intro
		$out->addHTML( Html::element( 'p',
			[ 'class' => 'mw-vtmo-intro' ],
			$this->msg( 'vaulttecmediaoptimizer-backfill-intro' )->text()
		) );

		// Show banner if we just performed an action
		$resultParam = $request->getVal( 'result' );
		if ( $resultParam !== null ) {
			$out->addHTML( $this->renderResultBanner( $resultParam ) );
		}

		// Large-wiki mode: the schedule buttons below still enqueue jobs, but
		// with the queue mode off the admin has (per the documented setup) no
		// job runner consuming them — clicks would pile up jobs shown as
		// "Queued: N" forever with no explanation. Warn before they wonder.
		if ( !$this->getConfig()->get( 'VaultTecMediaOptimizerUseJobQueue' ) ) {
			$out->addHTML( Html::warningBox(
				$this->msg( 'vaulttecmediaoptimizer-backfill-queue-disabled-warning' )->escaped()
			) );
		}

		// === Stats ===
		$stats = $this->record->getStats();
		$totalEligible = $this->scheduler->countTotalEligible();
		$pending = $this->scheduler->countPending();
		$queued = $this->scheduler->getQueuedJobCount();
		$failed = (int)( $stats['failed'] ?? 0 );

		$out->addHTML( $this->renderProgress( $stats, $totalEligible ) );
		$out->addHTML( $this->renderStatsGrid( $stats, $totalEligible, $pending, $queued ) );

		// === Forms ===
		$out->addHTML( $this->renderForms( $pending, $failed ) );

		// Hint about $wgJobRunRate
		$out->addHTML( Html::element( 'p',
			[ 'class' => 'mw-vtmo-hint' ],
			$this->msg( 'vaulttecmediaoptimizer-backfill-rate-hint' )->text()
		) );
	}

	private function renderResultBanner( string $resultParam ): string {
		[ $action, $value ] = array_pad( explode( ':', $resultParam, 2 ), 2, '' );
		$count = (int)$value;
		$cssClass = 'mw-vtmo-summary mw-vtmo-summary-ok';
		$msg = '';

		switch ( $action ) {
			case 'scheduled':
				if ( $count > 0 ) {
					$msg = $this->msg( 'vaulttecmediaoptimizer-backfill-queue-info', $count )->text();
				} else {
					$cssClass = 'mw-vtmo-summary mw-vtmo-summary-warn';
					$msg = $this->msg( 'vaulttecmediaoptimizer-backfill-no-eligible' )->text();
				}
				break;
			case 'scheduledall':
				if ( $count > 0 ) {
					$msg = $this->msg( 'vaulttecmediaoptimizer-backfill-scheduledall-info', $count )->text();
				} else {
					$cssClass = 'mw-vtmo-summary mw-vtmo-summary-warn';
					$msg = $this->msg( 'vaulttecmediaoptimizer-backfill-no-eligible' )->text();
				}
				break;
			case 'reset':
				$msg = $this->msg( 'vaulttecmediaoptimizer-backfill-reset-done', $count )->text();
				break;
			case 'zopfli':
				if ( $count > 0 ) {
					$msg = $this->msg( 'vaulttecmediaoptimizer-backfill-zopfli-info', $count )->text();
				} else {
					$cssClass = 'mw-vtmo-summary mw-vtmo-summary-warn';
					$msg = $this->msg( 'vaulttecmediaoptimizer-backfill-zopfli-none' )->text();
				}
				break;
			default:
				return '';
		}

		return Html::rawElement( 'div', [ 'class' => $cssClass ],
			Html::element( 'strong', [], $msg )
		);
	}

	private function renderProgress( array $stats, int $totalEligible ): string {
		$processed = (int)( $stats['complete'] ?? 0 ) + (int)( $stats['skipped'] ?? 0 )
			+ (int)( $stats['failed'] ?? 0 );
		$percent = $totalEligible > 0
			? min( 100, (int)round( $processed / $totalEligible * 100 ) )
			: 0;

		return Html::rawElement( 'div',
			[ 'class' => 'mw-vtmo-progress' ],
			Html::element( 'div',
				[
					'class' => 'mw-vtmo-progress-bar',
					'style' => "width: {$percent}%;",
				],
				''
			) .
			Html::element( 'span',
				[ 'class' => 'mw-vtmo-progress-label' ],
				$this->msg( 'vaulttecmediaoptimizer-backfill-progress',
					$processed, $totalEligible, $percent )->text()
			)
		);
	}

	private function renderStatsGrid( array $stats, int $totalEligible, int $pending, int $queued ): string {
		$cards = [];

		$cards[] = $this->renderStatCard(
			$this->msg( 'vaulttecmediaoptimizer-backfill-total-files' )->text(),
			(string)$totalEligible
		);
		$cards[] = $this->renderStatCard(
			$this->msg( 'vaulttecmediaoptimizer-backfill-processed' )->text(),
			(string)( $stats['complete'] ?? 0 )
		);
		$cards[] = $this->renderStatCard(
			$this->msg( 'vaulttecmediaoptimizer-backfill-pending' )->text(),
			(string)$pending
		);
		$cards[] = $this->renderStatCard(
			$this->msg( 'vaulttecmediaoptimizer-backfill-failed' )->text(),
			(string)( $stats['failed'] ?? 0 )
		);
		$cards[] = $this->renderStatCard(
			$this->msg( 'vaulttecmediaoptimizer-backfill-skipped' )->text(),
			(string)( $stats['skipped'] ?? 0 )
		);
		$cards[] = $this->renderStatCard(
			$this->msg( 'vaulttecmediaoptimizer-backfill-queued' )->text(),
			(string)$queued
		);

		return Html::rawElement( 'div',
			[ 'class' => 'mw-vtmo-stats-grid' ],
			implode( '', $cards )
		);
	}

	private function renderStatCard( string $label, string $value ): string {
		return Html::rawElement( 'div',
			[ 'class' => 'mw-vtmo-stat-card' ],
			Html::element( 'div',
				[ 'class' => 'mw-vtmo-stat-card-value' ],
				$value
			) .
			Html::element( 'div',
				[ 'class' => 'mw-vtmo-stat-card-label' ],
				$label
			)
		);
	}

	/**
	 * Render the action forms: "Schedule batch", "Schedule all", and
	 * (if any failures) "Retry failed".
	 */
	private function renderForms( int $pending, int $failed ): string {
		$out = '';
		$token = $this->getUser()->getEditToken();
		$action = $this->getPageTitle()->getLocalURL();
		$disabled = $pending === 0;

		// Wrap both schedule buttons in a single form area
		$out .= Html::rawElement( 'div',
			[ 'class' => 'mw-vtmo-form-row' ],

			// Schedule one batch (configurable size, default 20)
			Html::rawElement( 'form',
				[
					'method' => 'POST',
					'action' => $action,
					'class' => 'mw-vtmo-form',
					'style' => 'display: inline-block; margin-right: 0.5em;',
				],
				Html::hidden( 'wpEditToken', $token ) .
				Html::element( 'button',
					[
						'type' => 'submit',
						'name' => 'wpScheduleBatch',
						'value' => '1',
						'class' => 'mw-vtmo-button',
						'disabled' => $disabled ? 'disabled' : null,
					],
					$this->msg( 'vaulttecmediaoptimizer-backfill-schedule-batch' )->text()
				)
			) .

			// Schedule ALL remaining files in one go
			Html::rawElement( 'form',
				[
					'method' => 'POST',
					'action' => $action,
					'class' => 'mw-vtmo-form',
					'style' => 'display: inline-block;',
				],
				Html::hidden( 'wpEditToken', $token ) .
				Html::element( 'button',
					[
						'type' => 'submit',
						'name' => 'wpScheduleAll',
						'value' => '1',
						'class' => 'mw-vtmo-button mw-vtmo-button-primary',
						'disabled' => $disabled ? 'disabled' : null,
						'title' => $this->msg( 'vaulttecmediaoptimizer-backfill-schedule-all-tooltip' )->text(),
					],
					$this->msg( 'vaulttecmediaoptimizer-backfill-schedule-all', $pending )->text()
				)
			)
		);

		// Retry failed (only show when there are failures)
		if ( $failed > 0 ) {
			$out .= Html::rawElement( 'form',
				[
					'method' => 'POST',
					'action' => $action,
					'class' => 'mw-vtmo-form',
				],
				Html::hidden( 'wpEditToken', $token ) .
				Html::element( 'button',
					[
						'type' => 'submit',
						'name' => 'wpResetFailed',
						'value' => '1',
						'class' => 'mw-vtmo-button',
					],
					$this->msg( 'vaulttecmediaoptimizer-backfill-reset-failed', $failed )->text()
				)
			);
		}

		// Second-pass recompression of original PNGs. Only shown when the
		// configured engine is actually available, otherwise the button would
		// just produce no-ops and confuse the admin. A short note explains the
		// gain is disk-space only. The engine name is shown dynamically so the
		// UI matches whichever engine (oxipng/zopflipng) is configured.
		if ( $this->zopfli->isAvailable() ) {
			$engineName = $this->zopfli->getName();
			$zopfliPending = $this->scheduler->countZopfliPending();
			$out .= Html::rawElement( 'div',
				[ 'class' => 'mw-vtmo-zopfli-section' ],
				Html::element( 'h2', [],
					$this->msg( 'vaulttecmediaoptimizer-backfill-zopfli-heading' )->text() ) .
				Html::element( 'p', [ 'class' => 'mw-vtmo-hint' ],
					$this->msg( 'vaulttecmediaoptimizer-backfill-zopfli-explain' )
						->params( $engineName )->text() ) .
				Html::rawElement( 'form',
					[
						'method' => 'POST',
						'action' => $action,
						'class' => 'mw-vtmo-form',
					],
					Html::hidden( 'wpEditToken', $token ) .
					Html::element( 'button',
						[
							'type' => 'submit',
							'name' => 'wpScheduleZopfli',
							'value' => '1',
							'class' => 'mw-vtmo-button',
							'disabled' => $zopfliPending === 0 ? 'disabled' : false,
						],
						$this->msg( 'vaulttecmediaoptimizer-backfill-zopfli-start' )
							->params( $engineName )->numParams( $zopfliPending )->text()
					)
				)
			);
		}

		return $out;
	}
}
