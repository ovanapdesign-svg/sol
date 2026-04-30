/* global ConfigKit */
/**
 * Phase 4.3 dalis 2 — Simple Mode Product Builder UI.
 *
 * The Woo product ConfigKit tab mounts this on
 * `#configkit-product-builder-app`. The owner picks a product type
 * and fills in the recipe-driven blocks; every save hits one of the
 * `/configkit/v1/product-builder/*` endpoints. The orchestrator
 * provisions modules, libraries, items, lookup tables, templates,
 * and the binding behind the scenes — owners never see snake_case
 * keys.
 *
 * UX rules followed throughout:
 *   - Uncontrolled text inputs (Bug 2 from real-data fixes): we set
 *     `value` on render and read with `.value` on save; we do NOT
 *     update React-style state on every keystroke or re-render the
 *     input mid-input.
 *   - Owner-friendly errors: the controller's response surfaces a
 *     human message; we display it verbatim.
 *   - Single round-trip on open via `/snapshot` so the page renders
 *     fully populated.
 */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-product-builder-app' );
	if ( ! root ) return;
	if ( ! window.ConfigKit || typeof window.ConfigKit.request !== 'function' ) return;

	const productId = parseInt( root.getAttribute( 'data-product-id' ) || '0', 10 );
	if ( ! productId ) {
		root.innerHTML = '<p class="configkit-app__loading">Save the product first, then refresh to use Product Builder.</p>';
		return;
	}

	const advancedRoot = document.getElementById( 'configkit-product-binding-app' );

	// =========================================================
	// State
	// =========================================================

	const state = {
		ready: false,
		busy: false,
		showAdvanced: false,
		recipes: [],
		snapshot: null,        // full /snapshot payload
		message: null,         // { kind: 'success' | 'error', text }
		// Uncontrolled drafts — populated only when the user is editing.
		// We never re-render an input element while the owner is typing.
		drafts: {
			pricing_rows:   null,
			fabrics:        null,
			profile_colors: null,
			stangs:         null,
			motors:         null,
			controls:       null,
			accessories:    null,
		},
		activeTabs: {
			pricing: 'manual',
			fabrics: 'manual',
			motors:  'single',
		},
	};

	// =========================================================
	// DOM helpers
	// =========================================================

	function el( tag, attrs, ...children ) {
		const node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( ( key ) => {
				const value = attrs[ key ];
				if ( value === false || value === null || value === undefined ) return;
				if ( key === 'class' ) {
					node.className = value;
				} else if ( key === 'html' ) {
					node.innerHTML = value;
				} else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
					node.addEventListener( key.slice( 2 ).toLowerCase(), value );
				} else if ( key in node && typeof value === 'boolean' ) {
					node[ key ] = value;
				} else {
					node.setAttribute( key, String( value ) );
				}
			} );
		}
		children.flat().forEach( ( c ) => {
			if ( c === null || c === undefined || c === false ) return;
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function svgIcon( pathD, viewBox ) {
		// Inline lucide-style icons. Returned element is detached so
		// the caller appends it where needed.
		const svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', viewBox || '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.7' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		const path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', pathD );
		svg.appendChild( path );
		return svg;
	}

	const ICONS = {
		// Roughly lucide: sun-medium / blinds / outdoor-grill / umbrella / sparkles
		markise:     'M12 4v2 M12 18v2 M4 12h2 M18 12h2 M5.6 5.6l1.4 1.4 M17 17l1.4 1.4 M17 7l1.4-1.4 M5.6 18.4l1.4-1.4 M12 8a4 4 0 100 8 4 4 0 000-8z',
		screen:      'M4 5h16v3H4z M4 8v9 M20 8v9 M4 17h16 M8 11v3 M12 11v3 M16 11v3',
		pergola:     'M3 10l9-6 9 6 M5 10v10 M19 10v10 M5 14h14 M5 18h14',
		terrassetak: 'M3 13l9-7 9 7 M5 13v8h14v-8 M9 21v-5h6v5',
		custom:      'M12 3l1.8 4 4.3.4-3.2 3 1 4.3L12 12.6 8.1 14.7l1-4.3-3.2-3 4.3-.4z',
	};

	// =========================================================
	// Network
	// =========================================================

	function showMessage( kind, text ) {
		state.message = { kind, text };
		render();
		if ( kind === 'success' ) {
			setTimeout( () => {
				if ( state.message && state.message.text === text ) {
					state.message = null;
					render();
				}
			}, 4000 );
		}
	}

	function explainError( err ) {
		if ( window.ConfigKit && window.ConfigKit.describeError ) {
			const desc = window.ConfigKit.describeError( err );
			return desc && desc.friendly ? desc.friendly : ( err && err.message ) || 'Something went wrong.';
		}
		return ( err && err.message ) || 'Something went wrong.';
	}

	async function pbRequest( path, options ) {
		state.busy = true;
		render();
		try {
			const data = await window.ConfigKit.request( '/product-builder/' + productId + path, options );
			return data;
		} finally {
			state.busy = false;
		}
	}

	async function loadSnapshot() {
		try {
			const data = await window.ConfigKit.request( '/product-builder/' + productId + '/snapshot' );
			state.snapshot = data;
			// Reset drafts so re-rendered editors show server-truth.
			Object.keys( state.drafts ).forEach( ( k ) => { state.drafts[ k ] = null; } );
		} catch ( err ) {
			state.snapshot = { state: {}, pricing_rows: [], fabrics: [], profile_colors: [], stangs: [], motors: [], controls: [], accessories: [], checklist: { ready: false, checklist: [] } };
			showMessage( 'error', explainError( err ) );
		}
	}

	async function loadRecipes() {
		try {
			const data = await window.ConfigKit.request( '/product-builder/recipes' );
			state.recipes = ( data && data.recipes ) || [];
		} catch ( e ) { state.recipes = []; }
	}

	async function init() {
		root.dataset.loading = 'true';
		await Promise.all( [ loadRecipes(), loadSnapshot() ] );
		state.ready = true;
		root.dataset.loading = 'false';
		render();
	}

	// =========================================================
	// Render — header + status pill + advanced toggle
	// =========================================================

	function statusPill() {
		const pbState = ( state.snapshot && state.snapshot.state ) || {};
		const enabled = !! pbState.enabled;
		const ready   = state.snapshot && state.snapshot.checklist && state.snapshot.checklist.ready;
		const text    = enabled ? 'Live' : ( ready ? 'Ready' : ( pbState.product_type ? 'Setup in progress' : 'Disabled' ) );
		const cls     = enabled ? 'is-live' : ( ready ? 'is-ready' : ( pbState.product_type ? 'is-progress' : 'is-disabled' ) );
		return el( 'span', { class: 'configkit-pb__status configkit-pb__status--' + cls }, text );
	}

	function renderHeader() {
		const wrap = el( 'header', { class: 'configkit-pb__header' } );
		wrap.appendChild( el( 'div', { class: 'configkit-pb__title-block' },
			el( 'h2', { class: 'configkit-pb__title' }, 'Setup configurable product' ),
			statusPill()
		) );

		const toggle = el( 'button', {
			type: 'button',
			class: 'button configkit-pb__advanced-toggle',
			onClick: () => {
				state.showAdvanced = ! state.showAdvanced;
				applyAdvancedVisibility();
				render();
			},
		}, state.showAdvanced ? '← Back to Product Builder' : 'Show advanced settings' );
		wrap.appendChild( toggle );
		return wrap;
	}

	function applyAdvancedVisibility() {
		// Hide self when advanced view is open, show binding app instead.
		if ( advancedRoot ) {
			advancedRoot.hidden = ! state.showAdvanced;
		}
		root.classList.toggle( 'configkit-product-builder--advanced-open', state.showAdvanced );
	}

	function messageBanner() {
		if ( ! state.message ) return null;
		const cls = 'notice ' + ( state.message.kind === 'success' ? 'notice-success' : 'notice-error' ) + ' inline configkit-notice';
		return el( 'div', { class: cls },
			el( 'p', null, state.message.text )
		);
	}

	// =========================================================
	// Block 1 — Product type cards
	// =========================================================

	async function pickProductType( typeId ) {
		try {
			const result = await pbRequest( '/product-type', {
				method: 'POST',
				body: { product_type: typeId },
			} );
			showMessage( 'success', ( result && result.message ) || 'Product type set.' );
			await loadSnapshot();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function renderBlockProductType() {
		const block = el( 'section', { class: 'configkit-pb__block' } );
		block.appendChild( el( 'header', { class: 'configkit-pb__block-header' },
			el( 'h3', null, '1. Product type' ),
			el( 'p', { class: 'description' }, 'What kind of product is this? Pick once to unlock the rest of the blocks.' )
		) );

		const current = ( state.snapshot && state.snapshot.state && state.snapshot.state.product_type ) || null;
		const grid = el( 'div', { class: 'configkit-pb__type-grid' } );
		state.recipes.forEach( ( recipe ) => {
			const isCurrent = recipe.id === current;
			const card = el( 'button', {
				type: 'button',
				class: 'configkit-pb__type-card' + ( isCurrent ? ' is-selected' : '' ),
				disabled: state.busy,
				onClick: () => pickProductType( recipe.id ),
			} );
			const iconWrap = el( 'span', { class: 'configkit-pb__type-icon', 'aria-hidden': 'true' } );
			if ( ICONS[ recipe.id ] ) iconWrap.appendChild( svgIcon( ICONS[ recipe.id ] ) );
			else iconWrap.textContent = recipe.icon || '✱';
			card.appendChild( iconWrap );
			card.appendChild( el( 'span', { class: 'configkit-pb__type-label' }, recipe.label ) );
			card.appendChild( el( 'span', { class: 'configkit-pb__type-desc' }, recipe.description ) );
			if ( isCurrent ) card.appendChild( el( 'span', { class: 'configkit-pb__type-badge' }, 'Selected' ) );
			grid.appendChild( card );
		} );
		block.appendChild( grid );
		return block;
	}

	// =========================================================
	// Render — main loop
	// =========================================================

	function render() {
		if ( ! state.ready ) return;
		root.replaceChildren();

		root.appendChild( renderHeader() );

		if ( state.showAdvanced ) {
			// Owner toggled to advanced mode — product-binding.js owns
			// the screen below. Just leave a small note here.
			root.appendChild( el( 'p', { class: 'description configkit-pb__advanced-note' },
				'Advanced settings are shown below. Click "Back to Product Builder" to return to the simple view.'
			) );
			return;
		}

		const banner = messageBanner();
		if ( banner ) root.appendChild( banner );

		root.appendChild( renderBlockProductType() );

		// Subsequent blocks land in chunks 3-6.
		const recipe = currentRecipe();
		if ( recipe ) {
			root.appendChild( el( 'p', { class: 'description configkit-pb__block-stub' },
				'Pricing, fabrics, and the rest of the blocks land in subsequent chunks. The orchestrator backend is ready to receive them.'
			) );
		}
	}

	function currentRecipe() {
		const id = state.snapshot && state.snapshot.state && state.snapshot.state.product_type;
		if ( ! id ) return null;
		return state.recipes.find( ( r ) => r.id === id ) || null;
	}

	// Kick off.
	init();
} )();
