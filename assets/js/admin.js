/**
 * Vendor Analytics Pro for HivePress - settings screen chrome and tab scoping.
 *
 * Two jobs:
 *
 * 1. The shared settings chrome - the quick-links anchor nav, the floating Save
 *    control and the back-to-top button. That block is copied from the
 *    reference implementation (account-menu-enhancer-for-hivepress,
 *    assets/js/backend.js) and only the two constants in CHROME differ.
 * 2. A scope class for this plugin's own rules in admin.css.
 *
 * Since 1.9.1 the enqueue only loads this file on a tab that carries this
 * plugin's own fields. The hp_vendor_analytics_ checks below stay anyway: the
 * enqueue decides whether the file loads, these decide whether it acts, and
 * neither is a substitute for the other.
 *
 * The show/hide of the "Vendor choice" and "Quiet months" rows is NOT in this
 * file. HivePress core drives that from the "_parent" field argument
 * (hivepress/assets/js/common.js), including the initial state on load.
 */

/* global hpvaAdmin */

(function ($) {
	'use strict';

	/* ======================================================================
	 * SHARED SETTINGS CHROME
	 *
	 * Three pieces of furniture for a long settings tab: the quick-links
	 * anchor nav, a floating Save control and a back-to-top button. Written
	 * to be copied verbatim into the other plugins, so everything below is
	 * self-contained and the only plugin-specific values are the two
	 * constants in CHROME.
	 *
	 * THE HOUSE RULE THIS IMPLEMENTS (resources/hivepress-settings.md, "The
	 * settings anchor nav: one shared marker class", 2026-08-30). Several of
	 * these plugins can decorate one settings screen, so each piece carries
	 * TWO classes: a shared marker that is never styled and exists only so
	 * siblings can find it (`hp-settings-nav`, `hp-settings-save`,
	 * `hp-settings-top`), plus the plugin's own prefixed class carrying all
	 * the CSS. Before rendering a piece, test for its marker with an EXACT
	 * class selector and stand down if a sibling got there first, so the
	 * owner sees one of each however many extensions are active.
	 *
	 * The exact test is the point. The old convention was the substring
	 * `nav[class*="settings-nav"]`, which was blind to three of the plugins
	 * it was meant to see - Account Menu Enhancer's own nav was called
	 * `amehp-section-nav` - and it failed silently.
	 * ================================================================== */

	var CHROME = {
		// This plugin's own class prefix and the field prefix that says the
		// rendered tab belongs to it. The only two lines to change on a copy.
		prefix: 'hpva',
		fieldPrefix: 'hp_vendor_analytics_',
	};

	/*
	 * The only thing in this block that reaches outside itself, and it is
	 * a read of one localised object with a fallback for every string. That
	 * is deliberate: the block is copied verbatim across the extension
	 * family, so anything it depended on would have to be copied with it,
	 * and a copy that landed without its dependency would break nothing
	 * until somebody opened that plugin's settings screen.
	 */
	function chromeLabels() {
		return ( window.hpvaAdmin && window.hpvaAdmin.labels ) || {};
	}

	/**
	 * The settings form, but only when this plugin's tab is the one rendered.
	 *
	 * Gating on our own fields rather than on heading count, because a count
	 * is true of every HivePress tab: Geolocation Plus 1.1.0 gated that way
	 * and decorated other plugins' tabs until 1.1.1.
	 *
	 * @return {Element|null}
	 */
	function chromeForm() {
		var form = document.querySelector( '.hp-page form.hp-form--table' );

		if ( ! form || ! form.querySelector( '[name^="' + CHROME.fieldPrefix + '"]' ) ) {
			return null;
		}

		return form;
	}

	/**
	 * The quick-links anchor nav.
	 *
	 * WordPress renders settings sections as bare <h2>s through
	 * do_settings_sections(), with no hook to add anchors, so the ids and the
	 * nav have to be added here.
	 *
	 * @param {Element} form Settings form.
	 */
	function addSectionNav( form ) {
		if ( document.querySelector( 'nav.hp-settings-nav' ) ) {
			return;
		}

		// Direct children only. A settings section is a direct child of the
		// form; an h2 nested inside a panel or a card is not a section and
		// must not become a quick link.
		var headings = form.querySelectorAll( ':scope > h2' );

		if ( headings.length < 2 ) {
			return;
		}

		var nav = document.createElement( 'nav' ),
			navLabel = chromeLabels().jumpTo || 'Jump to a section:';

		nav.className = 'hp-settings-nav ' + CHROME.prefix + '-settings-nav';

		/*
		 * The bar opens with its own wording, not just an aria-label.
		 *
		 * A row of pills with nothing in front of it reads as decoration, and
		 * the one audience that was told what it is - a screen reader, through
		 * the aria-label - is the one audience that could not see the pills
		 * anyway. The visible text is part of the house chrome spec
		 * (resources/hivepress-settings.md, "The settings anchor nav"), so it
		 * carries its own class for the sibling plugins to copy, and the
		 * aria-label is dropped: the text now names the nav for everybody, and
		 * leaving both would have a screen reader announce the name twice.
		 */
		var label = document.createElement( 'span' );

		label.className = CHROME.prefix + '-settings-nav__label';
		label.textContent = navLabel;

		nav.appendChild( label );

		headings.forEach( function ( heading, index ) {

			/*
			 * Reuse the id WordPress already put on the heading and mint one
			 * only where there is none. Overwriting it breaks every link,
			 * bookmark and sibling script pointing at the real
			 * `wp-settings-section-{name}` id, which is what the first
			 * version of this nav did.
			 */
			if ( ! heading.id ) {
				heading.id = CHROME.prefix + '-section-' + index;
			}

			heading.classList.add( CHROME.prefix + '-section-heading' );

			if ( 0 === index ) {
				heading.classList.add( CHROME.prefix + '-section-heading--first' );
			}

			var link = document.createElement( 'a' );

			link.href = '#' + heading.id;

			// textContent on both ends, so heading markup can never become
			// link markup.
			link.textContent = heading.textContent;

			nav.appendChild( link );
		} );

		form.insertBefore( nav, headings[ 0 ] );
	}

	/**
	 * The floating Save control.
	 *
	 * It submits the real form rather than carrying any save logic of its
	 * own: requestSubmit() runs the same validation and the same submit
	 * handlers as pressing the button at the bottom of the page, so there is
	 * only ever one way to save. The real button stays exactly where it was.
	 *
	 * @param {Element} form Settings form.
	 */
	function addFloatingSave( form ) {
		if ( document.querySelector( '.hp-settings-save' ) ) {
			return;
		}

		var submit = form.querySelector( 'input[type="submit"], button[type="submit"]' );

		if ( ! submit ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			text = document.createElement( 'span' ),
			label = chromeLabels().save || 'Save Changes';

		button.type = 'button';

		/*
		 * Core's own button classes, so WordPress paints it.
		 *
		 * This control IS the form's Save button, moved somewhere reachable,
		 * so it has to look like it - and "looks like it" is not one colour.
		 * Every user can pick an Admin Colour Scheme under Users > Profile,
		 * and each scheme repaints .wp-core-ui .button-primary. Painting our
		 * own #2271b1 matched the default scheme and nothing else: measured on
		 * 2026-08-30 under Modern, the real button was rgb(56,88,233) and this
		 * tab rgb(34,113,177), side by side on the same screen. The prefixed
		 * class is kept for layout only.
		 */
		button.className = 'hp-settings-save ' + CHROME.prefix + '-settings-save button button-primary';
		button.setAttribute( 'aria-label', label );

		icon.className = 'dashicons dashicons-saved';
		icon.setAttribute( 'aria-hidden', 'true' );

		text.className = CHROME.prefix + '-settings-save__text';
		text.textContent = label;

		button.appendChild( icon );
		button.appendChild( text );

		button.addEventListener( 'click', function () {

			// requestSubmit() fires the submit event and the browser's own
			// validation; form.submit() would skip both. Older browsers
			// without it get the real button pressed instead, which is the
			// same thing by a longer route.
			if ( form.requestSubmit ) {
				form.requestSubmit( submit );
			} else {
				submit.click();
			}
		} );

		document.body.appendChild( button );
	}

	/**
	 * The back-to-top button.
	 *
	 * Hidden until the page has actually scrolled, so it never covers
	 * anything on a tab short enough not to need it.
	 */
	function addBackToTop() {
		if ( document.querySelector( '.hp-settings-top' ) ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			label = chromeLabels().backToTop || 'Back to top';

		button.type = 'button';

		// Core's secondary button, for the same reason as the Save tab above:
		// its blue is the scheme's blue, not a hex of ours.
		button.className = 'hp-settings-top ' + CHROME.prefix + '-settings-top button';
		button.setAttribute( 'aria-label', label );
		button.title = label;
		button.hidden = true;

		icon.className = 'dashicons dashicons-arrow-up-alt2';
		icon.setAttribute( 'aria-hidden', 'true' );

		button.appendChild( icon );

		button.addEventListener( 'click', function () {

			// A reader who has asked for reduced motion is asking not to be
			// moved through a long page; "auto" jumps instead of animating.
			var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			window.scrollTo( {
				top: 0,
				behavior: reduced ? 'auto' : 'smooth',
			} );

			// Focus follows the scroll, so a keyboard user carries on from the
			// top of the page rather than from a button that is now off screen.
			var heading = document.querySelector( '.hp-page__title' );

			if ( heading ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus( { preventScroll: true } );
			}
		} );

		document.body.appendChild( button );

		/*
		 * The show/hide runs straight off the scroll event.
		 *
		 * It used to be deferred into requestAnimationFrame, which is the
		 * usual advice for scroll handlers - and it meant the button never
		 * appeared at all whenever the page was not being painted, because a
		 * browser pauses rAF on a hidden page and the callback simply never
		 * ran. Caught by measurement on 2026-08-30: document.hidden was true,
		 * the page was scrolled to 1500px, and the button stayed hidden.
		 * Nobody is looking at a page in that state, so the symptom was
		 * invisible rather than harmless - it would equally have hidden a
		 * genuine failure. The work here is two property reads and a boolean
		 * write, which is cheap enough to do on the event itself, so the
		 * optimisation bought nothing and cost correctness.
		 */
		function update() {
			button.hidden = ( window.pageYOffset || document.documentElement.scrollTop ) < 300;
		}

		window.addEventListener( 'scroll', update, { passive: true } );

		update();
	}

	/**
	 * Adds every piece of chrome, one tick after ready.
	 *
	 * The delay is deliberate: load order between plugins is not something
	 * any of them controls, so a sibling whose hook registered first may
	 * still be placing its own nav when this runs. One tick lets it finish,
	 * and the stand-down guards then see it.
	 */
	function addSettingsChrome() {
		window.setTimeout( function () {
			var form = chromeForm();

			if ( ! form ) {
				return;
			}

			addSectionNav( form );
			addFloatingSave( form );
			addBackToTop();
		}, 0 );
	}

	$(function () {

		// A field that is always registered on our tab, whatever is enabled.
		// Scopes this plugin's own rules in admin.css to that tab only.
		$(':input[name="hp_vendor_analytics_views"]').closest('form').addClass('hpva-settings');

		addSettingsChrome();
	});
})(jQuery);
