/* global ConfigKit */
/**
 * Phase 4.4 — Yith-style Product Configurator Builder.
 *
 * The Woo product ConfigKit tab mounts this on
 * `#configkit-configurator-builder-app`. Section cards live in a
 * vertical drag-drop list. Each card opens a modal editor whose
 * tabs differ by section type (size_pricing → range table,
 * option_group → option cards + bulk paste, motor → single +
 * bundle tabs, etc.). Section CRUD hits
 * `/configkit/v1/configurator/{productId}/sections/*`; per-block
 * data lives behind the existing per-block save endpoints from
 * Phase 4.3.
 *
 * UX rules (kept from real-data testing fixes):
 *   - Uncontrolled text inputs — render() sets `value`, save reads
 *     `.value`. We never overwrite a value the owner is in the
 *     middle of typing.
 *   - Owner-friendly errors: surface `result.message` verbatim.
 *   - Element IDs visible read-only with copy-on-click.
 *
 * Subsequent chunks land:
 *   chunk 4 — size pricing range table editor
 *   chunk 5 — option group cards + bulk paste + ZIP image matcher
 *   chunk 6 — motor section (single + bundle)
 *   chunk 7 — visibility builder
 *   chunk 8 — diagnostics modal + status pills
 */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-configurator-builder-app' );
	if ( ! root ) return;
	if ( ! window.ConfigKit || typeof window.ConfigKit.request !== 'function' ) return;

	const productId = parseInt( root.getAttribute( 'data-product-id' ) || '0', 10 );
	if ( ! productId ) {
		root.innerHTML = '<p class="configkit-app__loading">Save the product first, then refresh to use the configurator builder.</p>';
		return;
	}

	const advancedRoot = document.getElementById( 'configkit-product-binding-app' );

	// =========================================================
	// State
	// =========================================================

	const state = {
		ready:        false,
		busy:         false,
		showAdvanced: false,
		message:      null,
		sections:     [],
		sectionTypes: [],
		modal:        null, // { sectionId, tab, ranges?, rangeDiagnostics?, options?, zipMatch? }
		bulkPaste:    null, // { sectionId, text, errors }
		optionCounts: {},   // sectionId → number of saved options (for card meta)
		// drag-drop bookkeeping
		dragId:       null,
	};

	// =========================================================
	// DOM helpers
	// =========================================================

	function el( tag, attrs, ...children ) {
		const node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( ( key ) => {
				const v = attrs[ key ];
				if ( v === false || v === null || v === undefined ) return;
				if ( key === 'class' ) node.className = v;
				else if ( key === 'html' ) node.innerHTML = v;
				else if ( key.startsWith( 'on' ) && typeof v === 'function' ) node.addEventListener( key.slice( 2 ).toLowerCase(), v );
				else if ( key in node && typeof v === 'boolean' ) node[ key ] = v;
				else node.setAttribute( key, String( v ) );
			} );
		}
		children.flat().forEach( ( c ) => {
			if ( c === null || c === undefined || c === false ) return;
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

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

	async function cbRequest( path, options ) {
		state.busy = true;
		render();
		try {
			return await window.ConfigKit.request( '/configurator/' + productId + path, options );
		} finally {
			state.busy = false;
		}
	}

	async function loadSectionTypes() {
		try {
			const data = await window.ConfigKit.request( '/configurator/section-types' );
			state.sectionTypes = ( data && data.types ) || [];
		} catch ( e ) { state.sectionTypes = []; }
	}

	async function loadSections() {
		try {
			const data = await window.ConfigKit.request( '/configurator/' + productId + '/sections' );
			state.sections = ( data && data.sections ) || [];
		} catch ( err ) {
			state.sections = [];
			showMessage( 'error', explainError( err ) );
		}
	}

	async function init() {
		root.dataset.loading = 'true';
		await Promise.all( [ loadSectionTypes(), loadSections() ] );
		state.ready = true;
		root.dataset.loading = 'false';
		render();
	}

	// =========================================================
	// Header + advanced toggle
	// =========================================================

	function deriveStatus() {
		if ( state.sections.length === 0 ) return { cls: 'is-disabled', text: 'Disabled' };
		// Future chunks (8) compute Ready / In progress / Issues from
		// per-section diagnostics; placeholder for now.
		return { cls: 'is-progress', text: 'Setup in progress' };
	}

	function renderHeader() {
		const wrap = el( 'header', { class: 'configkit-cb__header' } );
		const status = deriveStatus();
		wrap.appendChild( el( 'div', { class: 'configkit-cb__title-block' },
			el( 'h2', { class: 'configkit-cb__title' }, 'Setup configurator' ),
			el( 'span', { class: 'configkit-cb__status configkit-cb__status--' + status.cls }, status.text )
		) );
		wrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link configkit-cb__advanced-link',
			onClick: () => {
				state.showAdvanced = ! state.showAdvanced;
				if ( advancedRoot ) advancedRoot.hidden = ! state.showAdvanced;
				render();
			},
		}, state.showAdvanced ? 'Back to configurator builder' : 'Show advanced settings' ) );
		return wrap;
	}

	function messageBanner() {
		if ( ! state.message ) return null;
		const cls = 'notice ' + ( state.message.kind === 'success' ? 'notice-success' : 'notice-error' ) + ' inline configkit-notice';
		return el( 'div', { class: cls }, el( 'p', null, state.message.text ) );
	}

	// =========================================================
	// Section list + drag-drop
	// =========================================================

	function findSection( id ) {
		return state.sections.find( ( s ) => s.id === id ) || null;
	}

	function findType( typeId ) {
		return state.sectionTypes.find( ( t ) => t.id === typeId ) || null;
	}

	function renderSectionList() {
		const wrap = el( 'div', { class: 'configkit-cb__sections' } );
		if ( state.sections.length === 0 ) {
			wrap.appendChild( el( 'div', { class: 'configkit-cb__empty' },
				el( 'p', null, 'No sections yet. Add a section to start building this product\'s configurator.' )
			) );
		}
		state.sections.forEach( ( section ) => wrap.appendChild( renderSectionCard( section ) ) );
		wrap.appendChild( renderAddSection() );
		return wrap;
	}

	function renderSectionCard( section ) {
		const type = findType( section.type ) || { label: section.type, icon: '' };
		const card = el( 'article', {
			class: 'configkit-cb__section-card',
			draggable: 'true',
			'data-section-id': section.id,
			onDragstart: ( ev ) => {
				state.dragId = section.id;
				ev.dataTransfer.effectAllowed = 'move';
				card.classList.add( 'is-dragging' );
			},
			onDragend: () => {
				state.dragId = null;
				card.classList.remove( 'is-dragging' );
				root.querySelectorAll( '.configkit-cb__section-card' ).forEach( ( c ) => c.classList.remove( 'is-drop-before' ) );
			},
			onDragover: ( ev ) => {
				if ( ! state.dragId || state.dragId === section.id ) return;
				ev.preventDefault();
				ev.dataTransfer.dropEffect = 'move';
				card.classList.add( 'is-drop-before' );
			},
			onDragleave: () => card.classList.remove( 'is-drop-before' ),
			onDrop: ( ev ) => {
				ev.preventDefault();
				card.classList.remove( 'is-drop-before' );
				if ( ! state.dragId || state.dragId === section.id ) return;
				moveSection( state.dragId, section.id );
			},
		} );

		card.appendChild( el( 'span', { class: 'configkit-cb__drag-handle', 'aria-hidden': 'true', title: 'Drag to reorder' }, '⋮⋮' ) );

		const main = el( 'div', { class: 'configkit-cb__section-main' } );
		main.appendChild( el( 'div', { class: 'configkit-cb__section-title-row' },
			el( 'span', { class: 'configkit-cb__type-badge' }, type.label ),
			el( 'h3', { class: 'configkit-cb__section-title' }, section.label || type.label )
		) );
		main.appendChild( el( 'p', { class: 'configkit-cb__section-meta' },
			el( 'span', { class: 'configkit-cb__meta-pill' }, summariseSectionContent( section ) ),
			el( 'span', { class: 'configkit-cb__meta-pill' }, summariseVisibility( section ) ),
			el( 'code', {
				class: 'configkit-cb__element-id',
				title: 'Click to copy element ID',
				onClick: () => copyToClipboard( section.id ),
			}, section.id )
		) );
		card.appendChild( main );

		card.appendChild( el( 'div', { class: 'configkit-cb__section-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => openSectionModal( section.id ),
			}, 'Edit' ),
			el( 'button', {
				type: 'button',
				class: 'button-link configkit-cb__section-delete',
				title: 'Delete section',
				onClick: () => deleteSection( section.id ),
			}, '✕' )
		) );
		return card;
	}

	function summariseSectionContent( section ) {
		if ( section.type === 'size_pricing' ) {
			const n = Array.isArray( section.range_rows ) ? section.range_rows.length : 0;
			return n > 0 ? n + ' price row' + ( n === 1 ? '' : 's' ) : 'No pricing yet';
		}
		// Option-group counts come from the editor cache once the
		// modal has loaded; falls back to a generic label.
		const cached = state.optionCounts && state.optionCounts[ section.id ];
		if ( typeof cached === 'number' ) {
			return cached + ' option' + ( cached === 1 ? '' : 's' );
		}
		return 'Choices';
	}

	function summariseVisibility( section ) {
		const v = section.visibility || { mode: 'always' };
		if ( v.mode === 'always' ) return 'Visible always';
		const cs = ( v.conditions || [] ).length;
		return 'Visible when ' + cs + ' condition' + ( cs === 1 ? '' : 's' );
	}

	function renderAddSection() {
		const wrap = el( 'div', { class: 'configkit-cb__add' } );
		const select = el( 'select', { class: 'configkit-cb__add-select' } );
		select.appendChild( el( 'option', { value: '' }, 'Choose a section type to add…' ) );
		state.sectionTypes.forEach( ( t ) => {
			select.appendChild( el( 'option', { value: t.id, title: t.description }, t.label ) );
		} );
		wrap.appendChild( select );
		wrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-primary',
			onClick: () => {
				if ( ! select.value ) return;
				addSection( select.value );
			},
		}, '+ Add section' ) );
		return wrap;
	}

	// =========================================================
	// Section CRUD
	// =========================================================

	async function addSection( type ) {
		try {
			const result = await cbRequest( '/sections', {
				method: 'POST',
				body: { type },
			} );
			state.sections = result.sections || state.sections;
			showMessage( 'success', result.message || 'Section added.' );
			if ( result.section && result.section.id ) {
				openSectionModal( result.section.id );
			}
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	async function deleteSection( sectionId ) {
		const section = findSection( sectionId );
		if ( ! section ) return;
		if ( ! window.confirm( 'Remove section "' + ( section.label || sectionId ) + '"? Underlying data is kept and can be reattached.' ) ) return;
		try {
			const result = await cbRequest( '/sections/' + encodeURIComponent( sectionId ), {
				method: 'DELETE',
				body: {},
			} );
			state.sections = result.sections || [];
			showMessage( 'success', result.message || 'Section removed.' );
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	async function moveSection( movedId, targetId ) {
		const ids = state.sections.map( ( s ) => s.id );
		const fromIdx = ids.indexOf( movedId );
		const toIdx   = ids.indexOf( targetId );
		if ( fromIdx < 0 || toIdx < 0 ) return;
		ids.splice( fromIdx, 1 );
		ids.splice( toIdx, 0, movedId );
		try {
			const result = await cbRequest( '/sections/order', {
				method: 'PUT',
				body: { order: ids },
			} );
			state.sections = result.sections || state.sections;
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	// =========================================================
	// Section modal — shell only in this chunk; per-type editors
	// land in chunks 4-7.
	// =========================================================

	async function openSectionModal( sectionId ) {
		const section = findSection( sectionId );
		state.modal = { sectionId, tab: 'choices' };
		render();
		if ( ! section ) return;
		if ( section.type === 'size_pricing' ) {
			await loadRanges( sectionId );
		} else {
			await loadOptions( sectionId );
		}
		render();
	}

	async function loadRanges( sectionId ) {
		try {
			const data = await window.ConfigKit.request(
				'/configurator/' + productId + '/sections/' + encodeURIComponent( sectionId ) + '/ranges'
			);
			if ( ! state.modal || state.modal.sectionId !== sectionId ) return;
			state.modal.ranges = ( data && data.rows ) || [];
			if ( state.modal.ranges.length === 0 ) state.modal.ranges = [ blankRange() ];
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
		}
	}

	function blankRange() {
		return { width_from: '', width_to: '', height_from: '', height_to: '', price: '', price_group_key: '' };
	}

	function closeSectionModal() {
		state.modal = null;
		render();
	}

	function renderModal() {
		if ( ! state.modal ) return null;
		const section = findSection( state.modal.sectionId );
		if ( ! section ) return null;
		const type = findType( section.type ) || {};

		const overlay = el( 'div', {
			class: 'configkit-cb__modal-overlay',
			onClick: ( ev ) => {
				if ( ev.target === overlay ) closeSectionModal();
			},
		} );
		const modal = el( 'div', { class: 'configkit-cb__modal', role: 'dialog', 'aria-modal': 'true' } );

		// Header
		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-header' },
			el( 'div', { class: 'configkit-cb__modal-title' },
				el( 'span', { class: 'configkit-cb__type-badge' }, type.label || section.type ),
				renderEditableLabel( section )
			),
			el( 'div', { class: 'configkit-cb__modal-id' },
				el( 'span', { class: 'configkit-cb__modal-id-label' }, 'Element ID' ),
				el( 'code', {
					class: 'configkit-cb__element-id',
					title: 'Click to copy',
					onClick: () => copyToClipboard( section.id ),
				}, section.id )
			),
			el( 'button', {
				type: 'button',
				class: 'configkit-cb__modal-close',
				'aria-label': 'Close',
				onClick: closeSectionModal,
			}, '✕' )
		) );

		// Tab strip — Choices is the per-type editor, Visibility
		// builder lands in chunk 7.
		const tabs = [
			{ id: 'choices',    label: tabLabelFor( section.type ) },
			{ id: 'visibility', label: 'Visibility' },
		];
		const tabBar = el( 'div', { class: 'configkit-cb__modal-tabs', role: 'tablist' } );
		tabs.forEach( ( t ) => {
			tabBar.appendChild( el( 'button', {
				type: 'button',
				class: 'configkit-cb__modal-tab' + ( state.modal.tab === t.id ? ' is-active' : '' ),
				onClick: () => {
					state.modal.tab = t.id;
					render();
				},
			}, t.label ) );
		} );
		modal.appendChild( tabBar );

		const body = el( 'div', { class: 'configkit-cb__modal-body' } );
		if ( state.modal.tab === 'choices' ) {
			body.appendChild( renderChoicesTab( section ) );
		} else if ( state.modal.tab === 'visibility' ) {
			body.appendChild( el( 'p', { class: 'description' },
				'Visibility rule builder lands in chunk 7.'
			) );
		}
		modal.appendChild( body );

		// Footer
		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-footer' },
			el( 'button', { type: 'button', class: 'button', onClick: closeSectionModal }, 'Close' )
		) );
		overlay.appendChild( modal );
		return overlay;
	}

	function tabLabelFor( type ) {
		switch ( type ) {
			case 'size_pricing':     return 'Pricing rows';
			case 'option_group':     return 'Options';
			case 'motor':            return 'Motors';
			case 'manual_operation': return 'Operation types';
			case 'controls':         return 'Controls';
			case 'accessories':      return 'Accessories';
			default:                 return 'Choices';
		}
	}

	function renderChoicesTab( section ) {
		if ( section.type === 'size_pricing' ) return renderRangeEditor( section );
		if ( section.type === 'motor' )        return renderMotorEditor( section );
		return renderOptionEditor( section );
	}

	// =========================================================
	// Option editor — shared by option_group, manual_operation,
	// controls, accessories, custom. Motor uses a richer editor
	// (single + bundle tabs) that lands in chunk 6.
	// =========================================================

	async function loadOptions( sectionId ) {
		try {
			const data = await window.ConfigKit.request(
				'/configurator/' + productId + '/sections/' + encodeURIComponent( sectionId ) + '/options'
			);
			if ( ! state.modal || state.modal.sectionId !== sectionId ) return;
			const records = ( data && data.options ) || [];
			state.modal.options = records.map( optionRecordToDraft );
			state.optionCounts[ sectionId ] = records.length;
			if ( state.modal.options.length === 0 ) state.modal.options = [ blankOption() ];
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
		}
	}

	function optionRecordToDraft( o ) {
		const attrs = ( o && o.attributes && typeof o.attributes === 'object' ) ? o.attributes : {};
		return {
			label:          o.label || '',
			sku:            o.sku || '',
			image_url:      o.image_url || '',
			brand:          attrs.brand || '',
			collection:     attrs.collection || '',
			color_family:   o.color_family || '',
			price_group:    o.price_group_key || '',
			price:          ( o.price === null || o.price === undefined ) ? '' : String( o.price ),
			active:         o.is_active !== false,
			// Motor-specific. Survive a round-trip through the
			// option editor so a non-motor section's save doesn't
			// drop these fields silently.
			item_type:          o.item_type || 'simple_option',
			price_source:       o.price_source || 'configkit',
			woo_product_id:     o.woo_product_id || 0,
			woo_product_sku:    o.woo_product_sku || '',
			bundle_components:  Array.isArray( o.bundle_components ) ? o.bundle_components.map( componentRecordToDraft ) : [],
			bundle_fixed_price: ( o.bundle_fixed_price === null || o.bundle_fixed_price === undefined ) ? '' : String( o.bundle_fixed_price ),
		};
	}

	function componentRecordToDraft( c ) {
		return {
			component_key:   c.component_key || '',
			woo_product_id:  c.woo_product_id || 0,
			woo_product_sku: c.woo_product_sku || '',
			qty:             c.qty || 1,
			price_source:    c.price_source || 'woo',
		};
	}

	function blankOption() {
		return {
			label: '', sku: '', image_url: '',
			brand: '', collection: '', color_family: '',
			price_group: '', price: '', active: true,
			item_type: 'simple_option',
			price_source: 'configkit',
			woo_product_id: 0,
			woo_product_sku: '',
			bundle_components: [],
			bundle_fixed_price: '',
		};
	}

	function blankComponent() {
		return { component_key: '', woo_product_id: 0, woo_product_sku: '', qty: 1, price_source: 'woo' };
	}

	function renderOptionEditor( section ) {
		const wrap = el( 'div', { class: 'configkit-cb__option-editor' } );
		const options = ( state.modal && Array.isArray( state.modal.options ) ) ? state.modal.options : null;
		if ( options === null ) {
			wrap.appendChild( el( 'p', { class: 'description' }, 'Loading options…' ) );
			return wrap;
		}

		if ( state.modal.zipMatch ) {
			wrap.appendChild( renderZipMatchSummary( state.modal.zipMatch ) );
		}

		const list = el( 'div', { class: 'configkit-cb__option-list' } );
		options.forEach( ( opt, i ) => list.appendChild( renderOptionCard( section, opt, i ) ) );
		wrap.appendChild( list );

		wrap.appendChild( el( 'div', { class: 'configkit-cb__option-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					readOptionDraftsFromDOM();
					state.modal.options.push( blankOption() );
					render();
				},
			}, '+ Add option' ),
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => openBulkPaste( section.id ),
			}, '📋 Bulk paste options' ),
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => openZipMatcher( section.id ),
			}, '🖼 Match images by SKU' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveOptions,
			}, state.busy ? 'Saving…' : 'Save options' )
		) );
		return wrap;
	}

	function renderOptionCard( section, opt, index ) {
		const card = el( 'div', { class: 'configkit-cb__option-card', 'data-option-index': String( index ) } );

		// Image preview + picker (uses wp.media if present).
		const img = el( 'div', { class: 'configkit-cb__option-image' } );
		const refreshImg = ( url ) => {
			img.replaceChildren();
			if ( url ) {
				img.appendChild( el( 'img', { src: url, alt: '' } ) );
			} else {
				img.appendChild( el( 'span', { class: 'configkit-cb__option-image-empty' }, 'No image' ) );
			}
		};
		refreshImg( opt.image_url );
		const imgPick = el( 'button', {
			type: 'button',
			class: 'button-link',
			onClick: () => pickImageInto( opt, 'image_url', ( url ) => {
				refreshImg( url );
				const hidden = card.querySelector( '[data-field="image_url"]' );
				if ( hidden ) hidden.value = url;
			} ),
		}, opt.image_url ? 'Change' : 'Pick' );
		const imgClear = el( 'button', {
			type: 'button',
			class: 'button-link configkit-cb__option-image-clear',
			onClick: () => {
				opt.image_url = '';
				refreshImg( '' );
				const hidden = card.querySelector( '[data-field="image_url"]' );
				if ( hidden ) hidden.value = '';
			},
		}, '✕' );
		const imgWrap = el( 'div', { class: 'configkit-cb__option-image-wrap' },
			img,
			el( 'div', { class: 'configkit-cb__option-image-actions' }, imgPick, opt.image_url ? imgClear : null )
		);
		card.appendChild( imgWrap );

		// Field grid.
		const grid = el( 'div', { class: 'configkit-cb__option-grid' } );
		grid.appendChild( labelled( 'Name',         textInput( opt.label, 'label' ) ) );
		grid.appendChild( labelled( 'SKU',          textInput( opt.sku, 'sku' ) ) );
		grid.appendChild( labelled( 'Brand',        textInput( opt.brand, 'brand' ) ) );
		grid.appendChild( labelled( 'Collection',   textInput( opt.collection, 'collection' ) ) );
		grid.appendChild( labelled( 'Color family', textInput( opt.color_family, 'color_family' ) ) );
		grid.appendChild( labelled( 'Price group',  textInput( opt.price_group, 'price_group' ) ) );
		grid.appendChild( labelled( 'Price (kr)',   numberInput( opt.price, 'price' ) ) );
		card.appendChild( grid );

		// Hidden image_url field so readOptionDraftsFromDOM picks it up.
		card.appendChild( el( 'input', {
			type: 'hidden',
			'data-field': 'image_url',
			value: opt.image_url || '',
		} ) );

		// Footer: active toggle + delete.
		const footer = el( 'div', { class: 'configkit-cb__option-footer' } );
		const activeBox = el( 'input', {
			type: 'checkbox',
			'data-field': 'active',
			checked: opt.active !== false,
		} );
		footer.appendChild( el( 'label', { class: 'configkit-cb__option-active' },
			activeBox, document.createTextNode( ' Active' )
		) );
		footer.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link configkit-cb__option-delete',
			'aria-label': 'Delete option',
			onClick: () => {
				readOptionDraftsFromDOM();
				state.modal.options.splice( index, 1 );
				if ( state.modal.options.length === 0 ) state.modal.options.push( blankOption() );
				render();
			},
		}, '✕ Delete' ) );
		card.appendChild( footer );

		return card;
	}

	function textInput( value, field, cls ) {
		return el( 'input', {
			type: 'text',
			class: 'configkit-cb__option-input' + ( cls ? ' ' + cls : '' ),
			'data-field': field,
			value: value !== null && value !== undefined ? String( value ) : '',
		} );
	}

	function numberInput( value, field ) {
		return el( 'input', {
			type: 'number',
			class: 'configkit-cb__option-input',
			'data-field': field,
			value: value !== null && value !== undefined && value !== '' ? String( value ) : '',
			step: '0.01',
		} );
	}

	function labelled( label, child ) {
		return el( 'label', { class: 'configkit-cb__option-field' },
			el( 'span', { class: 'configkit-cb__option-field-label' }, label ),
			child
		);
	}

	function readOptionDraftsFromDOM() {
		if ( ! state.modal || ! Array.isArray( state.modal.options ) ) return;
		const cards = root.querySelectorAll( '[data-option-index]' );
		const out = [];
		cards.forEach( ( card ) => {
			out.push( {
				label:        pickString( card, 'label' ),
				sku:          pickString( card, 'sku' ),
				image_url:    pickString( card, 'image_url' ),
				brand:        pickString( card, 'brand' ),
				collection:   pickString( card, 'collection' ),
				color_family: pickString( card, 'color_family' ),
				price_group:  pickString( card, 'price_group' ),
				price:        pickString( card, 'price' ),
				active:       !! ( card.querySelector( '[data-field="active"]' ) || {} ).checked,
			} );
		} );
		state.modal.options = out;
	}

	async function saveOptions() {
		if ( ! state.modal ) return;
		readOptionDraftsFromDOM();
		const payload = ( state.modal.options || [] )
			.filter( ( o ) => ( o.label || '' ).trim() !== '' )
			.map( ( o ) => ( {
				label:        o.label,
				sku:          o.sku,
				image_url:    o.image_url,
				brand:        o.brand,
				collection:   o.collection,
				color_family: o.color_family,
				price_group:  o.price_group,
				price:        o.price === '' ? null : Number( o.price ),
				active:       !! o.active,
			} ) );
		try {
			const result = await cbRequest(
				'/sections/' + encodeURIComponent( state.modal.sectionId ) + '/options',
				{ method: 'POST', body: { options: payload } }
			);
			showMessage( 'success', result.message || 'Options saved.' );
			state.optionCounts[ state.modal.sectionId ] = payload.length;
			await loadOptions( state.modal.sectionId );
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	// wp.media bridge — falls back to a prompt() when wp.media isn't
	// present (which happens on the very first load if the asset hasn't
	// fired its enqueue yet, or in headless-test environments).
	function pickImageInto( target, key, onChange ) {
		const wpMedia = window.wp && window.wp.media;
		if ( ! wpMedia ) {
			const url = window.prompt( 'Image URL:', target[ key ] || '' );
			if ( url !== null ) {
				target[ key ] = url;
				onChange( url );
			}
			return;
		}
		const frame = wpMedia( {
			title: 'Select image',
			button: { text: 'Use this image' },
			multiple: false,
			library: { type: 'image' },
		} );
		frame.on( 'select', () => {
			const sel = frame.state().get( 'selection' ).first().toJSON();
			const url = sel && sel.url ? sel.url : '';
			target[ key ] = url;
			onChange( url );
		} );
		frame.open();
	}

	// =========================================================
	// Image-by-SKU matcher (browser side — pastes a list of
	// filenames, surfaces matched/unmatched against the current
	// drafts' SKUs).
	// =========================================================

	function openZipMatcher( sectionId ) {
		state.modal.zipMatch = { sectionId, filenames: '', report: null };
		render();
	}

	function closeZipMatcher() {
		if ( ! state.modal ) return;
		state.modal.zipMatch = null;
		render();
	}

	function renderZipMatchSummary( zip ) {
		const wrap = el( 'div', { class: 'configkit-cb__zip-match' } );
		wrap.appendChild( el( 'h4', null, 'Match images to SKUs' ) );
		wrap.appendChild( el( 'p', { class: 'description' },
			'Paste image filenames (one per line) — we will match each to the option whose SKU appears at the start of the filename.'
		) );
		wrap.appendChild( el( 'textarea', {
			class: 'configkit-cb__bulk-textarea',
			rows: 5,
			placeholder: 'U171.jpg\nu172_main.png\nU173-2.webp',
			value: zip.filenames,
			onInput: ( ev ) => { zip.filenames = ev.target.value; },
		} ) );
		const actions = el( 'div', { class: 'configkit-cb__zip-actions' } );
		actions.appendChild( el( 'button', {
			type: 'button',
			class: 'button',
			onClick: closeZipMatcher,
		}, 'Cancel' ) );
		actions.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-primary',
			onClick: applyZipMatch,
		}, 'Match & fill' ) );
		wrap.appendChild( actions );
		if ( zip.report ) {
			wrap.appendChild( el( 'p', { class: 'configkit-cb__range-diag-ok' },
				'✓ Matched ' + zip.report.matched + ' / ' + zip.report.total + ' filename(s).'
			) );
			if ( ( zip.report.unmatched_filenames || [] ).length > 0 ) {
				wrap.appendChild( el( 'p', { class: 'configkit-cb__range-diag-warn' },
					'⚠ Unmatched files: ' + zip.report.unmatched_filenames.join( ', ' )
				) );
			}
			if ( ( zip.report.unmatched_skus || [] ).length > 0 ) {
				wrap.appendChild( el( 'p', { class: 'configkit-cb__range-diag-info' },
					'ⓘ SKUs without an image: ' + zip.report.unmatched_skus.join( ', ' )
				) );
			}
		}
		return wrap;
	}

	function applyZipMatch() {
		if ( ! state.modal || ! state.modal.zipMatch ) return;
		readOptionDraftsFromDOM();
		const filenames = String( state.modal.zipMatch.filenames || '' )
			.split( /\r?\n/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		const skuToIdx = {};
		( state.modal.options || [] ).forEach( ( o, i ) => {
			if ( o.sku ) skuToIdx[ o.sku.toLowerCase() ] = i;
		} );
		const unmatchedFiles = [];
		let matched = 0;
		filenames.forEach( ( name ) => {
			const key = normaliseFilenameToSku( name );
			if ( key && skuToIdx[ key ] !== undefined ) {
				const idx = skuToIdx[ key ];
				if ( ! state.modal.options[ idx ].image_url ) {
					state.modal.options[ idx ].image_url = name;
					matched++;
					return;
				}
			}
			unmatchedFiles.push( name );
		} );
		const unmatchedSkus = ( state.modal.options || [] )
			.filter( ( o ) => o.sku && ! o.image_url )
			.map( ( o ) => o.sku );
		state.modal.zipMatch.report = {
			total: filenames.length,
			matched,
			unmatched_filenames: unmatchedFiles,
			unmatched_skus: unmatchedSkus,
		};
		render();
	}

	// =========================================================
	// Motor editor — single + bundle variants. Reuses the
	// option-list/save plumbing; per-card UI swaps in motor
	// fields (woo product, price source, bundle components).
	// =========================================================

	function renderMotorEditor( section ) {
		const wrap = el( 'div', { class: 'configkit-cb__option-editor' } );
		const options = ( state.modal && Array.isArray( state.modal.options ) ) ? state.modal.options : null;
		if ( options === null ) {
			wrap.appendChild( el( 'p', { class: 'description' }, 'Loading motors…' ) );
			return wrap;
		}

		const list = el( 'div', { class: 'configkit-cb__option-list' } );
		options.forEach( ( opt, i ) => list.appendChild( renderMotorCard( section, opt, i ) ) );
		wrap.appendChild( list );

		wrap.appendChild( el( 'div', { class: 'configkit-cb__option-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					readMotorDraftsFromDOM();
					state.modal.options.push( blankOption() );
					render();
				},
			}, '+ Add motor' ),
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					readMotorDraftsFromDOM();
					const draft = blankOption();
					draft.item_type    = 'bundle';
					draft.price_source = 'bundle_sum';
					draft.bundle_components.push( blankComponent() );
					state.modal.options.push( draft );
					render();
				},
			}, '+ Add motor package' ),
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => openBulkPaste( section.id ),
			}, '📋 Bulk paste motors' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveMotorOptions,
			}, state.busy ? 'Saving…' : 'Save motors' )
		) );
		return wrap;
	}

	function renderMotorCard( section, opt, index ) {
		const isBundle = opt.item_type === 'bundle';
		const card = el( 'div', {
			class: 'configkit-cb__option-card configkit-cb__motor-card' + ( isBundle ? ' is-bundle' : '' ),
			'data-option-index': String( index ),
		} );

		// Type toggle.
		const typeRow = el( 'div', { class: 'configkit-cb__motor-type' } );
		typeRow.appendChild( el( 'label', null,
			el( 'input', {
				type: 'radio',
				name: 'motor-type-' + index,
				value: 'simple_option',
				'data-field': 'item_type_simple',
				checked: ! isBundle,
				onChange: () => {
					readMotorDraftsFromDOM();
					state.modal.options[ index ].item_type    = 'simple_option';
					state.modal.options[ index ].price_source = 'configkit';
					render();
				},
			} ),
			document.createTextNode( ' Single motor' )
		) );
		typeRow.appendChild( el( 'label', null,
			el( 'input', {
				type: 'radio',
				name: 'motor-type-' + index,
				value: 'bundle',
				'data-field': 'item_type_bundle',
				checked: isBundle,
				onChange: () => {
					readMotorDraftsFromDOM();
					state.modal.options[ index ].item_type    = 'bundle';
					state.modal.options[ index ].price_source = 'bundle_sum';
					if ( state.modal.options[ index ].bundle_components.length === 0 ) {
						state.modal.options[ index ].bundle_components.push( blankComponent() );
					}
					render();
				},
			} ),
			document.createTextNode( ' Motor package (bundle)' )
		) );
		card.appendChild( typeRow );

		// Common header — name + sku + active.
		const header = el( 'div', { class: 'configkit-cb__option-grid' } );
		header.appendChild( labelled( 'Name',          textInput( opt.label, 'label' ) ) );
		header.appendChild( labelled( 'SKU',           textInput( opt.sku, 'sku' ) ) );
		card.appendChild( header );

		if ( isBundle ) {
			card.appendChild( renderBundleSection( opt, index ) );
		} else {
			card.appendChild( renderSingleMotorSection( opt ) );
		}

		// Hidden fields preserved across saves.
		card.appendChild( el( 'input', { type: 'hidden', 'data-field': 'image_url',  value: opt.image_url || '' } ) );
		card.appendChild( el( 'input', { type: 'hidden', 'data-field': 'brand',      value: opt.brand || '' } ) );
		card.appendChild( el( 'input', { type: 'hidden', 'data-field': 'collection', value: opt.collection || '' } ) );

		// Footer.
		const footer = el( 'div', { class: 'configkit-cb__option-footer' } );
		const activeBox = el( 'input', {
			type: 'checkbox',
			'data-field': 'active',
			checked: opt.active !== false,
		} );
		footer.appendChild( el( 'label', { class: 'configkit-cb__option-active' },
			activeBox, document.createTextNode( ' Active' )
		) );
		footer.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link configkit-cb__option-delete',
			onClick: () => {
				readMotorDraftsFromDOM();
				state.modal.options.splice( index, 1 );
				if ( state.modal.options.length === 0 ) state.modal.options.push( blankOption() );
				render();
			},
		}, '✕ Delete' ) );
		card.appendChild( footer );

		return card;
	}

	function renderSingleMotorSection( opt ) {
		const wrap = el( 'div', { class: 'configkit-cb__option-grid' } );
		wrap.appendChild( labelled( 'Woo product SKU', textInput( opt.woo_product_sku, 'woo_product_sku' ) ) );
		wrap.appendChild( labelled( 'Custom price (kr)', numberInput( opt.price, 'price' ) ) );

		const sourceSelect = el( 'select', { 'data-field': 'price_source', class: 'configkit-cb__option-input' } );
		[ 'configkit', 'woo' ].forEach( ( v ) => {
			sourceSelect.appendChild( el( 'option', {
				value: v,
				selected: ( opt.price_source === v ),
			}, v === 'woo' ? 'From Woo product' : 'Custom (above)' ) );
		} );
		wrap.appendChild( labelled( 'Price source', sourceSelect ) );

		// Hidden numeric id passes through (resolver-set).
		wrap.appendChild( el( 'input', { type: 'hidden', 'data-field': 'woo_product_id', value: String( opt.woo_product_id || 0 ) } ) );
		return wrap;
	}

	function renderBundleSection( opt, optIndex ) {
		const wrap = el( 'div', { class: 'configkit-cb__bundle' } );
		wrap.appendChild( el( 'h4', null, 'Components' ) );

		const table = el( 'div', { class: 'configkit-cb__bundle-table' } );
		table.appendChild( el( 'div', { class: 'configkit-cb__bundle-row configkit-cb__bundle-row--head' },
			el( 'span', null, 'Woo SKU' ),
			el( 'span', null, 'Qty' ),
			el( 'span', null, 'Price source' ),
			el( 'span', null, '' )
		) );
		opt.bundle_components.forEach( ( comp, ci ) => {
			table.appendChild( renderBundleComponent( opt, optIndex, comp, ci ) );
		} );
		wrap.appendChild( table );

		wrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link',
			onClick: () => {
				readMotorDraftsFromDOM();
				state.modal.options[ optIndex ].bundle_components.push( blankComponent() );
				render();
			},
		}, '+ Add component' ) );

		const priceRow = el( 'div', { class: 'configkit-cb__bundle-pricing' } );
		const sumRadio = el( 'input', {
			type: 'radio',
			name: 'bundle-price-' + optIndex,
			value: 'bundle_sum',
			'data-field': 'price_source_sum',
			checked: opt.price_source !== 'fixed_bundle',
			onChange: () => {
				readMotorDraftsFromDOM();
				state.modal.options[ optIndex ].price_source = 'bundle_sum';
				render();
			},
		} );
		const fixedRadio = el( 'input', {
			type: 'radio',
			name: 'bundle-price-' + optIndex,
			value: 'fixed_bundle',
			'data-field': 'price_source_fixed',
			checked: opt.price_source === 'fixed_bundle',
			onChange: () => {
				readMotorDraftsFromDOM();
				state.modal.options[ optIndex ].price_source = 'fixed_bundle';
				render();
			},
		} );
		priceRow.appendChild( el( 'label', null, sumRadio,   document.createTextNode( ' Sum of components' ) ) );
		priceRow.appendChild( el( 'label', null, fixedRadio, document.createTextNode( ' Fixed price (kr): ' ),
			el( 'input', {
				type: 'number',
				step: '0.01',
				'data-field': 'bundle_fixed_price',
				class: 'configkit-cb__option-input',
				value: opt.bundle_fixed_price || '',
				disabled: opt.price_source !== 'fixed_bundle',
			} )
		) );
		wrap.appendChild( priceRow );
		return wrap;
	}

	function renderBundleComponent( opt, optIndex, comp, ci ) {
		const row = el( 'div', { class: 'configkit-cb__bundle-row', 'data-component-index': String( ci ) },
			el( 'input', {
				type: 'text',
				class: 'configkit-cb__option-input',
				'data-field': 'comp_woo_sku',
				value: comp.woo_product_sku || '',
				placeholder: 'SOMFY-MOT-25',
			} ),
			el( 'input', {
				type: 'number',
				min: '1',
				class: 'configkit-cb__option-input',
				'data-field': 'comp_qty',
				value: String( comp.qty || 1 ),
			} ),
			( () => {
				const sel = el( 'select', { class: 'configkit-cb__option-input', 'data-field': 'comp_price_source' } );
				[ 'woo', 'configkit' ].forEach( ( v ) => {
					sel.appendChild( el( 'option', {
						value: v,
						selected: ( comp.price_source === v ),
					}, v === 'woo' ? 'Woo' : 'Free' ) );
				} );
				return sel;
			} )(),
			el( 'button', {
				type: 'button',
				class: 'configkit-cb__row-remove',
				'aria-label': 'Remove component',
				onClick: () => {
					readMotorDraftsFromDOM();
					state.modal.options[ optIndex ].bundle_components.splice( ci, 1 );
					render();
				},
			}, '✕' ),
			// Hidden numeric id (resolver-set).
			el( 'input', { type: 'hidden', 'data-field': 'comp_woo_id', value: String( comp.woo_product_id || 0 ) } )
		);
		return row;
	}

	function readMotorDraftsFromDOM() {
		if ( ! state.modal || ! Array.isArray( state.modal.options ) ) return;
		const cards = root.querySelectorAll( '[data-option-index]' );
		const out = [];
		cards.forEach( ( card ) => {
			const isBundle = !! ( card.querySelector( '[data-field="item_type_bundle"]' ) || {} ).checked;
			const draft = {
				label:           pickString( card, 'label' ),
				sku:             pickString( card, 'sku' ),
				image_url:       pickString( card, 'image_url' ),
				brand:           pickString( card, 'brand' ),
				collection:      pickString( card, 'collection' ),
				color_family:    '',
				price_group:     '',
				price:           pickString( card, 'price' ),
				active:          !! ( card.querySelector( '[data-field="active"]' ) || {} ).checked,
				item_type:       isBundle ? 'bundle' : 'simple_option',
				price_source:    pickString( card, 'price_source' ) || 'configkit',
				woo_product_id:  Number( pickString( card, 'woo_product_id' ) ) || 0,
				woo_product_sku: pickString( card, 'woo_product_sku' ),
				bundle_components: [],
				bundle_fixed_price: pickString( card, 'bundle_fixed_price' ),
			};
			if ( isBundle ) {
				const fixedChecked = !! ( card.querySelector( '[data-field="price_source_fixed"]' ) || {} ).checked;
				draft.price_source = fixedChecked ? 'fixed_bundle' : 'bundle_sum';
				card.querySelectorAll( '[data-component-index]' ).forEach( ( cRow ) => {
					draft.bundle_components.push( {
						component_key:  '',
						woo_product_id: Number( pickString( cRow, 'comp_woo_id' ) ) || 0,
						woo_product_sku: pickString( cRow, 'comp_woo_sku' ),
						qty:            Math.max( 1, Number( pickString( cRow, 'comp_qty' ) ) || 1 ),
						price_source:   pickString( cRow, 'comp_price_source' ) || 'woo',
					} );
				} );
			}
			out.push( draft );
		} );
		state.modal.options = out;
	}

	async function saveMotorOptions() {
		if ( ! state.modal ) return;
		readMotorDraftsFromDOM();
		const payload = ( state.modal.options || [] )
			.filter( ( o ) => ( o.label || '' ).trim() !== '' )
			.map( ( o ) => {
				const row = {
					label:           o.label,
					sku:             o.sku,
					active:          !! o.active,
					price:           o.price === '' ? null : Number( o.price ),
					price_source:    o.price_source,
					woo_product_id:  o.woo_product_id || 0,
					woo_product_sku: o.woo_product_sku,
				};
				if ( o.item_type === 'bundle' ) {
					row.components = ( o.bundle_components || [] )
						.filter( ( c ) => ( c.woo_product_sku || '' ).trim() !== '' || ( c.woo_product_id || 0 ) > 0 )
						.map( ( c ) => ( {
							component_key:   c.component_key || '',
							woo_product_id:  c.woo_product_id || 0,
							woo_product_sku: c.woo_product_sku,
							qty:             c.qty,
							price_source:    c.price_source,
						} ) );
					if ( o.price_source === 'fixed_bundle' && o.bundle_fixed_price !== '' ) {
						row.bundle_fixed_price = Number( o.bundle_fixed_price );
					}
				}
				return row;
			} );
		try {
			const result = await cbRequest(
				'/sections/' + encodeURIComponent( state.modal.sectionId ) + '/options',
				{ method: 'POST', body: { options: payload } }
			);
			showMessage( 'success', result.message || 'Motors saved.' );
			state.optionCounts[ state.modal.sectionId ] = payload.length;
			await loadOptions( state.modal.sectionId );
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function normaliseFilenameToSku( name ) {
		let base = String( name || '' ).replace( /\.[A-Za-z0-9]{2,5}$/, '' ).toLowerCase();
		[ '_main', '_thumb', '_large', '_small', '_alt' ].forEach( ( s ) => {
			if ( base.endsWith( s ) ) base = base.slice( 0, -s.length );
		} );
		base = base.replace( /[-_]\d+$/, '' );
		return base.replace( /^[-_ ]+|[-_ ]+$/g, '' );
	}

	// =========================================================
	// Size pricing — range table + bulk paste
	// =========================================================

	function renderRangeEditor( section ) {
		const wrap = el( 'div', { class: 'configkit-cb__range-editor' } );
		const ranges = ( state.modal && Array.isArray( state.modal.ranges ) ) ? state.modal.ranges : null;
		if ( ranges === null ) {
			wrap.appendChild( el( 'p', { class: 'description' }, 'Loading ranges…' ) );
			return wrap;
		}

		// Diagnostics block — populated by the most recent save.
		if ( state.modal.rangeDiagnostics ) {
			wrap.appendChild( renderRangeDiagnostics( state.modal.rangeDiagnostics ) );
		}

		const table = el( 'div', { class: 'configkit-cb__range-table' } );
		table.appendChild( el( 'div', { class: 'configkit-cb__range-row configkit-cb__range-row--head' },
			el( 'span', null, 'Width from' ),
			el( 'span', null, 'Width to' ),
			el( 'span', null, 'Height from' ),
			el( 'span', null, 'Height to' ),
			el( 'span', null, 'Price (kr)' ),
			el( 'span', null, 'Group' ),
			el( 'span', null, '' )
		) );
		ranges.forEach( ( row, i ) => {
			table.appendChild( renderRangeRow( row, i ) );
		} );
		wrap.appendChild( table );

		wrap.appendChild( el( 'div', { class: 'configkit-cb__range-actions' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => {
					readRangesFromDOM();
					state.modal.ranges.push( blankRange() );
					render();
				},
			}, '+ Add row' ),
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => openBulkPaste( section.id ),
			}, '📋 Bulk paste rows' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveRanges,
			}, state.busy ? 'Saving…' : 'Save ranges' )
		) );
		return wrap;
	}

	function renderRangeRow( row, index ) {
		const overlapWith = state.modal && state.modal.rangeDiagnostics
			? overlapsForRow( index, state.modal.rangeDiagnostics )
			: [];
		const cls = 'configkit-cb__range-row' + ( overlapWith.length > 0 ? ' is-overlap' : '' );
		const node = el( 'div', { class: cls, 'data-range-row': String( index ) },
			rangeInput( row.width_from,  'width_from' ),
			rangeInput( row.width_to,    'width_to' ),
			rangeInput( row.height_from, 'height_from' ),
			rangeInput( row.height_to,   'height_to' ),
			rangeInput( row.price,       'price', 'number', '0.01' ),
			rangeInput( row.price_group_key, 'price_group_key', 'text' ),
			el( 'button', {
				type: 'button',
				class: 'configkit-cb__row-remove',
				'aria-label': 'Remove row',
				onClick: () => {
					readRangesFromDOM();
					state.modal.ranges.splice( index, 1 );
					if ( state.modal.ranges.length === 0 ) state.modal.ranges.push( blankRange() );
					render();
				},
			}, '✕' )
		);
		if ( overlapWith.length > 0 ) {
			node.title = 'Overlaps with row ' + overlapWith.map( ( i ) => i + 1 ).join( ', ' );
		}
		return node;
	}

	function rangeInput( value, field, type, step ) {
		return el( 'input', {
			type: type || 'number',
			class: 'configkit-cb__range-input',
			'data-field': field,
			value: value !== null && value !== undefined && value !== '' ? String( value ) : '',
			step: step,
		} );
	}

	function readRangesFromDOM() {
		if ( ! state.modal || ! Array.isArray( state.modal.ranges ) ) return;
		const rows = root.querySelectorAll( '[data-range-row]' );
		const out = [];
		rows.forEach( ( row ) => {
			out.push( {
				width_from:      pickNumber( row, 'width_from' ),
				width_to:        pickNumber( row, 'width_to' ),
				height_from:     pickNumber( row, 'height_from' ),
				height_to:       pickNumber( row, 'height_to' ),
				price:           pickNumber( row, 'price' ),
				price_group_key: pickString( row, 'price_group_key' ),
			} );
		} );
		state.modal.ranges = out;
	}

	function pickNumber( row, field ) {
		const node = row.querySelector( '[data-field="' + field + '"]' );
		if ( ! node ) return '';
		const raw = String( node.value || '' ).trim();
		if ( raw === '' ) return '';
		const n = Number( raw );
		return Number.isFinite( n ) ? n : '';
	}

	function pickString( row, field ) {
		const node = row.querySelector( '[data-field="' + field + '"]' );
		return node ? String( node.value || '' ).trim() : '';
	}

	function overlapsForRow( index, diag ) {
		const out = [];
		( diag.overlaps || [] ).forEach( ( pair ) => {
			if ( pair.a === index ) out.push( pair.b );
			if ( pair.b === index ) out.push( pair.a );
		} );
		return out;
	}

	function renderRangeDiagnostics( diag ) {
		const wrap = el( 'div', { class: 'configkit-cb__range-diag' } );
		if ( ( diag.overlaps || [] ).length === 0 && ( diag.gaps || [] ).length === 0 ) {
			wrap.appendChild( el( 'p', { class: 'configkit-cb__range-diag-ok' }, '✓ No overlaps or gaps detected.' ) );
			return wrap;
		}
		( diag.overlaps || [] ).forEach( ( o ) => {
			wrap.appendChild( el( 'p', { class: 'configkit-cb__range-diag-warn' }, '⚠ ' + o.message ) );
		} );
		( diag.gaps || [] ).forEach( ( g ) => {
			wrap.appendChild( el( 'p', { class: 'configkit-cb__range-diag-info' }, 'ⓘ ' + g ) );
		} );
		return wrap;
	}

	async function saveRanges() {
		if ( ! state.modal ) return;
		readRangesFromDOM();
		const rows = ( state.modal.ranges || [] ).filter( ( r ) =>
			r.width_to !== '' && r.height_to !== '' && r.price !== ''
		);
		try {
			const result = await cbRequest(
				'/sections/' + encodeURIComponent( state.modal.sectionId ) + '/ranges',
				{ method: 'POST', body: { rows } }
			);
			showMessage( 'success', result.message || 'Ranges saved.' );
			state.modal.rangeDiagnostics = result.diagnostics || null;
			await loadRanges( state.modal.sectionId );
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	// =========================================================
	// Bulk paste — shared modal for section types that support it
	// =========================================================

	function openBulkPaste( sectionId ) {
		state.bulkPaste = { sectionId, text: '', errors: [] };
		render();
	}

	function closeBulkPaste() {
		state.bulkPaste = null;
		render();
	}

	function renderBulkPasteOverlay() {
		if ( ! state.bulkPaste ) return null;
		const section = findSection( state.bulkPaste.sectionId );
		if ( ! section ) return null;
		const type    = findType( section.type ) || {};
		const columns = ( type.bulk_paste_columns || [] ).join( ' \\t ' );

		const overlay = el( 'div', {
			class: 'configkit-cb__modal-overlay',
			onClick: ( ev ) => { if ( ev.target === overlay ) closeBulkPaste(); },
		} );
		const modal = el( 'div', { class: 'configkit-cb__modal configkit-cb__modal--bulk', role: 'dialog' } );
		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-header' },
			el( 'div', { class: 'configkit-cb__modal-title' },
				el( 'h3', null, 'Bulk paste rows' )
			),
			el( 'span', null, '' ),
			el( 'button', {
				type: 'button',
				class: 'configkit-cb__modal-close',
				'aria-label': 'Close',
				onClick: closeBulkPaste,
			}, '✕' )
		) );
		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-body' },
			el( 'p', { class: 'description' },
				'Paste tab-separated rows from Excel. Expected columns: ',
				el( 'code', null, columns )
			),
			el( 'textarea', {
				class: 'configkit-cb__bulk-textarea',
				rows: 10,
				placeholder: '1000\t2100\t1000\t2000\t10000\tI',
				value: state.bulkPaste.text,
				onInput: ( ev ) => { state.bulkPaste.text = ev.target.value; },
			} ),
			...( state.bulkPaste.errors || [] ).map( ( m ) => el( 'p', { class: 'configkit-cb__range-diag-warn' }, m ) )
		) );
		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-footer' },
			el( 'button', { type: 'button', class: 'button', onClick: closeBulkPaste }, 'Cancel' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				onClick: applyBulkPaste,
			}, 'Add rows' )
		) );
		overlay.appendChild( modal );
		return overlay;
	}

	function applyBulkPaste() {
		if ( ! state.bulkPaste || ! state.modal ) return;
		const section = findSection( state.bulkPaste.sectionId );
		if ( ! section ) return;
		const text = String( state.bulkPaste.text || '' ).trim();
		if ( text === '' ) {
			closeBulkPaste();
			return;
		}
		if ( section.type === 'size_pricing' ) {
			applyBulkPasteRanges( text );
			return;
		}
		applyBulkPasteOptions( section, text );
	}

	function applyBulkPasteRanges( text ) {
		const lines = text.split( /\r?\n/ ).map( ( l ) => l.trim() ).filter( Boolean );
		const errors = [];
		const parsed = [];
		lines.forEach( ( line, i ) => {
			const parts = line.split( /\t|\s{2,}|,/ ).map( ( p ) => p.trim() );
			if ( parts.length < 5 ) {
				errors.push( 'Row ' + ( i + 1 ) + ': expected at least 5 values (got ' + parts.length + ').' );
				return;
			}
			const wf = Number( parts[0] ), wt = Number( parts[1] );
			const hf = Number( parts[2] ), ht = Number( parts[3] );
			const price = Number( parts[4] );
			if ( ! Number.isFinite( wf ) || ! Number.isFinite( wt ) || ! Number.isFinite( hf ) || ! Number.isFinite( ht ) ) {
				errors.push( 'Row ' + ( i + 1 ) + ': width/height values must be numeric.' );
				return;
			}
			if ( ! Number.isFinite( price ) ) {
				errors.push( 'Row ' + ( i + 1 ) + ': price must be a number.' );
				return;
			}
			parsed.push( {
				width_from:      wf,
				width_to:        wt,
				height_from:     hf,
				height_to:       ht,
				price:           price,
				price_group_key: parts[5] || '',
			} );
		} );
		if ( errors.length > 0 ) {
			state.bulkPaste.errors = errors;
			render();
			return;
		}
		readRangesFromDOM();
		const existing = ( state.modal.ranges || [] ).filter( ( r ) => r.width_to !== '' );
		state.modal.ranges = existing.concat( parsed );
		closeBulkPaste();
	}

	function applyBulkPasteOptions( section, text ) {
		const type    = findType( section.type ) || {};
		const columns = Array.isArray( type.bulk_paste_columns ) ? type.bulk_paste_columns : [];
		const lines   = text.split( /\r?\n/ ).map( ( l ) => l.trim() ).filter( Boolean );
		const errors  = [];
		const parsed  = [];
		lines.forEach( ( line, i ) => {
			const parts = line.split( /\t|\s{2,}|,/ ).map( ( p ) => p.trim() );
			if ( parts.length < 2 ) {
				errors.push( 'Row ' + ( i + 1 ) + ': expected at least 2 values (got ' + parts.length + ').' );
				return;
			}
			const draft = blankOption();
			columns.forEach( ( col, ci ) => {
				if ( parts[ ci ] === undefined ) return;
				const value = parts[ ci ];
				switch ( col ) {
					case 'sku':            draft.sku = value; break;
					case 'label':          draft.label = value; break;
					case 'brand':          draft.brand = value; break;
					case 'collection':     draft.collection = value; break;
					case 'price_group':    draft.price_group = value; break;
					case 'color_family':   draft.color_family = value; break;
					case 'image_filename': draft.image_url = value; break;
					case 'price':          draft.price = value; break;
					case 'woo_product_sku':
						// Stored on draft for chunk 6 (motor) lookup;
						// option_group ignores it.
						draft.woo_product_sku = value;
						break;
					default:
						draft[ col ] = value;
				}
			} );
			if ( ! draft.label ) {
				// Common owner pattern: SKU only, no name. Fall back to
				// SKU as the name so the owner can rename inline.
				draft.label = draft.sku || ( '(option ' + ( i + 1 ) + ')' );
			}
			parsed.push( draft );
		} );
		if ( errors.length > 0 ) {
			state.bulkPaste.errors = errors;
			render();
			return;
		}
		readOptionDraftsFromDOM();
		const existing = ( state.modal.options || [] ).filter( ( o ) => ( o.label || '' ).trim() !== '' );
		state.modal.options = existing.concat( parsed );
		closeBulkPaste();
	}

	function renderEditableLabel( section ) {
		const input = el( 'input', {
			type: 'text',
			class: 'configkit-cb__modal-label-input',
			value: section.label || '',
			onChange: async ( ev ) => {
				const next = String( ev.target.value || '' ).trim();
				if ( next === ( section.label || '' ).trim() ) return;
				try {
					const result = await cbRequest( '/sections/' + encodeURIComponent( section.id ), {
						method: 'PUT',
						body: { label: next },
					} );
					state.sections = result.sections || state.sections;
					showMessage( 'success', 'Section renamed.' );
					render();
				} catch ( err ) {
					showMessage( 'error', explainError( err ) );
				}
			},
		} );
		return input;
	}

	function copyToClipboard( text ) {
		try {
			navigator.clipboard.writeText( text );
			showMessage( 'success', 'Copied: ' + text );
		} catch ( e ) { /* swallow */ }
	}

	// =========================================================
	// Render
	// =========================================================

	function render() {
		if ( ! state.ready ) return;
		root.replaceChildren();
		root.appendChild( renderHeader() );

		if ( state.showAdvanced ) {
			root.appendChild( el( 'p', { class: 'description configkit-cb__advanced-note' },
				'Advanced settings render below. Click "Back to configurator builder" in the header to return to the simple view.'
			) );
			return;
		}
		const banner = messageBanner();
		if ( banner ) root.appendChild( banner );
		root.appendChild( renderSectionList() );

		const modal = renderModal();
		if ( modal ) root.appendChild( modal );

		const bulk = renderBulkPasteOverlay();
		if ( bulk ) root.appendChild( bulk );
	}

	init();
} )();
