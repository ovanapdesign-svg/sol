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
		modal:        null, // { sectionId, tab, ranges?, rangeDiagnostics? }
		bulkPaste:    null, // { sectionId, text, errors }
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
		// Per-type meta lands in chunks 4-6 once each editor reports
		// counts. For now we mark the underlying entity.
		if ( section.type === 'size_pricing' ) return 'Range pricing';
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
		if ( section && section.type === 'size_pricing' ) {
			await loadRanges( sectionId );
			render();
		}
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
			case 'size_pricing': return 'Pricing rows';
			default:             return 'Choices';
		}
	}

	function renderChoicesTab( section ) {
		if ( section.type === 'size_pricing' ) return renderRangeEditor( section );
		return el( 'p', { class: 'description' },
			'Editor for ' + section.type + ' lands in chunks 5-6.'
		);
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
		if ( section.type !== 'size_pricing' ) {
			showMessage( 'error', 'Bulk paste for this section type lands in a later chunk.' );
			closeBulkPaste();
			return;
		}
		const text = String( state.bulkPaste.text || '' ).trim();
		if ( text === '' ) {
			closeBulkPaste();
			return;
		}
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
		// Drop the trailing blank row that renderRangeEditor seeds.
		const existing = ( state.modal.ranges || [] ).filter( ( r ) => r.width_to !== '' );
		state.modal.ranges = existing.concat( parsed );
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
