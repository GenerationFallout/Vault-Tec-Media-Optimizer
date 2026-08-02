<?php

namespace MediaWiki\Extension\VaultTecMediaOptimizer\Special;

use MediaWiki\Extension\VaultTecMediaOptimizer\Service\PrerequisiteChecker;
use MediaWiki\Extension\VaultTecMediaOptimizer\Storage\WebPRepo;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:VTMOStatus
 *
 * Dashboard showing the extension's prerequisites and environment health.
 * Reserved for users with the vaulttecmediaoptimizer-admin permission.
 *
 * Also offers a one-click "Create WebP directory" action when the directory
 * is missing but the parent is writable (using PRG to avoid re-submit on
 * reload).
 */
class SpecialVTMOStatus extends SpecialPage {

	private PrerequisiteChecker $checker;
	private WebPRepo $webpRepo;

	public function __construct( PrerequisiteChecker $checker, WebPRepo $webpRepo ) {
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
		parent::__construct( 'VTMOStatus', 'vaulttecmediaoptimizer-admin' );
		$this->checker = $checker;
		$this->webpRepo = $webpRepo;
	}

	/** @inheritDoc */
	public function doesWrites() {
		// We may create the WebP directory on POST, which counts as a write
		// in the broad sense (filesystem). MediaWiki uses this to gate read-only
		// mode, so declaring true is the safe choice.
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

		// === PRG: handle POST actions then redirect to GET ===
		if ( $request->wasPosted()
			&& $user->matchEditToken( $request->getVal( 'wpEditToken' ) )
			&& $request->getCheck( 'wpCreateWebpDir' )
		) {
			[ $ok, $msg ] = $this->webpRepo->createRootDir();
			// Truncate to keep the redirect URL within typical limits (~2KB)
			$shortMsg = mb_substr( $msg, 0, 500 );
			$result = ( $ok ? 'webpdir-ok:' : 'webpdir-fail:' ) . rawurlencode( $shortMsg );
			$this->getOutput()->redirect(
				$this->getPageTitle()->getLocalURL( [ 'result' => $result ] )
			);
			return;
		}

		$out = $this->getOutput();
		$out->addModuleStyles( [ 'ext.vaultTecMediaOptimizer.dashboard' ] );

		$out->addHTML( Html::element( 'p',
			[ 'class' => 'mw-vtmo-intro' ],
			$this->msg( 'vaulttecmediaoptimizer-status-intro' )->text()
		) );

		// Result banner from a previous POST
		$resultParam = $request->getVal( 'result' );
		if ( $resultParam !== null ) {
			$out->addHTML( $this->renderResultBanner( $resultParam ) );
		}

		$report = $this->checker->runAll();

		$out->addHTML( $this->renderSummary( $report['summary'] ) );
		$out->addHTML( $this->renderActions( $report['summary'] ) );

		// If the WebP dir check failed, offer the create button
		if ( $this->shouldOfferCreateWebpDir( $report ) ) {
			$out->addHTML( $this->renderCreateWebpDirForm() );
		}

		$sectionTitles = [
			'system' => 'vaulttecmediaoptimizer-status-section-system',
			'libraries' => 'vaulttecmediaoptimizer-status-section-libraries',
			'filesystem' => 'vaulttecmediaoptimizer-status-section-filesystem',
			'database' => 'vaulttecmediaoptimizer-status-section-database',
			'zopfli' => 'vaulttecmediaoptimizer-status-section-zopfli',
			'gifsicle' => 'vaulttecmediaoptimizer-status-section-gifsicle',
			'extensions' => 'vaulttecmediaoptimizer-status-section-extensions',
			'config' => 'vaulttecmediaoptimizer-status-section-config',
		];

		foreach ( $report['sections'] as $key => $checks ) {
			if ( !$checks ) {
				continue;
			}
			$out->addHTML( $this->renderSection( $sectionTitles[$key] ?? $key, $checks ) );
		}
	}

	private function renderSummary( array $summary ): string {
		$failCount = $summary['fail'] ?? 0;
		$warnCount = $summary['warn'] ?? 0;

		if ( $failCount > 0 ) {
			$class = 'mw-vtmo-summary-fail';
			$msg = $this->msg( 'vaulttecmediaoptimizer-status-has-failures', $failCount )->text();
		} elseif ( $warnCount > 0 ) {
			$class = 'mw-vtmo-summary-warn';
			$msg = $this->msg( 'vaulttecmediaoptimizer-status-has-warnings', $warnCount )->text();
		} else {
			$class = 'mw-vtmo-summary-ok';
			$msg = $this->msg( 'vaulttecmediaoptimizer-status-no-failures' )->text();
		}

		return Html::rawElement( 'div',
			[ 'class' => "mw-vtmo-summary $class" ],
			Html::element( 'strong', [], $msg ) . ' ' . $this->renderCounts( $summary )
		);
	}

	private function renderCounts( array $summary ): string {
		$parts = [];
		// Message keys are built dynamically below. Listed here so grep-based
		// tooling and translatewiki maintainers can find them:
		// * vaulttecmediaoptimizer-status-ok
		// * vaulttecmediaoptimizer-status-warn
		// * vaulttecmediaoptimizer-status-fail
		// * vaulttecmediaoptimizer-status-info
		foreach ( [ 'ok', 'warn', 'fail', 'info' ] as $status ) {
			$count = $summary[$status] ?? 0;
			if ( $count > 0 ) {
				$label = $this->msg( 'vaulttecmediaoptimizer-status-' . $status )->text();
				$parts[] = Html::element( 'span',
					[ 'class' => "mw-vtmo-badge mw-vtmo-badge-$status" ],
					"$count $label"
				);
			}
		}
		return implode( ' ', $parts );
	}

