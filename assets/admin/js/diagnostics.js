/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-diagnostics-app' );
	if ( ! root ) return;

	const TYPE_LABELS = {
		all: 'All issues',
		product: 'Products',
		template: 'Templates',
		library_item: 'Libraries',
		lookup_table: 'Lookup tables',
		rule: 'Rules',
		module: 'Modules',
	};

	const TAB_ORDER = [ 'all', 'product', 'template', 'library_item', 'lookup_table', 'rule', 'module' ];

	const state = {
		view: 'loading', // 'loading' | 'list' | 'error'
		issues: [],
		counts: { critical: 0, warning: 0, total: 0, acknowledged: 0 },
		ranAt: null,
		filterType: 'all',
		includeAcknowledged: false,
		busy: false,
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

	async function load( forceRefresh = false ) {
		state.view = 'loading';
		state.message = null;
		render();
		const params = new URLSearchParams();
		if ( state.includeAcknowledged ) params.set( 'include_acknowledged', 'true' );
		const path = '/diagnostics' + ( forceRefresh ? '/refresh' : '' ) + ( params.toString() ? '?' + params.toString() : '' );
		try {
			const res = await ConfigKit.request( path, forceRefresh ? { method: 'POST', body: {} } : undefined );
			state.issues = res.issues || [];
			state.counts = res.counts || state.counts;
			state.ranAt = res.ran_at || null;
			state.view = 'list';
		} catch ( err ) {
			showError( err );
			state.view = 'error';
		}
		render();
	}

	async function acknowledge( issue ) {
		if ( state.busy ) return;
		const note = window.prompt( 'Optional note for the audit log:', '' );
		if ( note === null ) return; // cancelled
		state.busy = true;
		render();
		try {
			await ConfigKit.request( '/diagnostics/acknowledge', {
				method: 'POST',
				body: {
					issue_id: issue.id,
					object_type: issue.object_type,
					object_id: issue.object_id,
					note: note,
				},
			} );
			state.message = { kind: 'success', text: 'Issue marked as known.' };
			await load( true );
			return;
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	function setTab( type ) {
		state.filterType = type;
		render();
	}

	function setIncludeAck( v ) {
		state.includeAcknowledged = v;
		load( false );
	}

	function render() {
		root.dataset.loading = state.view === 'loading' ? 'true' : 'false';
		root.replaceChildren();

		root.appendChild( renderToolbar() );
		if ( state.message ) root.appendChild( messageBanner( state.message ) );

		if ( state.view === 'loading' ) {
			root.appendChild( el( 'p', { class: 'configkit-app__loading' }, 'Scanning…' ) );
			return;
		}
		if ( state.view === 'error' ) {
			root.appendChild( el(
				'p',
				null,
				el( 'button', { type: 'button', class: 'button', onClick: () => load( false ) }, 'Retry' )
			) );
			return;
		}

		root.appendChild( renderTabs() );
		root.appendChild( renderIssueList() );
		if ( state.ranAt ) {
			root.appendChild( el( 'p', { class: 'configkit-diagnostics__meta' }, 'Last scanned: ' + state.ranAt + ' UTC' ) );
		}
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

	function renderToolbar() {
		return el(
			'div',
			{ class: 'configkit-diagnostics__toolbar' },
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: () => load( true ),
			}, state.busy ? 'Working…' : 'Re-scan' ),
			el(
				'label',
				{ class: 'configkit-checkbox' },
				el( 'input', {
					type: 'checkbox',
					checked: state.includeAcknowledged,
					onChange: ( ev ) => setIncludeAck( ev.target.checked ),
				} ),
				' ',
				'Show all (including acknowledged)'
			),
			el(
				'span',
				{ class: 'configkit-diagnostics__counts' },
				renderCount( 'critical', state.counts.critical ),
				renderCount( 'warning', state.counts.warning ),
				state.counts.acknowledged > 0
					? renderCount( 'acknowledged', state.counts.acknowledged )
					: null
			)
		);
	}

	function renderCount( kind, n ) {
		if ( n === 0 && kind !== 'critical' ) return el( 'span' );
		const labels = { critical: 'critical', warning: 'warning', acknowledged: 'acknowledged' };
		return el(
			'span',
			{ class: 'configkit-diagnostics__count configkit-diagnostics__count--' + kind },
			n + ' ' + labels[ kind ]
		);
	}

	function renderTabs() {
		const wrap = el( 'nav', { class: 'configkit-diagnostics__tabs nav-tab-wrapper' } );
		const counts = countByType( state.issues );
		TAB_ORDER.forEach( ( type ) => {
			const n = type === 'all' ? state.issues.length : ( counts[ type ] || 0 );
			if ( type !== 'all' && n === 0 ) return;
			const cls = 'nav-tab' + ( state.filterType === type ? ' nav-tab-active' : '' );
			wrap.appendChild( el(
				'a',
				{
					href: '#',
					class: cls,
					onClick: ( ev ) => { ev.preventDefault(); setTab( type ); },
				},
				TYPE_LABELS[ type ] + ' (' + n + ')'
			) );
		} );
		return wrap;
	}

	function countByType( issues ) {
		const out = {};
		issues.forEach( ( i ) => { out[ i.object_type ] = ( out[ i.object_type ] || 0 ) + 1; } );
		return out;
	}

	function renderIssueList() {
		const visible = state.filterType === 'all'
			? state.issues
			: state.issues.filter( ( i ) => i.object_type === state.filterType );

		if ( visible.length === 0 ) {
			return el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, state.issues.length === 0
					? 'No critical issues. Everything looks clean.'
					: 'No issues match this tab.' )
			);
		}

		const list = el( 'ul', { class: 'configkit-diagnostics__list' } );
		visible.forEach( ( issue ) => list.appendChild( renderIssue( issue ) ) );
		return list;
	}

	function renderIssue( issue ) {
		const severityCls = 'configkit-diagnostics__issue configkit-diagnostics__issue--' + issue.severity
			+ ( issue.acknowledged ? ' configkit-diagnostics__issue--acknowledged' : '' );

		const objectLabel = issue.object_name || ( '#' + issue.object_id );

		return el(
			'li',
			{ class: severityCls },
			el(
				'div',
				{ class: 'configkit-diagnostics__issue-head' },
				el( 'span', {
					class: 'configkit-badge configkit-badge--' + ( issue.severity === 'critical' ? 'error' : 'warning' ),
				}, issue.severity ),
				el( 'strong', null, issue.title ),
				el( 'span', { class: 'configkit-diagnostics__issue-object' }, ' — ' + ( TYPE_LABELS[ issue.object_type ] || issue.object_type ) + ': ' + objectLabel ),
				issue.acknowledged
					? el( 'span', { class: 'configkit-diagnostics__ack' }, '  (acknowledged ' + ( issue.ack_at || '' ) + ')' )
					: null
			),
			el( 'p', { class: 'configkit-diagnostics__issue-message' }, issue.message ),
			issue.suggested_fix
				? el( 'p', { class: 'configkit-diagnostics__issue-fix' },
					el( 'span', { class: 'configkit-diagnostics__fix-label' }, 'Suggested fix: ' ),
					issue.suggested_fix
				)
				: null,
			el(
				'div',
				{ class: 'configkit-diagnostics__issue-actions' },
				( issue.fix_link || issue.fix_url )
					? el( 'a', { href: issue.fix_link || issue.fix_url, class: 'button button-primary' }, fixButtonLabel( issue ) )
					: null,
				issue.acknowledged
					? null
					: el( 'button', {
						type: 'button',
						class: 'button',
						disabled: state.busy,
						onClick: () => acknowledge( issue ),
					}, 'Mark as known' )
			)
		);
	}

	function fixButtonLabel( issue ) {
		var url = issue.fix_link || issue.fix_url || '';
		if ( /#cells\b/.test( url ) )                return 'Open cells editor →';
		if ( /#publish\b/.test( url ) )              return 'Open template publish →';
		if ( /tab=rules/.test( url ) )               return 'Open rules drawer →';
		if ( /configkit_product_data/.test( url ) )  return 'Open product binding →';
		return 'Open fix →';
	}

	load( false );
} )();
