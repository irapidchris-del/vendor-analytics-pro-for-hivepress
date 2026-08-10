/**
 * Vendor Analytics Pro beacon.
 *
 * Cache-safe by design: no nonces (cached pages would bake stale ones), one
 * view per browser session per object, owner self-views skipped via a
 * localStorage flag set when the vendor visits their analytics dashboard.
 */
( function () {
	'use strict';

	var cfg = window.hpvaConfig;

	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}

	function isOwner() {
		try {
			return cfg.vendor && localStorage.getItem( 'hpvaOwner' ) === String( cfg.vendor );
		} catch ( e ) {
			return false;
		}
	}

	function send( metric ) {
		if ( isOwner() ) {
			return;
		}

		var payload = JSON.stringify( { m: metric, l: cfg.listing || 0, v: cfg.vendor || 0 } );

		// Old Chromium (~60-80, still alive in unupdated Android WebViews)
		// threw a synchronous TypeError for sendBeacon with a JSON Blob, and
		// an uncaught throw here would kill the whole script including the
		// click listener below. Catch and fall through to fetch; also fall
		// through when sendBeacon reports its queue is full (returns false).
		try {
			if ( navigator.sendBeacon && navigator.sendBeacon( cfg.endpoint, new Blob( [ payload ], { type: 'application/json' } ) ) ) {
				return;
			}
		} catch ( e ) {
			// Fall through to fetch.
		}

		if ( window.fetch ) {
			fetch( cfg.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: payload,
				keepalive: true,
				credentials: 'omit'
			} ).catch( function () {} );
		}
	}

	// One view per session per object.
	if ( cfg.views ) {
		var key = 'hpvaV' + ( cfg.listing ? 'l' + cfg.listing : 'v' + cfg.vendor );

		try {
			if ( ! sessionStorage.getItem( key ) ) {
				sessionStorage.setItem( key, '1' );
				send( cfg.listing ? 'view' : 'vendor_view' );
			}
		} catch ( e ) {
			send( cfg.listing ? 'view' : 'vendor_view' );
		}
	}

	// tel:/mailto: click tracking (delegated; counts once per link per page view).
	if ( cfg.clicks && ( cfg.listing || cfg.vendor ) ) {
		var seen = [];

		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest ? event.target.closest( 'a[href^="tel:"],a[href^="mailto:"]' ) : null;

			if ( ! link || seen.indexOf( link ) !== -1 ) {
				return;
			}

			seen.push( link );
			send( link.getAttribute( 'href' ).indexOf( 'tel:' ) === 0 ? 'phone_click' : 'email_click' );
		}, true );
	}
} )();