	private function renderActions( array $summary ): string {
		$canProceed = ( $summary['fail'] ?? 0 ) === 0;
		$buttons = [];

		$buttons[] = Html::element( 'a', [
			'href' => $this->getPageTitle()->getLocalURL(),
			'class' => 'mw-vtmo-button mw-vtmo-button-refresh',
		], $this->msg( 'vaulttecmediaoptimizer-status-refresh' )->text() );

		if ( $canProceed ) {
			$backfillTitle = SpecialPage::getTitleFor( 'VTMOBackfill' );
			$buttons[] = Html::element( 'a', [
				'href' => $backfillTitle->getLocalURL(),
				'class' => 'mw-vtmo-button mw-vtmo-button-primary',
			], $this->msg( 'vaulttecmediaoptimizer-status-action-backfill' )->text() );
		}

		$statsTitle = SpecialPage::getTitleFor( 'VTMOStats' );
		$buttons[] = Html::element( 'a', [
			'href' => $statsTitle->getLocalURL(),
			'class' => 'mw-vtmo-button',
		], $this->msg( 'vaulttecmediaoptimizer-status-action-stats' )->text() );

		return Html::rawElement( 'div',
			[ 'class' => 'mw-vtmo-actions' ],
			implode( ' ', $buttons )
		);
	}

	private function renderSection( string $titleMsgKey, array $checks ): string {
		$rows = '';
		foreach ( $checks as $check ) {
			$rows .= $this->renderCheckRow( $check );
		}

		$title = $this->msg( $titleMsgKey )->isDisabled()
			? $titleMsgKey
			: $this->msg( $titleMsgKey )->text();

		return Html::rawElement( 'section',
			[ 'class' => 'mw-vtmo-section' ],
			Html::element( 'h2', [], $title ) .
			Html::rawElement( 'table',
				[ 'class' => 'wikitable mw-vtmo-table' ],
				Html::rawElement( 'tbody', [], $rows )
			)
		);
	}

	private function renderCheckRow( array $check ): string {
		$status = $check['status'];
		$labelMsg = $this->msg( $check['label_key'] );
		$label = $labelMsg->isDisabled() ? $check['label_key'] : $labelMsg->text();

		$statusLabel = $this->msg( 'vaulttecmediaoptimizer-status-' . $status )->text();
		$statusBadge = Html::element( 'span',
			[ 'class' => "mw-vtmo-badge mw-vtmo-badge-$status" ],
			$statusLabel
		);

		$valueCell = Html::element( 'code', [], $check['value'] );
		if ( $check['detail'] !== null ) {
			$valueCell .= Html::element( 'div',
				[ 'class' => 'mw-vtmo-detail' ],
				$check['detail']
			);
		}

		return Html::rawElement( 'tr', [],
			Html::element( 'th', [ 'scope' => 'row' ], $label ) .
			Html::rawElement( 'td',
				[ 'class' => 'mw-vtmo-status-cell' ],
				$statusBadge
			) .
			Html::rawElement( 'td', [], $valueCell )
		);
	}

	/**
	 * Decide whether to show the "Create WebP directory" button.
	 *
	 * We show it if the WebP dir check is in 'warn' (not yet created but parent
	 * is writable) or 'fail' status — in both cases, the admin can try creating
	 * and either succeed or get a clear error message.
	 */
	private function shouldOfferCreateWebpDir( array $report ): bool {
		$fsChecks = $report['sections']['filesystem'] ?? [];
		foreach ( $fsChecks as $check ) {
			if ( $check['label_key'] === 'vaulttecmediaoptimizer-check-webp-dir'
				&& in_array( $check['status'],
					[ PrerequisiteChecker::STATUS_WARN, PrerequisiteChecker::STATUS_FAIL ], true )
			) {
				return true;
			}
		}
		return false;
	}

	private function renderCreateWebpDirForm(): string {
		$token = $this->getUser()->getEditToken();
		return Html::rawElement( 'form',
			[
				'method' => 'POST',
				'action' => $this->getPageTitle()->getLocalURL(),
				'class' => 'mw-vtmo-form',
				'style' => 'margin-top: 1em;',
			],
			Html::hidden( 'wpEditToken', $token ) .
			Html::element( 'button',
				[
					'type' => 'submit',
					'name' => 'wpCreateWebpDir',
					'value' => '1',
					'class' => 'mw-vtmo-button mw-vtmo-button-primary',
				],
				$this->msg( 'vaulttecmediaoptimizer-status-create-webp-dir' )->text()
			)
		);
	}

	private function renderResultBanner( string $resultParam ): string {
		// Format: "webpdir-ok:<urlencoded message>" or "webpdir-fail:<...>"
		[ $action, $value ] = array_pad( explode( ':', $resultParam, 2 ), 2, '' );
		$msg = rawurldecode( $value );

		if ( $action === 'webpdir-ok' ) {
			$cssClass = 'mw-vtmo-summary mw-vtmo-summary-ok';
		} elseif ( $action === 'webpdir-fail' ) {
			$cssClass = 'mw-vtmo-summary mw-vtmo-summary-fail';
		} else {
			return '';
		}

		return Html::rawElement( 'div', [ 'class' => $cssClass ],
			Html::element( 'strong', [], $msg )
		);
	}
}
