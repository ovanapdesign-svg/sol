/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-lookup-tables-app' );
	if ( ! root ) return;

	const MATCH_MODES = [
		[ 'exact', 'exact' ],
		[ 'round_up', 'round_up (default)' ],
		[ 'nearest', 'nearest' ],
	];

	const UNITS = [
		[ 'mm', 'mm' ],
		[ 'cm', 'cm' ],
		[ 'm', 'm' ],
	];

	const state = {
		view: 'loading', // 'list' | 'table_form' | 'table_detail' | 'cell_form'
		list: { items: [], total: 0 },
		table: null,
		stats: null,
		cells: { items: [], total: 0 },
		editingTable: null,
		editingCell: null,
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

	function consumeSavedFlag() {
		const params = new URLSearchParams( window.location.search );
		const v = params.get( 'saved' );
		if ( ! v ) return null;
		setUrl( { saved: null } );
		return ( {
			tbl_created: 'Lookup table created.',
			tbl_updated: 'Lookup table updated.',
			tbl_deleted: 'Lookup table deactivated.',
			cell_created: 'Cell created.',
			cell_updated: 'Cell updated.',
			cell_deleted: 'Cell deleted.',
		} )[ v ] || null;
	}

	function clearMessages() {
		state.message = null;
		state.fieldErrors = {};
	}

	function showError( err ) {
		if ( err && err.status === 409 ) {
			state.message = {
				kind: 'conflict',
				text: 'This lookup table was edited by someone else (or another tab) since you opened it. Reload to see the latest version.',
			};
			return;
		}
		const errors = ( err && err.data && err.data.errors ) || [];
		state.fieldErrors = {};
		errors.forEach( ( e ) => {
			const key = e.field || '_global';
			state.fieldErrors[ key ] = state.fieldErrors[ key ] || [];
			state.fieldErrors[ key ].push( e.message );
		} );
		state.message = { kind: 'error', text: err && err.message ? err.message : 'Something went wrong.' };
	}

	// ---- Loaders ----

	async function loadList() {
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/lookup-tables?per_page=500' );
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

	function showNewTableForm() {
		state.view = 'table_form';
		state.editingTable = blankTable();
		state.dirty = false;
		clearMessages();
		setUrl( { action: 'new', id: null, cell_id: null, cell_action: null } );
		render();
	}

	async function loadTableDetail( id ) {
		state.view = 'loading';
		render();
		try {
			const tableResp = await ConfigKit.request( '/lookup-tables/' + id );
			state.table = tableResp.record;
			state.stats = state.table.stats || null;
			state.editingTable = JSON.parse( JSON.stringify( state.table ) );
			delete state.editingTable.stats;
			const cellsResp = await ConfigKit.request( '/lookup-tables/' + id + '/cells?per_page=500' );
			state.cells = cellsResp;
			state.view = 'table_detail';
			state.dirty = false;
			clearMessages();
			setUrl( { action: null, id: id, cell_id: null, cell_action: null } );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function showNewCellForm() {
		if ( ! state.table ) return;
		state.view = 'cell_form';
		state.editingCell = blankCell();
		state.dirty = false;
		clearMessages();
		setUrl( { action: null, id: state.table.id, cell_action: 'new', cell_id: null } );
		render();
	}

	async function loadCell( tableId, cellId ) {
		state.view = 'loading';
		render();
		try {
			if ( ! state.table || state.table.id !== tableId ) {
				const tableResp = await ConfigKit.request( '/lookup-tables/' + tableId );
				state.table = tableResp.record;
			}
			const cellResp = await ConfigKit.request( '/lookup-tables/' + tableId + '/cells/' + cellId );
			state.editingCell = cellResp.record;
			state.view = 'cell_form';
			state.dirty = false;
			clearMessages();
			setUrl( { action: null, id: tableId, cell_id: cellId, cell_action: null } );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	function blankTable() {
		return {
			id: 0,
			lookup_table_key: '',
			name: '',
			family_key: '',
			description: '',
			unit: 'mm',
			supports_price_group: false,
			width_min: '',
			width_max: '',
			height_min: '',
			height_max: '',
			match_mode: 'round_up',
			is_active: true,
			version_hash: '',
		};
	}

	function blankCell() {
		return {
			id: 0,
			width: '',
			height: '',
			price: '',
			price_group_key: '',
		};
	}

	// ---- Save / Delete ----

	async function saveTable() {
		if ( state.busy ) return;
		state.busy = true;
		render();

		const rec = state.editingTable;
		const wasNew = ! ( rec.id > 0 );
		const payload = {
			lookup_table_key: rec.lookup_table_key,
			name: rec.name,
			family_key: rec.family_key || null,
			unit: rec.unit,
			supports_price_group: !! rec.supports_price_group,
			width_min: rec.width_min === '' ? null : rec.width_min,
			width_max: rec.width_max === '' ? null : rec.width_max,
			height_min: rec.height_min === '' ? null : rec.height_min,
			height_max: rec.height_max === '' ? null : rec.height_max,
			match_mode: rec.match_mode,
			is_active: !! rec.is_active,
		};

		try {
			if ( rec.id > 0 ) {
				payload.version_hash = rec.version_hash;
				await ConfigKit.request( '/lookup-tables/' + rec.id, { method: 'PUT', body: payload } );
			} else {
				await ConfigKit.request( '/lookup-tables', { method: 'POST', body: payload } );
			}
			redirectToList( wasNew ? 'tbl_created' : 'tbl_updated' );
			return;
		} catch ( err ) {
			showError( err );
		}
		state.busy = false;
		render();
	}

	async function softDeleteTable() {
		if ( ! state.table ) return;
		if ( ! window.confirm( 'Soft-delete this lookup table? It will be marked inactive. Cells remain in the database.' ) ) {
			return;
		}
		try {
			await ConfigKit.request( '/lookup-tables/' + state.table.id, { method: 'DELETE' } );
			redirectToList( 'tbl_deleted' );
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function saveCell() {
		if ( state.busy || ! state.table ) return;
		state.busy = true;
		render();

		const rec = state.editingCell;
		const wasNew = ! ( rec.id > 0 );
		const payload = {
			width: rec.width === '' ? 0 : rec.width,
			height: rec.height === '' ? 0 : rec.height,
			price: rec.price === '' ? 0 : rec.price,
			price_group_key: state.table.supports_price_group ? rec.price_group_key || '' : '',
		};

		try {
			if ( rec.id > 0 ) {
				await ConfigKit.request(
					'/lookup-tables/' + state.table.id + '/cells/' + rec.id,
					{ method: 'PUT', body: payload }
				);
			} else {
				await ConfigKit.request(
					'/lookup-tables/' + state.table.id + '/cells',
					{ method: 'POST', body: payload }
				);
			}
			redirectToTable( state.table.id, wasNew ? 'cell_created' : 'cell_updated' );
			return;
		} catch ( err ) {
			showError( err );
		}
		state.busy = false;
		render();
	}

	async function deleteCell() {
		if ( ! state.table || ! state.editingCell || ! state.editingCell.id ) return;
		if ( ! window.confirm( 'Permanently delete this cell?' ) ) return;
		try {
			await ConfigKit.request(
				'/lookup-tables/' + state.table.id + '/cells/' + state.editingCell.id,
				{ method: 'DELETE' }
			);
			redirectToTable( state.table.id, 'cell_deleted' );
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	function redirectToList( flag ) {
		const url = new URL( window.location.href );
		[ 'action', 'id', 'cell_id', 'cell_action' ].forEach( ( p ) => url.searchParams.delete( p ) );
		url.searchParams.set( 'saved', flag );
		window.location.href = url.toString();
	}

	function redirectToTable( tableId, flag ) {
		const url = new URL( window.location.href );
		[ 'action', 'cell_id', 'cell_action' ].forEach( ( p ) => url.searchParams.delete( p ) );
		url.searchParams.set( 'id', String( tableId ) );
		url.searchParams.set( 'saved', flag );
		window.location.href = url.toString();
	}

	function cancelToList() {
		if ( state.dirty && ! window.confirm( 'Discard unsaved changes?' ) ) return;
		setUrl( { action: null, id: null, cell_id: null, cell_action: null } );
		loadList();
	}

	function cancelToTable() {
		if ( state.dirty && ! window.confirm( 'Discard unsaved changes?' ) ) return;
		if ( state.table ) loadTableDetail( state.table.id );
		else loadList();
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
		else if ( state.view === 'table_form' ) root.appendChild( renderTableForm() );
		else if ( state.view === 'table_detail' ) root.appendChild( renderTableDetail() );
		else if ( state.view === 'cell_form' ) root.appendChild( renderCellForm() );
	}

	function messageBanner( m ) {
		const cls = 'notice ' + (
			m.kind === 'success' ? 'notice-success'
				: m.kind === 'conflict' ? 'notice-warning'
				: 'notice-error'
		) + ' inline configkit-notice';
		return el( 'div', { class: cls }, el( 'p', null, m.text ) );
	}

	function renderList() {
		const wrap = el( 'div', { class: 'configkit-list' } );
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-list__header' },
			el( 'button', { type: 'button', class: 'button button-primary', onClick: showNewTableForm }, '+ New lookup table' )
		) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		const items = state.list.items || [];
		if ( items.length === 0 ) {
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No lookup tables yet.' ),
				el( 'p', { class: 'configkit-empty__hint' }, 'Lookup tables map (width, height) — and optionally a price group — to a price.' )
			) );
			return wrap;
		}

		const table = el(
			'table',
			{ class: 'wp-list-table widefat striped configkit-lookup-tables-table' },
			el(
				'thead',
				null,
				el(
					'tr',
					null,
					el( 'th', null, 'Name' ),
					el( 'th', null, 'lookup_table_key' ),
					el( 'th', null, 'Family' ),
					el( 'th', null, 'Match mode' ),
					el( 'th', null, 'Unit' ),
					el( 'th', null, 'Status' ),
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
								loadTableDetail( t.id );
							},
						},
						t.name
					)
				),
				el( 'td', null, el( 'code', null, t.lookup_table_key ) ),
				el( 'td', null, t.family_key || '—' ),
				el( 'td', null, t.match_mode ),
				el( 'td', null, t.unit ),
				el(
					'td',
					null,
					el(
						'span',
						{ class: 'configkit-badge configkit-badge--' + ( t.is_active ? 'active' : 'inactive' ) },
						t.is_active ? 'active' : 'inactive'
					)
				),
				el(
					'td',
					{ class: 'configkit-actions' },
					el( 'button', { type: 'button', class: 'button', onClick: () => loadTableDetail( t.id ) }, 'Open' )
				)
			) );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	function renderTableForm() {
		const rec = state.editingTable;
		const isNew = rec.id === 0;
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el( 'h2', null, isNew ? 'New lookup table' : 'Edit lookup table: ' + rec.name ) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Name', 'name', rec.name, ( v ) => {
				rec.name = v;
				state.dirty = true;
				if ( ! rec.lookup_table_key && isNew ) {
					rec.lookup_table_key = slugify( v );
					render();
				}
			} ),
			textField( 'lookup_table_key', 'lookup_table_key', rec.lookup_table_key, ( v ) => {
				rec.lookup_table_key = v;
				state.dirty = true;
			}, { mono: true, help: isNew
				? 'Lowercase, snake_case, max 64 chars. Locked after save.'
				: 'lookup_table_key is immutable after a table is saved.' } ),
			isNew ? null : el( 'p', { class: 'description' }, 'lookup_table_key cannot be changed.' ),
			textField( 'Family key (optional)', 'family_key', rec.family_key || '', ( v ) => {
				rec.family_key = v;
				state.dirty = true;
			} ),
		] ) );

		wrap.appendChild( fieldset( 'Matching', [
			selectFieldRow( 'Unit', 'unit', UNITS, rec.unit, ( v ) => {
				rec.unit = v;
				state.dirty = true;
			} ),
			selectFieldRow( 'Match mode', 'match_mode', MATCH_MODES, rec.match_mode, ( v ) => {
				rec.match_mode = v;
				state.dirty = true;
			} ),
			checkboxField( 'Supports price group', 'supports_price_group', !! rec.supports_price_group, ( v ) => {
				rec.supports_price_group = v;
				state.dirty = true;
			} ),
			fieldErrors( 'supports_price_group' ),
		] ) );

		wrap.appendChild( fieldset( 'Bounding box (optional, mm)', [
			numberFieldRow( 'Width min', 'width_min', rec.width_min, ( v ) => {
				rec.width_min = v;
				state.dirty = true;
			} ),
			numberFieldRow( 'Width max', 'width_max', rec.width_max, ( v ) => {
				rec.width_max = v;
				state.dirty = true;
			} ),
			numberFieldRow( 'Height min', 'height_min', rec.height_min, ( v ) => {
				rec.height_min = v;
				state.dirty = true;
			} ),
			numberFieldRow( 'Height max', 'height_max', rec.height_max, ( v ) => {
				rec.height_max = v;
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
			el( 'button', { type: 'button', class: 'button button-primary', disabled: state.busy, onClick: saveTable }, state.busy ? 'Saving…' : 'Save lookup table' ),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Cancel' )
		) );
		return wrap;
	}

	function renderTableDetail() {
		const t = state.table;
		const wrap = el( 'div' );

		// Stats panel
		const stats = state.stats || {};
		const statsBlock = el(
			'div',
			{ class: 'configkit-stats' },
			el( 'h2', null, t.name ),
			el( 'p', { class: 'description' },
				'lookup_table_key: ',
				el( 'code', null, t.lookup_table_key ),
				t.family_key ? ' · family: ' + t.family_key : ''
			),
			el(
				'ul',
				{ class: 'configkit-counts' },
				el(
					'li',
					null,
					el( 'span', { class: 'configkit-counts__label' }, 'Cells' ),
					el( 'span', { class: 'configkit-counts__value' }, String( stats.cells || 0 ) )
				),
				el(
					'li',
					null,
					el( 'span', { class: 'configkit-counts__label' }, 'Width range' ),
					el( 'span', { class: 'configkit-counts__value' },
						stats.width_min !== null && stats.width_min !== undefined
							? stats.width_min + '–' + stats.width_max + ' mm'
							: '—'
					)
				),
				el(
					'li',
					null,
					el( 'span', { class: 'configkit-counts__label' }, 'Height range' ),
					el( 'span', { class: 'configkit-counts__value' },
						stats.height_min !== null && stats.height_min !== undefined
							? stats.height_min + '–' + stats.height_max + ' mm'
							: '—'
					)
				),
				el(
					'li',
					null,
					el( 'span', { class: 'configkit-counts__label' }, 'Match mode' ),
					el( 'span', { class: 'configkit-counts__value' }, t.match_mode )
				),
				el(
					'li',
					null,
					el( 'span', { class: 'configkit-counts__label' }, 'Price groups' ),
					el( 'span', { class: 'configkit-counts__value' }, String( stats.price_groups || 0 ) )
				)
			)
		);
		wrap.appendChild( statsBlock );

		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		// Metadata edit
		const editForm = state.editingTable || t;
		const meta = el( 'div', { class: 'configkit-form' } );
		meta.appendChild( el( 'h3', null, 'Lookup table settings' ) );
		meta.appendChild( fieldset( 'Basics', [
			textField( 'Name', 'name', editForm.name, ( v ) => {
				editForm.name = v;
				state.dirty = true;
			} ),
			textField( 'Family key', 'family_key', editForm.family_key || '', ( v ) => {
				editForm.family_key = v;
				state.dirty = true;
			} ),
		] ) );
		meta.appendChild( fieldset( 'Matching', [
			selectFieldRow( 'Unit', 'unit', UNITS, editForm.unit, ( v ) => {
				editForm.unit = v;
				state.dirty = true;
			} ),
			selectFieldRow( 'Match mode', 'match_mode', MATCH_MODES, editForm.match_mode, ( v ) => {
				editForm.match_mode = v;
				state.dirty = true;
			} ),
			checkboxField( 'Supports price group', 'supports_price_group', !! editForm.supports_price_group, ( v ) => {
				editForm.supports_price_group = v;
				state.dirty = true;
			} ),
			fieldErrors( 'supports_price_group' ),
		] ) );
		meta.appendChild( fieldset( 'Status', [
			checkboxField( 'Active', 'is_active', !! editForm.is_active, ( v ) => {
				editForm.is_active = v;
				state.dirty = true;
			} ),
		] ) );
		meta.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', { type: 'button', class: 'button button-primary', disabled: state.busy, onClick: saveTable }, state.busy ? 'Saving…' : 'Save table' ),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Back to list' ),
			el( 'button', { type: 'button', class: 'button button-link-delete', onClick: softDeleteTable }, 'Soft delete table' )
		) );
		wrap.appendChild( meta );

		// Cells block
		const cellsBlock = el( 'div', { class: 'configkit-form configkit-items' } );
		cellsBlock.appendChild( el(
			'div',
			{ class: 'configkit-list__header' },
			el( 'h3', null, 'Cells' ),
			el( 'button', { type: 'button', class: 'button button-secondary', onClick: showNewCellForm }, '+ New cell' )
		) );

		const cells = state.cells.items || [];
		if ( cells.length === 0 ) {
			cellsBlock.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No cells yet. Add at least one to make this lookup table usable.' )
			) );
		} else {
			const headers = [ 'Width', 'Height' ];
			if ( t.supports_price_group ) headers.push( 'Price group' );
			headers.push( 'Price', '' );

			const tbl = el( 'table', { class: 'wp-list-table widefat striped configkit-cells-table' } );
			const thead = el( 'thead' );
			const headRow = el( 'tr' );
			headers.forEach( ( h ) => headRow.appendChild( el( 'th', null, h ) ) );
			thead.appendChild( headRow );
			tbl.appendChild( thead );

			const tbody = el( 'tbody' );
			cells.forEach( ( c ) => {
				const row = el( 'tr' );
				row.appendChild( el(
					'td',
					null,
					el(
						'a',
						{
							href: '#',
							onClick: ( ev ) => {
								ev.preventDefault();
								loadCell( t.id, c.id );
							},
						},
						String( c.width )
					)
				) );
				row.appendChild( el( 'td', null, String( c.height ) ) );
				if ( t.supports_price_group ) {
					row.appendChild( el( 'td', null, c.price_group_key || '—' ) );
				}
				row.appendChild( el( 'td', null, c.price.toFixed( 2 ) ) );
				row.appendChild( el(
					'td',
					{ class: 'configkit-actions' },
					el( 'button', { type: 'button', class: 'button', onClick: () => loadCell( t.id, c.id ) }, 'Edit' )
				) );
				tbody.appendChild( row );
			} );
			tbl.appendChild( tbody );
			cellsBlock.appendChild( tbl );
		}
		wrap.appendChild( cellsBlock );
		return wrap;
	}

	function renderCellForm() {
		const rec = state.editingCell;
		const t = state.table;
		const isNew = rec.id === 0;
		const wrap = el( 'div', { class: 'configkit-form' } );
		wrap.appendChild( el( 'h2', null, isNew ? 'New cell' : 'Edit cell #' + rec.id ) );
		if ( state.message ) wrap.appendChild( messageBanner( state.message ) );

		wrap.appendChild( fieldset( 'Cell', [
			numberFieldRow( 'Width (' + t.unit + ')', 'width', rec.width, ( v ) => {
				rec.width = v;
				state.dirty = true;
			} ),
			fieldErrors( 'width' ),
			numberFieldRow( 'Height (' + t.unit + ')', 'height', rec.height, ( v ) => {
				rec.height = v;
				state.dirty = true;
			} ),
			fieldErrors( 'height' ),
			numberFieldRow( 'Price', 'price', rec.price, ( v ) => {
				rec.price = v;
				state.dirty = true;
			}, { allowFloat: true } ),
			fieldErrors( 'price' ),
			t.supports_price_group
				? textField( 'Price group key', 'price_group_key', rec.price_group_key || '', ( v ) => {
					rec.price_group_key = v;
					state.dirty = true;
				}, { mono: true } )
				: null,
		] ) );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', { type: 'button', class: 'button button-primary', disabled: state.busy, onClick: saveCell }, state.busy ? 'Saving…' : 'Save cell' ),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToTable }, 'Cancel' ),
			rec.id > 0 ? el( 'button', { type: 'button', class: 'button button-link-delete', onClick: deleteCell }, 'Delete cell' ) : null
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
				onInput: ( ev ) => onChange( ev.target.value ),
			} ),
			opts.help ? el( 'p', { class: 'description' }, opts.help ) : null,
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

	function numberFieldRow( label, name, value, onChange, opts ) {
		opts = opts || {};
		return el(
			'div',
			{ class: 'configkit-field configkit-field--inline' },
			el( 'label', { for: 'cf_' + name }, label ),
			el( 'input', {
				id: 'cf_' + name,
				type: 'number',
				step: opts.allowFloat ? '0.01' : '1',
				value: value === null || value === undefined ? '' : String( value ),
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

	// ---- Init ----

	function init() {
		const params = new URLSearchParams( window.location.search );
		const action = params.get( 'action' );
		const id = parseInt( params.get( 'id' ) || '0', 10 );
		const cellId = parseInt( params.get( 'cell_id' ) || '0', 10 );
		const cellAction = params.get( 'cell_action' );

		const savedMessage = consumeSavedFlag();

		if ( action === 'new' ) {
			showNewTableForm();
		} else if ( id > 0 && cellId > 0 ) {
			loadCell( id, cellId );
		} else if ( id > 0 && cellAction === 'new' ) {
			loadTableDetail( id ).then( () => showNewCellForm() );
		} else if ( id > 0 ) {
			loadTableDetail( id ).then( () => {
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
