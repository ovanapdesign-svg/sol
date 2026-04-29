/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-families-app' );
	if ( ! root ) return;

	const state = {
		view: 'loading', // 'list' | 'form' | 'loading'
		list: { items: [], total: 0 },
		editing: null,
		dirty: false,
		message: null,
		fieldErrors: {},
		busy: false,
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

	function slugify( s ) {
		if ( ! s ) return '';
		let out = s.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
		out = out.replace( /^[^a-z]+/, '' );
		return out.slice( 0, 64 );
	}

	function setUrl( params ) {
		const url = new URL( window.location.href );
		Object.keys( params ).forEach( ( k ) => {
			if ( params[ k ] === null || params[ k ] === undefined ) url.searchParams.delete( k );
			else url.searchParams.set( k, params[ k ] );
		} );
		window.history.replaceState( null, '', url.toString() );
	}

	function clearMessages() {
		state.message = null;
		state.fieldErrors = {};
	}

	function consumeSavedFlag() {
		const params = new URLSearchParams( window.location.search );
		const v = params.get( 'saved' );
		if ( ! v ) return null;
		setUrl( { saved: null } );
		return ( {
			created: 'Family created.',
			updated: 'Family updated.',
		} )[ v ] || null;
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

	async function loadList() {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/families?per_page=500' );
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

	async function loadFamily( id ) {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/families/' + id );
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
		return {
			id: 0,
			family_key: '',
			name: '',
			description: '',
			is_active: true,
			version_hash: '',
		};
	}

	async function save() {
		if ( state.busy ) return;
		state.busy = true;
		render();

		const rec = state.editing;
		const wasNew = ! ( rec.id > 0 );
		const payload = {
			family_key: rec.family_key,
			name: rec.name,
			description: rec.description || null,
			is_active: !! rec.is_active,
		};

		let success = false;
		try {
			if ( rec.id > 0 ) {
				payload.version_hash = rec.version_hash;
				await ConfigKit.request( '/families/' + rec.id, { method: 'PUT', body: payload } );
			} else {
				await ConfigKit.request( '/families', { method: 'POST', body: payload } );
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

	async function softDelete() {
		if ( ! state.editing || ! state.editing.id ) return;
		if ( ! window.confirm( 'Soft-delete this family? It will be marked inactive.' ) ) return;
		try {
			await ConfigKit.request( '/families/' + state.editing.id, { method: 'DELETE' } );
			redirectToList( 'updated' );
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	function redirectToList( flag ) {
		const url = new URL( window.location.href );
		[ 'action', 'id' ].forEach( ( p ) => url.searchParams.delete( p ) );
		url.searchParams.set( 'saved', flag );
		window.location.href = url.toString();
	}

	function cancelToList() {
		if ( state.dirty && ! window.confirm( 'Discard unsaved changes?' ) ) return;
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
		if ( state.view === 'list' ) root.appendChild( renderList() );
		else if ( state.view === 'form' ) root.appendChild( renderForm() );
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
			el( 'button', { type: 'button', class: 'button button-primary', onClick: showNewForm }, '+ New family' )
		) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		const items = state.list.items || [];
		if ( items.length === 0 ) {
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No families yet.' ),
				el( 'p', { class: 'configkit-empty__hint' }, 'Families group templates by product taxonomy. Create one to start binding products to templates.' )
			) );
			return wrap;
		}

		const table = el(
			'table',
			{ class: 'wp-list-table widefat striped configkit-families-table' },
			el(
				'thead',
				null,
				el(
					'tr',
					null,
					el( 'th', null, 'Name' ),
					el( 'th', null, 'family_key' ),
					el( 'th', null, 'Status' ),
					el( 'th', { class: 'configkit-actions' }, '' )
				)
			)
		);
		const tbody = el( 'tbody' );
		items.forEach( ( f ) => {
			tbody.appendChild( el(
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
								loadFamily( f.id );
							},
						},
						f.name
					)
				),
				el( 'td', null, el( 'code', null, f.family_key ) ),
				el(
					'td',
					null,
					el(
						'span',
						{ class: 'configkit-badge configkit-badge--' + ( f.is_active ? 'active' : 'inactive' ) },
						f.is_active ? 'active' : 'inactive'
					)
				),
				el(
					'td',
					{ class: 'configkit-actions' },
					el( 'button', { type: 'button', class: 'button', onClick: () => loadFamily( f.id ) }, 'Edit' )
				)
			) );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	function renderForm() {
		const rec = state.editing;
		const isNew = rec.id === 0;
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el( 'h2', null, isNew ? 'New family' : 'Edit family: ' + rec.name ) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Name', 'name', rec.name, ( v ) => {
				rec.name = v;
				state.dirty = true;
				if ( ! rec.family_key && isNew ) {
					rec.family_key = slugify( v );
					render();
				}
			} ),
			textField( 'family_key', 'family_key', rec.family_key, ( v ) => {
				rec.family_key = v;
				state.dirty = true;
			}, {
				mono: true,
				disabled: ! isNew,
				help: isNew
					? 'Lowercase, snake_case, 3–64 chars. Locked after save.'
					: 'family_key is immutable after a family is saved.',
			} ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		wrap.appendChild( fieldset( 'Status', [
			checkboxField( 'Active', 'is_active', !! rec.is_active, ( v ) => {
				rec.is_active = v;
				state.dirty = true;
			} ),
		] ) );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{ type: 'button', class: 'button button-primary', disabled: state.busy, onClick: save },
				state.busy ? 'Saving…' : 'Save family'
			),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Cancel' ),
			rec.id > 0
				? el( 'button', { type: 'button', class: 'button button-link-delete', onClick: softDelete }, 'Soft delete' )
				: null
		) );

		return wrap;
	}

	function fieldset( legend, children ) {
		return el( 'fieldset', { class: 'configkit-fieldset' }, el( 'legend', null, legend ), ...children );
	}

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
			el( 'label', { for: 'cf_' + name }, label ),
			el( 'input', {
				id: 'cf_' + name,
				type: 'text',
				class: opts.mono ? 'regular-text code' : 'regular-text',
				value: value || '',
				disabled: !! opts.disabled,
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

	function init() {
		const params = new URLSearchParams( window.location.search );
		const action = params.get( 'action' );
		const id = parseInt( params.get( 'id' ) || '0', 10 );
		const savedMessage = consumeSavedFlag();

		if ( action === 'new' ) {
			showNewForm();
		} else if ( action === 'edit' && id > 0 ) {
			loadFamily( id );
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
