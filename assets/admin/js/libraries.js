/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-libraries-app' );
	if ( ! root ) {
		return;
	}

	const state = {
		view: 'loading', // 'list' | 'library_form' | 'library_detail' | 'item_form'
		list: { items: [], total: 0 },
		modules: [], // cached module list
		library: null, // current library record
		moduleOfLibrary: null, // module record for current library
		items: { items: [], total: 0 },
		editingLibrary: null, // record being edited (null when creating)
		editingItem: null,
		dirty: false,
		message: null,
		fieldErrors: {},
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
		let out = raw.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
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

	function consumeSavedFlag() {
		const params = new URLSearchParams( window.location.search );
		const saved = params.get( 'saved' );
		if ( ! saved ) return null;
		setUrl( { saved: null } );
		const map = {
			lib_created: 'Library created.',
			lib_updated: 'Library updated.',
			item_created: 'Item created.',
			item_updated: 'Item updated.',
		};
		return map[ saved ] || null;
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

	// ---- Loaders ----

	async function loadModulesCache() {
		if ( state.modules.length > 0 ) return;
		try {
			const data = await ConfigKit.request( '/modules?per_page=200' );
			state.modules = ( data.items || [] ).filter( ( m ) => m.is_active );
		} catch ( e ) {
			state.modules = [];
		}
	}

	async function loadList() {
		state.view = 'loading';
		render();
		await loadModulesCache();
		try {
			const data = await ConfigKit.request( '/libraries?per_page=500' );
			state.list = data;
			state.view = 'list';
			clearMessages();
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function showNewLibraryForm() {
		state.view = 'library_form';
		state.editingLibrary = blankLibrary();
		state.dirty = false;
		clearMessages();
		setUrl( { action: 'new', id: null } );
		loadModulesCache().then( render );
	}

	async function loadLibrary( id ) {
		state.view = 'loading';
		render();
		await loadModulesCache();
		try {
			const data = await ConfigKit.request( '/libraries/' + id );
			state.library = data.record;
			state.editingLibrary = JSON.parse( JSON.stringify( state.library ) );
			state.moduleOfLibrary = state.modules.find(
				( m ) => m.module_key === state.library.module_key
			) || null;
			const itemsData = await ConfigKit.request( '/libraries/' + id + '/items?per_page=500' );
			state.items = itemsData;
			state.view = 'library_detail';
			state.dirty = false;
			clearMessages();
			setUrl( { action: null, id: id, item_id: null, item_action: null } );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function showNewItemForm() {
		if ( ! state.library || ! state.moduleOfLibrary ) return;
		state.editingItem = blankItem( state.moduleOfLibrary );
		state.view = 'item_form';
		state.dirty = false;
		clearMessages();
		setUrl( { action: null, id: state.library.id, item_action: 'new', item_id: null } );
		render();
	}

	async function loadItem( libraryId, itemId ) {
		state.view = 'loading';
		render();
		await loadModulesCache();
		try {
			if ( ! state.library || state.library.id !== libraryId ) {
				const lib = await ConfigKit.request( '/libraries/' + libraryId );
				state.library = lib.record;
				state.moduleOfLibrary = state.modules.find(
					( m ) => m.module_key === state.library.module_key
				) || null;
			}
			const item = await ConfigKit.request(
				'/libraries/' + libraryId + '/items/' + itemId
			);
			state.editingItem = item.record;
			state.view = 'item_form';
			state.dirty = false;
			clearMessages();
			setUrl( { action: null, id: libraryId, item_id: itemId, item_action: null } );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function blankLibrary() {
		return {
			id: 0,
			library_key: '',
			module_key: '',
			name: '',
			description: '',
			brand: '',
			collection: '',
			is_active: true,
			sort_order: 0,
			version_hash: '',
		};
	}

	function blankItem( /* module */ ) {
		return {
			id: 0,
			item_key: '',
			label: '',
			short_label: '',
			description: '',
			sku: '',
			image_url: '',
			main_image_url: '',
			price: '',
			sale_price: '',
			price_group_key: '',
			color_family: '',
			woo_product_id: '',
			filters: [],
			compatibility: [],
			attributes: {},
			is_active: true,
			sort_order: 0,
			version_hash: '',
			// Phase 4.2b.2 — pricing source / bundle model. Enum values
			// stay backend-only; UI labels per UI_LABELS_MAPPING.md.
			item_type: 'simple_option',
			price_source: 'configkit',
			bundle_fixed_price: '',
			cart_behavior: 'price_inside_main',
			admin_order_display: 'expanded',
			bundle_components: [],
		};
	}

	// Phase 4.2b.2 — owner-friendly labels per UI_LABELS_MAPPING.md.
	// The backend enum value is the radio's `value`; the UI never shows it.
	const PRICE_SOURCE_LABELS = {
		configkit:        { label: 'Use price entered in ConfigKit',         help: 'Set the price directly in this library item.' },
		woo:              { label: 'Use WooCommerce product price',          help: 'Read the price from the linked WooCommerce product. The price freezes when customers add to cart.' },
		product_override: { label: 'Use special price for this Woo product', help: 'Specific Woo products can override this price in their ConfigKit tab.' },
		bundle_sum:       { label: 'Calculate package price from components', help: 'Sum the prices of each component (each component uses its own price source).' },
		fixed_bundle:     { label: 'Use fixed package price',                 help: 'Set a fixed price for the whole package, regardless of component prices.' },
	};

	const ITEM_TYPE_LABELS = {
		simple_option: { label: 'Single option', help: 'Default — one library item, optional Woo link.' },
		bundle:        { label: 'Package (multiple products combined)', help: 'Combines multiple Woo products into one selection.' },
	};

	const CART_BEHAVIOR_LABELS = {
		price_inside_main: { label: 'Show customer one configured product line', help: 'Customer sees one cart line for the whole configuration. Components still appear in the admin order breakdown.' },
		add_child_lines:   { label: 'Show each component as a separate cart line', help: 'Customer sees the main product plus each component as separate cart lines.' },
	};

	const ADMIN_DISPLAY_LABELS = {
		expanded:  { label: 'Show package components in admin order', help: 'Each component appears as a sub-line under the package in the admin order view.' },
		collapsed: { label: 'Show only package name in admin order',  help: 'Cleaner admin orders — components are hidden but still tracked internally.' },
	};

	// ---- Save / Delete ----

	async function saveLibrary() {
		if ( state.busy ) return;
		state.busy = true;
		render();

		const rec = state.editingLibrary;
		const wasNew = ! ( rec.id > 0 );
		const payload = {
			library_key: rec.library_key,
			module_key: rec.module_key,
			name: rec.name,
			description: rec.description || null,
			brand: rec.brand || null,
			collection: rec.collection || null,
			is_active: rec.is_active,
			sort_order: rec.sort_order,
		};

		let success = false;
		try {
			if ( rec.id > 0 ) {
				payload.version_hash = rec.version_hash;
				await ConfigKit.request( '/libraries/' + rec.id, { method: 'PUT', body: payload } );
			} else {
				await ConfigKit.request( '/libraries', { method: 'POST', body: payload } );
			}
			success = true;
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}

		if ( success ) {
			redirectToList( wasNew ? 'lib_created' : 'lib_updated' );
			return;
		}
		render();
	}

	async function softDeleteLibrary() {
		if ( ! state.library || ! state.library.id ) return;
		if ( ! window.confirm( 'Soft-delete this library? It will be marked inactive but its items remain in the database.' ) ) {
			return;
		}
		try {
			await ConfigKit.request( '/libraries/' + state.library.id, { method: 'DELETE' } );
			redirectToList( 'lib_updated' );
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function saveItem() {
		if ( state.busy || ! state.library ) return;
		state.busy = true;
		render();

		const rec = state.editingItem;
		const wasNew = ! ( rec.id > 0 );
		const module = state.moduleOfLibrary || {};
		const payload = {
			item_key: rec.item_key,
			label: rec.label,
			short_label: rec.short_label || null,
			description: rec.description || null,
			price_group_key: rec.price_group_key || '',
			is_active: rec.is_active,
			sort_order: rec.sort_order,
			attributes: rec.attributes || {},
		};
		if ( module.supports_sku ) payload.sku = rec.sku || null;
		if ( module.supports_image ) payload.image_url = rec.image_url || null;
		if ( module.supports_main_image ) payload.main_image_url = rec.main_image_url || null;
		if ( module.supports_price ) payload.price = rec.price === '' ? null : rec.price;
		if ( module.supports_sale_price ) payload.sale_price = rec.sale_price === '' ? null : rec.sale_price;
		if ( module.supports_color_family ) payload.color_family = rec.color_family || null;
		if ( module.supports_woo_product_link ) payload.woo_product_id = rec.woo_product_id === '' ? null : rec.woo_product_id;
		if ( module.supports_filters ) payload.filters = rec.filters || [];
		if ( module.supports_compatibility ) payload.compatibility = rec.compatibility || [];

		// Phase 4.2b.2 — send pricing source + bundle fields.
		payload.item_type    = rec.item_type    || 'simple_option';
		payload.price_source = rec.price_source || 'configkit';
		if ( payload.item_type === 'bundle' ) {
			payload.bundle_components   = Array.isArray( rec.bundle_components ) ? rec.bundle_components : [];
			payload.cart_behavior       = rec.cart_behavior       || 'price_inside_main';
			payload.admin_order_display = rec.admin_order_display || 'expanded';
			payload.bundle_fixed_price  = ( payload.price_source === 'fixed_bundle' && rec.bundle_fixed_price !== '' && rec.bundle_fixed_price !== null )
				? rec.bundle_fixed_price
				: null;
		} else {
			payload.bundle_components   = [];
			payload.cart_behavior       = null;
			payload.admin_order_display = null;
			payload.bundle_fixed_price  = null;
		}

		let success = false;
		try {
			if ( rec.id > 0 ) {
				payload.version_hash = rec.version_hash;
				await ConfigKit.request(
					'/libraries/' + state.library.id + '/items/' + rec.id,
					{ method: 'PUT', body: payload }
				);
			} else {
				await ConfigKit.request(
					'/libraries/' + state.library.id + '/items',
					{ method: 'POST', body: payload }
				);
			}
			success = true;
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}

		if ( success ) {
			redirectToLibrary( state.library.id, wasNew ? 'item_created' : 'item_updated' );
			return;
		}
		render();
	}

	async function softDeleteItem() {
		if ( ! state.library || ! state.editingItem || ! state.editingItem.id ) return;
		if ( ! window.confirm( 'Soft-delete this item? It will be marked inactive.' ) ) {
			return;
		}
		try {
			await ConfigKit.request(
				'/libraries/' + state.library.id + '/items/' + state.editingItem.id,
				{ method: 'DELETE' }
			);
			redirectToLibrary( state.library.id, 'item_updated' );
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	function redirectToList( savedFlag ) {
		const url = new URL( window.location.href );
		[ 'action', 'id', 'item_id', 'item_action' ].forEach( ( p ) => url.searchParams.delete( p ) );
		url.searchParams.set( 'saved', savedFlag );
		window.location.href = url.toString();
	}

	function redirectToLibrary( libraryId, savedFlag ) {
		const url = new URL( window.location.href );
		[ 'action', 'item_id', 'item_action' ].forEach( ( p ) => url.searchParams.delete( p ) );
		url.searchParams.set( 'id', String( libraryId ) );
		url.searchParams.set( 'saved', savedFlag );
		window.location.href = url.toString();
	}

	function cancelToList() {
		if ( state.dirty && ! window.confirm( 'Discard unsaved changes?' ) ) return;
		setUrl( { action: null, id: null, item_id: null, item_action: null } );
		loadList();
	}

	function cancelToLibrary() {
		if ( state.dirty && ! window.confirm( 'Discard unsaved changes?' ) ) return;
		if ( state.library ) {
			loadLibrary( state.library.id );
		} else {
			loadList();
		}
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
		if ( state.view === 'list' ) root.appendChild( renderList() );
		else if ( state.view === 'library_form' ) root.appendChild( renderLibraryForm() );
		else if ( state.view === 'library_detail' ) root.appendChild( renderLibraryDetail() );
		else if ( state.view === 'item_form' ) root.appendChild( renderItemForm() );
	}

	function updateSubBreadcrumb() {
		if ( ! window.ConfigKit || ! window.ConfigKit.subBreadcrumb ) return;
		if ( state.view === 'list' || state.view === 'loading' ) {
			window.ConfigKit.subBreadcrumb( null );
			return;
		}
		// Server breadcrumb already ends in "Libraries" → JS appends
		// the tail only.
		const segs = [];
		const lib = state.library || state.editingLibrary;
		if ( state.view === 'library_form' ) {
			const rec = state.editingLibrary;
			segs.push( { label: rec && rec.id > 0 ? 'Edit "' + ( rec.name || rec.library_key ) + '"' : 'New library' } );
		} else if ( state.view === 'library_detail' ) {
			segs.push( { label: lib && lib.name ? '"' + lib.name + '"' : 'Open' } );
		} else if ( state.view === 'item_form' ) {
			if ( lib && lib.name ) {
				segs.push( {
					label: '"' + lib.name + '"',
					onClick: () => { state.view = 'library_detail'; render(); },
				} );
			}
			const it = state.editingItem;
			segs.push( { label: it && it.id > 0 ? 'Edit "' + ( it.label || it.item_key ) + '"' : 'New item' } );
		}
		window.ConfigKit.subBreadcrumb( segs );
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

	function renderList() {
		const wrap = el( 'div', { class: 'configkit-list' } );
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-list__header' },
			el(
				'button',
				{ type: 'button', class: 'button button-primary', onClick: showNewLibraryForm },
				'+ New library'
			)
		) );

		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		const items = state.list.items || [];
		if ( items.length === 0 ) {
			const noModules = state.modules.length === 0;
			wrap.appendChild( window.ConfigKit.emptyState( {
				icon: '📚',
				title: noModules ? 'No modules yet — start there' : 'No libraries yet',
				message: noModules
					? 'A library belongs to a module. Create a module first, then come back and add libraries.'
					: 'A library is a concrete dataset (a fabric collection, a color set, etc.) inside a module.',
				primary: noModules
					? { label: 'Go to Modules', href: ( window.location.pathname || '' ) + '?page=configkit-modules' }
					: { label: '+ Create first library', onClick: showNewLibraryForm },
			} ) );
			return wrap;
		}

		// Group by module_key
		const byModule = {};
		items.forEach( ( lib ) => {
			byModule[ lib.module_key ] = byModule[ lib.module_key ] || [];
			byModule[ lib.module_key ].push( lib );
		} );

		Object.keys( byModule ).sort().forEach( ( moduleKey ) => {
			const module = state.modules.find( ( m ) => m.module_key === moduleKey );
			const heading = module ? module.name + ' (' + moduleKey + ')' : moduleKey;
			wrap.appendChild( el( 'h3', { class: 'configkit-group' }, heading ) );

			const table = el(
				'table',
				{ class: 'wp-list-table widefat striped configkit-libraries-table' },
				el(
					'thead',
					null,
					el(
						'tr',
						null,
						el( 'th', null, 'Name' ),
						el( 'th', null, 'Technical key' ),
						el( 'th', null, 'Brand' ),
						el( 'th', null, 'Collection' ),
						el( 'th', null, 'Status' ),
						el( 'th', { class: 'configkit-actions' }, '' )
					)
				)
			);
			const tbody = el( 'tbody' );
			byModule[ moduleKey ].forEach( ( lib ) => {
				tbody.appendChild( el(
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
									loadLibrary( lib.id );
								},
							},
							lib.name
						)
					),
					el( 'td', { 'data-label': 'Technical key' }, el( 'code', null, lib.library_key ) ),
					el( 'td', { 'data-label': 'Brand' }, lib.brand || '—' ),
					el( 'td', { 'data-label': 'Collection' }, lib.collection || '—' ),
					el(
						'td',
						{ 'data-label': 'Status' },
						el(
							'span',
							{ class: 'configkit-badge configkit-badge--' + ( lib.is_active ? 'active' : 'inactive' ) },
							lib.is_active ? 'active' : 'inactive'
						)
					),
					el(
						'td',
						{ class: 'configkit-actions' },
						el(
							'button',
							{ type: 'button', class: 'button', onClick: () => loadLibrary( lib.id ) },
							'Open'
						)
					)
				) );
			} );
			table.appendChild( tbody );
			wrap.appendChild( table );
		} );

		return wrap;
	}

	function renderLibraryForm() {
		const rec = state.editingLibrary;
		const isNew = rec.id === 0;
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el( 'h2', null, isNew ? 'New library' : 'Edit library: ' + rec.name ) );

		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		// If no modules exist, show explainer
		if ( state.modules.length === 0 && isNew ) {
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No active modules yet. Create one in Settings → Modules first.' )
			) );
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-form__footer' },
				el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Back' )
			) );
			return wrap;
		}

		// Module dropdown — disabled when editing (module is immutable)
		wrap.appendChild( fieldset( 'Module', [
			el(
				'div',
				{ class: 'configkit-field' },
				el( 'label', null, 'Module' ),
				selectField(
					rec.module_key,
					state.modules.map( ( m ) => [ m.module_key, m.name + ' (' + m.module_key + ')' ] ),
					( v ) => {
						rec.module_key = v;
						state.dirty = true;
						render();
					},
					! isNew
				),
				fieldErrors( 'module_key' ),
				! isNew
					? el(
						'p',
						{ class: 'description' },
						'Module is immutable after a library is created. Create a new library to use a different module.'
					)
					: null
			),
		] ) );

		const selectedModule = state.modules.find( ( m ) => m.module_key === rec.module_key );

		// Basics
		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Name', 'name', rec.name, ( v ) => {
				rec.name = v;
				state.dirty = true;
				if ( ! rec.library_key && isNew ) {
					rec.library_key = slugify( v );
					render();
				}
			} ),
			textField( 'Technical key', 'library_key', rec.library_key, ( v ) => {
				rec.library_key = v;
				state.dirty = true;
			}, {
				mono: true,
				help: 'Used internally to reference this library from items and rules. Lowercase, snake_case.',
				warnings: ( isNew && window.ConfigKit && window.ConfigKit.softKeyWarnings )
					? window.ConfigKit.softKeyWarnings( rec.library_key, {
						hint: 'try {module}_{brand}, e.g. textiles_dickson',
						duplicates: ( state.list.items || [] ).map( ( l ) => l.library_key ),
					} )
					: [],
			} ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		// Capability-conditional fields
		const cond = [];
		if ( selectedModule && selectedModule.supports_brand ) {
			cond.push( textField( 'Brand', 'brand', rec.brand || '', ( v ) => {
				rec.brand = v;
				state.dirty = true;
			} ) );
		}
		if ( selectedModule && selectedModule.supports_collection ) {
			cond.push( textField( 'Collection', 'collection', rec.collection || '', ( v ) => {
				rec.collection = v;
				state.dirty = true;
			} ) );
		}
		if ( cond.length > 0 ) {
			wrap.appendChild( fieldset( 'Branding', cond ) );
		}

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

		// Footer
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{
					type: 'button',
					class: 'button button-primary',
					disabled: state.busy,
					onClick: saveLibrary,
				},
				state.busy ? 'Saving…' : 'Save library'
			),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Cancel' )
		) );

		return wrap;
	}

	function renderLibraryDetail() {
		const lib = state.library;
		const module = state.moduleOfLibrary || {};
		const wrap = el( 'div' );

		// Library metadata edit (compact)
		const meta = el( 'div', { class: 'configkit-form' } );
		meta.appendChild( el( 'h2', null, lib.name ) );
		meta.appendChild( el(
			'p',
			{ class: 'description' },
			'Module: ' + ( module.name || lib.module_key ) + ' · library_key: '
		) );
		meta.appendChild( el( 'code', null, lib.library_key ) );

		if ( state.message ) meta.appendChild( messageBanner( state.message ) );

		const editForm = state.editingLibrary || lib;
		meta.appendChild( fieldset( 'Library settings', [
			textField( 'Name', 'name', editForm.name, ( v ) => {
				editForm.name = v;
				state.dirty = true;
			} ),
			textareaField( 'Description', 'description', editForm.description || '', ( v ) => {
				editForm.description = v;
				state.dirty = true;
			} ),
			...( module.supports_brand
				? [ textField( 'Brand', 'brand', editForm.brand || '', ( v ) => {
					editForm.brand = v;
					state.dirty = true;
				} ) ]
				: [] ),
			...( module.supports_collection
				? [ textField( 'Collection', 'collection', editForm.collection || '', ( v ) => {
					editForm.collection = v;
					state.dirty = true;
				} ) ]
				: [] ),
			checkboxField( 'Active', 'is_active', !! editForm.is_active, ( v ) => {
				editForm.is_active = v;
				state.dirty = true;
			} ),
		] ) );
		meta.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{ type: 'button', class: 'button button-primary', disabled: state.busy, onClick: saveLibrary },
				state.busy ? 'Saving…' : 'Save library'
			),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Back to list' ),
			el(
				'button',
				{ type: 'button', class: 'button button-link-delete', onClick: softDeleteLibrary },
				'Soft delete library'
			)
		) );
		wrap.appendChild( meta );

		// Items section
		const itemsBlock = el( 'div', { class: 'configkit-form configkit-items' } );
		itemsBlock.appendChild( el(
			'div',
			{ class: 'configkit-list__header' },
			el( 'h3', null, 'Items' ),
			el(
				'button',
				{ type: 'button', class: 'button button-secondary', onClick: showNewItemForm },
				'+ New item'
			)
		) );

		const items = state.items.items || [];
		if ( items.length === 0 ) {
			itemsBlock.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No items yet.' )
			) );
		} else {
			const headers = [ 'Label', 'item_key' ];
			if ( module.supports_sku ) headers.push( 'SKU' );
			if ( module.supports_price ) headers.push( 'Price' );
			if ( module.supports_price_group ) headers.push( 'Price group' );
			headers.push( 'Status', '' );

			const table = el(
				'table',
				{ class: 'wp-list-table widefat striped configkit-items-table' }
			);
			const thead = el( 'thead' );
			const headRow = el( 'tr' );
			headers.forEach( ( h ) => headRow.appendChild( el( 'th', null, h ) ) );
			thead.appendChild( headRow );
			table.appendChild( thead );

			const tbody = el( 'tbody' );
			items.forEach( ( it ) => {
				const cells = [
					el(
						'td',
						{ 'data-label': 'Title' },
						el(
							'a',
							{
								href: '#',
								onClick: ( ev ) => {
									ev.preventDefault();
									loadItem( lib.id, it.id );
								},
							},
							it.label
						)
					),
					el( 'td', { 'data-label': 'item_key' }, el( 'code', null, it.item_key ) ),
				];
				if ( module.supports_sku ) cells.push( el( 'td', { 'data-label': 'SKU' }, it.sku || '—' ) );
				if ( module.supports_price ) {
					cells.push( el( 'td', { 'data-label': 'Price' }, it.price === null ? '—' : String( it.price ) ) );
				}
				if ( module.supports_price_group ) {
					cells.push( el( 'td', { 'data-label': 'Price group' }, it.price_group_key || '—' ) );
				}
				cells.push(
					el(
						'td',
						{ 'data-label': 'Status' },
						el(
							'span',
							{ class: 'configkit-badge configkit-badge--' + ( it.is_active ? 'active' : 'inactive' ) },
							it.is_active ? 'active' : 'inactive'
						)
					)
				);
				cells.push(
					el(
						'td',
						{ class: 'configkit-actions' },
						el(
							'button',
							{ type: 'button', class: 'button', onClick: () => loadItem( lib.id, it.id ) },
							'Edit'
						)
					)
				);
				const tr = el( 'tr' );
				cells.forEach( ( c ) => tr.appendChild( c ) );
				tbody.appendChild( tr );
			} );
			table.appendChild( tbody );
			itemsBlock.appendChild( table );
		}
		wrap.appendChild( itemsBlock );

		return wrap;
	}

	function renderItemForm() {
		const rec = state.editingItem;
		const module = state.moduleOfLibrary || {};
		const isNew = rec.id === 0;
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el( 'h2', null, isNew ? 'New item' : 'Edit item: ' + rec.label ) );

		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		// Phase 4.2b.2 — Item type picker. Lives at the very top so the
		// owner picks Single / Package before filling out anything else;
		// the rest of the form filters its fields off this choice.
		// Spec: UI_LABELS_MAPPING.md §3.
		wrap.appendChild( fieldset( 'Item type', [
			radioGroup(
				'Choose how this library item behaves',
				'item_type',
				rec.item_type || 'simple_option',
				[
					[ 'simple_option', ITEM_TYPE_LABELS.simple_option.label, ITEM_TYPE_LABELS.simple_option.help ],
					[ 'bundle',        ITEM_TYPE_LABELS.bundle.label,        ITEM_TYPE_LABELS.bundle.help ],
				],
				( v ) => {
					rec.item_type = v;
					// When toggling type, snap price_source to a value
					// that is valid under the new type. Spec: validation
					// in LibraryItemService::validate (Phase 4.2b.1).
					if ( v === 'bundle' && [ 'configkit', 'woo', 'product_override' ].includes( rec.price_source ) ) {
						rec.price_source = 'bundle_sum';
					}
					if ( v === 'simple_option' && [ 'bundle_sum', 'fixed_bundle' ].includes( rec.price_source ) ) {
						rec.price_source = 'configkit';
					}
					state.dirty = true;
					render();
				}
			),
		] ) );

		// Basics
		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Label', 'label', rec.label, ( v ) => {
				rec.label = v;
				state.dirty = true;
				if ( ! rec.item_key && isNew ) {
					rec.item_key = slugify( v );
					render();
				}
			} ),
			textField( 'Technical key', 'item_key', rec.item_key, ( v ) => {
				rec.item_key = v;
				state.dirty = true;
			}, { mono: true } ),
			textField( 'Short label', 'short_label', rec.short_label || '', ( v ) => {
				rec.short_label = v;
				state.dirty = true;
			} ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		// Phase 4.2b.2 — Pricing source picker. The five-value enum
		// becomes a filtered radio group: simple items see configkit /
		// woo (and product_override read-only when set externally);
		// packages see bundle_sum / fixed_bundle. Spec:
		// UI_LABELS_MAPPING.md §2 + PRICING_SOURCE_MODEL.md §2.
		const isBundle = rec.item_type === 'bundle';
		const priceChoices = isBundle
			? [
				[ 'bundle_sum',   PRICE_SOURCE_LABELS.bundle_sum.label,   PRICE_SOURCE_LABELS.bundle_sum.help ],
				[ 'fixed_bundle', PRICE_SOURCE_LABELS.fixed_bundle.label, PRICE_SOURCE_LABELS.fixed_bundle.help ],
			]
			: [
				[ 'configkit', PRICE_SOURCE_LABELS.configkit.label, PRICE_SOURCE_LABELS.configkit.help ],
				[ 'woo',       PRICE_SOURCE_LABELS.woo.label,       PRICE_SOURCE_LABELS.woo.help ],
			];
		// product_override is read-only — render an inert row when it's
		// the current value (a binding has applied it externally).
		const showProductOverrideRow = ! isBundle && rec.price_source === 'product_override';

		const pricingChildren = [
			radioGroup(
				'How should the price be calculated?',
				'price_source',
				rec.price_source || ( isBundle ? 'bundle_sum' : 'configkit' ),
				priceChoices,
				( v ) => {
					rec.price_source = v;
					if ( v !== 'fixed_bundle' ) rec.bundle_fixed_price = '';
					state.dirty = true;
					render();
				}
			),
		];
		if ( showProductOverrideRow ) {
			pricingChildren.push( el(
				'p',
				{ class: 'description configkit-price-source-override-note' },
				PRICE_SOURCE_LABELS.product_override.label + ' — ' + PRICE_SOURCE_LABELS.product_override.help
			) );
		}
		// Fixed package price field — visible only when the owner
		// picked the fixed-bundle source.
		if ( isBundle && rec.price_source === 'fixed_bundle' ) {
			pricingChildren.push( numberField(
				'Fixed package price (kr)',
				'bundle_fixed_price',
				rec.bundle_fixed_price === '' || rec.bundle_fixed_price === null ? 0 : rec.bundle_fixed_price,
				( v ) => {
					rec.bundle_fixed_price = v;
					state.dirty = true;
				},
				{ allowFloat: true, icon: 'money-alt', tooltip: 'Customer sees this price for the whole package.' }
			) );
		}
		wrap.appendChild( fieldset( 'Pricing', pricingChildren ) );

		// Phase 4.2b.2 — Resolved-price preview panel. Mounts an empty
		// host that the preview function fills in async; lives directly
		// under the Pricing fieldset so the owner sees the effect of
		// every choice. Spec: UI_LABELS_MAPPING.md §9.1.
		const resolvedHost = el( 'div', { class: 'configkit-resolved-price-panel', 'data-cf-resolved-price': '' } );
		wrap.appendChild( resolvedHost );
		schedulePricePreview( rec, resolvedHost );

		// Capability-conditional fields. Each tooltip explains the term
		// for owners who don't think in technical capabilities.
		const props = [];
		if ( module.supports_sku ) {
			props.push( textField( 'Unique code (SKU)', 'sku', rec.sku || '', ( v ) => {
				rec.sku = v;
				state.dirty = true;
			}, { mono: true, icon: 'tag', tooltip: 'Unique product code, e.g. DICK-U171.' } ) );
		}
		if ( module.supports_image ) {
			props.push( textField( 'Image URL', 'image_url', rec.image_url || '', ( v ) => {
				rec.image_url = v;
				state.dirty = true;
			}, { icon: 'format-image', tooltip: 'Small thumbnail shown in pickers and carts.' } ) );
		}
		if ( module.supports_main_image ) {
			props.push( textField( 'Main image URL', 'main_image_url', rec.main_image_url || '', ( v ) => {
				rec.main_image_url = v;
				state.dirty = true;
			}, { icon: 'cover-image', tooltip: 'Large hero image shown in detail views.' } ) );
		}
		// Phase 4.2b.2 — the Price field belongs to the "ConfigKit"
		// pricing source. Hidden when the owner picked a different
		// source (woo / bundle_sum / fixed_bundle); in those modes
		// price comes from elsewhere and a stale number here would be
		// confusing.
		if ( module.supports_price && rec.price_source === 'configkit' ) {
			props.push( numberField( 'Price (NOK)', 'price', rec.price === '' || rec.price === null ? 0 : rec.price, ( v ) => {
				rec.price = v;
				state.dirty = true;
			}, { allowFloat: true, icon: 'money-alt', tooltip: 'Base price in NOK. Use the sale price field for discounts.' } ) );
		}
		if ( module.supports_sale_price ) {
			props.push( numberField( 'Sale price (NOK)', 'sale_price', rec.sale_price === '' || rec.sale_price === null ? 0 : rec.sale_price, ( v ) => {
				rec.sale_price = v;
				state.dirty = true;
			}, { allowFloat: true, icon: 'tickets-alt', tooltip: 'Discounted price. Leave 0 / blank for no sale.' } ) );
		}
		if ( module.supports_price_group ) {
			props.push( textField( 'Price group (I, II, III…)', 'price_group_key', rec.price_group_key || '', ( v ) => {
				rec.price_group_key = v;
				state.dirty = true;
			}, { mono: true, icon: 'groups', tooltip: 'Bucket key (I, II, III…) used by lookup tables to pick a row.' } ) );
		}
		if ( module.supports_color_family ) {
			props.push( textField( 'Color family', 'color_family', rec.color_family || '', ( v ) => {
				rec.color_family = v;
				state.dirty = true;
			}, { icon: 'art', tooltip: 'Group label like "blue" / "green" / "neutral" for color filtering.' } ) );
		}
		if ( module.supports_woo_product_link ) {
			props.push( numberField( 'Linked Woo product ID', 'woo_product_id', rec.woo_product_id === '' || rec.woo_product_id === null ? 0 : rec.woo_product_id, ( v ) => {
				rec.woo_product_id = v;
				state.dirty = true;
			}, { icon: 'cart', tooltip: 'WooCommerce product ID this item maps to (for cart line items).' } ) );
		}
		if ( props.length > 0 ) {
			wrap.appendChild( fieldset( 'Properties', props ) );
		}

		// Phase 4.2b.2 — bundle composition editor. Visible only for
		// packages. Spec: UI_LABELS_MAPPING.md §4 (no JSON shown to
		// owner; per-component picker + qty + price source + optional
		// stock toggle and cart label).
		if ( isBundle ) {
			wrap.appendChild( fieldset(
				'Package contents',
				[ renderBundleComponents( rec ) ]
			) );

			// Package breakdown preview — UI_LABELS_MAPPING §9.2.
			const breakdownHost = el( 'div', { class: 'configkit-bundle-breakdown', 'data-cf-bundle-breakdown': '' } );
			wrap.appendChild( breakdownHost );
			scheduleBundleBreakdown( rec, breakdownHost );
		}

		// Phase 4.2b.2 — bundle-only behavior toggles. Hidden entirely
		// for simple items; the saved value stays at default until the
		// owner toggles the item to a package. Specs: UI_LABELS_MAPPING
		// §5 (cart) + §7 (admin order).
		if ( isBundle ) {
			wrap.appendChild( fieldset( 'Cart display', [
				radioGroup(
					'How should the package appear in the cart?',
					'cart_behavior',
					rec.cart_behavior || 'price_inside_main',
					[
						[ 'price_inside_main', CART_BEHAVIOR_LABELS.price_inside_main.label, CART_BEHAVIOR_LABELS.price_inside_main.help ],
						[ 'add_child_lines',   CART_BEHAVIOR_LABELS.add_child_lines.label,   CART_BEHAVIOR_LABELS.add_child_lines.help ],
					],
					( v ) => { rec.cart_behavior = v; state.dirty = true; render(); }
				),
			] ) );

			wrap.appendChild( fieldset( 'Admin order display', [
				radioGroup(
					'How should this package appear inside admin orders?',
					'admin_order_display',
					rec.admin_order_display || 'expanded',
					[
						[ 'expanded',  ADMIN_DISPLAY_LABELS.expanded.label,  ADMIN_DISPLAY_LABELS.expanded.help ],
						[ 'collapsed', ADMIN_DISPLAY_LABELS.collapsed.label, ADMIN_DISPLAY_LABELS.collapsed.help ],
					],
					( v ) => { rec.admin_order_display = v; state.dirty = true; render(); }
				),
			] ) );
		}

		// Tags
		const tagFields = [];
		if ( module.supports_filters ) {
			tagFields.push( tagsField( 'Filter tags (comma-separated)', 'filters', rec.filters || [], ( arr ) => {
				rec.filters = arr;
				state.dirty = true;
			}, { icon: 'filter' } ) );
		}
		if ( module.supports_compatibility ) {
			tagFields.push( tagsField( 'Compatibility tags', 'compatibility', rec.compatibility || [], ( arr ) => {
				rec.compatibility = arr;
				state.dirty = true;
			}, { icon: 'admin-links' } ) );
		}
		if ( tagFields.length > 0 ) {
			wrap.appendChild( fieldset( 'Tags', tagFields, { collapsible: true, collapsed: true } ) );
		}

		// Custom attributes per module schema
		const schema = module.attribute_schema || {};
		const schemaKeys = Object.keys( schema );
		if ( schemaKeys.length > 0 ) {
			const attrFields = schemaKeys.map( ( key ) => {
				const type = schema[ key ];
				const value = rec.attributes && rec.attributes[ key ] !== undefined ? rec.attributes[ key ] : '';
				if ( type === 'boolean' ) {
					return checkboxField( key + ' (boolean)', 'attr_' + key, !! value, ( v ) => {
						rec.attributes = Object.assign( {}, rec.attributes || {}, { [ key ]: v } );
						state.dirty = true;
					} );
				}
				if ( type === 'integer' ) {
					return numberField(
						key + ' (integer)',
						'attr_' + key,
						value === '' || value === null ? 0 : value,
						( v ) => {
							rec.attributes = Object.assign( {}, rec.attributes || {}, { [ key ]: parseInt( v, 10 ) || 0 } );
							state.dirty = true;
						}
					);
				}
				return textField( key + ' (string)', 'attr_' + key, value || '', ( v ) => {
					rec.attributes = Object.assign( {}, rec.attributes || {}, { [ key ]: v } );
					state.dirty = true;
				} );
			} );
			wrap.appendChild( fieldset( 'Attributes', attrFields, { collapsible: true, collapsed: true } ) );
		}

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

		// Footer
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{ type: 'button', class: 'button button-primary', disabled: state.busy, onClick: saveItem },
				state.busy ? 'Saving…' : 'Save item'
			),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToLibrary }, 'Cancel' ),
			rec.id > 0
				? el(
					'button',
					{ type: 'button', class: 'button button-link-delete', onClick: softDeleteItem },
					'Soft delete'
				)
				: null
		) );

		return wrap;
	}

	// ---- Form helpers ----

	function fieldset( legend, children, opts ) {
		opts = opts || {};
		if ( opts.collapsible ) {
			const body = el( 'div', { class: 'configkit-fieldset__body' }, ...children );
			const fs = el( 'fieldset', { class: 'configkit-fieldset' }, el( 'legend', null, legend ), body );
			if ( window.ConfigKit && window.ConfigKit.makeCollapsible ) {
				window.ConfigKit.makeCollapsible( fs, { collapsed: !! opts.collapsed } );
			}
			return fs;
		}
		return _fieldset( legend, children );
	}

	function _fieldset( legend, children ) {
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
		const labelNode = el( 'label', { for: 'cf_' + name } );
		if ( opts.icon ) {
			labelNode.appendChild( el( 'span', {
				class: 'dashicons dashicons-' + opts.icon + ' configkit-cap-icon configkit-cap-icon--checked',
				'aria-hidden': 'true',
			} ) );
		}
		labelNode.appendChild( document.createTextNode( label ) );
		if ( opts.tooltip && window.ConfigKit && window.ConfigKit.help ) {
			labelNode.appendChild( window.ConfigKit.help( opts.tooltip ) );
		}
		return el(
			'div',
			{ class: 'configkit-field' },
			labelNode,
			el( 'input', {
				id: 'cf_' + name,
				type: 'text',
				class: opts.mono ? 'regular-text code' : 'regular-text',
				value: value || '',
				onInput: ( ev ) => onChange( ev.target.value ),
			} ),
			opts.help ? el( 'p', { class: 'description' }, opts.help ) : null,
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

	function checkboxField( label, name, checked, onChange ) {
		return el(
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
	}

	function numberField( label, name, value, onChange, opts ) {
		opts = opts || {};
		const labelNode = el( 'label', { for: 'cf_' + name } );
		if ( opts.icon ) {
			labelNode.appendChild( el( 'span', {
				class: 'dashicons dashicons-' + opts.icon + ' configkit-cap-icon configkit-cap-icon--checked',
				'aria-hidden': 'true',
			} ) );
		}
		labelNode.appendChild( document.createTextNode( label ) );
		if ( opts.tooltip && window.ConfigKit && window.ConfigKit.help ) {
			labelNode.appendChild( window.ConfigKit.help( opts.tooltip ) );
		}
		return el(
			'div',
			{ class: 'configkit-field configkit-field--inline' },
			labelNode,
			el( 'input', {
				id: 'cf_' + name,
				type: 'number',
				step: opts.allowFloat ? '0.01' : '1',
				value: String( value === null || value === undefined ? '' : value ),
				onInput: ( ev ) => {
					const raw = ev.target.value;
					if ( raw === '' ) {
						onChange( '' );
						return;
					}
					onChange( opts.allowFloat ? parseFloat( raw ) : parseInt( raw, 10 ) || 0 );
				},
			} )
		);
	}

	// Phase 4.2b.2 — preview state. The price-preview panel and the
	// bundle-breakdown panel both call /library-items/preview-price.
	// We debounce per-host (200 ms) and cancel stale responses by
	// generation count so the latest input always wins.
	const previewState = {
		debounceHandles: new WeakMap(),
		generation: new WeakMap(),
	};

	function schedulePricePreview( rec, host ) {
		const prev = previewState.debounceHandles.get( host );
		if ( prev ) clearTimeout( prev );

		// Show a minimal placeholder right away so the panel doesn't
		// flash empty between renders.
		renderResolvedPricePanel( host, { kind: 'loading' } );

		const handle = setTimeout( () => {
			runPreview( rec, host, ( data ) => {
				renderResolvedPricePanel( host, { kind: 'ok', data } );
			} );
		}, 200 );
		previewState.debounceHandles.set( host, handle );
	}

	function scheduleBundleBreakdown( rec, host ) {
		const prev = previewState.debounceHandles.get( host );
		if ( prev ) clearTimeout( prev );

		renderBundleBreakdownPanel( host, { kind: 'loading' } );

		const handle = setTimeout( () => {
			runPreview( rec, host, ( data ) => {
				renderBundleBreakdownPanel( host, { kind: 'ok', data } );
			} );
		}, 200 );
		previewState.debounceHandles.set( host, handle );
	}

	function runPreview( rec, host, onReady ) {
		const generation = ( previewState.generation.get( host ) || 0 ) + 1;
		previewState.generation.set( host, generation );

		const payload = {
			library_item: {
				library_key:        ( state.library && state.library.library_key ) || '',
				item_key:           rec.item_key || '',
				item_type:          rec.item_type || 'simple_option',
				price_source:       rec.price_source || 'configkit',
				price:              rec.price === '' ? null : rec.price,
				woo_product_id:     rec.woo_product_id === '' ? null : rec.woo_product_id,
				bundle_fixed_price: rec.bundle_fixed_price === '' ? null : rec.bundle_fixed_price,
				bundle_components:  Array.isArray( rec.bundle_components ) ? rec.bundle_components.map( ( c ) => ( {
					component_key:  c.component_key || '',
					woo_product_id: c.woo_product_id || 0,
					qty:            c.qty || 1,
					price_source:   c.price_source || 'woo',
					price:          c.price === '' ? null : c.price,
				} ) ) : [],
			},
		};

		ConfigKit.request( '/library-items/preview-price', { method: 'POST', body: payload } )
			.then( ( data ) => {
				if ( previewState.generation.get( host ) !== generation ) return;
				onReady( data );
			} )
			.catch( ( err ) => {
				if ( previewState.generation.get( host ) !== generation ) return;
				renderResolvedPricePanel( host, {
					kind: 'error',
					message: ( err && err.message ) || 'Preview failed.',
				} );
			} );
	}

	function renderResolvedPricePanel( host, snapshot ) {
		host.innerHTML = '';
		host.appendChild( el( 'h4', { class: 'configkit-resolved-price-panel__title' }, 'Resolved price preview' ) );
		if ( snapshot.kind === 'loading' ) {
			host.appendChild( el( 'p', { class: 'configkit-resolved-price-panel__body' }, 'Calculating…' ) );
			return;
		}
		if ( snapshot.kind === 'error' ) {
			host.appendChild( el( 'p', { class: 'configkit-resolved-price-panel__body is-error' }, snapshot.message ) );
			return;
		}
		const data = snapshot.data || {};
		const resolved = data.resolved_price;
		const source   = data.price_source || 'configkit';

		if ( resolved === null || resolved === undefined ) {
			host.appendChild( el(
				'p',
				{ class: 'configkit-resolved-price-panel__body is-warning' },
				priceMissingMessage( source )
			) );
			return;
		}
		const para = el( 'p', { class: 'configkit-resolved-price-panel__body' } );
		para.appendChild( document.createTextNode( 'If a customer picks this item now, the price will be: ' ) );
		para.appendChild( el( 'strong', null, formatKr( resolved ) ) );
		host.appendChild( para );

		if ( source === 'woo' ) {
			host.appendChild( el(
				'p',
				{ class: 'configkit-resolved-price-panel__note' },
				'(read from WooCommerce — frozen at add-to-cart)'
			) );
		}
		if ( source === 'product_override' ) {
			host.appendChild( el(
				'p',
				{ class: 'configkit-resolved-price-panel__note' },
				'Per-product overrides apply only on specific Woo products.'
			) );
		}
	}

	function priceMissingMessage( source ) {
		if ( source === 'woo' )           return 'No price yet — pick a WooCommerce product with a price.';
		if ( source === 'configkit' )     return 'No price yet — enter a price above.';
		if ( source === 'fixed_bundle' )  return 'No price yet — enter a fixed package price above.';
		if ( source === 'bundle_sum' )    return 'No price yet — at least one component is missing a price.';
		return 'No price yet.';
	}

	function renderBundleBreakdownPanel( host, snapshot ) {
		host.innerHTML = '';
		host.appendChild( el( 'h4', { class: 'configkit-bundle-breakdown__title' }, 'Package breakdown' ) );

		if ( snapshot.kind === 'loading' ) {
			host.appendChild( el( 'p', { class: 'description' }, 'Calculating…' ) );
			return;
		}
		if ( snapshot.kind === 'error' ) {
			host.appendChild( el( 'p', { class: 'description is-error' }, snapshot.message || 'Preview failed.' ) );
			return;
		}
		const data = ( snapshot.data && snapshot.data.breakdown ) || null;
		if ( ! data || ! Array.isArray( data.components ) || data.components.length === 0 ) {
			host.appendChild( el( 'p', { class: 'description' }, 'Add components above to see the package breakdown.' ) );
			return;
		}

		const table = el( 'table', { class: 'configkit-bundle-breakdown__table' } );
		const thead = el( 'thead', null,
			el( 'tr', null,
				el( 'th', null, 'Component' ),
				el( 'th', null, 'Qty' ),
				el( 'th', null, 'Source' ),
				el( 'th', { class: 'configkit-bundle-breakdown__num' }, 'Resolved' ),
				el( 'th', { class: 'configkit-bundle-breakdown__num' }, 'Subtotal' )
			)
		);
		table.appendChild( thead );

		const tbody = el( 'tbody' );
		data.components.forEach( ( c ) => {
			const sourceLabel = ( PRICE_SOURCE_LABELS[ c.price_source ] || {} ).label || '—';
			const meta = wooMetaCache[ c.woo_product_id ];
			const componentLabel = meta && ! meta._loading
				? meta.name
				: ( c.component_key || ( '#' + ( c.woo_product_id || 0 ) ) );
			tbody.appendChild( el( 'tr', null,
				el( 'td', null, componentLabel ),
				el( 'td', null, String( c.qty || 1 ) ),
				el( 'td', null, sourceLabel ),
				el( 'td', { class: 'configkit-bundle-breakdown__num' }, c.unit_price === null ? '—' : formatKr( c.unit_price ) ),
				el( 'td', { class: 'configkit-bundle-breakdown__num' }, c.subtotal === null ? '—' : formatKr( c.subtotal ) )
			) );
		} );
		table.appendChild( tbody );
		host.appendChild( table );

		const totalsRow = el( 'p', { class: 'configkit-bundle-breakdown__totals' } );
		if ( data.price_source === 'fixed_bundle' ) {
			totalsRow.appendChild( document.createTextNode( 'Total: ' ) );
			totalsRow.appendChild( el( 'strong', null,
				data.fixed_bundle_price === null ? '—' : 'Fixed at ' + formatKr( data.fixed_bundle_price )
			) );
			totalsRow.appendChild( el( 'span', { class: 'configkit-bundle-breakdown__totals-note' },
				' (component prices shown above are for stock and order-line accounting only)'
			) );
		} else {
			totalsRow.appendChild( document.createTextNode( 'Total: ' ) );
			totalsRow.appendChild( el( 'strong', null,
				data.total === null ? '—' : formatKr( data.total )
			) );
		}
		host.appendChild( totalsRow );
	}

	// Phase 4.2b.2 — cache of resolved Woo product rows keyed by id,
	// so re-renders don't re-hit /woo-products/{id} for the same id.
	// Hydrated lazily per row. Cleared on view change implicitly
	// because libraries.js is a single-page app instance per page load.
	const wooMetaCache = {};

	function ensureWooMeta( id, onReady ) {
		if ( ! id || id <= 0 ) return null;
		if ( wooMetaCache[ id ] ) return wooMetaCache[ id ];
		// Mark as in-flight so concurrent re-renders don't refetch.
		wooMetaCache[ id ] = wooMetaCache[ id ] || { id, name: '', sku: '', price: null, thumbnail_url: null, _loading: true };
		ConfigKit.request( '/woo-products/' + id ).then( ( data ) => {
			if ( data && data.record ) {
				wooMetaCache[ id ] = Object.assign( {}, data.record, { _loading: false } );
				onReady && onReady();
			}
		} ).catch( () => {
			wooMetaCache[ id ] = { id, name: '#' + id + ' (unavailable)', sku: '', price: null, thumbnail_url: null, _loading: false, _error: true };
			onReady && onReady();
		} );
		return wooMetaCache[ id ];
	}

	/**
	 * Phase 4.2b.2 — bundle composition editor (UI_LABELS_MAPPING §4).
	 * Each row exposes:
	 *   - WooCommerce product picker (reusable JS module)
	 *   - Quantity (positive integer)
	 *   - Per-component price source dropdown (woo / configkit / fixed_bundle)
	 *   - Resolved-price preview (live)
	 *   - Optional "Display name in cart" small input
	 *   - Optional "Check stock for this component" toggle
	 * Plus a single "+ Add component" button at the bottom and a
	 * leading helper paragraph that explains stock semantics.
	 */
	function renderBundleComponents( rec ) {
		const wrap = el( 'div', { class: 'configkit-bundle-editor' } );

		wrap.appendChild( el(
			'p',
			{ class: 'description' },
			'Stock is checked only when WooCommerce stock management is enabled for that component product. ' +
			'If a component has stock management off, its stock is not blocking — the order proceeds.'
		) );

		const list = Array.isArray( rec.bundle_components ) ? rec.bundle_components : [];
		if ( list.length === 0 ) {
			wrap.appendChild( el(
				'p',
				{ class: 'configkit-bundle-editor__empty' },
				'No components yet. Pick the WooCommerce products this package combines.'
			) );
		}

		list.forEach( ( component, index ) => {
			wrap.appendChild( renderBundleComponentRow( rec, component, index ) );
		} );

		wrap.appendChild( el(
			'button',
			{
				type: 'button',
				class: 'button button-secondary configkit-bundle-editor__add',
				onClick: () => {
					rec.bundle_components = list.concat( [ blankBundleComponent() ] );
					state.dirty = true;
					render();
				},
			},
			'+ Add component'
		) );

		return wrap;
	}

	function blankBundleComponent() {
		return {
			component_key: '',
			woo_product_id: 0,
			qty: 1,
			price_source: 'woo',
			price: null,
			stock_behavior: 'check_components',
			label_in_cart: '',
		};
	}

	function renderBundleComponentRow( rec, component, index ) {
		const row = el( 'div', { class: 'configkit-bundle-component-row' } );
		const top = el( 'div', { class: 'configkit-bundle-component-row__top' } );

		// Woo product picker (mounts via window.ConfigKit.createWooProductPicker
		// on the host node). The host stays empty until after-mount; after
		// the picker injects its DOM the row layout aligns.
		const pickerHost = el( 'div', { class: 'configkit-bundle-component-row__picker' } );
		top.appendChild( pickerHost );

		// Hydrate initial selection from the row's stored woo_product_id.
		let initial = null;
		if ( component.woo_product_id && component.woo_product_id > 0 ) {
			const meta = ensureWooMeta( component.woo_product_id, () => render() );
			if ( meta && ! meta._loading ) initial = meta;
			else initial = { id: component.woo_product_id, name: 'Loading…', sku: '', price: null, thumbnail_url: null };
		}

		if ( window.ConfigKit && window.ConfigKit.createWooProductPicker ) {
			const picker = window.ConfigKit.createWooProductPicker( {
				mount: pickerHost,
				initial,
				placeholder: 'Search WooCommerce product…',
				onChange: ( selection ) => {
					if ( selection ) {
						component.woo_product_id = selection.id;
						wooMetaCache[ selection.id ] = Object.assign( {}, selection, { _loading: false } );
						if ( ! component.component_key ) {
							component.component_key = generateComponentKey( rec, selection.name || ( '#' + selection.id ) );
						}
					} else {
						component.woo_product_id = 0;
					}
					state.dirty = true;
					render();
				},
			} );
			// Keep linter happy.
			void picker;
		} else {
			pickerHost.appendChild( el( 'em', null, 'Picker unavailable (asset not loaded).' ) );
		}

		// Qty stepper.
		top.appendChild( el(
			'div',
			{ class: 'configkit-bundle-component-row__qty' },
			el( 'label', null, 'Quantity' ),
			el( 'input', {
				type: 'number',
				min: 1,
				step: 1,
				value: String( component.qty || 1 ),
				onInput: ( ev ) => {
					const n = parseInt( ev.target.value, 10 );
					component.qty = Number.isFinite( n ) && n > 0 ? n : 1;
					state.dirty = true;
				},
			} )
		) );

		// Per-component price source dropdown. Owner sees labels;
		// the option's value is the backend enum.
		top.appendChild( el(
			'div',
			{ class: 'configkit-bundle-component-row__source' },
			el( 'label', null, 'Price source for this component' ),
			selectField(
				component.price_source || 'woo',
				[
					[ 'woo',          PRICE_SOURCE_LABELS.woo.label ],
					[ 'configkit',    PRICE_SOURCE_LABELS.configkit.label ],
					[ 'fixed_bundle', 'Use the package fixed price (no separate component price)' ],
				],
				( v ) => {
					component.price_source = v;
					if ( v !== 'configkit' ) component.price = null;
					state.dirty = true;
					render();
				}
			)
		) );

		// Resolved preview chip (best-effort live; the full
		// breakdown panel — CHUNK 4 — does the authoritative compute).
		top.appendChild( el(
			'div',
			{ class: 'configkit-bundle-component-row__resolved' },
			el( 'span', { class: 'configkit-bundle-component-row__resolved-label' }, 'Resolved' ),
			el( 'span', { class: 'configkit-bundle-component-row__resolved-value' }, formatComponentResolved( rec, component ) )
		) );

		// Remove button.
		top.appendChild( el(
			'button',
			{
				type: 'button',
				class: 'configkit-bundle-component-row__remove',
				'aria-label': 'Remove component',
				onClick: () => {
					rec.bundle_components = rec.bundle_components.filter( ( _, i ) => i !== index );
					state.dirty = true;
					render();
				},
			},
			'✕'
		) );

		row.appendChild( top );

		// Bottom row: optional configkit price input (when source = configkit),
		// label_in_cart small input, stock toggle.
		const bottom = el( 'div', { class: 'configkit-bundle-component-row__bottom' } );

		if ( component.price_source === 'configkit' ) {
			bottom.appendChild( el(
				'div',
				{ class: 'configkit-bundle-component-row__price' },
				el( 'label', null, 'Price (kr)' ),
				el( 'input', {
					type: 'number',
					step: '0.01',
					value: String( component.price === null || component.price === undefined ? '' : component.price ),
					onInput: ( ev ) => {
						const raw = ev.target.value;
						component.price = raw === '' ? null : parseFloat( raw );
						state.dirty = true;
					},
				} )
			) );
		}

		bottom.appendChild( el(
			'div',
			{ class: 'configkit-bundle-component-row__cart-label' },
			el( 'label', null, 'Display name in cart (optional)' ),
			el( 'input', {
				type: 'text',
				class: 'regular-text',
				value: component.label_in_cart || '',
				onInput: ( ev ) => {
					component.label_in_cart = ev.target.value;
					state.dirty = true;
				},
			} )
		) );

		bottom.appendChild( el(
			'label',
			{ class: 'configkit-bundle-component-row__stock' },
			el( 'input', {
				type: 'checkbox',
				checked: ( component.stock_behavior || 'check_components' ) === 'check_components',
				onChange: ( ev ) => {
					component.stock_behavior = ev.target.checked ? 'check_components' : 'ignore';
					state.dirty = true;
				},
			} ),
			' Check stock for this component'
		) );

		row.appendChild( bottom );
		return row;
	}

	function generateComponentKey( rec, productName ) {
		const base = slugify( productName ) || 'component';
		const taken = ( rec.bundle_components || [] ).map( ( c ) => c.component_key );
		if ( ! taken.includes( base ) ) return base;
		let i = 2;
		while ( taken.includes( base + '_' + i ) ) i++;
		return base + '_' + i;
	}

	function formatComponentResolved( rec, component ) {
		if ( rec.price_source === 'fixed_bundle' ) return '— (fixed package)';
		const qty = component.qty || 1;
		if ( component.price_source === 'configkit' ) {
			if ( component.price === null || component.price === undefined || component.price === '' ) return '—';
			return formatKr( Number( component.price ) * qty );
		}
		if ( component.price_source === 'woo' ) {
			const meta = wooMetaCache[ component.woo_product_id ];
			if ( ! meta || meta._loading ) return 'loading…';
			if ( meta.price === null || meta.price === undefined ) return '—';
			return formatKr( meta.price * qty );
		}
		// fixed_bundle component — accounted for in the package fixed price.
		return '— (in fixed price)';
	}

	function formatKr( num ) {
		if ( ! Number.isFinite( num ) ) return '—';
		return num.toLocaleString( undefined, { maximumFractionDigits: 0 } ) + ' kr';
	}

	/**
	 * Phase 4.2b.2 — vertical radio group with helper text under the
	 * selected option. `choices` is a list of [value, label, helpText].
	 * Renders nothing about the underlying enum value to the owner —
	 * just the labels — per UI_LABELS_MAPPING.md.
	 */
	function radioGroup( legend, name, value, choices, onChange ) {
		const group = el( 'div', { class: 'configkit-radio-group', role: 'radiogroup', 'aria-labelledby': 'cf_' + name + '_legend' } );
		group.appendChild( el( 'div', { class: 'configkit-radio-group__legend', id: 'cf_' + name + '_legend' }, legend ) );
		choices.forEach( ( [ v, label, helpText ] ) => {
			const id = 'cf_' + name + '_' + v;
			const checked = v === value;
			const row = el( 'label', { class: 'configkit-radio-row' + ( checked ? ' is-checked' : '' ), for: id } );
			row.appendChild( el( 'input', {
				id,
				type: 'radio',
				name: 'cf_' + name,
				value: v,
				checked,
				onChange: ( ev ) => { if ( ev.target.checked ) onChange( v ); },
			} ) );
			const meta = el( 'span', { class: 'configkit-radio-row__meta' } );
			meta.appendChild( el( 'span', { class: 'configkit-radio-row__label' }, label ) );
			if ( helpText ) {
				meta.appendChild( el( 'span', { class: 'configkit-radio-row__help' }, helpText ) );
			}
			row.appendChild( meta );
			group.appendChild( row );
		} );
		return group;
	}

	function selectField( value, choices, onChange, disabled ) {
		const select = el( 'select', {
			disabled: !! disabled,
			onChange: ( ev ) => onChange( ev.target.value ),
		} );
		select.appendChild( el( 'option', { value: '' }, '— Select —' ) );
		choices.forEach( ( [ v, label ] ) => {
			const o = el( 'option', { value: v }, label );
			if ( v === value ) o.selected = true;
			select.appendChild( o );
		} );
		return select;
	}

	function tagsField( label, name, values, onChange, opts ) {
		opts = opts || {};
		const list = ( values || [] ).slice();
		const labelNode = el( 'label', null );
		if ( opts.icon ) {
			labelNode.appendChild( el( 'span', {
				class: 'dashicons dashicons-' + opts.icon + ' configkit-cap-icon configkit-cap-icon--checked',
				'aria-hidden': 'true',
			} ) );
		}
		labelNode.appendChild( document.createTextNode( label ) );
		const wrap = el( 'div', { class: 'configkit-field' }, labelNode );
		const chipBox = el( 'div', { class: 'configkit-chips' } );

		function rerender() {
			chipBox.replaceChildren();
			list.forEach( ( tag, idx ) => {
				chipBox.appendChild( el(
					'span',
					{ class: 'configkit-chip' },
					tag,
					' ',
					el(
						'button',
						{
							type: 'button',
							class: 'configkit-chip__remove',
							'aria-label': 'Remove ' + tag,
							onClick: () => {
								list.splice( idx, 1 );
								onChange( list );
								rerender();
							},
						},
						'×'
					)
				) );
			} );
		}

		const input = el( 'input', {
			type: 'text',
			placeholder: 'Add tag and press Enter',
			class: 'regular-text',
			onKeydown: ( ev ) => {
				if ( ev.key !== 'Enter' ) return;
				ev.preventDefault();
				const v = ev.target.value.trim();
				if ( v && ! list.includes( v ) ) {
					list.push( v );
					onChange( list );
					rerender();
				}
				ev.target.value = '';
			},
		} );

		wrap.appendChild( chipBox );
		wrap.appendChild( input );
		rerender();
		return wrap;
	}

	// ---- Init ----

	function init() {
		const params = new URLSearchParams( window.location.search );
		const action = params.get( 'action' );
		const id = parseInt( params.get( 'id' ) || '0', 10 );
		const itemId = parseInt( params.get( 'item_id' ) || '0', 10 );
		const itemAction = params.get( 'item_action' );

		const savedMessage = consumeSavedFlag();

		if ( action === 'new' ) {
			showNewLibraryForm();
		} else if ( id > 0 && itemId > 0 ) {
			loadItem( id, itemId );
		} else if ( id > 0 && itemAction === 'new' ) {
			loadLibrary( id ).then( () => {
				showNewItemForm();
			} );
		} else if ( id > 0 ) {
			loadLibrary( id ).then( () => {
				if ( savedMessage ) {
					state.message = { kind: 'success', text: savedMessage };
					render();
				}
			} );
		} else {
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
