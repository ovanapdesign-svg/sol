/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-templates-app' );
	if ( ! root ) return;

	const STATUS_OPTIONS = [
		[ 'draft', 'draft' ],
		[ 'published', 'published' ],
		[ 'archived', 'archived' ],
	];

	const state = {
		view: 'loading', // 'list' | 'form' | 'detail_placeholder' | 'loading'
		list: { items: [], total: 0 },
		editing: null,
		viewing: null, // template being "Open"-ed (placeholder view in B1)
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
			created: 'Template created.',
			updated: 'Template metadata updated.',
			deleted: 'Template archived.',
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

	// ---- Loaders ----

	async function loadList() {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/templates?per_page=500' );
			state.list = data;
			state.view = 'list';
			state.editing = null;
			state.viewing = null;
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

	async function loadTemplateForEdit( id ) {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/templates/' + id );
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

	async function openTemplatePlaceholder( id ) {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/templates/' + id );
			state.viewing = data.record;
			state.view = 'detail_placeholder';
			clearMessages();
			setUrl( { action: null, id: id } );
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
			template_key: '',
			name: '',
			family_key: '',
			description: '',
			status: 'draft',
			version_hash: '',
		};
	}

	// ---- Save / Delete ----

	async function save() {
		if ( state.busy ) return;
		state.busy = true;
		render();

		const rec = state.editing;
		const wasNew = ! ( rec.id > 0 );
		const payload = {
			template_key: rec.template_key,
			name: rec.name,
			family_key: rec.family_key || null,
			description: rec.description || null,
			status: rec.status,
		};

		let success = false;
		try {
			if ( rec.id > 0 ) {
				payload.version_hash = rec.version_hash;
				await ConfigKit.request( '/templates/' + rec.id, { method: 'PUT', body: payload } );
			} else {
				await ConfigKit.request( '/templates', { method: 'POST', body: payload } );
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
		if ( ! window.confirm( 'Archive this template? It will be marked status=archived.' ) ) return;
		try {
			await ConfigKit.request( '/templates/' + state.editing.id, { method: 'DELETE' } );
			redirectToList( 'deleted' );
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
		else if ( state.view === 'detail_placeholder' ) root.appendChild( renderDetailPlaceholder() );
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
			el( 'button', { type: 'button', class: 'button button-primary', onClick: showNewForm }, '+ New template' )
		) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		const items = state.list.items || [];
		if ( items.length === 0 ) {
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No templates yet.' ),
				el( 'p', { class: 'configkit-empty__hint' }, 'Templates define the configurator for a family of products. Create one to start.' )
			) );
			return wrap;
		}

		const table = el(
			'table',
			{ class: 'wp-list-table widefat striped configkit-templates-table' },
			el(
				'thead',
				null,
				el(
					'tr',
					null,
					el( 'th', null, 'Name' ),
					el( 'th', null, 'template_key' ),
					el( 'th', null, 'Family' ),
					el( 'th', null, 'Status' ),
					el( 'th', null, 'Published version' ),
					el( 'th', null, 'Used by' ),
					el( 'th', null, 'Last edited' ),
					el( 'th', { class: 'configkit-actions' }, '' )
				)
			)
		);
		const tbody = el( 'tbody' );
		items.forEach( ( t ) => {
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
								openTemplatePlaceholder( t.id );
							},
						},
						t.name
					)
				),
				el( 'td', null, el( 'code', null, t.template_key ) ),
				el( 'td', null, t.family_key || '—' ),
				el(
					'td',
					null,
					el(
						'span',
						{ class: 'configkit-badge configkit-badge--' + statusBadge( t.status ) },
						t.status
					)
				),
				el( 'td', null, t.published_version_id ? 'v' + t.published_version_id : '—' ),
				el( 'td', null, '0' ), // bindings not implemented yet
				el( 'td', null, t.updated_at || '—' ),
				el(
					'td',
					{ class: 'configkit-actions' },
					el( 'button', { type: 'button', class: 'button', onClick: () => loadTemplateForEdit( t.id ) }, 'Edit metadata' )
				)
			) );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	function statusBadge( status ) {
		if ( status === 'published' ) return 'active';
		if ( status === 'archived' ) return 'inactive';
		return 'inactive';
	}

	function renderForm() {
		const rec = state.editing;
		const isNew = rec.id === 0;
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el( 'h2', null, isNew ? 'New template' : 'Edit metadata: ' + rec.name ) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Name', 'name', rec.name, ( v ) => {
				rec.name = v;
				state.dirty = true;
				if ( ! rec.template_key && isNew ) {
					rec.template_key = slugify( v );
					render();
				}
			} ),
			textField( 'template_key', 'template_key', rec.template_key, ( v ) => {
				rec.template_key = v;
				state.dirty = true;
			}, {
				mono: true,
				disabled: ! isNew,
				help: isNew
					? 'Lowercase, snake_case, 3–64 chars. Locked after save.'
					: 'template_key is immutable after a template is saved.',
			} ),
			textField( 'family_key (optional)', 'family_key', rec.family_key || '', ( v ) => {
				rec.family_key = v;
				state.dirty = true;
			}, { mono: true, help: 'Optional. If provided, must follow the same key rules.' } ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		wrap.appendChild( fieldset( 'Status', [
			selectFieldRow( 'Status', 'status', STATUS_OPTIONS, rec.status, ( v ) => {
				rec.status = v;
				state.dirty = true;
			} ),
			fieldErrors( 'status' ),
		] ) );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{ type: 'button', class: 'button button-primary', disabled: state.busy, onClick: save },
				state.busy ? 'Saving…' : 'Save metadata'
			),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Cancel' ),
			rec.id > 0
				? el( 'button', { type: 'button', class: 'button button-link-delete', onClick: softDelete }, 'Archive template' )
				: null
		) );

		return wrap;
	}

	function renderDetailPlaceholder() {
		const t = state.viewing;
		const wrap = el( 'div' );

		const meta = el( 'div', { class: 'configkit-form' } );
		meta.appendChild( el( 'h2', null, t.name ) );
		meta.appendChild( el(
			'p',
			{ class: 'description' },
			'template_key: '
		) );
		meta.appendChild( el( 'code', null, t.template_key ) );
		meta.appendChild( el(
			'p',
			{ class: 'description' },
			t.family_key ? 'Family: ' + t.family_key + ' · ' : '',
			'Status: ' + t.status
		) );

		const placeholder = el(
			'div',
			{ class: 'configkit-empty' },
			el( 'p', null, 'Steps and fields editor coming in next phase.' ),
			el(
				'p',
				{ class: 'configkit-empty__hint' },
				'B1 ships list + metadata only. Step CRUD lands in B2; field CRUD in B3; rules drawer in B4; publish workflow in B5.'
			)
		);
		meta.appendChild( placeholder );

		meta.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', { type: 'button', class: 'button', onClick: () => loadTemplateForEdit( t.id ) }, 'Edit metadata' ),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Back to list' )
		) );

		wrap.appendChild( meta );
		return wrap;
	}

	// ---- Form helpers ----

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

	function selectFieldRow( label, name, choices, value, onChange ) {
		const select = el( 'select', {
			id: 'cf_' + name,
			onChange: ( ev ) => onChange( ev.target.value ),
		} );
		choices.forEach( ( [ v, lab ] ) => {
			const o = el( 'option', { value: v }, lab );
			if ( v === value ) o.selected = true;
			select.appendChild( o );
		} );
		return el(
			'div',
			{ class: 'configkit-field configkit-field--inline' },
			el( 'label', { for: 'cf_' + name }, label ),
			select
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
			loadTemplateForEdit( id );
		} else if ( id > 0 ) {
			openTemplatePlaceholder( id );
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
