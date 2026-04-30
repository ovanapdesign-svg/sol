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

	// Phase 4.4b — both legacy mounts hide-and-show together so the
	// owner never sees the Yith builder + a legacy panel at the same
	// time. The binding app drives the existing Phase 4.3 advanced
	// mode; the product-builder app is the dalis-2 fallback.
	const advancedBindingRoot = document.getElementById( 'configkit-product-binding-app' );
	const advancedBuilderRoot = document.getElementById( 'configkit-product-builder-app' );

	function setAdvancedVisibility( visible ) {
		if ( advancedBindingRoot ) advancedBindingRoot.hidden = ! visible;
		if ( advancedBuilderRoot ) advancedBuilderRoot.hidden = ! visible;
	}

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
		modal:        null, // { sectionId, tab, ranges?, rangeDiagnostics?, options?, zipMatch?, visibility? }
		bulkPaste:    null, // { sectionId, text, errors }
		optionCounts: {},   // sectionId → number of saved options (for card meta)
		valueCache:   {},   // sectionId → list<{value, label}> for visibility dropdowns
		diagnostics:  null, // { summary, byId, sections } — populated lazily / after each save
		showDiagnostics: false,
		// Phase 4.3b half B — preset / setup-source state.
		setupSource:  { setup_source: 'start_blank', preset_id: 0, source_product_id: 0, preset: null },
		sourceModal:  null, // { kind: 'save'|'apply'|'copy'|'link'|'detach', payload }
		presets:      null, // [{id, name, preset_key, product_type}] — lazy
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
			// Phase 4.3b half B — pick up setup_source metadata the
			// resolver attaches so the source panel renders correctly
			// without a separate round-trip on every reload.
			state.setupSource = {
				setup_source:      ( data && data.setup_source )      || 'start_blank',
				preset_id:         ( data && data.preset_id )         || 0,
				source_product_id: ( data && data.source_product_id ) || 0,
				preset:            ( data && data.preset )            || null,
			};
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
		// Diagnostics fire after the initial render so the page paints
		// fast even on big section lists.
		loadDiagnostics();
	}

	async function loadDiagnostics() {
		try {
			const data = await window.ConfigKit.request(
				'/configurator/' + productId + '/diagnostics'
			);
			state.diagnostics = {
				summary:  ( data && data.summary ) || null,
				sections: ( data && data.sections ) || [],
				byId:     {},
			};
			( state.diagnostics.sections || [] ).forEach( ( s ) => {
				state.diagnostics.byId[ s.id ] = s;
			} );
			render();
		} catch ( e ) { /* swallow — diagnostics are advisory */ }
	}

	// =========================================================
	// Header + advanced toggle
	// =========================================================

	function deriveStatus() {
		if ( state.sections.length === 0 ) return { cls: 'is-disabled', text: 'Disabled' };
		const summary = state.diagnostics && state.diagnostics.summary;
		if ( ! summary ) return { cls: 'is-progress', text: 'Setup in progress' };
		if ( summary.overall === 'issues' )      return { cls: 'is-issues',  text: summary.issues + ' section' + ( summary.issues === 1 ? '' : 's' ) + ' need attention' };
		if ( summary.overall === 'ready' )       return { cls: 'is-ready',   text: 'Ready' };
		if ( summary.overall === 'in_progress' ) return { cls: 'is-progress', text: 'Setup in progress' };
		return { cls: 'is-disabled', text: 'Disabled' };
	}

	function renderHeader() {
		const wrap = el( 'header', { class: 'configkit-cb__header' } );
		const status = deriveStatus();
		wrap.appendChild( el( 'div', { class: 'configkit-cb__title-block' },
			el( 'h2', { class: 'configkit-cb__title' }, 'Setup configurator' ),
			el( 'span', { class: 'configkit-cb__status configkit-cb__status--' + status.cls }, status.text )
		) );
		const headerActions = el( 'div', { class: 'configkit-cb__header-actions' } );
		const summary = state.diagnostics && state.diagnostics.summary;
		if ( summary && summary.total > 0 ) {
			headerActions.appendChild( el( 'button', {
				type: 'button',
				class: 'button-link configkit-cb__diag-link',
				onClick: () => {
					state.showDiagnostics = true;
					loadDiagnostics();
					render();
				},
			}, summary.issues > 0
				? '⚠ View diagnostics (' + summary.issues + ')'
				: 'View diagnostics'
			) );
		}
		headerActions.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link configkit-cb__advanced-link',
			onClick: () => {
				state.showAdvanced = ! state.showAdvanced;
				setAdvancedVisibility( state.showAdvanced );
				render();
			},
		}, state.showAdvanced ? 'Back to product builder' : 'Show advanced settings' ) );
		wrap.appendChild( headerActions );
		return wrap;
	}

	function messageBanner() {
		if ( ! state.message ) return null;
		const cls = 'notice ' + ( state.message.kind === 'success' ? 'notice-success' : 'notice-error' ) + ' inline configkit-notice';
		return el( 'div', { class: cls }, el( 'p', null, state.message.text ) );
	}

	// =========================================================
	// Phase 4.3b half B — Configurator source panel.
	//
	// Sits above the section list, surfaces the current setup_source
	// mode + the action set the owner has available in that mode,
	// and triggers the action modals (saveAsPreset, applyPreset,
	// copyFromProduct, linkToSetup, detachFromPreset).
	// =========================================================

	function renderSourcePanel() {
		const ss   = state.setupSource || { setup_source: 'start_blank' };
		const mode = ss.setup_source || 'start_blank';
		const wrap = el( 'div', { class: 'configkit-cb__source-panel configkit-cb__source-panel--' + mode } );

		const title = el( 'div', { class: 'configkit-cb__source-title' } );
		switch ( mode ) {
			case 'use_preset':
				title.appendChild( el( 'span', { class: 'configkit-cb__source-icon' }, '🔗' ) );
				title.appendChild( el( 'strong', null, 'Using preset: ' + ( ss.preset && ss.preset.name ? ss.preset.name : '#' + ss.preset_id ) ) );
				break;
			case 'link_to_setup':
				title.appendChild( el( 'span', { class: 'configkit-cb__source-icon' }, '🔗' ) );
				title.appendChild( el( 'strong', null, 'Linked to product #' + ss.source_product_id ) );
				break;
			case 'start_blank':
			default:
				title.appendChild( el( 'span', { class: 'configkit-cb__source-icon' }, '📍' ) );
				title.appendChild( el( 'strong', null, 'This product has its own configurator (not shared)' ) );
		}
		wrap.appendChild( title );

		const sub = sourceSubText( mode );
		if ( sub ) wrap.appendChild( el( 'p', { class: 'description configkit-cb__source-sub' }, sub ) );

		wrap.appendChild( renderSourceActions( mode ) );
		return wrap;
	}

	function sourceSubText( mode ) {
		switch ( mode ) {
			case 'use_preset':
				return 'Updates to the preset will affect this product, except where you’ve added overrides.';
			case 'link_to_setup':
				return 'Both products share the same configuration. Changes here affect both.';
			case 'start_blank':
			default:
				return null;
		}
	}

	function renderSourceActions( mode ) {
		const actions = el( 'div', { class: 'configkit-cb__source-actions' } );
		const hasSections = state.sections && state.sections.length > 0;

		if ( mode === 'start_blank' ) {
			actions.appendChild( actionButton( '💾 Save as preset', 'save', {
				disabled: ! hasSections,
				title: hasSections ? '' : 'Finish at least one product setup before saving it as a preset.',
			} ) );
			actions.appendChild( actionButton( '📋 Copy from product', 'copy' ) );
			actions.appendChild( actionButton( '📐 Use preset', 'apply' ) );
		} else if ( mode === 'use_preset' ) {
			actions.appendChild( actionButton( '🔓 Detach from preset', 'detach' ) );
			actions.appendChild( actionButton( '📐 Switch preset', 'apply' ) );
			if ( state.setupSource && state.setupSource.preset_id ) {
				actions.appendChild( actionButton( '👁 Products using this preset', 'products-using', {
					meta: { preset_id: state.setupSource.preset_id },
				} ) );
			}
		} else if ( mode === 'link_to_setup' ) {
			actions.appendChild( actionButton( '🔓 Unlink (make local copy)', 'detach' ) );
		}
		return actions;
	}

	function actionButton( label, kind, opts ) {
		opts = opts || {};
		return el( 'button', {
			type: 'button',
			class: 'button configkit-cb__source-action' + ( kind === 'detach' ? ' configkit-cb__source-action--warn' : '' ),
			disabled: !! opts.disabled,
			title: opts.title || '',
			onClick: () => openSourceModal( kind, opts.meta || null ),
		}, label );
	}

	// Modal scaffolding lives here; per-action body / submit logic
	// lands in the next chunk (action modals + form fields).
	function openSourceModal( kind, meta ) {
		state.sourceModal = { kind, meta: meta || null, busy: false, message: null };
		render();
	}

	function closeSourceModal() {
		state.sourceModal = null;
		render();
	}

	function renderSourceModal() {
		if ( ! state.sourceModal ) return null;
		const overlay = el( 'div', {
			class: 'configkit-cb__modal-overlay',
			onClick: ( ev ) => { if ( ev.target === overlay ) closeSourceModal(); },
		} );
		const modal = el( 'div', { class: 'configkit-cb__modal configkit-cb__modal--source', role: 'dialog' } );

		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-header' },
			el( 'div', { class: 'configkit-cb__modal-title' },
				el( 'h3', null, sourceModalTitle( state.sourceModal.kind ) )
			),
			el( 'span', null, '' ),
			el( 'button', {
				type: 'button',
				class: 'configkit-cb__modal-close',
				'aria-label': 'Close',
				onClick: closeSourceModal,
			}, '✕' )
		) );

		const body   = el( 'div', { class: 'configkit-cb__modal-body' } );
		const footer = el( 'div', { class: 'configkit-cb__modal-footer' } );
		buildSourceModalBody( body, footer );
		modal.appendChild( body );
		modal.appendChild( footer );
		overlay.appendChild( modal );
		return overlay;
	}

	function buildSourceModalBody( body, footer ) {
		const m = state.sourceModal || {};
		switch ( m.kind ) {
			case 'save':           return buildSaveAsPresetBody( body, footer, m );
			case 'apply':          return buildApplyPresetBody( body, footer, m );
			case 'copy':           return buildCopyFromProductBody( body, footer, m );
			case 'detach':         return buildDetachBody( body, footer, m );
			case 'products-using': return buildProductsUsingBody( body, footer, m );
			default:
				body.appendChild( el( 'p', { class: 'description' }, 'Unknown action.' ) );
				footer.appendChild( el( 'button', { type: 'button', class: 'button', onClick: closeSourceModal }, 'Close' ) );
		}
	}

	function buildSaveAsPresetBody( body, footer, m ) {
		m.payload = m.payload || { name: '', description: '', product_type: '' };
		body.appendChild( el( 'p', { class: 'description' },
			'Save this product\'s current configurator as a reusable preset. Other products can then apply it without rebuilding from scratch — library items + lookup tables stay shared.'
		) );
		body.appendChild( labelledField( 'Name (required)', el( 'input', {
			type: 'text',
			class: 'regular-text',
			value: m.payload.name,
			onInput: ( ev ) => { m.payload.name = ev.target.value; },
		} ) ) );
		body.appendChild( labelledField( 'Description', el( 'textarea', {
			class: 'configkit-cb__source-textarea',
			rows: 2,
			onInput: ( ev ) => { m.payload.description = ev.target.value; },
			value: m.payload.description,
		} ) ) );
		body.appendChild( labelledField( 'Product type (optional)', el( 'input', {
			type: 'text',
			class: 'regular-text',
			placeholder: 'markise, screen, …',
			value: m.payload.product_type,
			onInput: ( ev ) => { m.payload.product_type = ev.target.value; },
		} ) ) );
		footer.appendChild( el( 'button', { type: 'button', class: 'button', onClick: closeSourceModal }, 'Cancel' ) );
		footer.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-primary',
			disabled: m.busy || ! ( m.payload.name && m.payload.name.trim() ),
			onClick: submitSaveAsPreset,
		}, m.busy ? 'Saving…' : 'Save preset' ) );
	}

	async function submitSaveAsPreset() {
		const m = state.sourceModal;
		if ( ! m || ! m.payload || ! m.payload.name.trim() ) return;
		m.busy = true; render();
		try {
			const result = await window.ConfigKit.request( '/product-builder/' + productId + '/save-as-preset', {
				method: 'POST',
				body: {
					name:         m.payload.name.trim(),
					description:  m.payload.description || '',
					product_type: m.payload.product_type || '',
				},
			} );
			closeSourceModal();
			showMessage( 'success', ( result && result.message ) || 'Preset saved.' );
		} catch ( err ) {
			m.busy = false;
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function buildApplyPresetBody( body, footer, m ) {
		m.payload = m.payload || { preset_id: 0 };
		body.appendChild( el( 'p', { class: 'description' },
			'Apply a preset. This product will inherit the preset\'s sections; library items + lookup tables are shared (no duplication).'
		) );
		if ( ! Array.isArray( state.presets ) ) {
			body.appendChild( el( 'p', { class: 'description' }, 'Loading presets…' ) );
			loadPresets().then( () => render() );
		} else if ( state.presets.length === 0 ) {
			body.appendChild( el( 'p', { class: 'configkit-cb__range-diag-warn' },
				'No presets saved yet. Build one product first, then click Save as preset.'
			) );
		} else {
			const select = el( 'select', { class: 'configkit-cb__source-select' } );
			select.appendChild( el( 'option', { value: '' }, 'Choose a preset…' ) );
			state.presets.forEach( ( p ) => {
				select.appendChild( el( 'option', { value: String( p.id ), selected: m.payload.preset_id === p.id }, p.name + ( p.product_type ? ' (' + p.product_type + ')' : '' ) ) );
			} );
			select.addEventListener( 'change', ( ev ) => { m.payload.preset_id = parseInt( ev.target.value || '0', 10 ); } );
			body.appendChild( labelledField( 'Preset', select ) );
		}
		footer.appendChild( el( 'button', { type: 'button', class: 'button', onClick: closeSourceModal }, 'Cancel' ) );
		footer.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-primary',
			disabled: m.busy || ! m.payload.preset_id,
			onClick: submitApplyPreset,
		}, m.busy ? 'Applying…' : 'Apply preset' ) );
	}

	async function loadPresets() {
		try {
			const data = await window.ConfigKit.request( '/presets' );
			state.presets = ( data && data.items ) || [];
		} catch ( e ) {
			state.presets = [];
		}
	}

	async function submitApplyPreset() {
		const m = state.sourceModal;
		if ( ! m || ! m.payload || ! m.payload.preset_id ) return;
		m.busy = true; render();
		try {
			const result = await window.ConfigKit.request( '/product-builder/' + productId + '/apply-preset', {
				method: 'POST',
				body: { preset_id: m.payload.preset_id },
			} );
			closeSourceModal();
			showMessage( 'success', ( result && result.message ) || 'Preset applied.' );
			await loadSections();
			loadDiagnostics();
			render();
		} catch ( err ) {
			m.busy = false;
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function buildCopyFromProductBody( body, footer, m ) {
		m.payload = m.payload || { source_product_id: 0, lookup_table_choice: 'inherit' };
		body.appendChild( el( 'p', { class: 'description' },
			'Copy another product\'s configurator into this one. The target becomes independent — future changes to the source do not propagate.'
		) );
		body.appendChild( labelledField( 'Source product ID', el( 'input', {
			type: 'number',
			class: 'regular-text',
			min: '1',
			value: m.payload.source_product_id || '',
			onInput: ( ev ) => { m.payload.source_product_id = parseInt( ev.target.value || '0', 10 ); },
		} ) ) );
		const lookupRow = el( 'div', { class: 'configkit-cb__source-lookup' } );
		[
			[ 'inherit', 'Inherit (share source\'s table)' ],
			[ 'reuse',   'Use existing table key' ],
			[ 'new',     'Create a new empty table' ],
		].forEach( ( pair ) => {
			lookupRow.appendChild( el( 'label', null,
				el( 'input', {
					type: 'radio',
					name: 'cb-lookup-choice',
					value: pair[0],
					checked: m.payload.lookup_table_choice === pair[0],
					onChange: () => { m.payload.lookup_table_choice = pair[0]; render(); },
				} ),
				document.createTextNode( ' ' + pair[1] )
			) );
		} );
		body.appendChild( labelledField( 'Lookup table', lookupRow ) );
		if ( m.payload.lookup_table_choice === 'reuse' ) {
			body.appendChild( labelledField( 'Reuse table key', el( 'input', {
				type: 'text',
				class: 'regular-text',
				placeholder: 'product_42_size_pricing_a8f2',
				onInput: ( ev ) => { m.payload.lookup_table_key = ev.target.value; },
				value: m.payload.lookup_table_key || '',
			} ) ) );
		}
		footer.appendChild( el( 'button', { type: 'button', class: 'button', onClick: closeSourceModal }, 'Cancel' ) );
		footer.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-primary',
			disabled: m.busy || ! m.payload.source_product_id,
			onClick: submitCopyFromProduct,
		}, m.busy ? 'Copying…' : 'Copy' ) );
	}

	async function submitCopyFromProduct() {
		const m = state.sourceModal;
		if ( ! m || ! m.payload || ! m.payload.source_product_id ) return;
		m.busy = true; render();
		try {
			const result = await window.ConfigKit.request( '/product-builder/' + productId + '/copy-from-product', {
				method: 'POST',
				body: {
					source_product_id:   m.payload.source_product_id,
					lookup_table_choice: m.payload.lookup_table_choice,
					lookup_table_key:    m.payload.lookup_table_key || '',
				},
			} );
			closeSourceModal();
			showMessage( 'success', ( result && result.message ) || 'Copy complete.' );
			await loadSections();
			loadDiagnostics();
			render();
		} catch ( err ) {
			m.busy = false;
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function buildDetachBody( body, footer, m ) {
		body.appendChild( el( 'p', { class: 'description' },
			'Detaching copies the current view into this product as local sections. After this, future changes to the preset / source product do NOT affect this product.'
		) );
		body.appendChild( el( 'p', { class: 'configkit-cb__range-diag-warn' },
			'Note: option-level overrides (price overrides, hidden options) are cleared on detach — option prices return to the library\'s stock value.'
		) );
		footer.appendChild( el( 'button', { type: 'button', class: 'button', onClick: closeSourceModal }, 'Cancel' ) );
		footer.appendChild( el( 'button', {
			type: 'button',
			class: 'button button-primary configkit-cb__source-action--warn',
			disabled: m.busy,
			onClick: submitDetach,
		}, m.busy ? 'Detaching…' : 'Detach' ) );
	}

	async function submitDetach() {
		const m = state.sourceModal;
		if ( ! m ) return;
		m.busy = true; render();
		try {
			const result = await window.ConfigKit.request( '/product-builder/' + productId + '/detach-from-preset', {
				method: 'POST',
				body: {},
			} );
			closeSourceModal();
			showMessage( 'success', ( result && result.message ) || 'Detached.' );
			await loadSections();
			loadDiagnostics();
			render();
		} catch ( err ) {
			m.busy = false;
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function buildProductsUsingBody( body, footer, m ) {
		const presetId = ( m.meta && m.meta.preset_id ) || 0;
		if ( ! Array.isArray( m.payload ) ) {
			body.appendChild( el( 'p', { class: 'description' }, 'Loading…' ) );
			window.ConfigKit.request( '/presets/' + presetId + '/products-using' ).then( ( data ) => {
				m.payload = ( data && data.products ) || [];
				render();
			} ).catch( ( err ) => {
				m.payload = [];
				showMessage( 'error', explainError( err ) );
				render();
			} );
		} else if ( m.payload.length === 0 ) {
			body.appendChild( el( 'p', { class: 'description' }, 'No other products use this preset yet.' ) );
		} else {
			const ul = el( 'ul', { class: 'configkit-cb__source-list' } );
			m.payload.forEach( ( p ) => {
				ul.appendChild( el( 'li', null,
					p.edit_url
						? el( 'a', { href: p.edit_url }, p.name || ( '#' + p.product_id ) )
						: el( 'span', null, ( p.name || ( '#' + p.product_id ) ) + ' (#' + p.product_id + ')' )
				) );
			} );
			body.appendChild( ul );
		}
		footer.appendChild( el( 'button', { type: 'button', class: 'button', onClick: closeSourceModal }, 'Close' ) );
	}

	function labelledField( label, child ) {
		return el( 'div', { class: 'configkit-cb__source-field' },
			el( 'label', { class: 'configkit-cb__source-field-label' }, label ),
			child
		);
	}

	// =========================================================
	// Phase 4.3b half B — inline override controls inside the
	// option editor.
	//
	// Shared sections render the price field with three companions:
	//   1. The numberInput itself (always present so save_options can
	//      still pick it up if the section is later detached).
	//   2. An "Override" inline button that opens a tiny prompt for a
	//      one-off price override → POST /write-override.
	//   3. When an override already exists, an "Overridden: 4500"
	//      indicator + ↺ Reset button → POST /reset-override.
	// On a Local section the field renders unchanged.
	// =========================================================

	function renderPriceFieldWithOverride( section, opt ) {
		const wrap  = el( 'div', { class: 'configkit-cb__price-field' } );
		const input = numberInput( opt.price, 'price' );
		wrap.appendChild( input );

		const isShared = section.source === 'shared' || section.source === 'overridden';
		if ( ! isShared ) return wrap;

		const overridden = opt.overridden_price !== null && opt.overridden_price !== undefined;
		if ( overridden ) {
			wrap.appendChild( el( 'div', { class: 'configkit-cb__override-indicator' },
				el( 'span', { class: 'configkit-cb__override-label' }, '✏️ Overridden: ' + opt.overridden_price ),
				el( 'button', {
					type: 'button',
					class: 'button-link configkit-cb__override-reset',
					title: 'Reset to preset value',
					onClick: () => confirmAndResetOverride( section, opt ),
				}, '↺ Reset' )
			) );
		} else {
			wrap.appendChild( el( 'button', {
				type: 'button',
				class: 'button-link configkit-cb__override-set',
				onClick: () => promptAndWriteOverride( section, opt ),
			}, '✏️ Override price' ) );
		}
		return wrap;
	}

	function priceOverridePath( section, opt ) {
		const pos = section.type_position !== undefined ? section.type_position : 0;
		return 'price_overrides.' + section.type + '.' + pos + '.' + ( opt.item_key || opt.sku || 'unknown' ) + '.price';
	}

	async function promptAndWriteOverride( section, opt ) {
		const presetPrice = opt.price !== '' && opt.price !== null && opt.price !== undefined ? String( opt.price ) : '0';
		const next = window.prompt(
			'Set an override price for "' + ( opt.label || opt.sku || 'option' ) + '". Preset value: ' + presetPrice + ' kr.',
			presetPrice
		);
		if ( next === null ) return;
		const num = Number( next );
		if ( ! Number.isFinite( num ) || num < 0 ) {
			showMessage( 'error', 'Override price must be a non-negative number.' );
			return;
		}
		const path = priceOverridePath( section, opt );
		try {
			await window.ConfigKit.request( '/product-builder/' + productId + '/write-override', {
				method: 'POST',
				body: { path, value: { price: num } },
			} );
			showMessage( 'success', 'Override saved.' );
			if ( state.modal ) await loadOptions( state.modal.sectionId );
			await loadSections();
			loadDiagnostics();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
		}
	}

	async function confirmAndResetOverride( section, opt ) {
		const presetPrice = opt.price !== '' && opt.price !== null && opt.price !== undefined ? String( opt.price ) : '(unset)';
		const current     = opt.overridden_price !== null && opt.overridden_price !== undefined ? String( opt.overridden_price ) : '(unset)';
		if ( ! window.confirm( 'Revert to preset value?\nCurrent: ' + current + '\nPreset: ' + presetPrice ) ) return;
		const path = priceOverridePath( section, opt );
		try {
			await window.ConfigKit.request( '/product-builder/' + productId + '/reset-override', {
				method: 'POST',
				body: { override_path: path },
			} );
			showMessage( 'success', 'Override reset.' );
			if ( state.modal ) await loadOptions( state.modal.sectionId );
			await loadSections();
			loadDiagnostics();
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
		}
	}

	function sourceModalTitle( kind ) {
		switch ( kind ) {
			case 'save':           return 'Save current setup as preset';
			case 'apply':          return 'Use a preset';
			case 'copy':           return 'Copy from another product';
			case 'link':           return 'Link to another product';
			case 'detach':         return 'Detach from source';
			case 'products-using': return 'Products using this preset';
			default:               return 'Configurator source';
		}
	}

	// =========================================================
	// Phase 4.3b half B — Section source badge.
	//
	// Each section card carries a small pill in its title row so the
	// owner sees at-a-glance which sections come from the preset
	// ("Shared"), which they've personalised ("Overridden"), and
	// which exist only in this product ("Local"). The labels are
	// computed from section.source which the resolver attaches.
	// =========================================================

	function renderSectionSourceBadge( section ) {
		const src = section.source || null;
		if ( ! src ) return null;
		let cls;
		let icon;
		let label;
		switch ( src ) {
			case 'shared':
				cls   = 'shared';
				icon  = '🔗';
				label = section.preset_name ? 'Shared from ' + section.preset_name : 'Shared';
				break;
			case 'overridden':
				cls   = 'overridden';
				icon  = '✏️';
				label = 'Overridden';
				break;
			case 'local':
			default:
				cls   = 'local';
				icon  = '📍';
				label = 'Local';
		}
		const overrideCount = ( section.overridden_paths || [] ).length;
		const title = src === 'overridden'
			? overrideCount + ' override' + ( overrideCount === 1 ? '' : 's' ) + ' active'
			: '';
		return el( 'span', {
			class: 'configkit-cb__source-pill configkit-cb__source-pill--' + cls,
			title: title,
		}, icon + ' ' + label );
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
				el( 'p', null, 'No sections yet. Add a section to start building this product\'s configurator.' ),
				el( 'p', { class: 'description' },
					'Add size pricing and at least one option section to test a price.'
				)
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
		const titleRow = el( 'div', { class: 'configkit-cb__section-title-row' },
			el( 'span', { class: 'configkit-cb__type-badge' }, type.label ),
			el( 'h3', { class: 'configkit-cb__section-title' }, section.label || type.label )
		);
		const sourceBadge = renderSectionSourceBadge( section );
		if ( sourceBadge ) titleRow.appendChild( sourceBadge );
		const pill = renderSectionStatusPill( section.id );
		if ( pill ) titleRow.appendChild( pill );
		main.appendChild( titleRow );
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
			loadDiagnostics();
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
			loadDiagnostics();
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
			el( 'div', {
				class: 'configkit-cb__modal-id',
				title: 'Internal stable ID used by rules and presets. You normally do not edit this.',
			},
				el( 'span', { class: 'configkit-cb__modal-id-label' }, 'Element ID' ),
				el( 'code', { class: 'configkit-cb__element-id' }, section.id ),
				el( 'button', {
					type: 'button',
					class: 'button-link configkit-cb__modal-id-copy',
					onClick: () => copyToClipboard( section.id ),
				}, 'Copy' )
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
			body.appendChild( renderVisibilityTab( section ) );
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
			item_key:       o.item_key || '',
			label:          o.label || '',
			sku:            o.sku || '',
			image_url:      o.image_url || '',
			brand:          attrs.brand || '',
			collection:     attrs.collection || '',
			color_family:   o.color_family || '',
			price_group:    o.price_group_key || '',
			price:          ( o.price === null || o.price === undefined ) ? '' : String( o.price ),
			active:         o.is_active !== false,
			// Phase 4.3b half B — override indicators surfaced by the
			// resolver / read_section_options.
			overridden_price:      ( o.overridden_price === undefined || o.overridden_price === null ) ? null : Number( o.overridden_price ),
			is_hidden_by_override: !! o.is_hidden_by_override,
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
		const nameError = ( state.modal && state.modal.optionErrors && state.modal.optionErrors[ String( index ) ] && state.modal.optionErrors[ String( index ) ].label ) || '';
		grid.appendChild( labelled( 'Name',         textInput( opt.label,        'label',        '', 'e.g. Dickson U171' ),
			{ required: true, helper: nameError ? null : undefined, error: nameError } ) );
		grid.appendChild( labelled( 'SKU',          textInput( opt.sku,          'sku',          '', 'e.g. DICK-U171' ) ) );
		grid.appendChild( labelled( 'Brand',        textInput( opt.brand,        'brand',        '', 'e.g. Dickson' ) ) );
		grid.appendChild( labelled( 'Collection',   textInput( opt.collection,   'collection',   '', 'e.g. Orchestra' ) ) );
		grid.appendChild( labelled( 'Color family', textInput( opt.color_family, 'color_family', '', 'e.g. Beige' ) ) );
		grid.appendChild( labelled( 'Price group',  textInput( opt.price_group,  'price_group',  '', 'e.g. I, II, III' ) ) );
		grid.appendChild( labelled( 'Price (kr)',   renderPriceFieldWithOverride( section, opt ),
			{ helper: '0 means no surcharge for this option.' } ) );
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

	// Phase 4.4b — text/number inputs accept an optional placeholder
	// (and number inputs an optional `step`) so per-section editors
	// can give owners a copy-pasteable example without re-implementing
	// the input markup. Backward-compatible: existing call sites keep
	// working unchanged.
	function textInput( value, field, cls, placeholder ) {
		return el( 'input', {
			type: 'text',
			class: 'configkit-cb__option-input' + ( cls ? ' ' + cls : '' ),
			'data-field': field,
			value: value !== null && value !== undefined ? String( value ) : '',
			placeholder: placeholder || '',
		} );
	}

	function numberInput( value, field, placeholder ) {
		return el( 'input', {
			type: 'number',
			class: 'configkit-cb__option-input',
			'data-field': field,
			value: value !== null && value !== undefined && value !== '' ? String( value ) : '',
			step: '0.01',
			placeholder: placeholder || '',
		} );
	}

	// Phase 4.4b — labelled() learns about required, helper, error.
	// `opts.required` → red asterisk on the label + .is-required class.
	// `opts.helper`   → small description below the field.
	// `opts.error`    → inline validation message + .is-invalid class.
	function labelled( label, child, opts ) {
		opts = opts || {};
		const labelNode = el( 'span', { class: 'configkit-cb__option-field-label' }, label );
		if ( opts.required ) {
			labelNode.appendChild( el( 'span', { class: 'configkit-cb__required-mark', 'aria-hidden': 'true' }, ' *' ) );
		}
		const cls = 'configkit-cb__option-field'
			+ ( opts.required ? ' is-required' : '' )
			+ ( opts.error    ? ' is-invalid'  : '' );
		const wrap = el( 'label', { class: cls }, labelNode, child );
		if ( opts.helper ) {
			wrap.appendChild( el( 'span', { class: 'configkit-cb__field-helper' }, opts.helper ) );
		}
		if ( opts.error ) {
			wrap.appendChild( el( 'span', { class: 'configkit-cb__field-error' }, opts.error ) );
		}
		return wrap;
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
		// Phase 4.4b — required-field check. An option that has any
		// non-name content (sku / price / image / etc.) but no name
		// is treated as a blocking error rather than silently
		// dropped.
		const errors = validateOptionRequiredFields( state.modal.options );
		state.modal.optionErrors = errors;
		if ( Object.keys( errors ).length > 0 ) {
			showMessage( 'error', 'Add a name to every option you want to save.' );
			render();
			return;
		}
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
			state.modal.optionErrors = {};
			await loadOptions( state.modal.sectionId );
			render();
			loadDiagnostics();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
	}

	function validateOptionRequiredFields( options ) {
		const errors = {};
		( options || [] ).forEach( ( o, i ) => {
			const hasContent = ( o.sku && o.sku.trim() ) ||
				( o.price !== '' && o.price !== null && o.price !== undefined ) ||
				( o.image_url && o.image_url.trim() ) ||
				( o.brand && o.brand.trim() ) ||
				( o.collection && o.collection.trim() );
			const blankLabel = ! o.label || ! o.label.trim();
			if ( blankLabel && hasContent ) {
				errors[ String( i ) ] = { label: 'Name is required.' };
			}
		} );
		return errors;
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
		const motorNameError = ( state.modal && state.modal.optionErrors && state.modal.optionErrors[ String( index ) ] && state.modal.optionErrors[ String( index ) ].label ) || '';
		const header = el( 'div', { class: 'configkit-cb__option-grid' } );
		header.appendChild( labelled( 'Name', textInput( opt.label, 'label', '', 'e.g. Somfy IO motor' ),
			{ required: true, error: motorNameError } ) );
		header.appendChild( labelled( 'SKU',  textInput( opt.sku,   'sku',   '', 'e.g. SOMFY-IO-MOTOR' ) ) );
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
		wrap.appendChild( labelled( 'Woo product SKU',
			textInput( opt.woo_product_sku, 'woo_product_sku', '', 'Search or enter linked Woo SKU' ),
			{ helper: 'Resolved to the matching WooCommerce product on save.' }
		) );
		wrap.appendChild( labelled( 'Custom price (kr)', numberInput( opt.price, 'price', 'e.g. 4500' ) ) );

		const sourceSelect = el( 'select', { 'data-field': 'price_source', class: 'configkit-cb__option-input' } );
		[ 'configkit', 'woo' ].forEach( ( v ) => {
			sourceSelect.appendChild( el( 'option', {
				value: v,
				selected: ( opt.price_source === v ),
			}, v === 'woo' ? 'From Woo product' : 'Custom (above)' ) );
		} );
		wrap.appendChild( labelled( 'Price source', sourceSelect, {
			helper: 'Custom uses the price above. Woo uses the linked Woo product price.',
		} ) );

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
		const errors = validateOptionRequiredFields( state.modal.options );
		state.modal.optionErrors = errors;
		if ( Object.keys( errors ).length > 0 ) {
			showMessage( 'error', 'Add a name to every motor row you want to save.' );
			render();
			return;
		}
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
			loadDiagnostics();
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
			el( 'span', null, 'Width from *' ),
			el( 'span', null, 'Width to *' ),
			el( 'span', null, 'Height from *' ),
			el( 'span', null, 'Height to *' ),
			el( 'span', null, 'Price (kr) *' ),
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
			rangeInput( row.width_from,  'width_from',  'number', null,    'e.g. 2100' ),
			rangeInput( row.width_to,    'width_to',    'number', null,    'e.g. 2400' ),
			rangeInput( row.height_from, 'height_from', 'number', null,    'e.g. 1500' ),
			rangeInput( row.height_to,   'height_to',   'number', null,    'e.g. 2000' ),
			rangeInput( row.price,       'price',       'number', '0.01',  'e.g. 12000' ),
			rangeInput( row.price_group_key, 'price_group_key', 'text', null, 'e.g. I' ),
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

	function rangeInput( value, field, type, step, placeholder ) {
		return el( 'input', {
			type: type || 'number',
			class: 'configkit-cb__range-input',
			'data-field': field,
			value: value !== null && value !== undefined && value !== '' ? String( value ) : '',
			step: step,
			placeholder: placeholder || '',
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
		// Phase 4.4b — surface "row N is incomplete" inline rather
		// than silently dropping rows that lack required values.
		const incomplete = [];
		( state.modal.ranges || [] ).forEach( ( r, i ) => {
			const hasAny = r.width_from !== '' || r.width_to !== '' || r.height_from !== '' || r.height_to !== '' || r.price !== '';
			if ( ! hasAny ) return;
			if ( r.width_to === '' || r.height_to === '' || r.price === '' ) {
				incomplete.push( i + 1 );
			}
		} );
		if ( incomplete.length > 0 ) {
			showMessage( 'error', 'Fill width_to, height_to and price on row(s) ' + incomplete.join( ', ' ) + ' before saving.' );
			render();
			return;
		}
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
			loadDiagnostics();
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

	// =========================================================
	// Diagnostics — per-card status pills + modal listing every
	// section's status with a quick "Open" link to its editor.
	// =========================================================

	function renderSectionStatusPill( sectionId ) {
		if ( ! state.diagnostics || ! state.diagnostics.byId ) return null;
		const entry = state.diagnostics.byId[ sectionId ];
		if ( ! entry ) return null;
		const meta = statusPillMeta( entry.status );
		const pill = el( 'span', {
			class: 'configkit-cb__pill configkit-cb__pill--' + meta.cls,
			title: ( entry.issues || [] ).join( '\n' ) || meta.title,
		}, meta.icon + ' ' + meta.label );
		return pill;
	}

	function statusPillMeta( status ) {
		switch ( status ) {
			case 'ready':        return { cls: 'ready',    icon: '✓', label: 'Ready',        title: 'Section is ready.' };
			case 'issues':       return { cls: 'issues',   icon: '⚠', label: 'Issues',       title: 'Section has detected issues.' };
			case 'setup_needed':
			default:             return { cls: 'progress', icon: '⏳', label: 'Needs setup', title: 'Section has no content yet.' };
		}
	}

	function renderDiagnosticsOverlay() {
		if ( ! state.showDiagnostics ) return null;
		const data = state.diagnostics;
		const overlay = el( 'div', {
			class: 'configkit-cb__modal-overlay',
			onClick: ( ev ) => { if ( ev.target === overlay ) closeDiagnostics(); },
		} );
		const modal = el( 'div', { class: 'configkit-cb__modal configkit-cb__modal--diag', role: 'dialog' } );

		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-header' },
			el( 'div', { class: 'configkit-cb__modal-title' },
				el( 'h3', null, 'Diagnostics' )
			),
			el( 'span', null, '' ),
			el( 'button', {
				type: 'button',
				class: 'configkit-cb__modal-close',
				'aria-label': 'Close',
				onClick: closeDiagnostics,
			}, '✕' )
		) );

		const body = el( 'div', { class: 'configkit-cb__modal-body' } );
		if ( ! data || ! data.sections ) {
			body.appendChild( el( 'p', { class: 'description' }, 'Loading diagnostics…' ) );
			modal.appendChild( body );
			overlay.appendChild( modal );
			return overlay;
		}
		body.appendChild( renderDiagnosticsSummary( data.summary ) );

		const list = el( 'div', { class: 'configkit-cb__diag-list' } );
		( data.sections || [] ).forEach( ( s ) => {
			list.appendChild( renderDiagnosticsRow( s ) );
		} );
		body.appendChild( list );
		modal.appendChild( body );

		modal.appendChild( el( 'div', { class: 'configkit-cb__modal-footer' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => loadDiagnostics(),
			}, '↻ Re-scan' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				onClick: closeDiagnostics,
			}, 'Close' )
		) );
		overlay.appendChild( modal );
		return overlay;
	}

	function closeDiagnostics() {
		state.showDiagnostics = false;
		render();
	}

	function renderDiagnosticsSummary( summary ) {
		if ( ! summary ) return el( 'p', { class: 'description' }, 'No sections yet.' );
		const text = summary.ready + ' ready · ' +
			summary.setup_needed + ' need setup · ' +
			summary.issues + ' with issues';
		return el( 'p', { class: 'configkit-cb__diag-summary' }, text );
	}

	function renderDiagnosticsRow( s ) {
		const meta = statusPillMeta( s.status );
		const row = el( 'div', { class: 'configkit-cb__diag-row configkit-cb__diag-row--' + meta.cls } );
		const head = el( 'div', { class: 'configkit-cb__diag-row-head' },
			el( 'span', { class: 'configkit-cb__pill configkit-cb__pill--' + meta.cls }, meta.icon + ' ' + meta.label ),
			el( 'span', { class: 'configkit-cb__diag-row-title' }, s.label || s.id ),
			el( 'span', { class: 'configkit-cb__diag-row-type' }, s.type ),
			el( 'button', {
				type: 'button',
				class: 'button-link configkit-cb__diag-row-open',
				onClick: () => {
					closeDiagnostics();
					openSectionModal( s.id );
				},
			}, 'Open →' )
		);
		row.appendChild( head );
		if ( ( s.issues || [] ).length > 0 ) {
			const ul = el( 'ul', { class: 'configkit-cb__diag-issues' } );
			s.issues.forEach( ( issue ) => ul.appendChild( el( 'li', null, issue ) ) );
			row.appendChild( ul );
		}
		return row;
	}

	// =========================================================
	// Visibility tab — visual rule builder per section.
	// Persists via PUT /sections/{id} with the existing visibility
	// patch shape sanitised by ConfiguratorBuilderService.
	// =========================================================

	function ensureVisibilityDraft( section ) {
		if ( ! state.modal ) return null;
		if ( state.modal.visibility ) return state.modal.visibility;
		const v = section.visibility || { mode: 'always', conditions: [], match: 'all' };
		state.modal.visibility = {
			mode:       v.mode === 'when' ? 'when' : 'always',
			match:      v.match === 'any' ? 'any' : 'all',
			conditions: ( v.conditions || [] ).map( ( c ) => ( {
				section_id: c.section_id || '',
				op:         c.op === 'not_equals' ? 'not_equals' : 'equals',
				value:      c.value === undefined || c.value === null ? '' : String( c.value ),
			} ) ),
		};
		return state.modal.visibility;
	}

	function renderVisibilityTab( section ) {
		const draft = ensureVisibilityDraft( section );
		const wrap  = el( 'div', { class: 'configkit-cb__visibility' } );

		const modeRow = el( 'div', { class: 'configkit-cb__visibility-mode' } );
		modeRow.appendChild( el( 'label', null,
			el( 'input', {
				type: 'radio',
				name: 'vis-mode-' + section.id,
				value: 'always',
				checked: draft.mode === 'always',
				onChange: () => {
					draft.mode = 'always';
					render();
				},
			} ),
			document.createTextNode( ' Always visible' )
		) );
		modeRow.appendChild( el( 'label', null,
			el( 'input', {
				type: 'radio',
				name: 'vis-mode-' + section.id,
				value: 'when',
				checked: draft.mode === 'when',
				onChange: () => {
					draft.mode = 'when';
					if ( draft.conditions.length === 0 ) {
						draft.conditions.push( { section_id: '', op: 'equals', value: '' } );
					}
					render();
				},
			} ),
			document.createTextNode( ' Show only when…' )
		) );
		wrap.appendChild( modeRow );

		if ( draft.mode === 'when' ) {
			wrap.appendChild( renderVisibilityWhen( section, draft ) );
		} else {
			wrap.appendChild( el( 'p', { class: 'description' },
				'This section is shown to every customer. Switch to "Show only when" to add a rule.'
			) );
		}

		wrap.appendChild( el( 'div', { class: 'configkit-cb__visibility-actions' },
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveVisibility,
			}, state.busy ? 'Saving…' : 'Save visibility' )
		) );
		return wrap;
	}

	function renderVisibilityWhen( section, draft ) {
		const wrap = el( 'div', { class: 'configkit-cb__visibility-when' } );

		const matchRow = el( 'div', { class: 'configkit-cb__visibility-match' } );
		matchRow.appendChild( el( 'span', null, 'Match' ) );
		const matchSel = el( 'select', null,
			el( 'option', { value: 'all', selected: draft.match === 'all' }, 'all conditions' ),
			el( 'option', { value: 'any', selected: draft.match === 'any' }, 'any condition' )
		);
		matchSel.addEventListener( 'change', ( ev ) => {
			draft.match = ev.target.value === 'any' ? 'any' : 'all';
		} );
		matchRow.appendChild( matchSel );
		wrap.appendChild( matchRow );

		const list = el( 'div', { class: 'configkit-cb__condition-list' } );
		draft.conditions.forEach( ( cond, i ) => {
			list.appendChild( renderConditionRow( section, draft, cond, i ) );
		} );
		wrap.appendChild( list );

		wrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button-link',
			onClick: () => {
				draft.conditions.push( { section_id: '', op: 'equals', value: '' } );
				render();
			},
		}, '+ Add condition' ) );
		return wrap;
	}

	function renderConditionRow( section, draft, cond, index ) {
		const row = el( 'div', { class: 'configkit-cb__condition' } );

		// Section dropdown — exclude self.
		const secSel = el( 'select', { class: 'configkit-cb__condition-input' } );
		secSel.appendChild( el( 'option', { value: '' }, 'When section…' ) );
		state.sections.forEach( ( s ) => {
			if ( s.id === section.id ) return;
			secSel.appendChild( el( 'option', {
				value: s.id,
				selected: cond.section_id === s.id,
			}, s.label || s.id ) );
		} );
		secSel.addEventListener( 'change', ( ev ) => {
			cond.section_id = ev.target.value;
			cond.value = '';
			ensureValueCache( cond.section_id ).then( () => render() );
			render();
		} );
		row.appendChild( secSel );

		// Operator dropdown.
		const opSel = el( 'select', { class: 'configkit-cb__condition-input' } );
		opSel.appendChild( el( 'option', { value: 'equals',     selected: cond.op === 'equals' }, 'is' ) );
		opSel.appendChild( el( 'option', { value: 'not_equals', selected: cond.op === 'not_equals' }, 'is not' ) );
		opSel.addEventListener( 'change', ( ev ) => {
			cond.op = ev.target.value === 'not_equals' ? 'not_equals' : 'equals';
		} );
		row.appendChild( opSel );

		// Value picker — dropdown when we have cached values, otherwise free text.
		row.appendChild( renderConditionValueInput( cond ) );

		row.appendChild( el( 'button', {
			type: 'button',
			class: 'configkit-cb__row-remove',
			'aria-label': 'Remove condition',
			onClick: () => {
				draft.conditions.splice( index, 1 );
				render();
			},
		}, '✕' ) );
		return row;
	}

	function renderConditionValueInput( cond ) {
		const cached = cond.section_id ? state.valueCache[ cond.section_id ] : null;
		if ( ! cond.section_id ) {
			return el( 'input', {
				type: 'text',
				class: 'configkit-cb__condition-input',
				placeholder: 'Pick a section first',
				disabled: true,
				value: cond.value || '',
			} );
		}
		if ( ! Array.isArray( cached ) ) {
			// Kick off the fetch the first time we render this row.
			ensureValueCache( cond.section_id ).then( () => render() );
			return el( 'input', {
				type: 'text',
				class: 'configkit-cb__condition-input',
				placeholder: 'Loading values…',
				disabled: true,
				value: cond.value || '',
			} );
		}
		if ( cached.length === 0 ) {
			// Section has no enumerable values — fall back to free text
			// so size_pricing-style sections still work as triggers.
			const inp = el( 'input', {
				type: 'text',
				class: 'configkit-cb__condition-input',
				placeholder: 'Enter value (e.g. SKU)',
				value: cond.value || '',
			} );
			inp.addEventListener( 'change', ( ev ) => { cond.value = ev.target.value; } );
			return inp;
		}
		const sel = el( 'select', { class: 'configkit-cb__condition-input' } );
		sel.appendChild( el( 'option', { value: '', selected: ! cond.value }, 'Pick a value…' ) );
		cached.forEach( ( v ) => {
			sel.appendChild( el( 'option', {
				value: v.value,
				selected: cond.value === v.value,
			}, v.label ) );
		} );
		sel.addEventListener( 'change', ( ev ) => { cond.value = ev.target.value; } );
		return sel;
	}

	async function ensureValueCache( sectionId ) {
		if ( ! sectionId ) return;
		if ( state.valueCache[ sectionId ] !== undefined ) return;
		const section = findSection( sectionId );
		if ( ! section ) {
			state.valueCache[ sectionId ] = [];
			return;
		}
		// size_pricing has no enumerable values — leave empty so the
		// editor falls back to free text.
		if ( section.type === 'size_pricing' ) {
			state.valueCache[ sectionId ] = [];
			return;
		}
		try {
			const data = await window.ConfigKit.request(
				'/configurator/' + productId + '/sections/' + encodeURIComponent( sectionId ) + '/options'
			);
			const opts = ( data && data.options ) || [];
			state.valueCache[ sectionId ] = opts
				.filter( ( o ) => ( o.sku || o.item_key ) && o.is_active !== false )
				.map( ( o ) => ( {
					value: o.sku || o.item_key,
					label: ( o.label || o.sku || o.item_key ) + ( o.sku ? ' (' + o.sku + ')' : '' ),
				} ) );
		} catch ( e ) {
			state.valueCache[ sectionId ] = [];
		}
	}

	async function saveVisibility() {
		if ( ! state.modal || ! state.modal.visibility ) return;
		const draft = state.modal.visibility;
		const payload = {
			mode:       draft.mode,
			match:      draft.match,
			conditions: draft.mode === 'when' ? draft.conditions.filter( ( c ) => c.section_id ) : [],
		};
		try {
			const result = await cbRequest(
				'/sections/' + encodeURIComponent( state.modal.sectionId ),
				{ method: 'PUT', body: { visibility: payload } }
			);
			state.sections = result.sections || state.sections;
			showMessage( 'success', 'Visibility saved.' );
			loadDiagnostics();
			// Refresh the working copy from the server-sanitised version.
			const fresh = findSection( state.modal.sectionId );
			if ( fresh ) {
				state.modal.visibility = null;
				ensureVisibilityDraft( fresh );
			}
			render();
		} catch ( err ) {
			showMessage( 'error', explainError( err ) );
			render();
		}
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
			const note = el( 'div', { class: 'notice notice-warning inline configkit-cb__advanced-banner' },
				el( 'p', null,
					el( 'strong', null, 'Advanced settings are for troubleshooting. ' ),
					document.createTextNode( 'Most product setup should happen in the builder above.' )
				),
				el( 'button', {
					type: 'button',
					class: 'button',
					onClick: () => {
						state.showAdvanced = false;
						setAdvancedVisibility( false );
						render();
					},
				}, '← Back to product builder' )
			);
			root.appendChild( note );
			return;
		}
		const banner = messageBanner();
		if ( banner ) root.appendChild( banner );
		root.appendChild( renderSourcePanel() );
		root.appendChild( renderSectionList() );

		const modal = renderModal();
		if ( modal ) root.appendChild( modal );

		const bulk = renderBulkPasteOverlay();
		if ( bulk ) root.appendChild( bulk );

		const diag = renderDiagnosticsOverlay();
		if ( diag ) root.appendChild( diag );

		const source = renderSourceModal();
		if ( source ) root.appendChild( source );
	}

	init();
} )();
