/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-product-binding-app' );
	if ( ! root ) return;

	const productId = parseInt( root.getAttribute( 'data-product-id' ) || '0', 10 );
	if ( ! productId ) {
		root.replaceChildren();
		root.appendChild( textNode( 'Save the product as draft first to enable ConfigKit binding.' ) );
		return;
	}

	const FRONTEND_MODES = [ 'stepper', 'accordion', 'single-page' ];
	const SALE_MODES     = [ 'off', 'force_regular', 'discount_percent' ];
	const VAT_DISPLAYS   = [ 'use_global', 'incl_vat', 'excl_vat', 'off' ];

	const state = {
		view: 'loading', // 'loading' | 'ready' | 'error'
		binding: null,
		fields: [],
		templates: [],
		lookupTables: [],
		families: [],
		libraries: [],
		dirty: false,
		busy: false,
		message: null,
		fieldErrors: {},
		diagnostics: null,
		diagnosticsBusy: false,
	};

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

	function textNode( s ) {
		return document.createTextNode( s );
	}

	function showError( err ) {
		let desc;
		try {
			desc = window.ConfigKit && window.ConfigKit.describeError
				? window.ConfigKit.describeError( err )
				: null;
		} catch ( e ) {
			desc = null;
		}
		if ( ! desc ) {
			desc = {
				kind: 'error',
				friendly: ( err && err.message ) || 'Something went wrong.',
				technical: ( err && err.message ) || '',
				showFieldErrors: true,
			};
		}
		state.fieldErrors = {};
		if ( desc.showFieldErrors ) {
			const errors = ( err && err.data && err.data.errors ) || [];
			errors.forEach( ( e ) => {
				const key = e.field || '_global';
				state.fieldErrors[ key ] = state.fieldErrors[ key ] || [];
				state.fieldErrors[ key ].push( e.message );
			} );
		}
		state.message = { kind: desc.kind, text: desc.friendly, technical: desc.technical };
	}

	async function loadAll() {
		state.view = 'loading';
		render();
		try {
			const [ binding, fields, templates, lookupTables, families, libraries ] = await Promise.all( [
				ConfigKit.request( '/products/' + productId + '/binding' ),
				ConfigKit.request( '/products/' + productId + '/template-fields' ),
				ConfigKit.request( '/templates?per_page=500' ),
				ConfigKit.request( '/lookup-tables?per_page=500' ),
				ConfigKit.request( '/families?per_page=500' ),
				ConfigKit.request( '/libraries?per_page=500' ),
			] );
			state.binding = normalizeBinding( binding.record );
			state.fields = ( fields && fields.items ) || [];
			state.templates = ( templates && templates.items ) || [];
			state.lookupTables = ( lookupTables && lookupTables.items ) || [];
			state.families = ( families && families.items ) || [];
			state.libraries = ( libraries && libraries.items ) || [];
			state.view = 'ready';
			state.message = null;
			state.fieldErrors = {};
			render();
			refreshDiagnostics();
		} catch ( err ) {
			showError( err );
			state.view = 'error';
			render();
		}
	}

	function normalizeBinding( r ) {
		r = r || {};
		return {
			product_id: r.product_id || productId,
			enabled: !! r.enabled,
			template_key: r.template_key || '',
			template_version_id: r.template_version_id != null ? parseInt( r.template_version_id, 10 ) : 0,
			lookup_table_key: r.lookup_table_key || '',
			family_key: r.family_key || '',
			frontend_mode: r.frontend_mode || 'stepper',
			defaults: r.defaults && typeof r.defaults === 'object' ? r.defaults : {},
			allowed_sources: r.allowed_sources && typeof r.allowed_sources === 'object' ? r.allowed_sources : {},
			pricing_overrides: r.pricing_overrides && typeof r.pricing_overrides === 'object' ? r.pricing_overrides : {},
			field_overrides: r.field_overrides && typeof r.field_overrides === 'object' ? r.field_overrides : {},
			version_hash: r.version_hash || '',
			updated_at: r.updated_at || null,
		};
	}

	async function refreshDiagnostics() {
		if ( ! state.binding ) return;
		state.diagnosticsBusy = true;
		render();
		try {
			const res = await ConfigKit.request(
				'/products/' + productId + '/diagnostics',
				{ method: 'POST', body: {} }
			);
			state.diagnostics = res;
		} catch ( err ) {
			state.diagnostics = null;
		} finally {
			state.diagnosticsBusy = false;
			render();
		}
	}

	async function save() {
		if ( state.busy || ! state.binding ) return;
		state.busy = true;
		state.message = null;
		state.fieldErrors = {};
		render();

		const b = state.binding;
		const payload = {
			enabled: !! b.enabled,
			template_key: b.template_key || null,
			template_version_id: b.template_version_id || 0,
			lookup_table_key: b.lookup_table_key || null,
			family_key: b.family_key || null,
			frontend_mode: b.frontend_mode || 'stepper',
			defaults: b.defaults,
			allowed_sources: b.allowed_sources,
			pricing_overrides: b.pricing_overrides,
			field_overrides: b.field_overrides,
			version_hash: b.version_hash,
		};

		let success = false;
		try {
			const res = await ConfigKit.request( '/products/' + productId + '/binding', {
				method: 'PUT',
				body: payload,
			} );
			state.binding = normalizeBinding( res.record );
			state.dirty = false;
			state.message = { kind: 'success', text: 'Binding saved.' };
			success = true;
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
		if ( success ) {
			// Re-load template fields if template changed.
			try {
				const fields = await ConfigKit.request( '/products/' + productId + '/template-fields' );
				state.fields = ( fields && fields.items ) || [];
			} catch ( e ) {
				// Swallow — diagnostics will surface mismatches.
			}
			refreshDiagnostics();
			render();
		}
	}

	function setBinding( patch ) {
		Object.assign( state.binding, patch );
		state.dirty = true;
		render();
	}

	function setDefault( fieldKey, value ) {
		const next = Object.assign( {}, state.binding.defaults );
		if ( value === '' || value === null || value === undefined ) {
			delete next[ fieldKey ];
		} else {
			next[ fieldKey ] = value;
		}
		setBinding( { defaults: next } );
	}

	function setAllowedSources( fieldKey, listKey, items ) {
		const next = Object.assign( {}, state.binding.allowed_sources );
		const cur = Object.assign( {}, next[ fieldKey ] || {} );
		cur[ listKey ] = items;
		// Strip empty lists to keep the JSON tidy.
		Object.keys( cur ).forEach( ( k ) => {
			if ( Array.isArray( cur[ k ] ) && cur[ k ].length === 0 ) delete cur[ k ];
		} );
		if ( Object.keys( cur ).length === 0 ) {
			delete next[ fieldKey ];
		} else {
			next[ fieldKey ] = cur;
		}
		setBinding( { allowed_sources: next } );
	}

	function setPricing( key, value ) {
		const next = Object.assign( {}, state.binding.pricing_overrides );
		if ( value === '' || value === null || value === undefined ) {
			delete next[ key ];
		} else {
			next[ key ] = value;
		}
		setBinding( { pricing_overrides: next } );
	}

	function setFieldOverride( fieldKey, key, value ) {
		const next = Object.assign( {}, state.binding.field_overrides );
		const cur = Object.assign( {}, next[ fieldKey ] || {} );
		if ( value === false || value === '' || value === null || value === undefined ) {
			delete cur[ key ];
		} else {
			cur[ key ] = value;
		}
		if ( Object.keys( cur ).length === 0 ) {
			delete next[ fieldKey ];
		} else {
			next[ fieldKey ] = cur;
		}
		setBinding( { field_overrides: next } );
	}

	// ---- Rendering ----

	function render() {
		root.dataset.loading = state.view === 'loading' ? 'true' : 'false';
		root.replaceChildren();
		if ( state.view === 'loading' ) {
			root.appendChild( el( 'p', { class: 'configkit-app__loading' }, 'Loading binding…' ) );
			return;
		}
		if ( state.view === 'error' ) {
			if ( state.message ) root.appendChild( messageBanner( state.message ) );
			root.appendChild( el(
				'p',
				null,
				el( 'button', { type: 'button', class: 'button', onClick: loadAll }, 'Retry' )
			) );
			return;
		}

		const wrap = el( 'div', { class: 'configkit-binding' } );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		wrap.appendChild( renderProgressChecklist() );

		wrap.appendChild( renderEnableSection() );
		wrap.appendChild( renderBaseSetupSection() );
		wrap.appendChild( renderDefaultsSection() );
		wrap.appendChild( renderAllowedSourcesSection() );
		wrap.appendChild( renderPricingSection() );
		wrap.appendChild( renderVisibilitySection() );
		wrap.appendChild( renderDiagnosticsSection() );
		wrap.appendChild( renderPreviewSection() );
		wrap.appendChild( renderSaveBar() );

		root.appendChild( wrap );
	}

	function progressSteps() {
		const b = state.binding || {};
		const status = state.diagnostics && state.diagnostics.status ? state.diagnostics.status : null;
		const ready = status === 'ready';
		return [
			{ id: 'enable',       label: 'Enable ConfigKit',     done: !! b.enabled,                 anchor: 'section-enable' },
			{ id: 'template',     label: 'Select template',      done: !! b.template_key,            anchor: 'section-base-setup' },
			{ id: 'lookup',       label: 'Select lookup table',  done: !! b.lookup_table_key,        anchor: 'section-base-setup' },
			{ id: 'diagnostics',  label: 'Run diagnostics',      done: !! state.diagnostics,         anchor: 'section-diagnostics' },
			{ id: 'save',         label: 'Save binding',         done: !! b.updated_at && ready,     anchor: 'section-pricing' },
		];
	}

	function statusLabel() {
		if ( ! state.diagnostics || ! state.diagnostics.status ) return 'Loading status…';
		const labels = {
			ready: 'Ready',
			disabled: 'Disabled',
			missing_template: 'Missing template',
			missing_lookup_table: 'Missing lookup table',
			invalid_defaults: 'Invalid defaults',
			pricing_unresolved: 'Pricing unresolved',
		};
		return labels[ state.diagnostics.status ] || state.diagnostics.status;
	}

	function renderProgressChecklist() {
		const steps = progressSteps();
		const wrap = el( 'section', { class: 'configkit-progress', id: 'section-progress' } );
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-progress__header' },
			el( 'h3', { class: 'configkit-progress__heading' }, 'Setup progress' ),
			el(
				'p',
				{ class: 'configkit-progress__status' },
				'Status: ',
				statusBadge(),
				' ',
				el( 'span', { class: 'configkit-progress__status-label' }, statusLabel() )
			)
		) );

		// Identify the current step: first not-done step is "current",
		// rest are pending; everything before is done.
		let currentIndex = steps.findIndex( ( s ) => ! s.done );
		if ( currentIndex === -1 ) currentIndex = steps.length;

		const list = el( 'ol', { class: 'configkit-progress__list' } );
		steps.forEach( ( step, i ) => {
			const stateClass = step.done
				? 'configkit-progress__step--done'
				: ( i === currentIndex ? 'configkit-progress__step--current' : 'configkit-progress__step--pending' );
			const icon = step.done ? '✓' : ( i === currentIndex ? '⏳' : '○' );
			list.appendChild( el(
				'li',
				{ class: 'configkit-progress__step ' + stateClass },
				el( 'a', {
					href: '#' + step.anchor,
					class: 'configkit-progress__step-link',
					onClick: ( ev ) => { ev.preventDefault(); scrollToSection( step.anchor ); },
				},
					el( 'span', { class: 'configkit-progress__icon', 'aria-hidden': 'true' }, icon ),
					el( 'span', { class: 'configkit-progress__num' }, ( i + 1 ) + '.' ),
					el( 'span', { class: 'configkit-progress__label' }, step.label )
				)
			) );
		} );
		wrap.appendChild( list );
		return wrap;
	}

	function messageBanner( m ) {
		const cls = 'notice ' + (
			m.kind === 'success' ? 'notice-success'
				: m.kind === 'conflict' ? 'notice-warning'
				: 'notice-error'
		) + ' inline configkit-notice';
		const wrap = el( 'div', { class: cls } );
		wrap.appendChild( el( 'p', null, m.text ) );
		if ( m.technical ) {
			const details = el( 'details', { class: 'configkit-error-details' } );
			details.appendChild( el( 'summary', null, 'Show technical details' ) );
			details.appendChild( el( 'pre', null, m.technical ) );
			wrap.appendChild( details );
		}
		return wrap;
	}

	function statusBadge() {
		if ( ! state.diagnostics || ! state.diagnostics.status ) {
			return el( 'span', { class: 'configkit-badge configkit-badge--inactive' }, '…' );
		}
		const status = state.diagnostics.status;
		const labels = {
			ready: 'Ready',
			disabled: 'Disabled',
			missing_template: 'Missing template',
			missing_lookup_table: 'Missing lookup table',
			invalid_defaults: 'Invalid defaults',
			pricing_unresolved: 'Pricing unresolved',
		};
		const cls = status === 'ready'
			? 'configkit-badge--active'
			: ( status === 'disabled' ? 'configkit-badge--inactive' : 'configkit-badge--warning' );
		return el( 'span', { class: 'configkit-badge ' + cls }, labels[ status ] || status );
	}

	function section( id, title, children, extra, opts ) {
		opts = opts || {};
		const cls = 'configkit-binding__section'
			+ ( opts.locked ? ' configkit-binding__section--locked' : '' );
		const wrap = el( 'section', { class: cls, id: id } );
		const head = el( 'h3', { class: 'configkit-binding__section-title' }, title );
		if ( opts.locked ) {
			head.appendChild( el( 'span', { class: 'configkit-binding__lock', 'aria-hidden': 'true' }, ' 🔒' ) );
		}
		if ( extra ) head.appendChild( extra );
		wrap.appendChild( head );
		children.forEach( ( c ) => c && wrap.appendChild( c ) );
		return wrap;
	}

	function renderEnableSection() {
		const b = state.binding;
		return section(
			'section-enable',
			'1. Enable',
			[
				el(
					'label',
					{ class: 'configkit-checkbox' },
					el( 'input', {
						type: 'checkbox',
						checked: !! b.enabled,
						onChange: ( ev ) => setBinding( { enabled: ev.target.checked } ),
					} ),
					' ',
					'Enable ConfigKit on this product'
				),
				el(
					'p',
					{ class: 'description configkit-binding__status-row' },
					'Status: ',
					statusBadge(),
					state.diagnosticsBusy ? el( 'span', { class: 'configkit-binding__status-busy' }, ' (refreshing…)' ) : null
				),
			]
		);
	}

	function renderBaseSetupSection() {
		const b = state.binding;
		const tmpl  = b.template_key      ? state.templates.find( ( t ) => t.template_key === b.template_key ) : null;
		const lt    = b.lookup_table_key  ? state.lookupTables.find( ( t ) => t.lookup_table_key === b.lookup_table_key ) : null;
		const fam   = b.family_key        ? state.families.find( ( f ) => f.family_key === b.family_key ) : null;
		return section(
			'section-base-setup',
			'2. Base setup',
			[
				selectField(
					'Template',
					'template_key',
					b.template_key,
					[ { value: '', label: '— Select a template —' } ].concat(
						state.templates.map( ( t ) => ( { value: t.template_key, label: t.name + ' (' + t.template_key + ')' } ) )
					),
					( v ) => setBinding( { template_key: v, template_version_id: 0 } ),
					tmpl ? adminUrlForEntity( 'configkit-templates', tmpl.id ) : null
				),
				numberField(
					'Template version',
					'template_version_id',
					b.template_version_id,
					( v ) => setBinding( { template_version_id: v } ),
					{ min: 0, help: '0 = always use the latest published version. Set a specific id to pin.' }
				),
				selectField(
					'Lookup table',
					'lookup_table_key',
					b.lookup_table_key,
					[ { value: '', label: '— Select a lookup table —' } ].concat(
						state.lookupTables.map( ( t ) => ( { value: t.lookup_table_key, label: t.name + ' (' + t.lookup_table_key + ')' } ) )
					),
					( v ) => setBinding( { lookup_table_key: v } ),
					lt ? adminUrlForEntity( 'configkit-lookup-tables', lt.id ) : null
				),
				selectField(
					'Family',
					'family_key',
					b.family_key,
					[ { value: '', label: '— None —' } ].concat(
						state.families.map( ( f ) => ( { value: f.family_key, label: f.name + ' (' + f.family_key + ')' } ) )
					),
					( v ) => setBinding( { family_key: v } ),
					fam ? adminUrlForEntity( 'configkit-families', fam.id ) : null
				),
				selectField(
					'Frontend mode',
					'frontend_mode',
					b.frontend_mode,
					FRONTEND_MODES.map( ( m ) => ( { value: m, label: m } ) ),
					( v ) => setBinding( { frontend_mode: v } )
				),
			]
		);
	}

	function renderDefaultsSection() {
		const b = state.binding;
		const fields = state.fields;
		const children = [];
		if ( ! b.template_key ) {
			children.push( emptyStateCta( {
				icon: '↑',
				title: 'No template selected',
				message: 'Pick a template in section 2 — defaults are per-field, so we need to know which fields exist.',
				primary: { label: 'Go to template picker ↑', onClick: () => scrollToSection( 'section-base-setup' ) },
			} ) );
		} else if ( fields.length === 0 ) {
			children.push( emptyStateCta( {
				icon: '🧱',
				title: 'This template has no fields yet',
				message: 'Open the template builder and add fields, then come back to set defaults.',
			} ) );
		} else {
			children.push( el( 'p', { class: 'description' }, 'Set the initial value for each field. Leave blank to use the field\'s own default.' ) );
			fields.forEach( ( f ) => {
				children.push( renderFieldDefault( f, b.defaults[ f.field_key ] ) );
			} );
		}
		return section( 'section-defaults', '3. Product defaults', children, null, { locked: ! state.binding.template_key } );
	}

	function emptyStateCta( opts ) {
		if ( window.ConfigKit && window.ConfigKit.emptyState ) {
			return window.ConfigKit.emptyState( opts );
		}
		// Fallback: simple paragraph if helper unavailable.
		return el( 'p', { class: 'description' }, opts.message || opts.title || '' );
	}

	function scrollToSection( id ) {
		const node = document.getElementById( id );
		if ( node && typeof node.scrollIntoView === 'function' ) {
			node.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	function renderFieldDefault( field, value ) {
		const label = field.label + '  (' + field.field_key + ')';
		if ( field.value_source === 'manual_options' ) {
			// Phase 3 minimum: free text. Option-key validation happens server-side.
			return textField(
				label,
				'default__' + field.field_key,
				typeof value === 'string' ? value : '',
				( v ) => setDefault( field.field_key, v ),
				{ help: 'Option key (e.g. ' + ( field.field_key === 'control_type' ? 'manual' : 'option_key' ) + ').' }
			);
		}
		if ( field.input_type === 'number' ) {
			return numberField(
				label,
				'default__' + field.field_key,
				typeof value === 'number' ? value : ( value === '' || value == null ? '' : Number( value ) ),
				( v ) => setDefault( field.field_key, v === '' ? '' : Number( v ) ),
				{}
			);
		}
		return textField(
			label,
			'default__' + field.field_key,
			typeof value === 'string' ? value : ( value == null ? '' : String( value ) ),
			( v ) => setDefault( field.field_key, v )
		);
	}

	function renderAllowedSourcesSection() {
		const b = state.binding;
		const children = [];
		if ( ! b.template_key ) {
			children.push( emptyStateCta( {
				icon: '↑',
				title: 'No template selected',
				message: 'Allowed sources filter per-field libraries, options, and price groups — they need a template first.',
				primary: { label: 'Go to template picker ↑', onClick: () => scrollToSection( 'section-base-setup' ) },
			} ) );
		} else if ( state.fields.length === 0 ) {
			children.push( emptyStateCta( {
				icon: '🧱',
				title: 'This template has no fields yet',
				message: 'Allowed sources are configured per field.',
			} ) );
		} else {
			children.push( el( 'p', { class: 'description' }, 'Restrict which library items, options, or price groups appear for each field. Leave a list empty to inherit the template defaults.' ) );
			state.fields.forEach( ( f ) => {
				const cfg = b.allowed_sources[ f.field_key ] || {};
				children.push( renderFieldAllowedSources( f, cfg ) );
			} );
		}
		return section( 'section-allowed-sources', '4. Allowed sources', children, null, { locked: ! state.binding.template_key } );
	}

	function renderFieldAllowedSources( field, cfg ) {
		const wrap = el( 'div', { class: 'configkit-binding__field-card' } );
		wrap.appendChild( el( 'h4', null, field.label, ' ', el( 'code', null, field.field_key ) ) );
		wrap.appendChild( el( 'p', { class: 'description' }, 'value_source: ' + field.value_source ) );

		if ( field.value_source === 'library' ) {
			wrap.appendChild( csvListField(
				'Allowed libraries (library_key)',
				cfg.allowed_libraries || [],
				( arr ) => setAllowedSources( field.field_key, 'allowed_libraries', arr ),
				'Comma-separated library keys, e.g. textiles_dickson, textiles_orchestra'
			) );
			const allowed = Array.isArray( cfg.allowed_libraries ) ? cfg.allowed_libraries : [];
			if ( allowed.length > 0 ) {
				const linkRow = el( 'p', { class: 'configkit-binding__edit-link-row' } );
				linkRow.appendChild( document.createTextNode( 'Open in admin: ' ) );
				allowed.forEach( ( libKey, i ) => {
					if ( typeof libKey !== 'string' ) return;
					const lib = state.libraries.find( ( l ) => l.library_key === libKey );
					if ( ! lib ) return;
					if ( i > 0 ) linkRow.appendChild( document.createTextNode( ' · ' ) );
					linkRow.appendChild( entityEditLink(
						adminUrlForEntity( 'configkit-libraries', lib.id ),
						lib.name + ' ↗'
					) );
				} );
				wrap.appendChild( linkRow );
			}
			wrap.appendChild( csvListField(
				'Excluded items (library_key:item_key)',
				cfg.excluded_items || [],
				( arr ) => setAllowedSources( field.field_key, 'excluded_items', arr ),
				'Comma-separated library_key:item_key pairs.'
			) );
			wrap.appendChild( csvListField(
				'Allowed price groups',
				cfg.allowed_price_groups || [],
				( arr ) => setAllowedSources( field.field_key, 'allowed_price_groups', arr ),
				'Comma-separated price-group keys (e.g. A, B, C).'
			) );
		}

		if ( field.value_source === 'manual_options' ) {
			wrap.appendChild( csvListField(
				'Allowed option keys',
				cfg.allowed_options || [],
				( arr ) => setAllowedSources( field.field_key, 'allowed_options', arr ),
				'Comma-separated option keys. Leave empty to allow all.'
			) );
		}

		if ( field.value_source === 'lookup_table' ) {
			wrap.appendChild( el( 'p', { class: 'description' }, 'Lookup-table fields filter via the Base setup\'s lookup table.' ) );
		}

		return wrap;
	}

	function renderPricingSection() {
		const po = state.binding.pricing_overrides || {};
		return section(
			'section-pricing',
			'5. Pricing overrides',
			[
				el( 'p', { class: 'description' }, 'Per-product pricing tweaks. Leave fields blank to inherit the family / global pricing.' ),
				numberField(
					'Base price fallback',
					'po__base_price_fallback',
					po.base_price_fallback,
					( v ) => setPricing( 'base_price_fallback', v === '' ? '' : Number( v ) ),
					{ min: 0, step: '0.01', help: 'Used when no lookup-table cell matches.' }
				),
				numberField(
					'Minimum price',
					'po__minimum_price',
					po.minimum_price,
					( v ) => setPricing( 'minimum_price', v === '' ? '' : Number( v ) ),
					{ min: 0, step: '0.01' }
				),
				numberField(
					'Product surcharge',
					'po__product_surcharge',
					po.product_surcharge,
					( v ) => setPricing( 'product_surcharge', v === '' ? '' : Number( v ) ),
					{ min: 0, step: '0.01', help: 'Flat surcharge added to the resolved price.' }
				),
				selectField(
					'Sale mode',
					'po__sale_mode',
					po.sale_mode || '',
					[ { value: '', label: '— inherit —' } ].concat( SALE_MODES.map( ( m ) => ( { value: m, label: m } ) ) ),
					( v ) => {
						setPricing( 'sale_mode', v );
						if ( v !== 'discount_percent' ) setPricing( 'discount_percent', '' );
					}
				),
				po.sale_mode === 'discount_percent'
					? numberField(
						'Discount %',
						'po__discount_percent',
						po.discount_percent,
						( v ) => setPricing( 'discount_percent', v === '' ? '' : Number( v ) ),
						{ min: 0, step: '0.01', help: 'Percent off the resolved price.' }
					)
					: null,
				csvListField(
					'Allowed price groups',
					po.allowed_price_groups || [],
					( arr ) => setPricing( 'allowed_price_groups', arr ),
					'Comma-separated price-group keys allowed for this product.'
				),
				selectField(
					'VAT display',
					'po__vat_display',
					po.vat_display || '',
					[ { value: '', label: '— inherit —' } ].concat( VAT_DISPLAYS.map( ( m ) => ( { value: m, label: m } ) ) ),
					( v ) => setPricing( 'vat_display', v )
				),
			]
		);
	}

	function renderVisibilitySection() {
		const b = state.binding;
		const children = [];
		if ( ! b.template_key ) {
			children.push( emptyStateCta( {
				icon: '↑',
				title: 'No template selected',
				message: 'Visibility / locking applies to fields, so we need a template first.',
				primary: { label: 'Go to template picker ↑', onClick: () => scrollToSection( 'section-base-setup' ) },
			} ) );
		} else if ( state.fields.length === 0 ) {
			children.push( emptyStateCta( {
				icon: '🧱',
				title: 'This template has no fields yet',
				message: 'Visibility settings are per field.',
			} ) );
		} else {
			children.push( el( 'p', { class: 'description' }, 'Hide a field, force-require it, or lock it to a specific value for this product only.' ) );
			state.fields.forEach( ( f ) => {
				const cfg = b.field_overrides[ f.field_key ] || {};
				children.push( renderFieldOverride( f, cfg ) );
			} );
		}
		return section( 'section-visibility', '6. Visibility & locking', children, null, { locked: ! state.binding.template_key } );
	}

	function renderFieldOverride( field, cfg ) {
		const wrap = el( 'div', { class: 'configkit-binding__field-card' } );
		wrap.appendChild( el( 'h4', null, field.label, ' ', el( 'code', null, field.field_key ) ) );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-binding__override-row' },
			el(
				'label',
				{ class: 'configkit-checkbox' },
				el( 'input', {
					type: 'checkbox',
					checked: !! cfg.hide,
					onChange: ( ev ) => setFieldOverride( field.field_key, 'hide', ev.target.checked ),
				} ),
				' ',
				'Hide on this product'
			),
			el(
				'label',
				{ class: 'configkit-checkbox' },
				el( 'input', {
					type: 'checkbox',
					checked: !! cfg.require,
					onChange: ( ev ) => setFieldOverride( field.field_key, 'require', ev.target.checked ),
				} ),
				' ',
				'Force required'
			)
		) );

		wrap.appendChild( textField(
			'Lock to value',
			'lock__' + field.field_key,
			cfg.lock != null ? String( cfg.lock ) : '',
			( v ) => setFieldOverride( field.field_key, 'lock', v ),
			{ help: 'When set, the field is hidden and forced to this value at checkout.' }
		) );

		wrap.appendChild( textField(
			'Pre-select',
			'preselect__' + field.field_key,
			cfg.preselect != null ? String( cfg.preselect ) : '',
			( v ) => setFieldOverride( field.field_key, 'preselect', v ),
			{ help: 'Like a default, but shown as the active selection on first paint.' }
		) );

		return wrap;
	}

	function renderDiagnosticsSection() {
		const children = [];
		children.push( el(
			'div',
			{ class: 'configkit-binding__diag-actions' },
			el(
				'button',
				{
					type: 'button',
					class: 'button',
					disabled: state.diagnosticsBusy,
					onClick: refreshDiagnostics,
				},
				state.diagnosticsBusy ? 'Running…' : 'Run diagnostics'
			)
		) );

		if ( state.diagnostics && state.diagnostics.checks ) {
			const list = el( 'ul', { class: 'configkit-binding__checks' } );
			state.diagnostics.checks.forEach( ( c ) => {
				const cls = c.passed
					? 'configkit-binding__check configkit-binding__check--pass'
					: ( c.severity === 'warning'
						? 'configkit-binding__check configkit-binding__check--warn'
						: 'configkit-binding__check configkit-binding__check--fail' );
				const icon = c.passed ? '✓' : ( c.severity === 'warning' ? '⚠' : '✗' );
				const title = c.title || c.id;
				const item = el(
					'li',
					{ class: cls },
					el( 'div', { class: 'configkit-binding__check-head' },
						el( 'strong', null, icon + '  ' + title )
					),
					el( 'p', { class: 'configkit-binding__check-message' }, c.message )
				);
				if ( ! c.passed && c.suggested_fix ) {
					item.appendChild( el(
						'p',
						{ class: 'configkit-binding__check-fix' },
						el( 'span', { class: 'configkit-binding__check-fix-label' }, 'Suggested fix: ' ),
						c.suggested_fix
					) );
				}
				list.appendChild( item );
			} );
			children.push( list );
		} else {
			children.push( el( 'p', { class: 'description' }, 'Diagnostics have not run yet.' ) );
		}

		return section( 'section-diagnostics', '7. Diagnostics', children, statusBadge() );
	}

	function renderPreviewSection() {
		const ready = state.diagnostics && state.diagnostics.status === 'ready';
		return section(
			'section-preview',
			'8. Preview',
			[
				el( 'p', { class: 'description' }, 'Frontend preview will land in Phase 4 with the storefront app. Save your binding and use the WooCommerce product preview to verify the live experience.' ),
			],
			null,
			{ locked: ! ready }
		);
	}

	function renderSaveBar() {
		const b = state.binding;
		const updated = b.updated_at ? 'Last saved: ' + b.updated_at + ' UTC' : 'Not saved yet.';
		return el(
			'div',
			{ class: 'configkit-binding__savebar' },
			el( 'span', { class: 'configkit-binding__savebar-meta' }, updated ),
			el(
				'button',
				{ type: 'button', class: 'button button-primary', disabled: state.busy, onClick: save },
				state.busy ? 'Saving…' : 'Save binding'
			)
		);
	}

	// ---- Field helpers ----

	function fieldErrors( name ) {
		const errs = state.fieldErrors[ name ];
		if ( ! errs || errs.length === 0 ) return null;
		return el( 'ul', { class: 'configkit-errors' }, ...errs.map( ( m ) => el( 'li', null, m ) ) );
	}

	function textField( label, name, value, onChange, opts ) {
		opts = opts || {};
		return el(
			'div',
			{ class: 'configkit-field' },
			el( 'label', { for: 'cfb_' + name }, label ),
			el( 'input', {
				id: 'cfb_' + name,
				type: 'text',
				class: 'regular-text',
				value: value == null ? '' : String( value ),
				onInput: ( ev ) => onChange( ev.target.value ),
			} ),
			opts.help ? el( 'p', { class: 'description' }, opts.help ) : null,
			fieldErrors( name )
		);
	}

	function numberField( label, name, value, onChange, opts ) {
		opts = opts || {};
		const attrs = {
			id: 'cfb_' + name,
			type: 'number',
			class: 'regular-text',
			value: ( value === '' || value === null || value === undefined ) ? '' : String( value ),
			onInput: ( ev ) => onChange( ev.target.value ),
		};
		if ( opts.min !== undefined ) attrs.min = String( opts.min );
		if ( opts.step !== undefined ) attrs.step = String( opts.step );
		return el(
			'div',
			{ class: 'configkit-field' },
			el( 'label', { for: 'cfb_' + name }, label ),
			el( 'input', attrs ),
			opts.help ? el( 'p', { class: 'description' }, opts.help ) : null,
			fieldErrors( name )
		);
	}

	function selectField( label, name, value, options, onChange, editHref ) {
		const select = el( 'select', {
			id: 'cfb_' + name,
			onChange: ( ev ) => onChange( ev.target.value ),
		} );
		options.forEach( ( opt ) => {
			const o = el( 'option', { value: opt.value }, opt.label );
			if ( String( opt.value ) === String( value || '' ) ) o.selected = true;
			select.appendChild( o );
		} );
		return el(
			'div',
			{ class: 'configkit-field' },
			el( 'label', { for: 'cfb_' + name }, label ),
			el( 'div', { class: 'configkit-binding__select-row' },
				select,
				editHref ? entityEditLink( editHref, 'Edit ↗' ) : null
			),
			fieldErrors( name )
		);
	}

	function entityEditLink( href, label ) {
		return el( 'a', {
			class: 'configkit-binding__edit-link',
			href: href,
			target: '_blank',
			rel: 'noopener',
			title: 'Open in admin (new tab)',
		}, label );
	}

	function adminUrlForEntity( page, id ) {
		// Resolve relative to the current admin URL so tests that
		// patch window.location keep working.
		const path = window.location.pathname || '/wp-admin/admin.php';
		return path + '?page=' + encodeURIComponent( page ) + '&action=edit&id=' + encodeURIComponent( String( id ) );
	}

	function csvListField( label, value, onChange, help ) {
		const text = Array.isArray( value ) ? value.join( ', ' ) : '';
		return el(
			'div',
			{ class: 'configkit-field' },
			el( 'label', null, label ),
			el( 'input', {
				type: 'text',
				class: 'regular-text',
				value: text,
				onInput: ( ev ) => {
					const arr = ev.target.value.split( ',' )
						.map( ( s ) => s.trim() )
						.filter( ( s ) => s.length > 0 );
					onChange( arr );
				},
			} ),
			help ? el( 'p', { class: 'description' }, help ) : null
		);
	}

	loadAll();
} )();
