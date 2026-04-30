/* global CONFIGKIT */
( function () {
	'use strict';

	if ( typeof window.CONFIGKIT === 'undefined' ) {
		return;
	}

	/**
	 * Wrapper around fetch that:
	 *  - prefixes paths with the REST namespace
	 *  - sends the wp_rest nonce
	 *  - parses JSON and surfaces WP_Error structures consistently
	 */
	async function request( path, options = {} ) {
		const init = Object.assign(
			{
				method: 'GET',
				credentials: 'same-origin',
				headers: {},
			},
			options
		);

		init.headers = Object.assign(
			{
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-WP-Nonce': window.CONFIGKIT.nonce,
			},
			init.headers || {}
		);

		if ( init.body && typeof init.body !== 'string' ) {
			init.body = JSON.stringify( init.body );
		}

		const url = window.CONFIGKIT.restUrl + path;
		const response = await fetch( url, init );
		let payload = null;
		try {
			payload = await response.json();
		} catch ( e ) {
			payload = null;
		}

		if ( ! response.ok ) {
			const error = new Error(
				payload && payload.message ? payload.message : response.statusText
			);
			error.code = payload && payload.code ? payload.code : 'http_' + response.status;
			error.status = response.status;
			error.data = payload && payload.data ? payload.data : null;
			throw error;
		}

		return payload;
	}

	/**
	 * Translate a thrown REST error into a user-friendly description.
	 *
	 * Returned shape:
	 *   {
	 *     kind: 'error' | 'conflict',
	 *     friendly: string,        // shown to the owner
	 *     technical: string,       // raw message + code, hidden by default
	 *     showFieldErrors: bool,   // if true, also surface err.data.errors[*]
	 *   }
	 *
	 * 400 / 422 are treated as validation: friendly text is the server
	 * message (already meaningful), and field-level errors are surfaced
	 * separately by the page-specific JS via err.data.errors.
	 */
	function describeError( err ) {
		const status = err && err.status ? err.status : 0;
		const code = err && err.code ? err.code : '';
		const rawMessage = err && err.message ? err.message : '';
		const technical = rawMessage + ( code ? ' (' + code + ')' : '' )
			+ ( status ? ' [HTTP ' + status + ']' : '' );

		const isNoRoute = code === 'rest_no_route' || code === 'no_route';

		if ( status === 404 || isNoRoute ) {
			return {
				kind: 'error',
				friendly: 'Could not load this section. Try refreshing the page.',
				technical: technical,
				showFieldErrors: false,
			};
		}
		if ( status === 401 || status === 403 ) {
			return {
				kind: 'error',
				friendly: "You don't have permission for this action.",
				technical: technical,
				showFieldErrors: false,
			};
		}
		if ( status === 409 ) {
			return {
				kind: 'conflict',
				friendly:
					'Someone else changed this record while you were editing. Please reload and try again.',
				technical: technical,
				showFieldErrors: false,
			};
		}
		if ( status === 400 || status === 422 ) {
			return {
				kind: 'error',
				friendly: rawMessage || 'Please check the errors highlighted below.',
				technical: technical,
				showFieldErrors: true,
			};
		}
		return {
			kind: 'error',
			friendly: 'An unexpected error occurred. Please try again or contact support.',
			technical: technical,
			showFieldErrors: false,
		};
	}

	/**
	 * Build a small (?) help affordance. On hover the native title tip
	 * suffices for desktop; on tap (touch) we toggle a popout.
	 *
	 * Returns a DocumentFragment so callers can splice it into a label.
	 */
	function help( text ) {
		const frag = document.createDocumentFragment();
		if ( ! text ) return frag;
		const trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = 'configkit-help';
		trigger.setAttribute( 'aria-label', 'Help: ' + text );
		trigger.title = text;
		const popout = document.createElement( 'span' );
		popout.className = 'configkit-help-popout';
		popout.textContent = text;
		popout.style.display = 'none';
		popout.setAttribute( 'role', 'tooltip' );
		trigger.addEventListener( 'click', ( ev ) => {
			ev.preventDefault();
			ev.stopPropagation();
			popout.style.display = popout.style.display === 'none' ? 'block' : 'none';
		} );
		frag.appendChild( trigger );
		frag.appendChild( popout );
		return frag;
	}

	/**
	 * Wrap a fieldset legend so the section is collapsible. Pass the
	 * legend node and the body node; collapsed-by-default if `collapsed`.
	 */
	function makeCollapsible( fieldset, options ) {
		options = options || {};
		fieldset.classList.add( 'configkit-fieldset--collapsible' );
		const body = fieldset.querySelector( '.configkit-fieldset__body' );
		const legend = fieldset.querySelector( 'legend' );
		if ( ! body || ! legend ) return;
		fieldset.dataset.collapsed = options.collapsed ? 'true' : 'false';
		legend.setAttribute( 'role', 'button' );
		legend.setAttribute( 'tabindex', '0' );
		const toggle = () => {
			fieldset.dataset.collapsed = fieldset.dataset.collapsed === 'true' ? 'false' : 'true';
		};
		legend.addEventListener( 'click', toggle );
		legend.addEventListener( 'keydown', ( ev ) => {
			if ( ev.key === 'Enter' || ev.key === ' ' ) {
				ev.preventDefault();
				toggle();
			}
		} );
	}

	/**
	 * Standard empty state with optional CTAs. Returns a div the caller
	 * can append wherever they like.
	 *
	 *   ConfigKit.emptyState({
	 *     icon: '📦',
	 *     title: 'No modules yet',
	 *     message: 'Modules group library items by their capabilities.',
	 *     primary: { label: '+ Create first module', onClick: ... },
	 *     secondary: { label: 'Read the spec', href: '...' },
	 *   })
	 */
	function emptyState( opts ) {
		opts = opts || {};
		const wrap = document.createElement( 'div' );
		wrap.className = 'configkit-empty-cta';
		if ( opts.icon ) {
			const ico = document.createElement( 'div' );
			ico.className = 'configkit-empty-cta__icon';
			ico.textContent = opts.icon;
			wrap.appendChild( ico );
		}
		if ( opts.title ) {
			const t = document.createElement( 'p' );
			t.className = 'configkit-empty-cta__title';
			t.textContent = opts.title;
			wrap.appendChild( t );
		}
		if ( opts.message ) {
			const m = document.createElement( 'p' );
			m.className = 'configkit-empty-cta__message';
			m.textContent = opts.message;
			wrap.appendChild( m );
		}
		const actions = document.createElement( 'div' );
		if ( opts.primary ) {
			const btn = document.createElement( opts.primary.href ? 'a' : 'button' );
			if ( opts.primary.href ) btn.href = opts.primary.href;
			else btn.type = 'button';
			btn.className = 'button button-primary configkit-empty-cta__primary';
			btn.textContent = opts.primary.label;
			if ( opts.primary.onClick ) btn.addEventListener( 'click', opts.primary.onClick );
			actions.appendChild( btn );
		}
		if ( opts.secondary ) {
			const link = document.createElement( opts.secondary.href ? 'a' : 'button' );
			if ( opts.secondary.href ) link.href = opts.secondary.href;
			else link.type = 'button';
			link.className = 'configkit-empty-cta__secondary';
			link.textContent = opts.secondary.label;
			if ( opts.secondary.onClick ) link.addEventListener( 'click', opts.secondary.onClick );
			actions.appendChild( link );
		}
		if ( opts.primary || opts.secondary ) wrap.appendChild( actions );
		return wrap;
	}

	/**
	 * Soft warnings on a key value — generic / placeholder names. Empty
	 * array means the key looks fine.
	 */
	function softKeyWarnings( key, opts ) {
		opts = opts || {};
		const out = [];
		if ( typeof key !== 'string' ) return out;
		const k = key.trim();
		if ( k.length === 0 ) return out;

		if ( /^test_/i.test( k ) || /^tmp_/i.test( k ) || /^demo_/i.test( k ) || /^placeholder/i.test( k ) ) {
			out.push( 'Key looks like a test placeholder. Rename before going live.' );
		}

		// Generic / dictionary-ish stems below 5 chars are usually too vague.
		const generic = [
			'foo', 'bar', 'baz', 'qux', 'item', 'thing', 'stuff', 'data', 'info',
			'name', 'kind', 'type', 'main', 'temp', 'aaa', 'abc', 'xyz',
			'dar', 'vis', 'all', 'one', 'two', 'sub', 'tab', 'box', 'red', 'blue',
		];
		if ( k.length <= 4 && generic.includes( k.toLowerCase() ) ) {
			const hint = opts.hint || 'Try {prefix}_{descriptor} format';
			out.push( 'Key "' + k + '" looks generic. Consider being more specific: ' + hint + '.' );
		}

		if ( opts.duplicates && Array.isArray( opts.duplicates ) ) {
			const lower = k.toLowerCase();
			const close = opts.duplicates.find( ( d ) => {
				if ( ! d || typeof d !== 'string' ) return false;
				if ( d.toLowerCase() === lower ) return false; // self / exact = handled by hard validation
				return d.toLowerCase().startsWith( lower ) || lower.startsWith( d.toLowerCase() );
			} );
			if ( close ) {
				out.push( 'Looks similar to existing key "' + close + '". Possible duplication?' );
			}
		}

		return out;
	}

	function renderSoftWarnings( messages ) {
		if ( ! messages || messages.length === 0 ) return null;
		const ul = document.createElement( 'ul' );
		ul.className = 'configkit-soft-warnings';
		messages.forEach( ( m ) => {
			const li = document.createElement( 'li' );
			li.textContent = m;
			ul.appendChild( li );
		} );
		return ul;
	}

	/**
	 * Extend the server-rendered breadcrumb in place rather than
	 * rendering a second nav line. The original "current" segment
	 * (rendered server-side as a span with `data-cf-href`) is
	 * promoted to a link on the way in, and restored when the
	 * caller clears the tail with `subBreadcrumb(null)`.
	 *
	 *   ConfigKit.subBreadcrumb([
	 *     { label: 'Edit "Dickson Orchestra"' },
	 *   ]);
	 *
	 * Result: "ConfigKit › Libraries › Edit \"Dickson Orchestra\""
	 * — single line.
	 */
	function subBreadcrumb( segments ) {
		const nav = document.querySelector( '.wrap.configkit-admin > .configkit-breadcrumb' );
		if ( ! nav ) return;
		// Cache the original innerHTML once so we can restore on
		// subsequent calls instead of stripping segments forever.
		if ( nav.dataset.cfBaseHtml === undefined ) {
			nav.dataset.cfBaseHtml = nav.innerHTML;
		}
		const baseHtml = nav.dataset.cfBaseHtml;

		if ( ! segments || segments.length === 0 ) {
			nav.innerHTML = baseHtml;
			return;
		}

		// Reset to base, then promote the existing "current" span to a
		// link if it carries an href.
		nav.innerHTML = baseHtml;
		const lastSpan = nav.querySelector( '.configkit-breadcrumb__current[data-cf-href]' );
		if ( lastSpan ) {
			const href = lastSpan.getAttribute( 'data-cf-href' );
			const a = document.createElement( 'a' );
			a.className = 'configkit-breadcrumb__link';
			a.href = href;
			a.textContent = lastSpan.textContent;
			lastSpan.parentNode.replaceChild( a, lastSpan );
		}

		const last = segments.length - 1;
		segments.forEach( ( seg, i ) => {
			const sep = document.createElement( 'span' );
			sep.className = 'configkit-breadcrumb__sep';
			sep.setAttribute( 'aria-hidden', 'true' );
			sep.textContent = '›';
			nav.appendChild( sep );

			const isLast = i === last;
			if ( isLast || ( ! seg.href && ! seg.onClick ) ) {
				const span = document.createElement( 'span' );
				span.className = 'configkit-breadcrumb__current';
				span.setAttribute( 'aria-current', 'page' );
				span.textContent = seg.label;
				nav.appendChild( span );
			} else {
				const a = document.createElement( 'a' );
				a.className = 'configkit-breadcrumb__link';
				a.href = seg.href || '#';
				a.textContent = seg.label;
				if ( seg.onClick ) {
					a.addEventListener( 'click', ( ev ) => {
						ev.preventDefault();
						seg.onClick();
					} );
				}
				nav.appendChild( a );
			}
		} );
	}

	/**
	 * Wire up the .configkit-intro boxes: hide them when dismissed, and
	 * remember per-page dismissals in localStorage. Strictly UI memory
	 * — never used for business data.
	 */
	function initIntroBoxes() {
		const boxes = document.querySelectorAll( '.configkit-intro' );
		if ( boxes.length === 0 ) return;
		const KEY = 'configkit:intro:dismissed';
		let dismissed = {};
		try {
			const raw = window.localStorage.getItem( KEY );
			if ( raw ) dismissed = JSON.parse( raw ) || {};
		} catch ( e ) { dismissed = {}; }

		boxes.forEach( ( box ) => {
			const id = box.getAttribute( 'data-intro-id' ) || '';
			if ( id && dismissed[ id ] ) {
				box.hidden = true;
				return;
			}
			const btn = box.querySelector( '.configkit-intro__dismiss' );
			if ( ! btn ) return;
			btn.addEventListener( 'click', () => {
				box.hidden = true;
				if ( ! id ) return;
				dismissed[ id ] = Date.now();
				try {
					window.localStorage.setItem( KEY, JSON.stringify( dismissed ) );
				} catch ( e ) { /* localStorage may be disabled — silently no-op */ }
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initIntroBoxes );
	} else {
		initIntroBoxes();
	}

	window.ConfigKit = window.ConfigKit || {};
	window.ConfigKit.request = request;
	window.ConfigKit.describeError = describeError;
	window.ConfigKit.help = help;
	window.ConfigKit.makeCollapsible = makeCollapsible;
	window.ConfigKit.emptyState = emptyState;
	window.ConfigKit.softKeyWarnings = softKeyWarnings;
	window.ConfigKit.renderSoftWarnings = renderSoftWarnings;
	window.ConfigKit.subBreadcrumb = subBreadcrumb;
} )();
