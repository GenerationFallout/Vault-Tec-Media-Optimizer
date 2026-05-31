/**
 * Vault-Tec Media Optimizer dashboard - light client-side enhancements.
 *
 * Auto-refreshes the Special:VTMOBackfill page every 10s while visible,
 * to reflect job queue progress. Also injects a small countdown indicator
 * so the admin sees that the page is actually live and when the next refresh
 * will happen.
 *
 * IMPORTANT: We don't use window.location.reload() because that would
 * re-POST any form just submitted. Instead we navigate to the GET URL,
 * which is safe (the page uses Post-Redirect-Get on the server side,
 * so a GET only reads state, never modifies).
 */
( function () {
	'use strict';

	// Only run on the backfill page
	var canonicalPage = mw.config.get( 'wgCanonicalSpecialPageName' );
	if ( canonicalPage !== 'VTMOBackfill' ) {
		return;
	}

	var REFRESH_INTERVAL_S = 10;
	var remaining = REFRESH_INTERVAL_S;
	var tickTimer = null;
	var indicator = null;

	function buildIndicator() {
		var el = document.createElement( 'div' );
		el.className = 'mw-vtmo-refresh-indicator';
		el.style.cssText = 'margin: 0.5em 0; font-size: 0.85em; opacity: 0.7;';
		el.setAttribute( 'aria-live', 'polite' );
		updateIndicator( el );
		// Insert just before the first form, or at the start of #mw-content-text
		var content = document.querySelector( '#mw-content-text, .mw-parser-output' );
		var firstForm = content && content.querySelector( '.mw-vtmo-form' );
		if ( firstForm && firstForm.parentNode ) {
			firstForm.parentNode.insertBefore( el, firstForm );
		} else if ( content ) {
			content.appendChild( el );
		} else {
			document.body.appendChild( el );
		}
		return el;
	}

	function updateIndicator( el ) {
		if ( document.hidden ) {
			el.textContent = '⏸ Actualisation en pause (onglet inactif)';
		} else {
			el.textContent = '⟳ Actualisation automatique dans ' + remaining + 's';
		}
	}

	function refresh() {
		// Strip any 'result' query param so banners don't reappear after refresh
		var url = new URL( window.location.href );
		url.searchParams.delete( 'result' );
		// Use assign() with a GET URL to avoid re-POSTing any prior form
		window.location.assign( url.toString() );
	}

	function tick() {
		if ( document.hidden ) {
			// Reset the countdown when tab is hidden — we'll restart fresh on return
			remaining = REFRESH_INTERVAL_S;
			if ( indicator ) {
				updateIndicator( indicator );
			}
			return;
		}
		remaining -= 1;
		if ( indicator ) {
			updateIndicator( indicator );
		}
		if ( remaining <= 0 ) {
			refresh();
		}
	}

	function start() {
		stop();
		tickTimer = window.setInterval( tick, 1000 );
	}

	function stop() {
		if ( tickTimer ) {
			window.clearInterval( tickTimer );
			tickTimer = null;
		}
	}

	// Wait for DOM ready so we can find the form anchor
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	function init() {
		indicator = buildIndicator();
		document.addEventListener( 'visibilitychange', function () {
			if ( indicator ) {
				updateIndicator( indicator );
			}
		} );
		start();
	}
}() );
