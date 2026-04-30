/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-modules-app' );
	if ( ! root ) {
		return;
	}

	// [ key, label, help, dashicon ]
	const CAPABILITY_FLAGS = [
		[ 'supports_sku', 'Item has a unique code (SKU)', 'A short identifier such as DICK-U171.', 'tag' ],
		[ 'supports_image', 'Item has a thumbnail image', 'Small image shown in pickers and carts.', 'format-image' ],
		[ 'supports_main_image', 'Item has a large hero image', 'Big image shown in the detail view.', 'cover-image' ],
		[ 'supports_price', 'Item has a price', 'Base price stored on the item.', 'money-alt' ],
		[ 'supports_sale_price', 'Item can have a sale price', 'Discounted price alongside the base price.', 'tickets-alt' ],
		[ 'supports_filters', 'Items can be filtered by tags', 'Tags like "blackout" or "waterproof" used by frontend filters.', 'filter' ],
		[ 'supports_compatibility', 'Items have compatibility/rule tags', 'Tags like "io_protocol" used by rules.', 'admin-links' ],
		[ 'supports_price_group', 'Items belong to price groups (I, II, III)', 'Bucket key used by lookup tables to pick a row.', 'groups' ],
		[ 'supports_brand', 'Item has a brand name', 'Brand label like "Dickson".', 'awards' ],
		[ 'supports_collection', 'Item has a collection name', 'Collection label like "Orchestra Max".', 'portfolio' ],
		[ 'supports_color_family', 'Item has a color family', 'Group label like "blue" or "green" for color filtering.', 'art' ],
		[ 'supports_woo_product_link', 'Item links to a WooCommerce product', 'Used to map items to existing Woo product IDs.', 'cart' ],
	];

	// [ key, help, dashicon ]
	const FIELD_KINDS = [
		[ 'input',    'Owner-pickable values (radio, dropdown, library cards).', 'edit' ],
		[ 'display',  'Read-only values rendered for context, not chosen.',     'info-outline' ],
		[ 'computed', 'Server-computed values (rule output, derived).',         'calculator' ],
		[ 'addon',    'Optional add-on products that affect price.',            'cart' ],
		[ 'lookup',   'Dimensions used by lookup tables (width / height / depth).', 'grid-view' ],
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
		// Owner lands directly on a blank form. The 4 named presets
		// (Textiles / Colors / Motors / Accessories) become inline
		// "Apply preset" buttons inside renderForm() that overlay
		// their capabilities on top of whatever is already set.
		state.view = 'form';
		state.editing = blankRecord();
		state.pickedPresetId = null;
		state.dirty = false;
		state.userEditedKey = false;
		clearMessages();
		setUrl( { action: 'new', id: null } );
		render();
	}

	/**
	 * Overlay a preset's capability set on top of the current form
	 * state. Owner-supplied checkbox state is NEVER cleared — the
	 * preset only ticks new boxes on. Same union-merge for
	 * allowed_field_kinds. Owner can untick anything afterwards.
	 */
	function applyPreset( presetId ) {
		const preset = MODULE_TYPE_PRESETS.find( ( p ) => p.id === presetId );
		if ( ! preset || ! state.editing ) return;
		Object.keys( preset.capabilities ).forEach( ( k ) => {
			if ( preset.capabilities[ k ] ) state.editing[ k ] = true;
		} );
		const merged = ( state.editing.allowed_field_kinds || [] ).slice();
		( preset.allowed_field_kinds || [] ).forEach( ( kind ) => {
			if ( merged.indexOf( kind ) === -1 ) merged.push( kind );
		} );
		state.editing.allowed_field_kinds = merged;
		state.pickedPresetId = presetId;
		state.dirty = true;
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
		// The server breadcrumb already ends in "Modules" — JS only
		// appends the tail (the entity action or name).
		const rec = state.editing;
		const tail = rec && rec.id > 0
			? 'Edit "' + ( rec.name || rec.module_key ) + '"'
			: 'New module';
		window.ConfigKit.subBreadcrumb( [ { label: tail } ] );
	}

	function renderPresetButtons() {
		// Only the named, capability-bearing presets — "Custom" is the
		// default state, so we don't render a button for it.
		const presets = MODULE_TYPE_PRESETS.filter( ( p ) => Object.keys( p.capabilities ).some( ( k ) => p.capabilities[ k ] ) );
		const wrap = el( 'div', { class: 'configkit-preset-row' } );
		wrap.appendChild( el( 'span', { class: 'configkit-preset-row__label' }, 'Need a starting point? Apply a preset:' ) );
		const buttons = el( 'div', { class: 'configkit-preset-row__buttons' } );
		presets.forEach( ( preset ) => {
			const isActive = state.pickedPresetId === preset.id;
			buttons.appendChild( el( 'button', {
				type: 'button',
				class: 'button configkit-preset-row__btn' + ( isActive ? ' is-active' : '' ),
				title: preset.description,
				onClick: () => applyPreset( preset.id ),
			},
				el( 'span', { class: 'configkit-preset-row__icon', 'aria-hidden': 'true' }, preset.icon ),
				' ',
				preset.label
			) );
		} );
		wrap.appendChild( buttons );
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
					el( 'th', null, 'Technical key' ),
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
					el( 'td', { 'data-label': 'Technical key' }, el( 'code', null, m.module_key ) ),
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

		// Optional preset helpers — only on Create. The form starts
		// blank; clicking a preset overlays its capabilities on top
		// of whatever the owner has already toggled.
		if ( isNew ) {
			wrap.appendChild( renderPresetButtons() );
		}

		// Basics — Phase 4 dalis 4 BUG 3: Technical key moved to a
		// collapsed "Advanced" fieldset so the owner doesn't have to
		// know what `module_key` is to create a module.
		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Name', 'name', rec.name, ( v ) => {
				rec.name = v;
				state.dirty = true;
				if ( ! rec.id && ! state.userEditedKey ) {
					rec.module_key = ( window.ConfigKit && window.ConfigKit.slugify )
						? window.ConfigKit.slugify( v, { fallbackPrefix: 'module' } )
						: slugify( v );
					const techInput = document.getElementById( 'cf_module_key' );
					if ( techInput && techInput.value !== rec.module_key ) {
						techInput.value = rec.module_key;
					}
				}
			} ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		wrap.appendChild( fieldset(
			isNew ? 'Technical key (auto-generated)' : 'Technical key',
			[
				textField( 'Technical key', 'module_key', rec.module_key, ( v ) => {
					rec.module_key = v;
					state.dirty = true;
					state.userEditedKey = true;
				}, {
					mono: true,
					help: isNew
						? 'Auto-filled from Name. Edit only if you need a specific key — locked once the module is saved.'
						: 'Used internally to reference this module from libraries and rules. Lowercase, snake_case, max 64 chars.',
					warnings: window.ConfigKit && window.ConfigKit.softKeyWarnings
						? window.ConfigKit.softKeyWarnings( rec.module_key, {
							hint: 'try {brand}_{kind} format, e.g. textiles_dickson',
							duplicates: ( state.list.items || [] ).map( ( m ) => m.module_key ),
						} )
						: [],
				} ),
			],
			{ collapsible: true, collapsed: isNew }
		) );

		// Capabilities
		const capChecks = CAPABILITY_FLAGS.map( ( [ key, label, help, icon ] ) =>
			checkboxField( label, key, !! rec[ key ], ( v ) => {
				rec[ key ] = v;
				state.dirty = true;
			}, help, icon )
		);
		wrap.appendChild( fieldset( 'What items in this module can store', [
			el(
				'div',
				{ class: 'configkit-grid configkit-grid--3' },
				...capChecks
			),
		], { collapsible: true, collapsed: ! isNew } ) );

		// Allowed field kinds
		const kindChecks = FIELD_KINDS.map( ( [ kind, help, icon ] ) =>
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
				help,
				icon
			)
		);
		wrap.appendChild( fieldset( 'Where this module can be used', [
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

	function checkboxField( label, name, checked, onChange, help, icon ) {
		// The whole row gets a .configkit-capability-row container so
		// CSS can style icon + label uniformly via .is-checked instead
		// of relying on :has() (still not reliable across all browsers).
		const checkbox = el( 'input', {
			type: 'checkbox',
			checked: !! checked,
			onChange: ( ev ) => onChange( ev.target.checked ),
		} );
		const labelEl = el( 'label', { class: 'configkit-checkbox' + ( icon ? ' configkit-checkbox--with-icon' : '' ) } );
		labelEl.appendChild( checkbox );
		labelEl.appendChild( document.createTextNode( ' ' ) );
		if ( icon ) {
			labelEl.appendChild( el( 'span', {
				class: 'dashicons dashicons-' + icon + ' configkit-cap-icon',
				'aria-hidden': 'true',
			} ) );
		}
		labelEl.appendChild( document.createTextNode( label ) );
		if ( help && window.ConfigKit && window.ConfigKit.help ) {
			labelEl.appendChild( window.ConfigKit.help( help ) );
		}
		const row = el(
			'div',
			{ class: 'configkit-capability-row' + ( checked ? ' is-checked' : '' ) },
			labelEl
		);
		return row;
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
