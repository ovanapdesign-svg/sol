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
		view: 'loading', // 'list' | 'form' | 'detail' | 'step_form' | 'loading'
		list: { items: [], total: 0 },
		editing: null,
		viewing: null, // template currently opened in detail view
		steps: { items: [], total: 0 },
		editingStep: null,
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
			step_created: 'Step created.',
			step_updated: 'Step updated.',
			step_deleted: 'Step deleted.',
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

	async function openTemplateDetail( id ) {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/templates/' + id );
			state.viewing = data.record;
			const stepsData = await ConfigKit.request( '/templates/' + id + '/steps' );
			state.steps = stepsData;
			state.view = 'detail';
			clearMessages();
			setUrl( { action: null, id: id, step_id: null, step_action: null } );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function showNewStepForm() {
		if ( ! state.viewing ) return;
		state.view = 'step_form';
		state.editingStep = blankStep();
		state.dirty = false;
		clearMessages();
		setUrl( { action: null, id: state.viewing.id, step_action: 'new', step_id: null } );
		render();
	}

	async function loadStep( templateId, stepId ) {
		state.view = 'loading';
		render();
		try {
			if ( ! state.viewing || state.viewing.id !== templateId ) {
				const tmplResp = await ConfigKit.request( '/templates/' + templateId );
				state.viewing = tmplResp.record;
			}
			const stepResp = await ConfigKit.request( '/templates/' + templateId + '/steps/' + stepId );
			state.editingStep = stepResp.record;
			state.view = 'step_form';
			state.dirty = false;
			clearMessages();
			setUrl( { action: null, id: templateId, step_id: stepId, step_action: null } );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function blankStep() {
		return {
			id: 0,
			step_key: '',
			label: '',
			description: '',
			sort_order: '',
			is_required: false,
			is_collapsed_by_default: false,
			version_hash: '',
		};
	}

	async function saveStep() {
		if ( state.busy || ! state.viewing ) return;
		state.busy = true;
		render();

		const rec = state.editingStep;
		const wasNew = ! ( rec.id > 0 );
		const payload = {
			step_key: rec.step_key,
			label: rec.label,
			description: rec.description || null,
			is_required: !! rec.is_required,
			is_collapsed_by_default: !! rec.is_collapsed_by_default,
		};
		if ( rec.sort_order !== '' && rec.sort_order !== null ) {
			payload.sort_order = rec.sort_order;
		}

		let success = false;
		try {
			if ( rec.id > 0 ) {
				payload.version_hash = rec.version_hash;
				await ConfigKit.request(
					'/templates/' + state.viewing.id + '/steps/' + rec.id,
					{ method: 'PUT', body: payload }
				);
			} else {
				await ConfigKit.request(
					'/templates/' + state.viewing.id + '/steps',
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
			redirectToTemplate( state.viewing.id, wasNew ? 'step_created' : 'step_updated' );
			return;
		}
		render();
	}

	async function deleteStep() {
		if ( ! state.viewing || ! state.editingStep || ! state.editingStep.id ) return;
		if ( ! window.confirm( 'Delete this step? This action is permanent. Fields inside this step will become orphaned (Phase 3 B3 will offer reassignment).' ) ) return;
		try {
			await ConfigKit.request(
				'/templates/' + state.viewing.id + '/steps/' + state.editingStep.id,
				{ method: 'DELETE' }
			);
			redirectToTemplate( state.viewing.id, 'step_deleted' );
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function reorderStep( stepId, direction ) {
		if ( ! state.viewing ) return;
		const items = state.steps.items.slice();
		const idx = items.findIndex( ( s ) => s.id === stepId );
		if ( idx < 0 ) return;
		const swap = direction === 'up' ? idx - 1 : idx + 1;
		if ( swap < 0 || swap >= items.length ) return;

		// Swap and rebuild sort_order (1..N).
		const a = items[ idx ];
		items[ idx ] = items[ swap ];
		items[ swap ] = a;
		const payload = items.map( ( s, i ) => ( { step_id: s.id, sort_order: i + 1 } ) );

		try {
			await ConfigKit.request(
				'/templates/' + state.viewing.id + '/steps/reorder',
				{ method: 'POST', body: { items: payload } }
			);
			openTemplateDetail( state.viewing.id );
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	function redirectToTemplate( templateId, flag ) {
		const url = new URL( window.location.href );
		[ 'action', 'step_id', 'step_action' ].forEach( ( p ) => url.searchParams.delete( p ) );
		url.searchParams.set( 'id', String( templateId ) );
		url.searchParams.set( 'saved', flag );
		window.location.href = url.toString();
	}

	function cancelToTemplate() {
		if ( state.dirty && ! window.confirm( 'Discard unsaved changes?' ) ) return;
		if ( state.viewing ) openTemplateDetail( state.viewing.id );
		else loadList();
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
		else if ( state.view === 'detail' ) root.appendChild( renderDetail() );
		else if ( state.view === 'step_form' ) root.appendChild( renderStepForm() );
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
								openTemplateDetail( t.id );
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

	function renderDetail() {
		const t = state.viewing;
		const wrap = el( 'div' );

		// Template header (metadata summary).
		const header = el( 'div', { class: 'configkit-form' } );
		header.appendChild( el( 'h2', null, t.name ) );
		header.appendChild( el(
			'p',
			{ class: 'description' },
			'template_key: ',
			el( 'code', null, t.template_key ),
			t.family_key ? ' · Family: ' + t.family_key : '',
			' · Status: ' + t.status
		) );

		if ( state.message ) header.appendChild( messageBanner( state.message ) );

		header.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', { type: 'button', class: 'button', onClick: () => loadTemplateForEdit( t.id ) }, 'Edit metadata' ),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Back to list' )
		) );
		wrap.appendChild( header );

		// Steps panel.
		const stepsBlock = el( 'div', { class: 'configkit-form configkit-items' } );
		stepsBlock.appendChild( el(
			'div',
			{ class: 'configkit-list__header' },
			el( 'h3', null, 'Steps' ),
			el(
				'button',
				{ type: 'button', class: 'button button-secondary', onClick: showNewStepForm },
				'+ Add step'
			)
		) );

		const steps = state.steps.items || [];
		if ( steps.length === 0 ) {
			stepsBlock.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No steps yet.' ),
				el(
					'p',
					{ class: 'configkit-empty__hint' },
					'Templates need at least one step. Steps group fields the customer fills out (e.g. Mål, Duk og farge, Betjening).'
				),
				el(
					'p',
					null,
					el( 'button', { type: 'button', class: 'button button-primary', onClick: showNewStepForm }, '+ Add first step' )
				)
			) );
		} else {
			const table = el(
				'table',
				{ class: 'wp-list-table widefat striped configkit-steps-table' },
				el(
					'thead',
					null,
					el(
						'tr',
						null,
						el( 'th', { class: 'configkit-steps-table__order' }, 'Order' ),
						el( 'th', null, 'Step name' ),
						el( 'th', null, 'step_key' ),
						el( 'th', null, 'Required' ),
						el( 'th', null, 'Sort' ),
						el( 'th', { class: 'configkit-actions' }, '' )
					)
				)
			);

			const tbody = el( 'tbody' );
			steps.forEach( ( s, i ) => {
				tbody.appendChild( el(
					'tr',
					null,
					el(
						'td',
						{ class: 'configkit-steps-table__order' },
						el(
							'button',
							{
								type: 'button',
								class: 'button-link',
								disabled: i === 0,
								onClick: () => reorderStep( s.id, 'up' ),
								'aria-label': 'Move up',
								title: 'Move up',
							},
							'▲'
						),
						el(
							'button',
							{
								type: 'button',
								class: 'button-link',
								disabled: i === steps.length - 1,
								onClick: () => reorderStep( s.id, 'down' ),
								'aria-label': 'Move down',
								title: 'Move down',
							},
							'▼'
						)
					),
					el(
						'td',
						null,
						el(
							'a',
							{
								href: '#',
								onClick: ( ev ) => {
									ev.preventDefault();
									loadStep( t.id, s.id );
								},
							},
							s.label
						)
					),
					el( 'td', null, el( 'code', null, s.step_key ) ),
					el( 'td', null, s.is_required ? 'Yes' : 'No' ),
					el( 'td', null, String( s.sort_order ) ),
					el(
						'td',
						{ class: 'configkit-actions' },
						el( 'button', { type: 'button', class: 'button', onClick: () => loadStep( t.id, s.id ) }, 'Edit' )
					)
				) );
			} );
			table.appendChild( tbody );
			stepsBlock.appendChild( table );
		}

		wrap.appendChild( stepsBlock );
		return wrap;
	}

	function renderStepForm() {
		const rec = state.editingStep;
		const t = state.viewing;
		const isNew = rec.id === 0;
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el(
			'h2',
			null,
			isNew ? 'New step in: ' + t.name : 'Edit step: ' + rec.label
		) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Step name', 'label', rec.label, ( v ) => {
				rec.label = v;
				state.dirty = true;
				if ( ! rec.step_key && isNew ) {
					rec.step_key = slugify( v );
					render();
				}
			} ),
			textField( 'step_key', 'step_key', rec.step_key, ( v ) => {
				rec.step_key = v;
				state.dirty = true;
			}, {
				mono: true,
				disabled: ! isNew,
				help: isNew
					? 'Lowercase, snake_case, 3–64 chars. Locked after save. Unique within this template.'
					: 'step_key is immutable after a step is saved.',
			} ),
			textareaField( 'Description', 'description', rec.description || '', ( v ) => {
				rec.description = v;
				state.dirty = true;
			} ),
		] ) );

		wrap.appendChild( fieldset( 'Behavior', [
			checkboxField( 'Required', 'is_required', !! rec.is_required, ( v ) => {
				rec.is_required = v;
				state.dirty = true;
			} ),
			checkboxField( 'Collapsed by default', 'is_collapsed_by_default', !! rec.is_collapsed_by_default, ( v ) => {
				rec.is_collapsed_by_default = v;
				state.dirty = true;
			} ),
			el( 'p', { class: 'description' }, 'Visibility is controlled by rules at render time, not stored on the step itself.' ),
		] ) );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{ type: 'button', class: 'button button-primary', disabled: state.busy, onClick: saveStep },
				state.busy ? 'Saving…' : 'Save step'
			),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToTemplate }, 'Cancel' ),
			rec.id > 0
				? el( 'button', { type: 'button', class: 'button button-link-delete', onClick: deleteStep }, 'Delete step' )
				: null
		) );

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
		const stepAction = params.get( 'step_action' );
		const stepId = parseInt( params.get( 'step_id' ) || '0', 10 );
		const savedMessage = consumeSavedFlag();

		if ( action === 'new' ) {
			showNewForm();
		} else if ( action === 'edit' && id > 0 ) {
			loadTemplateForEdit( id );
		} else if ( id > 0 && stepId > 0 ) {
			loadStep( id, stepId );
		} else if ( id > 0 && stepAction === 'new' ) {
			openTemplateDetail( id ).then( () => {
				showNewStepForm();
			} );
		} else if ( id > 0 ) {
			openTemplateDetail( id ).then( () => {
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
