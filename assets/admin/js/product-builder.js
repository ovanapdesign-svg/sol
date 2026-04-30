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
	// Shared helpers — tabs + drop zone
	// =========================================================

	function tabBar( blockId, tabs ) {
		const wrap = el( 'div', { class: 'configkit-pb__tabs', role: 'tablist' } );
		const active = state.activeTabs[ blockId ] || tabs[0].id;
		tabs.forEach( ( t ) => {
			wrap.appendChild( el( 'button', {
				type:    'button',
				class:   'configkit-pb__tab' + ( t.id === active ? ' is-active' : '' ),
				role:    'tab',
				'aria-selected': t.id === active ? 'true' : 'false',
				onClick: () => {
					state.activeTabs[ blockId ] = t.id;
					render();
				},
			}, t.label ) );
		} );
		return wrap;
	}

	function activeTab( blockId, fallback ) {
		return state.activeTabs[ blockId ] || fallback;
	}

	/**
	 * Multipart upload to the existing /imports endpoint. Target
	 * type / key come from the orchestrator's snapshot.state so
	 * Simple Mode hands the importer the same keys the import
	 * wizard would.
	 */
	async function uploadImportFile( file, importType, targetKey ) {
		if ( ! file ) return null;
		if ( ! /\.xlsx$/i.test( file.name ) ) {
			showMessage( 'error', 'Only .xlsx files are supported.' );
			return null;
		}
		if ( file.size > 10 * 1024 * 1024 ) {
			showMessage( 'error', 'File exceeds the 10 MB limit.' );
			return null;
		}
		state.busy = true;
		render();

		const form = new FormData();
		form.append( 'file', file );
		form.append( 'import_type', importType );
		if ( importType === 'library_items' ) form.append( 'target_library_key', targetKey );
		else                                  form.append( 'target_lookup_table_key', targetKey );
		form.append( 'mode', 'replace_all' );

		try {
			const url = window.CONFIGKIT.restUrl + '/imports';
			const res = await fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CONFIGKIT.nonce },
				body: form,
			} );
			const payload = await res.json().catch( () => null );
			if ( ! res.ok ) {
				throw new Error( ( payload && payload.message ) || res.statusText );
			}
			const batchId = payload && payload.record && payload.record.id;
			if ( batchId ) {
				const commitRes = await window.ConfigKit.request( '/imports/' + batchId + '/commit', { method: 'POST', body: {} } );
				const summary = commitRes && commitRes.summary;
				if ( summary ) {
					showMessage( 'success', summary.inserted + ' inserted, ' + summary.updated + ' updated, ' + summary.skipped + ' skipped.' );
				} else {
					showMessage( 'success', 'Excel imported.' );
				}
			}
			await loadSnapshot();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
		} finally {
			state.busy = false;
		}
		return null;
	}

	function dropZone( opts ) {
		const drop = el( 'div', { class: 'configkit-pb__drop', tabindex: '0' } );
		drop.appendChild( el( 'p', null, opts.placeholder ) );
		drop.appendChild( el( 'p', { class: 'description' }, opts.helperText || 'Max 10 MB. .xlsx only.' ) );
		const fileInput = el( 'input', {
			type: 'file',
			accept: '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			class: 'configkit-pb__drop-input',
			onChange: ( ev ) => {
				if ( ev.target.files && ev.target.files[0] ) opts.onPick( ev.target.files[0] );
			},
		} );
		drop.appendChild( fileInput );
		drop.addEventListener( 'click', () => fileInput.click() );
		drop.addEventListener( 'dragover', ( ev ) => {
			ev.preventDefault();
			drop.classList.add( 'is-hover' );
		} );
		drop.addEventListener( 'dragleave', () => drop.classList.remove( 'is-hover' ) );
		drop.addEventListener( 'drop', ( ev ) => {
			ev.preventDefault();
			drop.classList.remove( 'is-hover' );
			if ( ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0] ) opts.onPick( ev.dataTransfer.files[0] );
		} );
		return drop;
	}

	function blockShell( id, title, helper ) {
		const block = el( 'section', { class: 'configkit-pb__block', 'data-block-id': id } );
		block.appendChild( el( 'header', { class: 'configkit-pb__block-header' },
			el( 'h3', null, title ),
			helper ? el( 'p', { class: 'description' }, helper ) : null
		) );
		return block;
	}

	function inputField( placeholder, defaultValue, opts ) {
		opts = opts || {};
		return el( 'input', {
			type:        opts.type || 'text',
			class:       opts.cls || 'configkit-pb__input',
			placeholder: placeholder || '',
			value:       defaultValue !== null && defaultValue !== undefined ? String( defaultValue ) : '',
			step:        opts.step,
			min:         opts.min,
			'data-pb-field': opts.field || '',
		} );
	}

	function readNumberFromCell( cell, field ) {
		const node = cell.querySelector( '[data-pb-field="' + field + '"]' );
		if ( ! node ) return null;
		const raw = String( node.value || '' ).trim();
		if ( raw === '' ) return null;
		const n = Number( raw );
		return Number.isFinite( n ) ? n : null;
	}

	function readStringFromCell( cell, field ) {
		const node = cell.querySelector( '[data-pb-field="' + field + '"]' );
		if ( ! node ) return '';
		return String( node.value || '' ).trim();
	}

	function readCheckedFromCell( cell, field ) {
		const node = cell.querySelector( '[data-pb-field="' + field + '"]' );
		if ( ! node ) return false;
		return !! node.checked;
	}

	// =========================================================
	// Block 2 — Pricing rows
	// =========================================================

	function renderBlockPricing() {
		const block = blockShell( 'pricing', '2. Pricing by dimensions', 'Customer width × height rounds up to the smallest matching cell.' );
		block.appendChild( tabBar( 'pricing', [
			{ id: 'manual', label: 'Manual rows' },
			{ id: 'excel',  label: 'Import Excel' },
		] ) );

		if ( activeTab( 'pricing', 'manual' ) === 'excel' ) {
			block.appendChild( renderPricingExcel() );
		} else {
			block.appendChild( renderPricingManual() );
		}

		const count = state.snapshot ? state.snapshot.pricing_rows.length : 0;
		block.appendChild( el( 'p', { class: 'configkit-pb__count' }, count + ' row' + ( count === 1 ? '' : 's' ) + ' configured.' ) );
		return block;
	}

	function renderPricingManual() {
		// Rehydrate drafts from the snapshot the first time we land
		// here, but DO NOT re-overwrite drafts the owner is editing.
		if ( ! Array.isArray( state.drafts.pricing_rows ) ) {
			state.drafts.pricing_rows = ( state.snapshot.pricing_rows || [] ).map( ( r ) => ( {
				from_width:  '',
				to_width:    r.to_width,
				from_height: '',
				to_height:   r.to_height,
				price:       r.price,
				price_group_key: r.price_group_key || '',
			} ) );
			if ( state.drafts.pricing_rows.length === 0 ) {
				state.drafts.pricing_rows.push( blankPricingRow() );
			}
		}

		const wrap = el( 'div', { class: 'configkit-pb__pricing-table' } );
		const head = el( 'div', { class: 'configkit-pb__pricing-row configkit-pb__pricing-row--head' },
			el( 'span', null, 'From width' ),
			el( 'span', null, 'To width' ),
			el( 'span', null, 'From height' ),
			el( 'span', null, 'To height' ),
			el( 'span', null, 'Price (kr)' ),
			el( 'span', null, '' )
		);
		wrap.appendChild( head );

		state.drafts.pricing_rows.forEach( ( row, i ) => {
			wrap.appendChild( renderPricingRow( row, i ) );
		} );

		const footer = el( 'div', { class: 'configkit-pb__row-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					state.drafts.pricing_rows.push( blankPricingRow() );
					render();
				},
			}, '+ Add row' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: savePricing,
			}, state.busy ? 'Saving…' : 'Save pricing' )
		);
		wrap.appendChild( footer );
		return wrap;
	}

	function blankPricingRow() {
		return { from_width: '', to_width: '', from_height: '', to_height: '', price: '', price_group_key: '' };
	}

	function renderPricingRow( row, index ) {
		const cell = el( 'div', { class: 'configkit-pb__pricing-row' } );
		cell.appendChild( inputField( '0',    row.from_width,  { type: 'number', field: 'from_width' } ) );
		cell.appendChild( inputField( '2400', row.to_width,    { type: 'number', field: 'to_width' } ) );
		cell.appendChild( inputField( '0',    row.from_height, { type: 'number', field: 'from_height' } ) );
		cell.appendChild( inputField( '2000', row.to_height,   { type: 'number', field: 'to_height' } ) );
		cell.appendChild( inputField( '12000', row.price,      { type: 'number', step: '0.01', field: 'price' } ) );
		cell.appendChild( el( 'button', {
			type: 'button',
			class: 'configkit-pb__row-remove',
			'aria-label': 'Remove row',
			title: 'Remove row',
			onClick: () => {
				// Persist current input values into the draft so we
				// don't lose what the owner just typed.
				readPricingDraftFromDOM();
				state.drafts.pricing_rows.splice( index, 1 );
				if ( state.drafts.pricing_rows.length === 0 ) {
					state.drafts.pricing_rows.push( blankPricingRow() );
				}
				render();
			},
		}, '✕' ) );
		return cell;
	}

	/**
	 * Walk the rendered pricing rows and copy their current input
	 * values into state.drafts.pricing_rows. Called before any
	 * structural change (add/remove row) so the new render
	 * reproduces what the owner sees on screen.
	 */
	function readPricingDraftFromDOM() {
		const cells = root.querySelectorAll( '[data-block-id="pricing"] .configkit-pb__pricing-row' );
		const out = [];
		cells.forEach( ( cell ) => {
			if ( cell.classList.contains( 'configkit-pb__pricing-row--head' ) ) return;
			out.push( {
				from_width:      readNumberFromCell( cell, 'from_width' ),
				to_width:        readNumberFromCell( cell, 'to_width' ),
				from_height:     readNumberFromCell( cell, 'from_height' ),
				to_height:       readNumberFromCell( cell, 'to_height' ),
				price:           readNumberFromCell( cell, 'price' ),
				price_group_key: '',
			} );
		} );
		state.drafts.pricing_rows = out;
	}

	async function savePricing() {
		readPricingDraftFromDOM();
		const rows = ( state.drafts.pricing_rows || [] ).filter( ( r ) =>
			r.to_width !== null && r.to_height !== null && r.price !== null
		);
		try {
			const result = await pbRequest( '/pricing', {
				method: 'POST',
				body: { rows },
			} );
			showMessage( 'success', ( result && result.message ) || 'Pricing saved.' );
			state.drafts.pricing_rows = null;
			await loadSnapshot();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function renderPricingExcel() {
		const wrap = el( 'div', { class: 'configkit-pb__excel' } );
		const lookupKey = state.snapshot.state.lookup_table_key;
		if ( ! lookupKey ) {
			wrap.appendChild( el(
				'p',
				{ class: 'description' },
				'Save at least one manual row first — that creates the underlying lookup table the import targets.'
			) );
			return wrap;
		}
		wrap.appendChild( dropZone( {
			placeholder: 'Drop a pricing .xlsx (Format A grid or Format B long).',
			helperText:  'The file replaces every existing pricing row in the lookup table for this product.',
			onPick:      ( file ) => uploadImportFile( file, 'lookup_cells', lookupKey ),
		} ) );
		return wrap;
	}

	// =========================================================
	// Block 3 — Fabric options
	// =========================================================

	function renderBlockFabrics() {
		const block = blockShell( 'fabrics', '3. Fabric options', 'Library items the customer picks from. Each fabric inherits this product\'s pricing.' );
		block.appendChild( tabBar( 'fabrics', [
			{ id: 'manual', label: 'Add manually' },
			{ id: 'excel',  label: 'Import Excel' },
		] ) );

		if ( activeTab( 'fabrics', 'manual' ) === 'excel' ) {
			block.appendChild( renderFabricsExcel() );
		} else {
			block.appendChild( renderFabricsManual() );
		}

		const count = state.snapshot ? state.snapshot.fabrics.length : 0;
		block.appendChild( el( 'p', { class: 'configkit-pb__count' }, count + ' fabric' + ( count === 1 ? '' : 's' ) + ' configured.' ) );
		return block;
	}

	function renderFabricsManual() {
		if ( ! Array.isArray( state.drafts.fabrics ) ) {
			state.drafts.fabrics = ( state.snapshot.fabrics || [] ).map( ( f ) => ( {
				name:         f.label || '',
				code:         f.sku || '',
				collection:   ( f.attributes && f.attributes.collection ) || '',
				color_family: f.color_family || '',
				price_group:  f.price_group_key || '',
				extra_price:  f.price || '',
				image_url:    f.image_url || '',
				active:       !! f.is_active,
			} ) );
			if ( state.drafts.fabrics.length === 0 ) state.drafts.fabrics.push( blankFabric() );
		}

		const list = el( 'div', { class: 'configkit-pb__card-list' } );
		state.drafts.fabrics.forEach( ( fabric, i ) => list.appendChild( renderFabricCard( fabric, i ) ) );

		const footer = el( 'div', { class: 'configkit-pb__row-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					readFabricDraftFromDOM();
					state.drafts.fabrics.push( blankFabric() );
					render();
				},
			}, '+ Add fabric' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveFabrics,
			}, state.busy ? 'Saving…' : 'Save fabrics' )
		);

		const wrap = el( 'div' );
		wrap.appendChild( list );
		wrap.appendChild( footer );
		return wrap;
	}

	function blankFabric() {
		return { name: '', code: '', collection: '', color_family: '', price_group: '', extra_price: '', image_url: '', active: true };
	}

	function renderFabricCard( fabric, index ) {
		const card = el( 'div', { class: 'configkit-pb__card', 'data-block-card': 'fabric' } );

		const imageWrap = el( 'div', { class: 'configkit-pb__card-image' } );
		if ( fabric.image_url ) {
			imageWrap.appendChild( el( 'img', { src: fabric.image_url, alt: '' } ) );
		} else {
			imageWrap.appendChild( el( 'span', { class: 'configkit-pb__image-placeholder' }, '🖼' ) );
		}
		imageWrap.appendChild( el( 'button', {
			type:    'button',
			class:   'button button-small',
			onClick: () => pickImageInto( fabric, 'image_url', () => render() ),
		}, fabric.image_url ? 'Change image' : 'Set image' ) );
		// Keep a hidden mirror of image_url so we read it back into the
		// draft when the owner clicks Save / Add.
		imageWrap.appendChild( el( 'input', {
			type: 'hidden',
			'data-pb-field': 'image_url',
			value: fabric.image_url || '',
		} ) );
		card.appendChild( imageWrap );

		const fields = el( 'div', { class: 'configkit-pb__card-fields' } );
		fields.appendChild( labelled( 'Name',         inputField( 'Beige',   fabric.name,         { field: 'name' } ) ) );
		fields.appendChild( labelled( 'Code (SKU)',   inputField( 'U171',    fabric.code,         { field: 'code', cls: 'configkit-pb__input code' } ) ) );
		fields.appendChild( labelled( 'Collection',   inputField( 'Orchestra', fabric.collection, { field: 'collection' } ) ) );
		fields.appendChild( labelled( 'Color family', inputField( 'beige',   fabric.color_family, { field: 'color_family' } ) ) );
		fields.appendChild( labelled( 'Price group',  inputField( 'I',       fabric.price_group,  { field: 'price_group', cls: 'configkit-pb__input code' } ) ) );
		fields.appendChild( labelled( 'Extra price (kr)', inputField( '0', fabric.extra_price, { type: 'number', step: '0.01', field: 'extra_price' } ) ) );
		card.appendChild( fields );

		const meta = el( 'div', { class: 'configkit-pb__card-meta' } );
		const activeLabel = el( 'label', null,
			el( 'input', { type: 'checkbox', 'data-pb-field': 'active', checked: !! fabric.active } ),
			' Active'
		);
		meta.appendChild( activeLabel );
		meta.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link configkit-pb__card-remove',
			onClick: () => {
				readFabricDraftFromDOM();
				state.drafts.fabrics.splice( index, 1 );
				if ( state.drafts.fabrics.length === 0 ) state.drafts.fabrics.push( blankFabric() );
				render();
			},
		}, 'Delete' ) );
		card.appendChild( meta );
		return card;
	}

	function labelled( label, child ) {
		return el( 'label', { class: 'configkit-pb__field' },
			el( 'span', { class: 'configkit-pb__field-label' }, label ),
			child
		);
	}

	function readFabricDraftFromDOM() {
		const cards = root.querySelectorAll( '[data-block-card="fabric"]' );
		const out = [];
		cards.forEach( ( card ) => {
			out.push( {
				name:         readStringFromCell( card, 'name' ),
				code:         readStringFromCell( card, 'code' ),
				collection:   readStringFromCell( card, 'collection' ),
				color_family: readStringFromCell( card, 'color_family' ),
				price_group:  readStringFromCell( card, 'price_group' ),
				extra_price:  readNumberFromCell( card, 'extra_price' ),
				image_url:    readStringFromCell( card, 'image_url' ),
				active:       readCheckedFromCell( card, 'active' ),
			} );
		} );
		state.drafts.fabrics = out;
	}

	async function saveFabrics() {
		readFabricDraftFromDOM();
		const fabrics = ( state.drafts.fabrics || [] ).filter( ( f ) => ( f.name || '' ).trim() !== '' );
		try {
			const result = await pbRequest( '/fabrics', {
				method: 'POST',
				body: { fabrics },
			} );
			showMessage( 'success', ( result && result.message ) || 'Fabrics saved.' );
			state.drafts.fabrics = null;
			await loadSnapshot();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function renderFabricsExcel() {
		const wrap = el( 'div', { class: 'configkit-pb__excel' } );
		const libKey = state.snapshot.state.fabric_library_key;
		if ( ! libKey ) {
			wrap.appendChild( el(
				'p',
				{ class: 'description' },
				'Save at least one fabric manually first — that creates the underlying library the import targets.'
			) );
			return wrap;
		}
		wrap.appendChild( el( 'p', { class: 'description' },
			'Required headers: name, code. Optional: collection, color_family, price_group, image_url, fabric_code, material, transparency.'
		) );
		wrap.appendChild( dropZone( {
			placeholder: 'Drop a fabric .xlsx — Format C with library_key, item_key, label.',
			helperText:  'The file replaces every fabric in this product\'s library.',
			onPick:      ( file ) => uploadImportFile( file, 'library_items', libKey ),
		} ) );
		return wrap;
	}

	// =========================================================
	// Block 5 — Operation mode
	// =========================================================

	function renderBlockOperation() {
		const block = blockShell( 'operation', '5. How is the product operated?', 'Pick once. The customer-facing flow shows the right options.' );
		const current = state.snapshot.state.operation_mode || '';
		const options = [
			{ id: 'manual_only',    label: 'Manual only',     hint: 'Customer only sees stang / sveiv options.' },
			{ id: 'motorized_only', label: 'Motorized only',  hint: 'Customer only sees motor options.' },
			{ id: 'both',           label: 'Both — customer chooses', hint: 'Customer picks between manual and motorized; we render the matching options.' },
		];
		const grid = el( 'div', { class: 'configkit-pb__operation-grid' } );
		options.forEach( ( opt ) => {
			const card = el( 'button', {
				type:  'button',
				class: 'configkit-pb__operation-card' + ( opt.id === current ? ' is-selected' : '' ),
				disabled: state.busy,
				onClick: () => saveOperationMode( opt.id ),
			} );
			card.appendChild( el( 'span', { class: 'configkit-pb__operation-label' }, opt.label ) );
			card.appendChild( el( 'span', { class: 'configkit-pb__operation-hint' }, opt.hint ) );
			grid.appendChild( card );
		} );
		block.appendChild( grid );
		if ( current === 'both' ) {
			block.appendChild( el( 'p', { class: 'description configkit-pb__form-hint' },
				'When the customer picks "Manual" we hide motor options; when they pick "Motorized" we hide stang options. The orchestrator manages those rules silently.'
			) );
		}
		return block;
	}

	async function saveOperationMode( mode ) {
		try {
			const result = await pbRequest( '/operation-mode', { method: 'POST', body: { mode } } );
			showMessage( 'success', ( result && result.message ) || 'Operation mode saved.' );
			await loadSnapshot();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	// =========================================================
	// Block 6 — Stang options (manual operation)
	// =========================================================

	function renderBlockStang() {
		const block = blockShell( 'stang', '6. Stang / sveiv options', 'Manual cranks the customer can pick from.' );

		if ( ! Array.isArray( state.drafts.stangs ) ) {
			state.drafts.stangs = ( state.snapshot.stangs || [] ).map( ( s ) => ( {
				name:      s.label || '',
				code:      s.sku || '',
				length_cm: ( s.attributes && s.attributes.length_cm ) || '',
				price:     s.price || '',
				image_url: s.image_url || '',
				active:    !! s.is_active,
			} ) );
			if ( state.drafts.stangs.length === 0 ) state.drafts.stangs.push( blankStang() );
		}

		const list = el( 'div', { class: 'configkit-pb__card-list' } );
		state.drafts.stangs.forEach( ( s, i ) => list.appendChild( renderStangCard( s, i ) ) );

		const footer = el( 'div', { class: 'configkit-pb__row-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					readStangDraftFromDOM();
					state.drafts.stangs.push( blankStang() );
					render();
				},
			}, '+ Add stang' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveStangs,
			}, state.busy ? 'Saving…' : 'Save stang options' )
		);
		block.appendChild( list );
		block.appendChild( footer );

		const count = state.snapshot.stangs.length;
		block.appendChild( el( 'p', { class: 'configkit-pb__count' }, count + ' stang option' + ( count === 1 ? '' : 's' ) + ' configured.' ) );
		return block;
	}

	function blankStang() {
		return { name: '', code: '', length_cm: '', price: '', image_url: '', active: true };
	}

	function renderStangCard( stang, index ) {
		const card = el( 'div', { class: 'configkit-pb__card', 'data-block-card': 'stang' } );
		card.appendChild( imageCellFor( stang, () => render() ) );

		const fields = el( 'div', { class: 'configkit-pb__card-fields' } );
		fields.appendChild( labelled( 'Name',          inputField( 'Standard 150', stang.name,      { field: 'name' } ) ) );
		fields.appendChild( labelled( 'Code',          inputField( 'STG-150',      stang.code,      { field: 'code', cls: 'configkit-pb__input code' } ) ) );
		fields.appendChild( labelled( 'Length (cm)',   inputField( '150',          stang.length_cm, { type: 'number', field: 'length_cm' } ) ) );
		fields.appendChild( labelled( 'Price (kr)',    inputField( '800',          stang.price,     { type: 'number', step: '0.01', field: 'price' } ) ) );
		card.appendChild( fields );

		card.appendChild( deleteCell( () => {
			readStangDraftFromDOM();
			state.drafts.stangs.splice( index, 1 );
			if ( state.drafts.stangs.length === 0 ) state.drafts.stangs.push( blankStang() );
			render();
		}, stang.active ) );
		return card;
	}

	function readStangDraftFromDOM() {
		const cards = root.querySelectorAll( '[data-block-card="stang"]' );
		const out = [];
		cards.forEach( ( card ) => {
			out.push( {
				name:      readStringFromCell( card, 'name' ),
				code:      readStringFromCell( card, 'code' ),
				length_cm: readNumberFromCell( card, 'length_cm' ),
				price:     readNumberFromCell( card, 'price' ),
				image_url: readStringFromCell( card, 'image_url' ),
				active:    readCheckedFromCell( card, 'active' ),
			} );
		} );
		state.drafts.stangs = out;
	}

	async function saveStangs() {
		readStangDraftFromDOM();
		const stangs = ( state.drafts.stangs || [] )
			.filter( ( s ) => ( s.name || '' ).trim() !== '' )
			.map( ( s ) => ( {
				name:   s.name,
				code:   s.code,
				price:  s.price,
				image_url: s.image_url,
				active: s.active,
				attributes: s.length_cm ? { length_cm: s.length_cm } : {},
			} ) );
		try {
			const result = await pbRequest( '/stangs', { method: 'POST', body: { stangs } } );
			showMessage( 'success', ( result && result.message ) || 'Stang options saved.' );
			state.drafts.stangs = null;
			await loadSnapshot();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	// =========================================================
	// Block 7 — Motor options (single + bundle)
	// =========================================================

	function renderBlockMotor() {
		const block = blockShell( 'motor', '7. Motor options', 'Single motors and motor packages (bundles).' );
		block.appendChild( tabBar( 'motors', [
			{ id: 'single', label: 'Single motor' },
			{ id: 'bundle', label: 'Motor package' },
		] ) );

		// Drafts hold both kinds; render filters by tab.
		if ( ! Array.isArray( state.drafts.motors ) ) {
			state.drafts.motors = ( state.snapshot.motors || [] ).map( motorRecordToDraft );
			if ( state.drafts.motors.filter( ( m ) => ! m._bundle ).length === 0 ) {
				state.drafts.motors.push( blankMotorSingle() );
			}
			if ( state.drafts.motors.filter( ( m ) => m._bundle ).length === 0 ) {
				state.drafts.motors.push( blankMotorBundle() );
			}
		}

		const tab = activeTab( 'motors', 'single' );
		const filtered = state.drafts.motors.filter( ( m ) => ( !! m._bundle ) === ( tab === 'bundle' ) );
		const list = el( 'div', { class: 'configkit-pb__card-list' } );
		filtered.forEach( ( m ) => list.appendChild( tab === 'bundle' ? renderMotorBundleCard( m ) : renderMotorSingleCard( m ) ) );

		const footer = el( 'div', { class: 'configkit-pb__row-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					readMotorDraftFromDOM();
					state.drafts.motors.push( tab === 'bundle' ? blankMotorBundle() : blankMotorSingle() );
					render();
				},
			}, tab === 'bundle' ? '+ Add package' : '+ Add motor' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveMotors,
			}, state.busy ? 'Saving…' : ( tab === 'bundle' ? 'Save motor packages' : 'Save motors' ) )
		);
		block.appendChild( list );
		block.appendChild( footer );

		const count = state.snapshot.motors.length;
		block.appendChild( el( 'p', { class: 'configkit-pb__count' }, count + ' motor option' + ( count === 1 ? '' : 's' ) + ' configured.' ) );
		return block;
	}

	function motorRecordToDraft( m ) {
		const isBundle = ( m.item_type === 'bundle' );
		return {
			_bundle:        isBundle,
			_id:            m.id || null,
			name:           m.label || '',
			code:           m.sku || '',
			price:          m.price || '',
			image_url:      m.image_url || '',
			active:         !! m.is_active,
			woo_product_id: m.woo_product_id || 0,
			price_source:   m.price_source || 'configkit',
			components:     ( m.bundle_components || [] ).map( ( c ) => ( {
				woo_product_id: c.woo_product_id || 0,
				qty:            c.qty || 1,
			} ) ),
			fixed_price:    m.bundle_fixed_price || '',
		};
	}

	function blankMotorSingle() {
		return { _bundle: false, _id: null, name: '', code: '', price: '', image_url: '', active: true, woo_product_id: 0, price_source: 'woo', components: [], fixed_price: '' };
	}

	function blankMotorBundle() {
		return { _bundle: true, _id: null, name: '', code: '', price: '', image_url: '', active: true, woo_product_id: 0, price_source: 'bundle_sum', components: [], fixed_price: '' };
	}

	function renderMotorSingleCard( motor ) {
		const card = el( 'div', { class: 'configkit-pb__card', 'data-block-card': 'motor-single' } );
		card.appendChild( imageCellFor( motor, () => render() ) );

		const fields = el( 'div', { class: 'configkit-pb__card-fields' } );
		fields.appendChild( labelled( 'Name', inputField( 'Sonesse 30 IO', motor.name, { field: 'name' } ) ) );
		fields.appendChild( labelled( 'Code', inputField( 'SO30',          motor.code, { field: 'code', cls: 'configkit-pb__input code' } ) ) );

		// Linked Woo product picker.
		const pickerLabel = el( 'span', { class: 'configkit-pb__field-label' }, 'Linked Woo product' );
		const pickerHost  = el( 'div', { class: 'configkit-pb__woo-picker' } );
		fields.appendChild( el( 'label', { class: 'configkit-pb__field configkit-pb__field--wide' }, pickerLabel, pickerHost ) );
		mountWooPicker( pickerHost, motor );

		const sourceWrap = el( 'span', { class: 'configkit-pb__source-radio' } );
		[ 'woo', 'configkit' ].forEach( ( v ) => {
			const id = 'cf_motor_src_' + Math.random().toString( 36 ).slice( 2, 8 );
			sourceWrap.appendChild( el( 'label', { class: 'configkit-pb__source-option' },
				el( 'input', {
					type: 'radio',
					name: id,
					value: v,
					checked: ( motor.price_source || 'woo' ) === v,
					'data-pb-field': 'price_source',
					onChange: ( ev ) => {
						motor.price_source = ev.target.value;
						render();
					},
				} ),
				v === 'woo' ? ' Use Woo product price' : ' Use custom price',
			) );
		} );
		fields.appendChild( labelled( 'Price source', sourceWrap ) );

		if ( motor.price_source === 'configkit' ) {
			fields.appendChild( labelled( 'Custom price (kr)', inputField( '4500', motor.price, { type: 'number', step: '0.01', field: 'price' } ) ) );
		}

		// Hidden mirror so readMotorDraft picks up image + woo id.
		card.appendChild( el( 'input', { type: 'hidden', 'data-pb-field': '_bundle', value: '0' } ) );

		card.appendChild( fields );
		card.appendChild( deleteCell( () => removeMotor( motor ), motor.active ) );
		return card;
	}

	function renderMotorBundleCard( motor ) {
		const card = el( 'div', { class: 'configkit-pb__card configkit-pb__card--bundle', 'data-block-card': 'motor-bundle' } );
		card.appendChild( imageCellFor( motor, () => render() ) );

		const fields = el( 'div', { class: 'configkit-pb__card-fields' } );
		fields.appendChild( labelled( 'Package name', inputField( 'Premium pack', motor.name, { field: 'name' } ) ) );
		fields.appendChild( labelled( 'Code',         inputField( 'PKG-PREM',     motor.code, { field: 'code', cls: 'configkit-pb__input code' } ) ) );

		const sourceWrap = el( 'span', { class: 'configkit-pb__source-radio' } );
		[ 'bundle_sum', 'fixed_bundle' ].forEach( ( v ) => {
			const name = 'cf_pkg_src_' + Math.random().toString( 36 ).slice( 2, 8 );
			sourceWrap.appendChild( el( 'label', { class: 'configkit-pb__source-option' },
				el( 'input', {
					type: 'radio',
					name,
					value: v,
					checked: motor.price_source === v,
					onChange: ( ev ) => { motor.price_source = ev.target.value; render(); },
				} ),
				v === 'bundle_sum' ? ' Sum of components' : ' Custom package price',
			) );
		} );
		fields.appendChild( labelled( 'Price strategy', sourceWrap ) );

		if ( motor.price_source === 'fixed_bundle' ) {
			fields.appendChild( labelled( 'Fixed package price (kr)', inputField( '8990', motor.fixed_price, { type: 'number', step: '0.01', field: 'fixed_price' } ) ) );
		}
		card.appendChild( fields );

		// Components rows.
		const compsWrap = el( 'div', { class: 'configkit-pb__components' } );
		compsWrap.appendChild( el( 'p', { class: 'configkit-pb__field-label' }, 'Components' ) );
		motor.components.forEach( ( comp, ci ) => {
			const row = el( 'div', { class: 'configkit-pb__component-row' } );
			const pickerHost = el( 'div', { class: 'configkit-pb__woo-picker' } );
			mountWooPicker( pickerHost, comp );
			row.appendChild( pickerHost );
			row.appendChild( inputField( '1', comp.qty, { type: 'number', field: 'qty', cls: 'configkit-pb__input configkit-pb__input--qty' } ) );
			row.appendChild( el( 'button', {
				type: 'button',
				class: 'configkit-pb__row-remove',
				'aria-label': 'Remove component',
				onClick: () => {
					readMotorDraftFromDOM();
					motor.components.splice( ci, 1 );
					render();
				},
			}, '✕' ) );
			compsWrap.appendChild( row );
		} );
		compsWrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link',
			onClick: () => {
				readMotorDraftFromDOM();
				motor.components.push( { woo_product_id: 0, qty: 1 } );
				render();
			},
		}, '+ Add component' ) );
		card.appendChild( compsWrap );

		card.appendChild( deleteCell( () => removeMotor( motor ), motor.active, 'Delete package' ) );
		return card;
	}

	function removeMotor( motor ) {
		readMotorDraftFromDOM();
		const idx = state.drafts.motors.indexOf( motor );
		if ( idx >= 0 ) state.drafts.motors.splice( idx, 1 );
		if ( state.drafts.motors.filter( ( m ) => ! m._bundle ).length === 0 ) state.drafts.motors.push( blankMotorSingle() );
		if ( state.drafts.motors.filter( ( m ) => m._bundle ).length === 0 )   state.drafts.motors.push( blankMotorBundle() );
		render();
	}

	function readMotorDraftFromDOM() {
		// Single motors.
		const single = root.querySelectorAll( '[data-block-card="motor-single"]' );
		const bundle = root.querySelectorAll( '[data-block-card="motor-bundle"]' );

		// Reuse object identities from existing drafts so we don't lose
		// woo picker selections that live on the JS object directly.
		const existingSingle = state.drafts.motors.filter( ( m ) => ! m._bundle );
		const existingBundle = state.drafts.motors.filter( ( m ) => m._bundle );
		single.forEach( ( card, i ) => {
			const m = existingSingle[ i ] || ( existingSingle[ i ] = blankMotorSingle() );
			m.name        = readStringFromCell( card, 'name' );
			m.code        = readStringFromCell( card, 'code' );
			m.image_url   = readStringFromCell( card, 'image_url' );
			m.active      = readCheckedFromCell( card, 'active' );
			m.price       = readNumberFromCell( card, 'price' );
			// price_source already updated by radio onChange handler.
		} );
		bundle.forEach( ( card, i ) => {
			const m = existingBundle[ i ] || ( existingBundle[ i ] = blankMotorBundle() );
			m.name        = readStringFromCell( card, 'name' );
			m.code        = readStringFromCell( card, 'code' );
			m.image_url   = readStringFromCell( card, 'image_url' );
			m.active      = readCheckedFromCell( card, 'active' );
			m.fixed_price = readNumberFromCell( card, 'fixed_price' );
			// Read component qtys back into objects.
			const rows = card.querySelectorAll( '.configkit-pb__component-row' );
			rows.forEach( ( row, ci ) => {
				if ( m.components[ ci ] ) {
					m.components[ ci ].qty = readNumberFromCell( row, 'qty' ) || 1;
				}
			} );
		} );
		state.drafts.motors = existingSingle.concat( existingBundle );
	}

	async function saveMotors() {
		readMotorDraftFromDOM();
		const motors = ( state.drafts.motors || [] )
			.filter( ( m ) => ( m.name || '' ).trim() !== '' )
			.map( ( m ) => {
				if ( m._bundle ) {
					return {
						name:        m.name,
						code:        m.code,
						active:      m.active,
						image_url:   m.image_url,
						components:  ( m.components || [] ).filter( ( c ) => c.woo_product_id > 0 ),
						fixed_price: m.price_source === 'fixed_bundle' ? m.fixed_price : '',
					};
				}
				return {
					name:           m.name,
					code:           m.code,
					active:         m.active,
					image_url:      m.image_url,
					woo_product_id: m.woo_product_id,
					price_source:   m.price_source,
					price:          m.price_source === 'configkit' ? m.price : '',
				};
			} );
		try {
			const result = await pbRequest( '/motors', { method: 'POST', body: { motors } } );
			showMessage( 'success', ( result && result.message ) || 'Motors saved.' );
			state.drafts.motors = null;
			await loadSnapshot();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	// =========================================================
	// Shared UI bits — image cell, delete cell, Woo picker mount
	// =========================================================

	function imageCellFor( target, onChange ) {
		const wrap = el( 'div', { class: 'configkit-pb__card-image' } );
		if ( target.image_url ) {
			wrap.appendChild( el( 'img', { src: target.image_url, alt: '' } ) );
		} else {
			wrap.appendChild( el( 'span', { class: 'configkit-pb__image-placeholder' }, '🖼' ) );
		}
		wrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-small',
			onClick: () => pickImageInto( target, 'image_url', onChange ),
		}, target.image_url ? 'Change image' : 'Set image' ) );
		wrap.appendChild( el( 'input', {
			type: 'hidden',
			'data-pb-field': 'image_url',
			value: target.image_url || '',
		} ) );
		return wrap;
	}

	function deleteCell( onDelete, isActive, label ) {
		const meta = el( 'div', { class: 'configkit-pb__card-meta' } );
		meta.appendChild( el( 'label', null,
			el( 'input', { type: 'checkbox', 'data-pb-field': 'active', checked: !! isActive } ),
			' Active'
		) );
		meta.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link configkit-pb__card-remove',
			onClick: onDelete,
		}, label || 'Delete' ) );
		return meta;
	}

	function mountWooPicker( host, target ) {
		if ( ! window.ConfigKit || ! window.ConfigKit.createWooProductPicker ) {
			host.appendChild( el( 'em', null, 'Picker unavailable.' ) );
			return;
		}
		// If the target already has a woo_product_id we'd ideally pass
		// initial = { id, name, ... } but we don't have the name yet;
		// the picker self-hydrates via /woo-products/{id} in a future
		// chunk. For now we just display "#id" as the placeholder.
		const initial = target.woo_product_id && target.woo_product_id > 0
			? { id: target.woo_product_id, name: '#' + target.woo_product_id }
			: null;
		window.ConfigKit.createWooProductPicker( {
			mount: host,
			initial,
			placeholder: 'Search WooCommerce product…',
			onChange: ( selection ) => {
				target.woo_product_id = selection ? selection.id : 0;
			},
		} );
	}

	// =========================================================
	// Image picker — wp.media bridge
	// =========================================================

	function pickImageInto( target, key, onChange ) {
		if ( ! window.wp || ! window.wp.media ) {
			showMessage( 'error', 'WordPress media library is not available on this screen.' );
			return;
		}
		const frame = window.wp.media( {
			title:  'Pick image',
			button: { text: 'Use image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			target[ key ] = attachment.url || '';
			if ( typeof onChange === 'function' ) onChange();
		} );
		frame.open();
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

		const recipe = currentRecipe();
		if ( ! recipe ) return;

		if ( recipe.blocks.indexOf( 'pricing' )    >= 0 ) root.appendChild( renderBlockPricing() );
		if ( recipe.blocks.indexOf( 'fabrics' )    >= 0 ) root.appendChild( renderBlockFabrics() );
		if ( recipe.blocks.indexOf( 'operation' )  >= 0 ) root.appendChild( renderBlockOperation() );

		const mode = state.snapshot.state.operation_mode || '';
		const showStang = recipe.blocks.indexOf( 'stang' ) >= 0 && ( mode === 'manual_only' || mode === 'both' );
		const showMotor = recipe.blocks.indexOf( 'motor' ) >= 0 && ( mode === 'motorized_only' || mode === 'both' );
		if ( showStang ) root.appendChild( renderBlockStang() );
		if ( showMotor ) root.appendChild( renderBlockMotor() );
	}

	function currentRecipe() {
		const id = state.snapshot && state.snapshot.state && state.snapshot.state.product_type;
		if ( ! id ) return null;
		return state.recipes.find( ( r ) => r.id === id ) || null;
	}

	// Kick off.
	init();
} )();
