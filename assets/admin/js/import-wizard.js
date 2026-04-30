/* global ConfigKit */
( function () {
	'use strict';

	const root = document.getElementById( 'configkit-imports-app' );
	if ( ! root ) return;

	// Phase 4 dalis 4 — Quick import (Excel-first wizard) state.
	const quick = {
		step: 'idle',       // 'idle' | 'detecting' | 'confirm' | 'creating' | 'done' | 'error'
		message: null,
		detected: null,     // /imports/quick/detect response
		fileName: null,
		// Confirm-step inputs (prefilled from detect response).
		name: '',
		technicalKey: '',
		moduleKey: '',
		mode: 'insert_update',
		userEditedKey: false,
		result: null,       // /imports/quick/create response
	};

	// State machine for the wizard.
	const state = {
		step: 1,            // 1 = pick / 2 = upload / 3 = preview / 4 = result
		view: 'wizard',     // 'wizard' | 'list' | 'detail'
		busy: false,
		message: null,

		// Step 1 inputs
		importType: 'lookup_cells',
		targetTableKey: '',
		targetLibraryKey: '',
		mode: 'insert_update',
		lookupTables: [],
		libraries: [],

		// Upload state
		fileName: null,
		fileSize: null,

		// Step 3+
		batch: null,         // full record from /imports/{id}
		commitSummary: null, // { inserted, updated, skipped }

		// Recent imports list
		list: { items: [] },
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
		} catch ( e ) { desc = null; }
		if ( ! desc ) {
			desc = {
				kind: 'error',
				friendly: ( err && err.message ) || 'Something went wrong.',
				technical: ( err && err.message ) || '',
			};
		}
		state.message = { kind: desc.kind, text: desc.friendly, technical: desc.technical };
	}

	function clearMessage() { state.message = null; }

	async function loadLookupTables() {
		try {
			const data = await ConfigKit.request( '/lookup-tables?per_page=200' );
			state.lookupTables = ( data.items || [] ).filter( ( t ) => t.is_active );
		} catch ( e ) { state.lookupTables = []; }
	}

	async function loadLibraries() {
		try {
			const data = await ConfigKit.request( '/libraries?per_page=500' );
			state.libraries = ( data.items || [] ).filter( ( l ) => l.is_active );
		} catch ( e ) { state.libraries = []; }
	}

	async function loadList() {
		try {
			const data = await ConfigKit.request( '/imports?per_page=20' );
			state.list = data;
		} catch ( e ) { state.list = { items: [] }; }
	}

	async function init() {
		state.view = 'wizard';
		state.step = 1;
		await Promise.all( [ loadLookupTables(), loadLibraries(), loadList() ] );

		// Contextual entry: detail pages send owners here with the
		// destination already chosen via URL params. Skip step 1 and
		// land directly on the upload screen. Two flavours:
		//   ?target_type=lookup_cells&target_lookup_table_key=KEY
		//   ?target_type=library_items&target_library_key=KEY (or target_id=42)
		var params = new URLSearchParams( window.location.search );
		var targetType  = params.get( 'target_type' ) || '';
		var targetKey   = params.get( 'target_lookup_table_key' ) || '';
		var targetLibKey = params.get( 'target_library_key' ) || '';
		var targetId     = params.get( 'target_id' );
		if ( targetType === 'lookup_cells' && targetKey !== '' ) {
			var hit = state.lookupTables.find( function ( t ) { return t.lookup_table_key === targetKey; } );
			if ( hit ) {
				state.importType   = 'lookup_cells';
				state.targetTableKey = targetKey;
				if ( params.get( 'mode' ) === 'replace_all' ) state.mode = 'replace_all';
				state.step = 2;
				state.contextual = true;
			}
		} else if ( targetType === 'library_items' ) {
			if ( targetLibKey === '' && targetId ) {
				var libHit = state.libraries.find( function ( l ) { return String( l.id ) === String( targetId ); } );
				if ( libHit ) targetLibKey = libHit.library_key;
			}
			var libHit2 = state.libraries.find( function ( l ) { return l.library_key === targetLibKey; } );
			if ( libHit2 ) {
				state.importType      = 'library_items';
				state.targetLibraryKey = targetLibKey;
				if ( params.get( 'mode' ) === 'replace_all' ) state.mode = 'replace_all';
				state.step = 2;
				state.contextual = true;
			}
		}

		render();
	}

	// ---- Actions ----

	async function uploadFile( file ) {
		if ( state.busy ) return;
		if ( ! file ) {
			showError( { message: 'Pick a file first.' } );
			render();
			return;
		}
		if ( ! /\.xlsx$/i.test( file.name ) ) {
			showError( { message: 'Only .xlsx files are supported in this chunk.' } );
			render();
			return;
		}
		if ( file.size > 10 * 1024 * 1024 ) {
			showError( { message: 'File exceeds the 10 MB limit.' } );
			render();
			return;
		}

		state.busy = true;
		state.message = null;
		state.fileName = file.name;
		state.fileSize = file.size;
		render();

		const form = new FormData();
		form.append( 'file', file );
		form.append( 'import_type', state.importType );
		if ( state.importType === 'library_items' ) {
			form.append( 'target_library_key', state.targetLibraryKey );
		} else {
			form.append( 'target_lookup_table_key', state.targetTableKey );
		}
		form.append( 'mode', state.mode );

		try {
			const url = window.CONFIGKIT.restUrl + '/imports';
			const res = await fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CONFIGKIT.nonce },
				body: form,
			} );
			let payload = null;
			try { payload = await res.json(); } catch ( e ) { payload = null; }
			if ( ! res.ok ) {
				const e = new Error( payload && payload.message ? payload.message : res.statusText );
				e.code = payload && payload.code ? payload.code : 'http_' + res.status;
				e.status = res.status;
				e.data = payload && payload.data ? payload.data : null;
				throw e;
			}
			state.batch = payload && payload.record ? payload.record : null;
			state.step = 3;
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	async function commit() {
		if ( state.busy || ! state.batch ) return;
		if ( state.mode === 'replace_all' ) {
			const ok = window.confirm(
				'Replace all mode will DELETE every existing cell in this lookup table before inserting the new ones. This cannot be undone.\n\nProceed?'
			);
			if ( ! ok ) return;
		}
		state.busy = true;
		state.message = null;
		render();
		try {
			const res = await ConfigKit.request( '/imports/' + state.batch.id + '/commit', {
				method: 'POST',
				body: {},
			} );
			state.batch = res.record || state.batch;
			state.commitSummary = res.summary || null;
			state.step = 4;
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	async function cancel() {
		if ( ! state.batch ) {
			resetWizard();
			return;
		}
		state.busy = true;
		render();
		try {
			await ConfigKit.request( '/imports/' + state.batch.id + '/cancel', {
				method: 'POST',
				body: {},
			} );
		} catch ( e ) { /* swallow — user is leaving anyway */ }
		state.busy = false;
		resetWizard();
	}

	function resetWizard() {
		state.step = 1;
		state.batch = null;
		state.commitSummary = null;
		state.fileName = null;
		state.fileSize = null;
		state.message = null;
		render();
		loadList().then( render );
	}

	// ---- Rendering ----

	function render() {
		root.dataset.loading = 'false';
		root.replaceChildren();

		// History-first layout: when no upload is in flight (step 1
		// and no batch loaded) AND the owner hasn't arrived
		// contextually, lead with the past batches and offer the
		// wizard below. As soon as the owner advances the wizard
		// (steps 2/3/4) we put the wizard at the top and the
		// history below.
		var historyTop = ! state.contextual && state.step === 1 && ! state.batch;

		// Phase 4 dalis 4 — Quick import lives at the top whenever the
		// owner hasn't started a manual wizard run.
		if ( historyTop ) {
			root.appendChild( renderQuickImport() );
		}

		if ( historyTop ) {
			root.appendChild( renderRecentBatches() );
			if ( state.message ) root.appendChild( messageBanner( state.message ) );
			root.appendChild( renderStepper() );
			root.appendChild( renderStep1() );
		} else {
			root.appendChild( renderStepper() );
			if ( state.message ) root.appendChild( messageBanner( state.message ) );
			if ( state.step === 1 ) root.appendChild( renderStep1() );
			else if ( state.step === 2 ) root.appendChild( renderStep2() );
			else if ( state.step === 3 ) root.appendChild( renderStep3() );
			else if ( state.step === 4 ) root.appendChild( renderStep4() );
			root.appendChild( renderRecentBatches() );
		}
	}

	// =========================================================
	// Quick import (Excel-first) — Phase 4 dalis 4
	// =========================================================

	async function quickUploadAndDetect( file ) {
		if ( ! file ) return;
		if ( ! /\.xlsx$/i.test( file.name ) ) {
			quick.step = 'error';
			quick.message = { kind: 'error', text: 'Only .xlsx files are supported.' };
			render();
			return;
		}
		if ( file.size > 10 * 1024 * 1024 ) {
			quick.step = 'error';
			quick.message = { kind: 'error', text: 'File exceeds the 10 MB limit.' };
			render();
			return;
		}
		quick.step = 'detecting';
		quick.message = null;
		quick.detected = null;
		quick.fileName = file.name;
		render();

		const form = new FormData();
		form.append( 'file', file );
		try {
			const url = window.CONFIGKIT.restUrl + '/imports/quick/detect';
			const res = await fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.CONFIGKIT.nonce },
				body: form,
			} );
			const payload = await res.json().catch( () => null );
			if ( ! res.ok ) {
				const msg = ( payload && payload.message ) || res.statusText;
				quick.step = 'error';
				quick.message = { kind: 'error', text: msg };
				render();
				return;
			}
			quick.detected     = payload;
			quick.name         = payload.suggested_name || '';
			quick.technicalKey = payload.suggested_key  || '';
			// Phase 4.2c — prefer the auto-detected module when the
			// header overlap was confident (>= threshold). Otherwise
			// fall back to the first active module so the dropdown
			// doesn't render unselected.
			quick.moduleKey    = payload.suggested_module
				|| ( ( payload.available_modules && payload.available_modules.length > 0 )
					? payload.available_modules[0].module_key
					: '' );
			quick.userEditedKey = false;
			quick.step = 'confirm';
			render();
		} catch ( err ) {
			quick.step = 'error';
			quick.message = { kind: 'error', text: ( err && err.message ) || 'Upload failed.' };
			render();
		}
	}

	async function quickConfirmAndCreate() {
		if ( ! quick.detected || quick.step === 'creating' ) return;
		if ( ! quick.name.trim() || ! quick.technicalKey.trim() ) {
			quick.message = { kind: 'error', text: 'Name and Technical key are both required.' };
			render();
			return;
		}
		if ( quick.detected.target_type === 'library' && ! quick.moduleKey ) {
			quick.message = { kind: 'error', text: 'Pick a module for the new library.' };
			render();
			return;
		}
		if ( quick.mode === 'replace_all' ) {
			const okGo = window.confirm(
				'Replace-all mode will wipe existing rows in the target before inserting. Proceed?'
			);
			if ( ! okGo ) return;
		}

		quick.step = 'creating';
		quick.message = null;
		render();

		try {
			const res = await ConfigKit.request( '/imports/quick/create', {
				method: 'POST',
				body: {
					file_token:    quick.detected.file_token,
					target_type:   quick.detected.target_type,
					name:          quick.name,
					technical_key: quick.technicalKey,
					module_key:    quick.moduleKey,
					mode:          quick.mode,
					filename:      quick.detected.original_name || quick.fileName,
				},
			} );
			quick.result = res;
			quick.step = 'done';
			render();
			loadList().then( render );
		} catch ( err ) {
			quick.step = 'confirm';
			quick.message = { kind: 'error', text: ( err && err.message ) || 'Quick import failed.' };
			render();
		}
	}

	function quickReset() {
		quick.step = 'idle';
		quick.message = null;
		quick.detected = null;
		quick.fileName = null;
		quick.name = '';
		quick.technicalKey = '';
		quick.moduleKey = '';
		quick.mode = 'insert_update';
		quick.userEditedKey = false;
		quick.result = null;
		render();
	}

	function renderQuickImport() {
		const wrap = el( 'section', { class: 'configkit-quick-import' } );
		wrap.appendChild( el( 'h2', { class: 'configkit-quick-import__title' }, 'Quick import from Excel' ) );
		wrap.appendChild( el(
			'p',
			{ class: 'description' },
			'Drop an .xlsx and ConfigKit creates the lookup table or library for you. '
			+ 'For advanced options, use the wizard below.'
		) );

		if ( quick.message ) wrap.appendChild( messageBanner( quick.message ) );

		if ( quick.step === 'idle' || quick.step === 'detecting' || quick.step === 'error' ) {
			const drop = el( 'div', { class: 'configkit-dropzone configkit-dropzone--quick', tabindex: '0' } );
			drop.appendChild( el( 'p', null, quick.step === 'detecting' ? 'Reading file…' : 'Drop a .xlsx here, or click to choose.' ) );
			drop.appendChild( el( 'p', { class: 'description' }, 'Max 10 MB. ConfigKit will detect the format and propose a target.' ) );

			const fileInput = el( 'input', {
				type: 'file',
				accept: '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				class: 'configkit-dropzone__input',
				onChange: ( ev ) => {
					if ( ev.target.files && ev.target.files[0] ) quickUploadAndDetect( ev.target.files[0] );
				},
			} );
			drop.appendChild( fileInput );
			drop.addEventListener( 'click', () => fileInput.click() );
			drop.addEventListener( 'dragover', ( ev ) => {
				ev.preventDefault();
				drop.classList.add( 'configkit-dropzone--hover' );
			} );
			drop.addEventListener( 'dragleave', () => drop.classList.remove( 'configkit-dropzone--hover' ) );
			drop.addEventListener( 'drop', ( ev ) => {
				ev.preventDefault();
				drop.classList.remove( 'configkit-dropzone--hover' );
				if ( ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0] ) {
					quickUploadAndDetect( ev.dataTransfer.files[0] );
				}
			} );
			wrap.appendChild( drop );
			return wrap;
		}

		if ( quick.step === 'confirm' || quick.step === 'creating' ) {
			wrap.appendChild( renderQuickConfirm() );
			return wrap;
		}

		if ( quick.step === 'done' ) {
			wrap.appendChild( renderQuickResult() );
			return wrap;
		}
		return wrap;
	}

	function renderQuickConfirm() {
		const d = quick.detected || {};
		const formatLabel = d.format === 'A' ? 'Grid (Format A)'
			: d.format === 'B' ? 'Long (Format B)'
			: d.format === 'C' ? 'Library items (Format C)'
			: d.format;
		const targetLabel = d.target_type === 'library' ? 'New library' : 'New lookup table';

		const wrap = el( 'div', { class: 'configkit-quick-import__confirm' } );
		wrap.appendChild( el( 'p', null,
			el( 'strong', null, targetLabel ),
			' · ',
			el( 'em', null, formatLabel ),
			' · ',
			'File: ' + ( quick.fileName || 'upload' )
		) );

		// Name input — auto-keys the technical key on each change.
		const nameField = el( 'div', { class: 'configkit-field' } );
		nameField.appendChild( el( 'label', { for: 'quick_name' }, 'Name' ) );
		nameField.appendChild( el( 'input', {
			id: 'quick_name',
			type: 'text',
			class: 'regular-text',
			value: quick.name,
			onInput: ( ev ) => {
				quick.name = ev.target.value;
				if ( ! quick.userEditedKey ) {
					quick.technicalKey = ( window.ConfigKit && window.ConfigKit.slugify )
						? window.ConfigKit.slugify( quick.name, {
							fallbackPrefix: d.target_type === 'library' ? 'library' : 'table',
						} )
						: quick.name.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
					const tech = document.getElementById( 'quick_tech_key' );
					if ( tech && tech.value !== quick.technicalKey ) tech.value = quick.technicalKey;
				}
			},
		} ) );
		wrap.appendChild( nameField );

		// Phase 4.2c — auto-detected module note before the dropdown.
		if ( d.target_type === 'library' && d.suggested_module ) {
			const ratio = d.module_match ? Math.round( ( d.module_match.ratio || 0 ) * 100 ) : 0;
			const matched = ( d.available_modules || [] ).find( ( m ) => m.module_key === d.suggested_module );
			const moduleName = matched ? matched.name : d.suggested_module;
			wrap.appendChild( el(
				'p',
				{ class: 'description configkit-quick-import__detected' },
				'Detected module: ',
				el( 'strong', null, moduleName ),
				' (',
				ratio + '% of headers match',
				')'
			) );
		}

		// Module dropdown for libraries.
		if ( d.target_type === 'library' ) {
			const modField = el( 'div', { class: 'configkit-field' } );
			modField.appendChild( el( 'label', { for: 'quick_module' }, 'Module' ) );
			const sel = el( 'select', {
				id: 'quick_module',
				onChange: ( ev ) => { quick.moduleKey = ev.target.value; },
			} );
			sel.appendChild( el( 'option', { value: '' }, '— Select a module —' ) );
			( d.available_modules || [] ).forEach( ( m ) => {
				const o = el( 'option', { value: m.module_key }, m.name + ' (' + m.module_key + ')' );
				if ( m.module_key === quick.moduleKey ) o.selected = true;
				sel.appendChild( o );
			} );
			modField.appendChild( sel );
			if ( ( d.available_modules || [] ).length === 0 ) {
				modField.appendChild( el( 'p', { class: 'description' },
					'No active modules — create one in ConfigKit → Settings → Modules first.'
				) );
			}
			wrap.appendChild( modField );
		}

		// Collapsed Technical key (mirrors the entity-form treatment).
		const advanced = el( 'details', { class: 'configkit-quick-import__advanced' } );
		advanced.appendChild( el( 'summary', null, 'Technical key (auto-generated)' ) );
		const techField = el( 'div', { class: 'configkit-field' } );
		techField.appendChild( el( 'label', { for: 'quick_tech_key' }, 'Technical key' ) );
		techField.appendChild( el( 'input', {
			id: 'quick_tech_key',
			type: 'text',
			class: 'regular-text code',
			value: quick.technicalKey,
			onInput: ( ev ) => {
				quick.technicalKey = ev.target.value;
				quick.userEditedKey = true;
			},
		} ) );
		techField.appendChild( el( 'p', { class: 'description' },
			'Auto-filled from Name. Edit only if you need a specific key — locked once the entity is saved.'
		) );
		advanced.appendChild( techField );
		wrap.appendChild( advanced );

		// Sample summary.
		if ( d.sample ) {
			const ul = el( 'ul', { class: 'configkit-quick-import__sample' } );
			if ( typeof d.sample.rows_total === 'number' ) {
				ul.appendChild( el( 'li', null, d.sample.rows_total + ' rows detected' ) );
			}
			if ( Array.isArray( d.sample.columns ) && d.sample.columns.length > 0 ) {
				ul.appendChild( el( 'li', null, 'Columns: ' + d.sample.columns.join( ', ' ) ) );
			}
			if ( Array.isArray( d.sample.libraries ) && d.sample.libraries.length > 0 ) {
				ul.appendChild( el( 'li', null, 'library_key in file: ' + d.sample.libraries.join( ', ' ) ) );
			}
			if ( Array.isArray( d.sample.sheet_titles ) && d.sample.sheet_titles.length > 0 ) {
				ul.appendChild( el( 'li', null, 'Sheets: ' + d.sample.sheet_titles.join( ', ' ) ) );
			}
			wrap.appendChild( ul );
		}

		// Mode picker.
		wrap.appendChild( radio(
			'quick_mode',
			[
				{ value: 'insert_update', label: 'Insert / update (default)' },
				{ value: 'replace_all',   label: 'Replace all (wipe existing first)' },
			],
			quick.mode,
			( v ) => { quick.mode = v; render(); }
		) );

		const isCreating = quick.step === 'creating';
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: isCreating,
				onClick: quickConfirmAndCreate,
			}, isCreating ? 'Creating + importing…' : 'Create and import' ),
			el( 'button', { type: 'button', class: 'button', onClick: quickReset }, 'Cancel' )
		) );
		return wrap;
	}

	function renderQuickResult() {
		const r = quick.result || {};
		const wrap = el( 'div', { class: 'configkit-quick-import__result' } );
		const summary = r.summary || {};
		const t = r.target || {};
		wrap.appendChild( el(
			'p',
			null,
			'Created ',
			el( 'strong', null, t.name || quick.name ),
			' and imported ',
			( summary.inserted || 0 ) + ' rows',
			summary.updated ? ', ' + summary.updated + ' updated' : '',
			summary.skipped ? ', ' + summary.skipped + ' skipped' : '',
			'.'
		) );
		const link = r.target_type === 'library'
			? ( window.location.pathname || '' ) + '?page=configkit-libraries' + ( t.id ? '&id=' + t.id : '' )
			: ( window.location.pathname || '' ) + '?page=configkit-lookup-tables' + ( t.id ? '&id=' + t.id : '' );
		const linkLabel = r.target_type === 'library' ? 'Open library' : 'Open lookup table';
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'a', { href: link, class: 'button button-primary' }, linkLabel ),
			el( 'button', { type: 'button', class: 'button', onClick: quickReset }, 'Import another file' )
		) );
		return wrap;
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

	function renderStepper() {
		const steps = [
			{ n: 1, label: 'Pick destination' },
			{ n: 2, label: 'Upload file' },
			{ n: 3, label: 'Preview' },
			{ n: 4, label: 'Result' },
		];
		const nav = el( 'ol', { class: 'configkit-import-stepper' } );
		steps.forEach( ( s ) => {
			const cls = 'configkit-import-stepper__step '
				+ ( state.step === s.n
					? 'configkit-import-stepper__step--current'
					: ( state.step > s.n
						? 'configkit-import-stepper__step--done'
						: 'configkit-import-stepper__step--pending' ) );
			nav.appendChild( el(
				'li',
				{ class: cls },
				el( 'span', { class: 'configkit-import-stepper__num' }, String( s.n ) ),
				el( 'span', { class: 'configkit-import-stepper__label' }, s.label )
			) );
		} );
		return nav;
	}

	function renderStep1() {
		const wrap = el( 'section', { class: 'configkit-import-step' } );
		wrap.appendChild( el( 'h3', null, 'Step 1 — Pick destination' ) );

		// Import what?
		wrap.appendChild( el( 'p', { class: 'configkit-import-step__field-label' }, 'Import what?' ) );
		wrap.appendChild( radio(
			'import_type',
			[
				{ value: 'lookup_cells',  label: 'Lookup table cells' },
				{ value: 'library_items', label: 'Library items' },
			],
			state.importType,
			( v ) => { state.importType = v; render(); }
		) );

		// Target dropdown depends on import_type.
		if ( state.importType === 'library_items' ) {
			wrap.appendChild( el( 'p', { class: 'configkit-import-step__field-label' }, 'Target library' ) );
			const libSelect = el( 'select', {
				id: 'cf_target_library',
				onChange: ( ev ) => { state.targetLibraryKey = ev.target.value; render(); },
			} );
			libSelect.appendChild( el( 'option', { value: '' }, '— Select a library —' ) );
			state.libraries.forEach( ( l ) => {
				const o = el( 'option', { value: l.library_key }, l.name + ' (' + l.library_key + ')' );
				if ( l.library_key === state.targetLibraryKey ) o.selected = true;
				libSelect.appendChild( o );
			} );
			wrap.appendChild( libSelect );
			if ( state.libraries.length === 0 ) {
				wrap.appendChild( el(
					'p',
					{ class: 'description' },
					'No active libraries yet. Create one first in ConfigKit → Libraries.'
				) );
			}
		} else {
			wrap.appendChild( el( 'p', { class: 'configkit-import-step__field-label' }, 'Target lookup table' ) );
			const select = el( 'select', {
				id: 'cf_target_table',
				onChange: ( ev ) => { state.targetTableKey = ev.target.value; render(); },
			} );
			select.appendChild( el( 'option', { value: '' }, '— Select a lookup table —' ) );
			state.lookupTables.forEach( ( t ) => {
				const o = el( 'option', { value: t.lookup_table_key }, t.name + ' (' + t.lookup_table_key + ')' );
				if ( t.lookup_table_key === state.targetTableKey ) o.selected = true;
				select.appendChild( o );
			} );
			wrap.appendChild( select );
			if ( state.lookupTables.length === 0 ) {
				wrap.appendChild( el(
					'p',
					{ class: 'description' },
					'No active lookup tables yet. Create one first in ConfigKit → Lookup Tables.'
				) );
			}
		}

		// Mode
		wrap.appendChild( el( 'p', { class: 'configkit-import-step__field-label' }, 'Mode' ) );
		const replaceLabel = state.importType === 'library_items'
			? 'Replace all (soft-delete every item in this library first, then insert)'
			: 'Replace all (delete every cell in this table first, then insert)';
		wrap.appendChild( radio(
			'import_mode',
			[
				{ value: 'insert_update', label: 'Insert / update only (default — match by item_key, keep existing rows)' },
				{ value: 'replace_all',   label: replaceLabel },
			],
			state.mode,
			( v ) => { state.mode = v; render(); }
		) );
		if ( state.mode === 'replace_all' ) {
			const warning = state.importType === 'library_items'
				? 'Replace-all mode will soft-delete every item in this library before inserting. You will be asked to confirm before commit.'
				: 'Replace-all mode will DELETE every existing cell in the target table before inserting. You will be asked to confirm before commit.';
			wrap.appendChild( el(
				'p',
				{ class: 'configkit-soft-warnings' },
				el( 'span', null, warning )
			) );
		}

		const targetChosen = state.importType === 'library_items'
			? state.targetLibraryKey !== ''
			: state.targetTableKey !== '';

		// Continue
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: ! targetChosen,
				onClick: () => { state.step = 2; render(); },
			}, 'Continue' )
		) );

		return wrap;
	}

	function renderStep2() {
		const wrap = el( 'section', { class: 'configkit-import-step' } );
		wrap.appendChild( el( 'h3', null, 'Step 2 — Upload file' ) );

		const target = state.importType === 'library_items' ? state.targetLibraryKey : state.targetTableKey;
		wrap.appendChild( el(
			'p',
			{ class: 'description' },
			'Target: ' + target + ' · Mode: ' + state.mode
		) );

		const drop = el( 'div', { class: 'configkit-dropzone', tabindex: '0' } );
		drop.appendChild( el( 'p', null, state.busy ? 'Uploading…' : 'Drop a .xlsx file here, or click to choose.' ) );
		drop.appendChild( el( 'p', { class: 'description' }, 'Max 10 MB. .xlsx only.' ) );

		const fileInput = el( 'input', {
			type: 'file',
			accept: '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			class: 'configkit-dropzone__input',
			onChange: ( ev ) => {
				if ( ev.target.files && ev.target.files[ 0 ] ) uploadFile( ev.target.files[ 0 ] );
			},
		} );
		drop.appendChild( fileInput );

		drop.addEventListener( 'click', () => fileInput.click() );
		drop.addEventListener( 'dragover', ( ev ) => {
			ev.preventDefault();
			drop.classList.add( 'configkit-dropzone--hover' );
		} );
		drop.addEventListener( 'dragleave', () => drop.classList.remove( 'configkit-dropzone--hover' ) );
		drop.addEventListener( 'drop', ( ev ) => {
			ev.preventDefault();
			drop.classList.remove( 'configkit-dropzone--hover' );
			if ( ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[ 0 ] ) {
				uploadFile( ev.dataTransfer.files[ 0 ] );
			}
		} );

		wrap.appendChild( drop );

		if ( state.fileName ) {
			wrap.appendChild( el(
				'p',
				{ class: 'description' },
				'Selected: ' + state.fileName + ' (' + Math.round( ( state.fileSize || 0 ) / 1024 ) + ' KB)'
			) );
		}

		const backHref = state.importType === 'library_items'
			? ( window.location.pathname || '' ) + '?page=configkit-libraries'
			: ( window.location.pathname || '' ) + '?page=configkit-lookup-tables';
		const backLabel = state.importType === 'library_items'
			? '← Back to Libraries'
			: '← Back to Lookup Tables';
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			state.contextual
				? el( 'a', { href: backHref, class: 'button' }, backLabel )
				: el( 'button', {
					type: 'button',
					class: 'button',
					onClick: () => { state.step = 1; state.fileName = null; render(); },
				}, '← Back' )
		) );

		return wrap;
	}

	function renderStep3() {
		const wrap = el( 'section', { class: 'configkit-import-step' } );
		wrap.appendChild( el( 'h3', null, 'Step 3 — Preview' ) );

		const b = state.batch || {};
		const summary = b.summary || {};
		const counts  = b.counts || {};
		const stats   = summary.stats || {};

		const targetKey = state.importType === 'library_items' ? state.targetLibraryKey : state.targetTableKey;
		const formatLabel = summary.format === 'A'
			? 'Grid (Format A)'
			: summary.format === 'B'
				? 'Long (Format B)'
				: summary.format === 'C'
					? 'Library items (Format C)'
					: 'Unknown';
		wrap.appendChild( el(
			'p',
			null,
			el( 'strong', null, 'Target: ' ),
			targetKey,
			' · ',
			el( 'strong', null, 'Format: ' ),
			formatLabel
		) );

		const summaryList = el( 'ul', { class: 'configkit-import-summary' } );
		summaryList.appendChild( el( 'li', { class: 'configkit-import-summary__row' },
			'✓ ', String( counts.green || 0 ), ' valid' ) );
		summaryList.appendChild( el( 'li', { class: 'configkit-import-summary__row configkit-import-summary__row--warn' },
			'⚠ ', String( counts.yellow || 0 ), ' warnings' ) );
		summaryList.appendChild( el( 'li', { class: 'configkit-import-summary__row configkit-import-summary__row--err' },
			'✗ ', String( counts.red || 0 ), ' errors' ) );
		summaryList.appendChild( el( 'li', null,
			'Total rows parsed: ', String( counts.total || 0 ) ) );
		wrap.appendChild( summaryList );

		// Stats block
		const statsList = el( 'ul', { class: 'configkit-import-stats' } );
		if ( stats.width_min != null ) {
			statsList.appendChild( el( 'li', null, 'Width range: ' + stats.width_min + ' – ' + stats.width_max + ' mm' ) );
		}
		if ( stats.height_min != null ) {
			statsList.appendChild( el( 'li', null, 'Height range: ' + stats.height_min + ' – ' + stats.height_max + ' mm' ) );
		}
		if ( stats.price_min != null ) {
			statsList.appendChild( el( 'li', null, 'Price range: ' + stats.price_min + ' – ' + stats.price_max ) );
		}
		if ( Array.isArray( stats.price_groups ) && stats.price_groups.length > 0 ) {
			statsList.appendChild( el( 'li', null, 'Price groups detected: ' + stats.price_groups.join( ', ' ) ) );
		}
		if ( Array.isArray( stats.price_sources ) && stats.price_sources.length > 0 ) {
			statsList.appendChild( el( 'li', null, 'Price sources used: ' + stats.price_sources.join( ', ' ) ) );
		}
		if ( Array.isArray( stats.item_types ) && stats.item_types.length > 0 ) {
			statsList.appendChild( el( 'li', null, 'Item types: ' + stats.item_types.join( ', ' ) ) );
		}
		if ( Array.isArray( summary.columns ) && summary.columns.length > 0 ) {
			statsList.appendChild( el( 'li', null, 'Columns parsed: ' + summary.columns.join( ', ' ) ) );
		}
		if ( statsList.children.length > 0 ) wrap.appendChild( statsList );

		// Action on commit
		const actionList = el( 'ul', { class: 'configkit-import-actions' } );
		const noun = state.importType === 'library_items' ? 'items' : 'cells';
		actionList.appendChild( el( 'li', null, 'Insert ' + ( counts.insert || 0 ) + ' new ' + noun ) );
		actionList.appendChild( el( 'li', null, 'Update ' + ( counts.update || 0 ) + ' existing ' + noun ) );
		actionList.appendChild( el( 'li', null, 'Skip ' + ( counts.skip || 0 ) + ' (errors or duplicates)' ) );
		wrap.appendChild( el( 'h4', null, 'Action on commit' ) );
		wrap.appendChild( actionList );

		// Row-by-row details
		wrap.appendChild( renderRowDetails( b ) );

		const canCommit = ( counts.green || 0 ) + ( counts.yellow || 0 ) > 0
			&& ( b.status === 'validated' );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', {
				type: 'button',
				class: 'button',
				disabled: state.busy,
				onClick: cancel,
			}, 'Cancel import' ),
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: ! canCommit || state.busy,
				onClick: commit,
			}, state.busy ? 'Committing…' : 'Commit import' )
		) );

		return wrap;
	}

	function renderRowDetails( batch ) {
		const rows = batch && batch.rows && Array.isArray( batch.rows.items ) ? batch.rows.items : [];
		if ( rows.length === 0 ) return el( 'div' );
		const wrap = el( 'details', { class: 'configkit-import-rows' } );
		wrap.appendChild( el( 'summary', null, 'Show row details (' + rows.length + ' shown)' ) );
		const tbl = el( 'table', { class: 'wp-list-table widefat striped' } );
		const head = el( 'thead' );

		const isLibraryItems = state.importType === 'library_items';
		if ( isLibraryItems ) {
			head.appendChild( el( 'tr', null,
				el( 'th', null, 'Row' ),
				el( 'th', null, 'Status' ),
				el( 'th', null, 'Action' ),
				el( 'th', null, 'item_key' ),
				el( 'th', null, 'Label' ),
				el( 'th', null, 'SKU' ),
				el( 'th', null, 'Price' ),
				el( 'th', null, 'Message' )
			) );
		} else {
			head.appendChild( el( 'tr', null,
				el( 'th', null, 'Row' ),
				el( 'th', null, 'Status' ),
				el( 'th', null, 'Action' ),
				el( 'th', null, 'Width' ),
				el( 'th', null, 'Height' ),
				el( 'th', null, 'Price group' ),
				el( 'th', null, 'Price' ),
				el( 'th', null, 'Message' )
			) );
		}
		tbl.appendChild( head );

		const body = el( 'tbody' );
		rows.forEach( ( r ) => {
			const norm = r.normalized_data || {};
			const sevClass = 'configkit-row-status configkit-row-status--' + r.severity;
			if ( isLibraryItems ) {
				body.appendChild( el( 'tr', null,
					el( 'td', null, String( r.row_number ) ),
					el( 'td', null, el( 'span', { class: sevClass }, r.severity ) ),
					el( 'td', null, r.action ),
					el( 'td', null, norm.item_key || '—' ),
					el( 'td', null, norm.label || '—' ),
					el( 'td', null, norm.sku || '—' ),
					el( 'td', null, norm.price != null ? String( norm.price ) : '—' ),
					el( 'td', null, r.message || '' )
				) );
			} else {
				body.appendChild( el( 'tr', null,
					el( 'td', null, String( r.row_number ) ),
					el( 'td', null, el( 'span', { class: sevClass }, r.severity ) ),
					el( 'td', null, r.action ),
					el( 'td', null, norm.width != null ? String( norm.width ) : '—' ),
					el( 'td', null, norm.height != null ? String( norm.height ) : '—' ),
					el( 'td', null, norm.price_group_key || '—' ),
					el( 'td', null, norm.price != null ? String( norm.price ) : '—' ),
					el( 'td', null, r.message || '' )
				) );
			}
		} );
		tbl.appendChild( body );
		wrap.appendChild( tbl );
		return wrap;
	}

	function renderStep4() {
		const wrap = el( 'section', { class: 'configkit-import-step' } );
		const s = state.commitSummary || {};
		const isLibraryItems = state.importType === 'library_items';
		wrap.appendChild( el( 'h3', null, 'Step 4 — Imported' ) );

		if ( isLibraryItems ) {
			const lib = state.libraries.find( ( l ) => l.library_key === state.targetLibraryKey );
			const libLabel = lib ? '"' + ( lib.name || state.targetLibraryKey ) + '"' : state.targetLibraryKey;
			wrap.appendChild( el( 'p', null,
				( s.inserted || 0 ) + ' items imported into ' + libLabel + ', '
				+ ( s.updated || 0 ) + ' updated, '
				+ ( s.skipped || 0 ) + ' skipped.'
			) );
		} else {
			wrap.appendChild( el( 'p', null,
				( s.inserted || 0 ) + ' inserted, '
				+ ( s.updated || 0 ) + ' updated, '
				+ ( s.skipped || 0 ) + ' skipped.'
			) );
		}

		const editUrl = isLibraryItems
			? ( window.location.pathname || '' ) + '?page=configkit-libraries' + ( libraryIdForKey( state.targetLibraryKey ) ? '&id=' + libraryIdForKey( state.targetLibraryKey ) : '' )
			: ( window.location.pathname || '' ) + '?page=configkit-lookup-tables&action=edit';
		const editLabel = isLibraryItems ? 'Open library' : 'Open lookup table';

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'a', { href: editUrl, class: 'button' }, editLabel ),
			el( 'button', { type: 'button', class: 'button button-primary', onClick: resetWizard }, 'Import another file' )
		) );
		return wrap;
	}

	function libraryIdForKey( key ) {
		if ( ! key ) return null;
		const lib = state.libraries.find( ( l ) => l.library_key === key );
		return lib ? lib.id : null;
	}

	function renderRecentBatches() {
		const items = state.list && state.list.items ? state.list.items : [];
		const wrap = el( 'section', { class: 'configkit-import-recent' } );
		wrap.appendChild( el( 'h3', null, items.length === 0 ? 'No imports yet' : 'Past imports' ) );
		if ( items.length === 0 ) {
			wrap.appendChild( el(
				'p',
				{ class: 'description' },
				'When you import an Excel file, the batch lands here so you can re-open the preview, see warnings, or audit what changed.'
			) );
			return wrap;
		}
		const tbl = el( 'table', { class: 'wp-list-table widefat striped' } );
		const head = el( 'thead' );
		head.appendChild( el( 'tr', null,
			el( 'th', null, 'When' ),
			el( 'th', null, 'File' ),
			el( 'th', null, 'Target' ),
			el( 'th', null, 'Type' ),
			el( 'th', null, 'Status' ),
			el( 'th', null, 'Rows' )
		) );
		tbl.appendChild( head );
		const body = el( 'tbody' );
		items.forEach( ( b ) => {
			var summary = b.summary || {};
			var stats   = summary.commit_stats || {};
			var rows;
			if ( b.status === 'applied' ) {
				rows = ( stats.inserted || 0 ) + ' inserted, ' + ( stats.updated || 0 ) + ' updated';
				if ( stats.skipped ) rows += ', ' + stats.skipped + ' skipped';
			} else if ( b.status === 'validated' || b.status === 'parsed' ) {
				rows = 'Awaiting commit';
			} else {
				rows = '—';
			}
			const targetText = summary.target_library_key
				? summary.target_library_key
				: summary.target_lookup_table_key || '';
			body.appendChild( el( 'tr', null,
				el( 'td', { 'data-label': 'When' }, b.created_at || '—' ),
				el( 'td', { 'data-label': 'File' }, b.filename || '—' ),
				el( 'td', { 'data-label': 'Target' }, targetText
					? el( 'code', null, targetText )
					: '—' ),
				el( 'td', { 'data-label': 'Type' }, b.import_type ),
				el( 'td', { 'data-label': 'Status' },
					el( 'span', { class: 'configkit-row-status configkit-row-status--' + statusToSeverity( b.status ) }, b.status )
				),
				el( 'td', { 'data-label': 'Rows' }, rows )
			) );
		} );
		tbl.appendChild( body );
		wrap.appendChild( tbl );
		return wrap;
	}

	function statusToSeverity( status ) {
		if ( status === 'applied' ) return 'green';
		if ( status === 'failed' ) return 'red';
		if ( status === 'cancelled' ) return 'yellow';
		return 'yellow';
	}

	function radio( name, choices, current, onChange ) {
		const wrap = el( 'div', { class: 'configkit-radio-group' } );
		choices.forEach( ( c ) => {
			const id = 'cf_' + name + '_' + c.value;
			wrap.appendChild( el(
				'label',
				{ for: id, class: 'configkit-checkbox' },
				el( 'input', {
					id: id,
					type: 'radio',
					name: name,
					value: c.value,
					checked: c.value === current,
					onChange: ( ev ) => onChange( ev.target.value ),
				} ),
				' ',
				c.label
			) );
		} );
		return wrap;
	}

	init();
} )();
