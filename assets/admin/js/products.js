/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-products-app' );
	if ( ! root ) return;

	const state = {
		view: 'loading', // 'loading' | 'list' | 'error'
		list: { items: [], total: 0, page: 1, per_page: 50, total_pages: 0 },
		filters: { family_key: '', enabled: '' },
		families: [],
		message: null,
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
			};
		}
		state.message = { kind: desc.kind, text: desc.friendly, technical: desc.technical };
	}

	async function loadFamilies() {
		try {
			const data = await ConfigKit.request( '/families?per_page=500' );
			state.families = ( data && data.items ) || [];
		} catch ( e ) {
			state.families = [];
		}
	}

	async function loadList() {
		state.view = 'loading';
		render();
		const params = new URLSearchParams();
		params.set( 'per_page', String( state.list.per_page ) );
		params.set( 'page', String( state.list.page ) );
		if ( state.filters.family_key ) params.set( 'family_key', state.filters.family_key );
		if ( state.filters.enabled !== '' ) params.set( 'enabled', state.filters.enabled );
		try {
			const data = await ConfigKit.request( '/products?' + params.toString() );
			state.list = data;
			state.message = null;
			state.view = 'list';
		} catch ( err ) {
			showError( err );
			state.view = 'list';
		}
		render();
	}

	function setFilter( key, value ) {
		state.filters[ key ] = value;
		state.list.page = 1;
		loadList();
	}

	function setPage( page ) {
		state.list.page = page;
		loadList();
	}

	function render() {
		root.dataset.loading = state.view === 'loading' ? 'true' : 'false';
		root.replaceChildren();

		root.appendChild( renderFilters() );
		if ( state.message ) root.appendChild( messageBanner( state.message ) );

		if ( state.view === 'loading' ) {
			root.appendChild( el( 'p', { class: 'configkit-app__loading' }, 'Loading…' ) );
			return;
		}

		root.appendChild( renderTable() );
		root.appendChild( renderPagination() );
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

	function renderFilters() {
		const familyOpts = [ { value: '', label: '— All families —' } ].concat(
			state.families.map( ( f ) => ( { value: f.family_key, label: f.name } ) )
		);
		const enabledOpts = [
			{ value: '', label: '— All —' },
			{ value: 'true', label: 'Enabled only' },
			{ value: 'false', label: 'Disabled only' },
		];
		const familySelect = el( 'select', {
			onChange: ( ev ) => setFilter( 'family_key', ev.target.value ),
		} );
		familyOpts.forEach( ( o ) => {
			const node = el( 'option', { value: o.value }, o.label );
			if ( o.value === state.filters.family_key ) node.selected = true;
			familySelect.appendChild( node );
		} );
		const enabledSelect = el( 'select', {
			onChange: ( ev ) => setFilter( 'enabled', ev.target.value ),
		} );
		enabledOpts.forEach( ( o ) => {
			const node = el( 'option', { value: o.value }, o.label );
			if ( o.value === state.filters.enabled ) node.selected = true;
			enabledSelect.appendChild( node );
		} );

		return el(
			'div',
			{ class: 'configkit-products__filters' },
			el( 'label', null, 'Family: ', familySelect ),
			el( 'label', null, 'Status: ', enabledSelect )
		);
	}

	function renderTable() {
		const items = state.list.items || [];
		if ( items.length === 0 ) {
			return el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No products match these filters.' )
			);
		}
		const table = el(
			'table',
			{ class: 'wp-list-table widefat striped configkit-products-table' },
			el(
				'thead',
				null,
				el(
					'tr',
					null,
					el( 'th', null, 'Product' ),
					el( 'th', null, 'SKU' ),
					el( 'th', null, 'Family' ),
					el( 'th', null, 'Template' ),
					el( 'th', null, 'Lookup table' ),
					el( 'th', null, 'Status' ),
					el( 'th', { class: 'configkit-actions' }, '' )
				)
			)
		);
		const tbody = el( 'tbody' );
		items.forEach( ( p ) => {
			tbody.appendChild( el(
				'tr',
				null,
				el(
					'td',
					null,
					el( 'a', { href: p.edit_url }, p.name || '(untitled)' ),
					p.post_status && p.post_status !== 'publish'
						? el( 'span', { class: 'configkit-products__poststatus' }, ' [' + p.post_status + ']' )
						: null
				),
				el( 'td', null, p.sku ? el( 'code', null, p.sku ) : '—' ),
				el( 'td', null, p.family_key ? el( 'code', null, p.family_key ) : '—' ),
				el( 'td', null, p.template_key ? el( 'code', null, p.template_key ) : '—' ),
				el( 'td', null, p.lookup_table_key ? el( 'code', null, p.lookup_table_key ) : '—' ),
				el(
					'td',
					null,
					el(
						'span',
						{ class: 'configkit-badge configkit-badge--' + ( p.enabled ? 'active' : 'inactive' ) },
						p.enabled ? 'enabled' : 'disabled'
					)
				),
				el(
					'td',
					{ class: 'configkit-actions' },
					el( 'a', { href: p.edit_url, class: 'button' }, 'Edit binding' )
				)
			) );
		} );
		table.appendChild( tbody );
		return table;
	}

	function renderPagination() {
		const total = state.list.total || 0;
		const totalPages = state.list.total_pages || 0;
		const page = state.list.page || 1;
		if ( total === 0 || totalPages <= 1 ) return el( 'div' );
		return el(
			'div',
			{ class: 'configkit-pagination' },
			el( 'span', { class: 'configkit-pagination__count' }, total + ' product(s), page ' + page + ' of ' + totalPages ),
			el(
				'button',
				{ type: 'button', class: 'button', disabled: page <= 1, onClick: () => setPage( page - 1 ) },
				'‹ Prev'
			),
			el(
				'button',
				{ type: 'button', class: 'button', disabled: page >= totalPages, onClick: () => setPage( page + 1 ) },
				'Next ›'
			)
		);
	}

	async function init() {
		await loadFamilies();
		loadList();
	}

	init();
} )();
