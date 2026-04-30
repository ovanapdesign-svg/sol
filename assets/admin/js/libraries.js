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
		};
	}

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

		if ( state.view === 'loading' ) {
			root.appendChild( el( 'p', { class: 'configkit-app__loading' }, 'Loading…' ) );
			return;
		}
		if ( state.view === 'list' ) root.appendChild( renderList() );
		else if ( state.view === 'library_form' ) root.appendChild( renderLibraryForm() );
		else if ( state.view === 'library_detail' ) root.appendChild( renderLibraryDetail() );
		else if ( state.view === 'item_form' ) root.appendChild( renderItemForm() );
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
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No libraries yet.' ),
				el(
					'p',
					{ class: 'configkit-empty__hint' },
					'A library is a concrete dataset belonging to a module. Create a module first if you have not already, then add libraries to it.'
				)
			) );
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
						el( 'th', null, 'library_key' ),
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
					el( 'td', { 'data-label': 'library_key' }, el( 'code', null, lib.library_key ) ),
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
			textField( 'library_key', 'library_key', rec.library_key, ( v ) => {
				rec.library_key = v;
				state.dirty = true;
			}, { mono: true } ),
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
			textField( 'item_key', 'item_key', rec.item_key, ( v ) => {
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

		// Capability-conditional fields
		const props = [];
		if ( module.supports_sku ) {
			props.push( textField( 'SKU', 'sku', rec.sku || '', ( v ) => {
				rec.sku = v;
				state.dirty = true;
			}, { mono: true } ) );
		}
		if ( module.supports_image ) {
			props.push( textField( 'Image URL', 'image_url', rec.image_url || '', ( v ) => {
				rec.image_url = v;
				state.dirty = true;
			} ) );
		}
		if ( module.supports_main_image ) {
			props.push( textField( 'Main image URL', 'main_image_url', rec.main_image_url || '', ( v ) => {
				rec.main_image_url = v;
				state.dirty = true;
			} ) );
		}
		if ( module.supports_price ) {
			props.push( numberField( 'Price (NOK)', 'price', rec.price === '' || rec.price === null ? 0 : rec.price, ( v ) => {
				rec.price = v;
				state.dirty = true;
			}, { allowFloat: true } ) );
		}
		if ( module.supports_sale_price ) {
			props.push( numberField( 'Sale price (NOK)', 'sale_price', rec.sale_price === '' || rec.sale_price === null ? 0 : rec.sale_price, ( v ) => {
				rec.sale_price = v;
				state.dirty = true;
			}, { allowFloat: true } ) );
		}
		if ( module.supports_price_group ) {
			props.push( textField( 'Price group key', 'price_group_key', rec.price_group_key || '', ( v ) => {
				rec.price_group_key = v;
				state.dirty = true;
			}, { mono: true } ) );
		}
		if ( module.supports_color_family ) {
			props.push( textField( 'Color family', 'color_family', rec.color_family || '', ( v ) => {
				rec.color_family = v;
				state.dirty = true;
			} ) );
		}
		if ( module.supports_woo_product_link ) {
			props.push( numberField( 'Linked Woo product ID', 'woo_product_id', rec.woo_product_id === '' || rec.woo_product_id === null ? 0 : rec.woo_product_id, ( v ) => {
				rec.woo_product_id = v;
				state.dirty = true;
			} ) );
		}
		if ( props.length > 0 ) {
			wrap.appendChild( fieldset( 'Properties', props ) );
		}

		// Tags
		const tagFields = [];
		if ( module.supports_filters ) {
			tagFields.push( tagsField( 'Filter tags', 'filters', rec.filters || [], ( arr ) => {
				rec.filters = arr;
				state.dirty = true;
			} ) );
		}
		if ( module.supports_compatibility ) {
			tagFields.push( tagsField( 'Compatibility tags', 'compatibility', rec.compatibility || [], ( arr ) => {
				rec.compatibility = arr;
				state.dirty = true;
			} ) );
		}
		if ( tagFields.length > 0 ) {
			wrap.appendChild( fieldset( 'Tags', tagFields ) );
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
			wrap.appendChild( fieldset( 'Attributes', attrFields ) );
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

	function fieldset( legend, children ) {
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
		return el(
			'div',
			{ class: 'configkit-field configkit-field--inline' },
			el( 'label', { for: 'cf_' + name }, label ),
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

	function tagsField( label, name, values, onChange ) {
		const list = ( values || [] ).slice();
		const wrap = el( 'div', { class: 'configkit-field' }, el( 'label', null, label ) );
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
