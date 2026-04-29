/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-modules-app' );
	if ( ! root ) {
		return;
	}

	const CAPABILITY_FLAGS = [
		[ 'supports_sku', 'SKU' ],
		[ 'supports_image', 'Thumbnail image' ],
		[ 'supports_main_image', 'Hero / main image' ],
		[ 'supports_price', 'Price' ],
		[ 'supports_sale_price', 'Sale price' ],
		[ 'supports_filters', 'Filter tags' ],
		[ 'supports_compatibility', 'Compatibility tags' ],
		[ 'supports_price_group', 'Price group' ],
		[ 'supports_brand', 'Brand' ],
		[ 'supports_collection', 'Collection' ],
		[ 'supports_color_family', 'Color family' ],
		[ 'supports_woo_product_link', 'Linked Woo product' ],
	];

	const FIELD_KINDS = [ 'input', 'display', 'computed', 'addon', 'lookup' ];

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
		state.view = 'form';
		state.editing = blankRecord();
		state.dirty = false;
		clearMessages();
		setUrl( { action: 'new', id: null } );
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
			redirectToList( wasNew ? 'created' : 'updated' );
			return;
		} catch ( err ) {
			showError( err );
		}

		state.busy = false;
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
		const desc = window.ConfigKit.describeError( err );
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
			wrap.appendChild(
				el(
					'div',
					{ class: 'configkit-empty' },
					el( 'p', null, 'No modules yet.' ),
					el(
						'p',
						{ class: 'configkit-empty__hint' },
						'A module declares a kind of option group (textiles, motors, colors, etc.). Create one to start adding libraries.'
					)
				)
			);
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
						null,
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
					el( 'td', null, el( 'code', null, m.module_key ) ),
					el( 'td', null, capCount + ' / ' + CAPABILITY_FLAGS.length ),
					el(
						'td',
						null,
						( m.allowed_field_kinds || [] ).join( ', ' ) || '—'
					),
					el(
						'td',
						null,
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
			}, { mono: true, help: 'Lowercase, snake_case, max 64 chars. Once saved this is the stable identity for libraries and rules.' } ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		// Capabilities
		const capChecks = CAPABILITY_FLAGS.map( ( [ key, label ] ) =>
			checkboxField( label, key, !! rec[ key ], ( v ) => {
				rec[ key ] = v;
				state.dirty = true;
			} )
		);
		wrap.appendChild( fieldset( 'Capabilities', [
			el(
				'div',
				{ class: 'configkit-grid configkit-grid--3' },
				...capChecks
			),
		] ) );

		// Allowed field kinds
		const kindChecks = FIELD_KINDS.map( ( kind ) =>
			checkboxField(
				kind,
				'kind_' + kind,
				( rec.allowed_field_kinds || [] ).includes( kind ),
				( v ) => {
					const list = ( rec.allowed_field_kinds || [] ).filter( ( k ) => k !== kind );
					if ( v ) list.push( kind );
					rec.allowed_field_kinds = list;
					state.dirty = true;
				}
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
		] ) );

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
