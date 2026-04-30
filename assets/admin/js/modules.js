/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-modules-app' );
	if ( ! root ) {
		return;
	}

	const CAPABILITY_FLAGS = [
		[ 'supports_sku', 'SKU', 'Each item has a unique product code (e.g. DICK-U171).' ],
		[ 'supports_image', 'Thumbnail image', 'Items can have a small thumbnail image.' ],
		[ 'supports_main_image', 'Hero / main image', 'Items can have a large hero/main image for detail views.' ],
		[ 'supports_price', 'Price', 'Items have a base price (numeric).' ],
		[ 'supports_sale_price', 'Sale price', 'Items can have a discounted sale price alongside the base price.' ],
		[ 'supports_filters', 'Filter tags', 'Items can be filtered by tags such as "blackout" or "waterproof".' ],
		[ 'supports_compatibility', 'Compatibility tags', 'Items have compatibility tags (e.g. "io_protocol", "z_wave").' ],
		[ 'supports_price_group', 'Price group', 'Items belong to a price group (e.g. I, II, III) used by lookup tables.' ],
		[ 'supports_brand', 'Brand', 'Items have a brand label (e.g. "Dickson").' ],
		[ 'supports_collection', 'Collection', 'Items belong to a collection (e.g. "Orchestra Max").' ],
		[ 'supports_color_family', 'Color family', 'Items have a color family (e.g. "blue", "green") for grouping in pickers.' ],
		[ 'supports_woo_product_link', 'Linked Woo product', 'Each item links to an existing WooCommerce product.' ],
	];

	const FIELD_KINDS = [
		[ 'input',    'Owner-pickable values (radio, dropdown, library cards).' ],
		[ 'display',  'Read-only values rendered for context, not chosen.' ],
		[ 'computed', 'Server-computed values (rule output, derived).' ],
		[ 'addon',    'Optional add-on products that affect price.' ],
		[ 'lookup',   'Dimensions used by lookup tables (width / height / depth).' ],
	];

	// Mirrors src/Admin/ModuleTypePresets.php. The server applies the
	// same preset on POST when `module_type` is present, so this list
	// is purely a UI affordance for the Create flow.
	const MODULE_TYPE_PRESETS = [
		{
			id: 'textiles',
			label: 'Textiles',
			icon: '🧵',
			description: 'Fabric collections with brand, collection, color family, filter tags, and price groups.',
			capabilities: {
				supports_sku: true,
				supports_image: true,
				supports_price_group: true,
				supports_brand: true,
				supports_collection: true,
				supports_color_family: true,
				supports_filters: true,
				supports_compatibility: true,
			},
			allowed_field_kinds: [ 'input' ],
		},
		{
			id: 'colors',
			label: 'Colors',
			icon: '🎨',
			description: 'Color palettes with images and color family grouping.',
			capabilities: { supports_sku: true, supports_image: true, supports_color_family: true },
			allowed_field_kinds: [ 'input' ],
		},
		{
			id: 'motors',
			label: 'Motors',
			icon: '⚙',
			description: 'Motor products with price, compatibility, and a linked Woo product.',
			capabilities: {
				supports_sku: true,
				supports_price: true,
				supports_sale_price: true,
				supports_woo_product_link: true,
				supports_compatibility: true,
			},
			allowed_field_kinds: [ 'addon', 'input' ],
		},
		{
			id: 'accessories',
			label: 'Accessories',
			icon: '🔧',
			description: 'Add-on products with price and Woo link.',
			capabilities: {
				supports_sku: true,
				supports_price: true,
				supports_sale_price: true,
				supports_image: true,
				supports_woo_product_link: true,
			},
			allowed_field_kinds: [ 'addon' ],
		},
		{
			id: 'custom',
			label: 'Custom',
			icon: '✨',
			description: 'Build a module with custom capabilities — pick everything yourself.',
			capabilities: {},
			allowed_field_kinds: [],
		},
	];

	const state = {
		view: 'loading', // 'list' | 'form' | 'loading'
		list: { items: [], total: 0, page: 1, per_page: 50, total_pages: 0 },
		editing: null, // module record being edited; null when creating
		dirty: false,
		message: null, // { kind: 'error' | 'success' | 'conflict', text }
		fieldErrors: {}, // { fieldKey: [ messages ] }
		showAdvanced: false,
		busy: false,
	};

	function el( tag, attrs, ...children ) {
		const node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( ( key ) => {
				const value = attrs[ key ];
				if ( value === false || value === null || value === undefined ) {
					return;
				}
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
		children.flat().forEach( ( child ) => {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.appendChild(
				typeof child === 'string' ? document.createTextNode( child ) : child
			);
		} );
		return node;
	}

	function slugify( raw ) {
		if ( ! raw ) {
			return '';
		}
		let out = raw
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
		// Must start with [a-z]; trim leading digits/underscores until that's true.
		out = out.replace( /^[^a-z]+/, '' );
		return out.slice( 0, 64 );
	}

	function setUrl( params ) {
		const url = new URL( window.location.href );
		Object.keys( params ).forEach( ( key ) => {
			if ( params[ key ] === null || params[ key ] === undefined ) {
				url.searchParams.delete( key );
			} else {
				url.searchParams.set( key, params[ key ] );
			}
		} );
		window.history.replaceState( null, '', url.toString() );
	}

	function clearMessages() {
		state.message = null;
		state.fieldErrors = {};
	}

	async function loadList() {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/modules?per_page=50' );
			state.list = data;
			state.view = 'list';
			state.editing = null;
			clearMessages();
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function showNewForm() {
		// Step 1 — show the preset picker. Step 2 (`pickPreset`) seeds
		// the form with the chosen preset's capabilities + field kinds.
		state.view = 'presets';
		state.editing = null;
		state.pickedPresetId = null;
		state.dirty = false;
		clearMessages();
		setUrl( { action: 'new', id: null } );
		render();
	}

	function pickPreset( presetId ) {
		const preset = MODULE_TYPE_PRESETS.find( ( p ) => p.id === presetId );
		const rec = blankRecord();
		if ( preset ) {
			Object.keys( preset.capabilities ).forEach( ( k ) => { rec[ k ] = !! preset.capabilities[ k ]; } );
			rec.allowed_field_kinds = ( preset.allowed_field_kinds || [] ).slice();
		}
		state.editing = rec;
		state.pickedPresetId = presetId;
		state.view = 'form';
		state.dirty = false;
		render();
	}

	async function loadModule( id ) {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/modules/' + id );
			state.editing = data.record;
			state.view = 'form';
			state.dirty = false;
			clearMessages();
			setUrl( { action: 'edit', id: id } );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function blankRecord() {
		const rec = {
			id: 0,
			module_key: '',
			name: '',
			description: '',
			allowed_field_kinds: [],
			attribute_schema: {},
			is_active: true,
			sort_order: 0,
			version_hash: '',
		};
		CAPABILITY_FLAGS.forEach( ( [ key ] ) => {
			rec[ key ] = false;
		} );
		return rec;
	}

	async function save() {
		if ( state.busy ) return;
		state.busy = true;
		render();

		const rec = state.editing;
		const wasNew = ! ( rec.id > 0 );
		const payload = {
			module_key: rec.module_key,
			name: rec.name,
			description: rec.description || null,
			allowed_field_kinds: rec.allowed_field_kinds,
			attribute_schema: rec.attribute_schema,
			is_active: rec.is_active,
			sort_order: rec.sort_order,
		};
		CAPABILITY_FLAGS.forEach( ( [ key ] ) => {
			payload[ key ] = !! rec[ key ];
		} );

		let success = false;
		try {
			if ( rec.id > 0 ) {
				payload.version_hash = rec.version_hash;
				await ConfigKit.request( '/modules/' + rec.id, {
					method: 'PUT',
					body: payload,
				} );
			} else {
				await ConfigKit.request( '/modules', {
					method: 'POST',
					body: payload,
				} );
			}
			success = true;
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}

		if ( success ) {
			redirectToList( wasNew ? 'created' : 'updated' );
			return;
		}
		render();
	}

	function redirectToList( savedFlag ) {
		const url = new URL( window.location.href );
		// Strip detail query params, keep ?page=configkit-modules.
		[ 'action', 'id' ].forEach( ( p ) => url.searchParams.delete( p ) );
		url.searchParams.set( 'saved', savedFlag );
		window.location.href = url.toString();
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
		state.message = {
			kind: desc.kind,
			text: desc.friendly,
			technical: desc.technical,
		};
	}

	async function softDelete() {
		if ( ! state.editing || ! state.editing.id ) return;
		const ok = window.confirm(
			'Soft-delete this module? It will be marked is_active=0 and hidden from new field editors. You can re-activate it later.'
		);
		if ( ! ok ) return;
		try {
			await ConfigKit.request( '/modules/' + state.editing.id, { method: 'DELETE' } );
			await loadList();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	function cancelToList() {
		if ( state.dirty ) {
			const ok = window.confirm( 'Discard unsaved changes?' );
			if ( ! ok ) return;
		}
		setUrl( { action: null, id: null } );
		loadList();
	}

	// ---- Rendering ----

	function render() {
		root.dataset.loading = state.view === 'loading' ? 'true' : 'false';
		root.replaceChildren();
		updateSubBreadcrumb();

		if ( state.view === 'loading' ) {
			root.appendChild( el( 'p', { class: 'configkit-app__loading' }, 'Loading…' ) );
			return;
		}

		if ( state.view === 'list' ) {
			root.appendChild( renderList() );
			return;
		}

		if ( state.view === 'presets' ) {
			root.appendChild( renderPresetPicker() );
			return;
		}

		if ( state.view === 'form' ) {
			root.appendChild( renderForm() );
		}
	}

	function updateSubBreadcrumb() {
		if ( ! window.ConfigKit || ! window.ConfigKit.subBreadcrumb ) return;
		if ( state.view === 'list' || state.view === 'loading' ) {
			window.ConfigKit.subBreadcrumb( null );
			return;
		}
		const segs = [ { label: 'Modules', onClick: () => { setUrl( { action: null, id: null } ); loadList(); } } ];
		if ( state.view === 'presets' ) {
			segs.push( { label: 'New module' } );
		} else if ( state.view === 'form' ) {
			const rec = state.editing;
			if ( rec && rec.id > 0 ) segs.push( { label: 'Edit "' + ( rec.name || rec.module_key ) + '"' } );
			else segs.push( { label: 'New module' } );
		}
		window.ConfigKit.subBreadcrumb( segs );
	}

	function renderPresetPicker() {
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el( 'h2', null, 'New module — pick a starting point' ) );
		wrap.appendChild( el(
			'p',
			{ class: 'description' },
			'Choose the closest match. You can adjust every capability before saving.'
		) );

		const grid = el( 'div', { class: 'configkit-preset-grid' } );
		MODULE_TYPE_PRESETS.forEach( ( preset ) => {
			const enabledCaps = Object.keys( preset.capabilities ).filter( ( k ) => preset.capabilities[ k ] );
			const card = el(
				'button',
				{
					type: 'button',
					class: 'configkit-preset-card',
					onClick: () => pickPreset( preset.id ),
				},
				el( 'span', { class: 'configkit-preset-card__icon' }, preset.icon ),
				el( 'span', { class: 'configkit-preset-card__title' }, preset.label ),
				el( 'span', { class: 'configkit-preset-card__desc' }, preset.description ),
				enabledCaps.length > 0
					? el( 'span', { class: 'configkit-preset-card__caps' }, enabledCaps.length + ' capabilities preselected' )
					: el( 'span', { class: 'configkit-preset-card__caps' }, 'Pick everything yourself' )
			);
			grid.appendChild( card );
		} );
		wrap.appendChild( grid );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => { setUrl( { action: null, id: null } ); loadList(); },
			}, 'Cancel' )
		) );
		return wrap;
	}

	function renderList() {
		const wrap = el( 'div', { class: 'configkit-list' } );

		const header = el(
			'div',
			{ class: 'configkit-list__header' },
			el(
				'button',
				{
					type: 'button',
					class: 'button button-primary',
					onClick: showNewForm,
				},
				'+ New module'
			)
		);
		wrap.appendChild( header );

		if ( state.message ) {
			wrap.appendChild( messageBanner( state.message ) );
		}

		const items = state.list.items || [];
		if ( items.length === 0 ) {
			wrap.appendChild( window.ConfigKit.emptyState( {
				icon: '📦',
				title: 'No modules yet',
				message: 'A module declares a kind of option group (textiles, motors, colors). Create one to start adding libraries.',
				primary: { label: '+ Create first module', onClick: showNewForm },
			} ) );
			return wrap;
		}

		const table = el(
			'table',
			{ class: 'wp-list-table widefat striped configkit-modules-table' },
			el(
				'thead',
				null,
				el(
					'tr',
					null,
					el( 'th', null, 'Name' ),
					el( 'th', null, 'module_key' ),
					el( 'th', null, 'Capabilities' ),
					el( 'th', null, 'Field kinds' ),
					el( 'th', null, 'Status' ),
					el( 'th', { class: 'configkit-actions' }, '' )
				)
			)
		);

		const tbody = el( 'tbody' );
		items.forEach( ( m ) => {
			const capCount = CAPABILITY_FLAGS.reduce(
				( acc, [ k ] ) => ( m[ k ] ? acc + 1 : acc ),
				0
			);
			const status = m.is_active ? 'active' : 'inactive';
			tbody.appendChild(
				el(
					'tr',
					null,
					el(
						'td',
						{ 'data-label': 'Name' },
						el(
							'a',
							{
								href: '#',
								onClick: ( ev ) => {
									ev.preventDefault();
									loadModule( m.id );
								},
							},
							m.name
						)
					),
					el( 'td', { 'data-label': 'module_key' }, el( 'code', null, m.module_key ) ),
					el( 'td', { 'data-label': 'Capabilities' }, capCount + ' / ' + CAPABILITY_FLAGS.length ),
					el(
						'td',
						{ 'data-label': 'Field kinds' },
						( m.allowed_field_kinds || [] ).join( ', ' ) || '—'
					),
					el(
						'td',
						{ 'data-label': 'Status' },
						el(
							'span',
							{ class: 'configkit-badge configkit-badge--' + status },
							status
						)
					),
					el(
						'td',
						{ class: 'configkit-actions' },
						el(
							'button',
							{
								type: 'button',
								class: 'button',
								onClick: () => loadModule( m.id ),
							},
							'Edit'
						)
					)
				)
			);
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );

		return wrap;
	}

	function messageBanner( message ) {
		const cls = 'notice ' + (
			message.kind === 'success' ? 'notice-success'
				: message.kind === 'conflict' ? 'notice-warning'
				: 'notice-error'
		) + ' inline configkit-notice';
		const wrap = el( 'div', { class: cls } );
		wrap.appendChild( el( 'p', null, message.text ) );
		if ( message.technical ) {
			const details = el( 'details', { class: 'configkit-error-details' } );
			details.appendChild( el( 'summary', null, 'Show technical details' ) );
			details.appendChild( el( 'pre', null, message.technical ) );
			wrap.appendChild( details );
		}
		return wrap;
	}

	function renderForm() {
		const rec = state.editing;
		const isNew = ! ( rec.id > 0 );
		const wrap = el( 'div', { class: 'configkit-form' } );

		const heading = rec.id > 0 ? 'Edit module: ' + ( rec.name || rec.module_key ) : 'New module';
		wrap.appendChild( el( 'h2', null, heading ) );

		if ( state.message ) {
			wrap.appendChild( messageBanner( state.message ) );
		}

		// Basics
		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Name', 'name', rec.name, ( v ) => {
				rec.name = v;
				state.dirty = true;
				if ( ! rec.module_key && ! rec.id ) {
					rec.module_key = slugify( v );
					render();
				}
			} ),
			textField( 'module_key', 'module_key', rec.module_key, ( v ) => {
				rec.module_key = v;
				state.dirty = true;
			}, {
				mono: true,
				help: 'Lowercase, snake_case, max 64 chars. Once saved this is the stable identity for libraries and rules.',
				warnings: window.ConfigKit && window.ConfigKit.softKeyWarnings
					? window.ConfigKit.softKeyWarnings( rec.module_key, {
						hint: 'try {brand}_{kind} format, e.g. textiles_dickson',
						duplicates: ( state.list.items || [] ).map( ( m ) => m.module_key ),
					} )
					: [],
			} ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		// Capabilities
		const capChecks = CAPABILITY_FLAGS.map( ( [ key, label, help ] ) =>
			checkboxField( label, key, !! rec[ key ], ( v ) => {
				rec[ key ] = v;
				state.dirty = true;
			}, help )
		);
		wrap.appendChild( fieldset( 'Capabilities', [
			el(
				'div',
				{ class: 'configkit-grid configkit-grid--3' },
				...capChecks
			),
		], { collapsible: true, collapsed: ! isNew } ) );

		// Allowed field kinds
		const kindChecks = FIELD_KINDS.map( ( [ kind, help ] ) =>
			checkboxField(
				kind,
				'kind_' + kind,
				( rec.allowed_field_kinds || [] ).includes( kind ),
				( v ) => {
					const list = ( rec.allowed_field_kinds || [] ).filter( ( k ) => k !== kind );
					if ( v ) list.push( kind );
					rec.allowed_field_kinds = list;
					state.dirty = true;
				},
				help
			)
		);
		wrap.appendChild( fieldset( 'Allowed field kinds', [
			el(
				'div',
				{ class: 'configkit-grid configkit-grid--5' },
				...kindChecks
			),
			el(
				'p',
				{ class: 'description' },
				'Which field kinds (per FIELD_MODEL §2) may use libraries of this module as their value source.'
			),
		], { collapsible: true, collapsed: ! isNew } ) );

		// Status
		wrap.appendChild( fieldset( 'Status', [
			checkboxField( 'Active', 'is_active', !! rec.is_active, ( v ) => {
				rec.is_active = v;
				state.dirty = true;
			} ),
			numberField( 'Sort order', 'sort_order', rec.sort_order || 0, ( v ) => {
				rec.sort_order = v;
				state.dirty = true;
			} ),
		] ) );

		// Advanced
		const advancedToggle = el(
			'button',
			{
				type: 'button',
				class: 'button-link configkit-toggle',
				onClick: () => {
					state.showAdvanced = ! state.showAdvanced;
					render();
				},
			},
			state.showAdvanced ? 'Hide advanced' : 'Show advanced'
		);
		wrap.appendChild( advancedToggle );

		if ( state.showAdvanced ) {
			let schemaText = '';
			try {
				schemaText = JSON.stringify( rec.attribute_schema || {}, null, 2 );
			} catch ( e ) {
				schemaText = '{}';
			}
			const schemaErrors = state.fieldErrors.attribute_schema || [];
			wrap.appendChild( fieldset( 'Attribute schema', [
				el(
					'p',
					{ class: 'description' },
					'JSON object mapping snake_case attribute keys to one of: string, integer, boolean. Library items will get form fields generated from this schema.'
				),
				el( 'textarea', {
					class: 'configkit-json',
					rows: 10,
					value: schemaText,
					onInput: ( ev ) => {
						const raw = ev.target.value;
						try {
							const parsed = JSON.parse( raw );
							rec.attribute_schema = parsed;
							state.fieldErrors.attribute_schema = [];
						} catch ( e ) {
							state.fieldErrors.attribute_schema = [ 'Invalid JSON: ' + e.message ];
						}
						state.dirty = true;
					},
				} ),
				...schemaErrors.map( ( m ) => el( 'p', { class: 'configkit-error' }, m ) ),
			] ) );
		}

		// Footer
		const footer = el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{
					type: 'button',
					class: 'button button-primary',
					disabled: state.busy,
					onClick: save,
				},
				state.busy ? 'Saving…' : 'Save module'
			),
			el(
				'button',
				{ type: 'button', class: 'button', onClick: cancelToList },
				'Cancel'
			),
			rec.id > 0
				? el(
					'button',
					{
						type: 'button',
						class: 'button button-link-delete',
						onClick: softDelete,
					},
					'Soft delete'
				)
				: null
		);
		wrap.appendChild( footer );

		return wrap;
	}

	function fieldset( legend, children, opts ) {
		opts = opts || {};
		if ( opts.collapsible ) {
			const body = el( 'div', { class: 'configkit-fieldset__body' }, ...children );
			const fs = el(
				'fieldset',
				{ class: 'configkit-fieldset' },
				el( 'legend', null, legend ),
				body
			);
			if ( window.ConfigKit && window.ConfigKit.makeCollapsible ) {
				window.ConfigKit.makeCollapsible( fs, { collapsed: !! opts.collapsed } );
			}
			return fs;
		}
		return el(
			'fieldset',
			{ class: 'configkit-fieldset' },
			el( 'legend', null, legend ),
			...children
		);
	}

	function fieldErrors( name ) {
		const errs = state.fieldErrors[ name ];
		if ( ! errs || errs.length === 0 ) return null;
		return el(
			'ul',
			{ class: 'configkit-errors' },
			...errs.map( ( m ) => el( 'li', null, m ) )
		);
	}

	function textField( label, name, value, onChange, opts ) {
		opts = opts || {};
		const warningsNode = ( opts.warnings && window.ConfigKit && window.ConfigKit.renderSoftWarnings )
			? window.ConfigKit.renderSoftWarnings( opts.warnings )
			: null;
		return el(
			'div',
			{ class: 'configkit-field' },
			el( 'label', { for: 'cf_' + name }, label ),
			el( 'input', {
				id: 'cf_' + name,
				type: 'text',
				class: opts.mono ? 'regular-text code' : 'regular-text',
				value: value || '',
				onInput: ( ev ) => onChange( ev.target.value ),
			} ),
			opts.help ? el( 'p', { class: 'description' }, opts.help ) : null,
			warningsNode,
			fieldErrors( name )
		);
	}

	function textareaField( label, name, value, onChange ) {
		return el(
			'div',
			{ class: 'configkit-field' },
			el( 'label', { for: 'cf_' + name }, label ),
			el( 'textarea', {
				id: 'cf_' + name,
				rows: 3,
				value: value || '',
				onInput: ( ev ) => onChange( ev.target.value ),
			} ),
			fieldErrors( name )
		);
	}

	function checkboxField( label, name, checked, onChange, help ) {
		const wrap = el(
			'label',
			{ class: 'configkit-checkbox' },
			el( 'input', {
				type: 'checkbox',
				checked: !! checked,
				onChange: ( ev ) => onChange( ev.target.checked ),
			} ),
			' ',
			label
		);
		if ( help && window.ConfigKit && window.ConfigKit.help ) {
			wrap.appendChild( window.ConfigKit.help( help ) );
		}
		return wrap;
	}

	function numberField( label, name, value, onChange ) {
		return el(
			'div',
			{ class: 'configkit-field configkit-field--inline' },
			el( 'label', { for: 'cf_' + name }, label ),
			el( 'input', {
				id: 'cf_' + name,
				type: 'number',
				value: String( value ),
				onInput: ( ev ) => onChange( parseInt( ev.target.value, 10 ) || 0 ),
			} )
		);
	}

	// ---- Init ----

	function consumeSavedFlag() {
		const params = new URLSearchParams( window.location.search );
		const saved = params.get( 'saved' );
		if ( ! saved ) {
			return null;
		}
		setUrl( { saved: null } );
		if ( saved === 'created' ) return 'Module created.';
		if ( saved === 'updated' ) return 'Module updated.';
		return null;
	}

	function init() {
		const params = new URLSearchParams( window.location.search );
		const action = params.get( 'action' );
		const id = parseInt( params.get( 'id' ) || '0', 10 );

		if ( action === 'new' ) {
			showNewForm();
		} else if ( action === 'edit' && id > 0 ) {
			loadModule( id );
		} else {
			const savedMessage = consumeSavedFlag();
			loadList().then( () => {
				if ( savedMessage ) {
					state.message = { kind: 'success', text: savedMessage };
					render();
				}
			} );
		}
	}

	init();
} )();
