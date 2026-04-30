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

	const FIELD_KIND_CHOICES = [
		{ id: 'enter_number',  label: 'Enter a number', help: 'Numeric input with min/max/step.', axes: { field_kind: 'input',  input_type: 'number',   display_type: 'plain', behavior: 'normal_option', value_source: 'manual_options' } },
		{ id: 'pick_one',      label: 'Pick one option', help: 'Single choice from a small set.',  axes: { field_kind: 'input',  input_type: 'radio',    display_type: 'plain', behavior: 'normal_option' } },
		{ id: 'pick_multiple', label: 'Pick multiple options', help: 'Multi-select from a set.',   axes: { field_kind: 'input',  input_type: 'checkbox', display_type: 'plain', behavior: 'normal_option' } },
		{ id: 'addon',         label: 'Pick a Woo product (add-on)', help: 'Customer attaches a Woo product as an add-on.', axes: { field_kind: 'addon', input_type: 'checkbox', display_type: 'cards', behavior: 'product_addon', value_source: 'woo_category' } },
		{ id: 'show_info',     label: 'Show information (no input)', help: 'Static heading or info block.', axes: { field_kind: 'display', input_type: null, display_type: 'heading', value_source: 'manual_options', behavior: 'presentation_only' } },
		{ id: 'lookup',        label: 'Configure as a lookup dimension', help: 'Advanced. Field feeds a lookup table.', axes: { field_kind: 'lookup', input_type: 'number', display_type: 'plain', behavior: 'lookup_dimension', value_source: 'lookup_table' }, advanced: true },
	];

	const SOURCE_CHOICES_BY_KIND = {
		enter_number:  [], // no source picker — uses manual_options as a no-op default
		pick_one:      [
			{ id: 'manual_options', label: "I'll type them in here" },
			{ id: 'library',        label: 'Pull from a library' },
		],
		pick_multiple: [
			{ id: 'manual_options', label: "I'll type them in here" },
			{ id: 'library',        label: 'Pull from a library' },
		],
		addon: [
			{ id: 'woo_category', label: 'All Woo products in a category' },
			{ id: 'woo_products', label: 'Specific Woo products by SKU' },
		],
		show_info: [],
		lookup:    [],
	};

	const DISPLAY_STYLE_CHOICES = [
		{ id: 'plain',       label: 'Plain controls' },
		{ id: 'cards',       label: 'Cards with images' },
		{ id: 'image_grid',  label: 'Image grid' },
		{ id: 'swatch_grid', label: 'Color swatches' },
	];

	const PRICING_MODE_CHOICES = [
		[ 'none',             "Doesn't affect price" ],
		[ 'fixed',            'Fixed amount per selection' ],
		[ 'per_unit',         'Multiplied by number of selections' ],
		[ 'per_m2',           'Multiplied by area (width × height)' ],
		[ 'lookup_dimension', 'Field feeds the lookup table' ],
	];

	const state = {
		view: 'loading', // 'list' | 'form' | 'detail' | 'step_form' | 'loading'
		list: { items: [], total: 0 },
		editing: null,
		viewing: null, // template currently opened in detail view
		steps: { items: [], total: 0 },
		editingStep: null,
		// B3 additions
		selectedStepId: null,
		fields: { items: [], total: 0 },
		editingField: null, // field whose editor is shown in the right pane
		fieldOptions: { items: [], total: 0 },
		wizard: null, // null when closed; otherwise { step: 1|2|3, kindChoice, sourceChoice, label, fieldKey }
		// B4 additions — rules drawer
		rulesOpen: false,
		rules: { items: [], total: 0 },
		editingRule: null, // structured form: { id, rule_key, name, priority, is_active, conjunction, conditions[], actions[], spec, version_hash, jsonMode, jsonText, jsonLocked }
		// B5 additions — publish + versions
		versionsOpen: false,
		versions: { items: [], total: 0 },
		viewingVersion: null,
		validation: null, // { valid, errors[], warnings[] } or null
		validationOpen: false,
		publishConfirm: null, // null or { nextVersion, validation }
		dirty: false,
		message: null,
		fieldErrors: {},
		busy: false,
	};

	const RULE_OPERATORS = [
		[ 'equals', 'equals' ],
		[ 'not_equals', 'not equals' ],
		[ 'greater_than', 'greater than' ],
		[ 'less_than', 'less than' ],
		[ 'between', 'between (min,max)' ],
		[ 'contains', 'contains' ],
		[ 'is_selected', 'is selected (no value)' ],
		[ 'is_empty', 'is empty (no value)' ],
		[ 'in', 'in (comma-separated)' ],
		[ 'not_in', 'not in (comma-separated)' ],
	];

	const RULE_ACTIONS = [
		[ 'show_field', 'Show field' ],
		[ 'hide_field', 'Hide field' ],
		[ 'show_step', 'Show step' ],
		[ 'hide_step', 'Hide step' ],
		[ 'require_field', 'Require field' ],
		[ 'disable_option', 'Disable option' ],
		[ 'filter_source', 'Filter source by tag' ],
		[ 'set_default', 'Set default value' ],
		[ 'reset_value', 'Reset value' ],
		[ 'switch_lookup_table', 'Switch lookup table' ],
		[ 'add_surcharge', 'Add surcharge' ],
		[ 'show_warning', 'Show warning' ],
		[ 'block_add_to_cart', 'Block add to cart' ],
	];

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

	async function openTemplateDetail( id, opts ) {
		opts = opts || {};
		state.view = 'loading';
		render();
		try {
			const data = await ConfigKit.request( '/templates/' + id );
			state.viewing = data.record;
			const stepsData = await ConfigKit.request( '/templates/' + id + '/steps' );
			state.steps = stepsData;

			// Pick a step to show in the middle pane: explicit selection wins,
			// else the previously-selected step if still present, else first.
			let stepId = opts.selectStepId || state.selectedStepId || 0;
			const stepsList = state.steps.items || [];
			if ( ! stepsList.find( ( s ) => s.id === stepId ) ) {
				stepId = stepsList.length > 0 ? stepsList[ 0 ].id : null;
			}
			state.selectedStepId = stepId;
			await refreshFields();

			// Pick a field to show in the right pane (if requested).
			if ( opts.selectFieldId ) {
				try {
					const fr = await ConfigKit.request( '/templates/' + id + '/fields/' + opts.selectFieldId );
					state.editingField = fr.record;
					await refreshFieldOptions();
				} catch ( e ) {
					state.editingField = null;
				}
			} else {
				state.editingField = null;
				state.fieldOptions = { items: [], total: 0 };
			}

			state.view = 'detail';
			clearMessages();
			const urlParams = { action: null, step_id: state.selectedStepId, step_action: null, field_action: null };
			urlParams.field_id = state.editingField ? state.editingField.id : null;
			urlParams.id = id;
			setUrl( urlParams );
			// Refresh versions in the background so the "Publish v(N+1)"
			// label and rules count are accurate before any drawer opens.
			refreshVersions().then( () => {
				if ( state.view === 'detail' ) render();
			} );
			refreshRules().then( () => {
				if ( state.view === 'detail' ) render();
			} );
			render();
		} catch ( err ) {
			showError( err );
			state.view = 'list';
			render();
		}
	}

	async function refreshFields() {
		if ( ! state.viewing || ! state.selectedStepId ) {
			state.fields = { items: [], total: 0 };
			return;
		}
		try {
			const data = await ConfigKit.request(
				'/templates/' + state.viewing.id + '/steps/' + state.selectedStepId + '/fields'
			);
			state.fields = data;
		} catch ( e ) {
			state.fields = { items: [], total: 0 };
		}
	}

	async function refreshFieldOptions() {
		if ( ! state.editingField ) {
			state.fieldOptions = { items: [], total: 0 };
			return;
		}
		if ( state.editingField.value_source !== 'manual_options' ) {
			state.fieldOptions = { items: [], total: 0 };
			return;
		}
		try {
			const data = await ConfigKit.request( '/fields/' + state.editingField.id + '/options' );
			state.fieldOptions = data;
		} catch ( e ) {
			state.fieldOptions = { items: [], total: 0 };
		}
	}

	async function selectStep( stepId ) {
		state.selectedStepId = stepId;
		state.editingField = null;
		state.fieldOptions = { items: [], total: 0 };
		await refreshFields();
		setUrl( { step_id: stepId, field_id: null, field_action: null } );
		render();
	}

	async function selectField( fieldId ) {
		try {
			const data = await ConfigKit.request( '/templates/' + state.viewing.id + '/fields/' + fieldId );
			state.editingField = data.record;
			await refreshFieldOptions();
			setUrl( { field_id: fieldId, field_action: null } );
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function reorderField( fieldId, direction ) {
		if ( ! state.viewing ) return;
		const items = state.fields.items.slice();
		const idx = items.findIndex( ( f ) => f.id === fieldId );
		if ( idx < 0 ) return;
		const swap = direction === 'up' ? idx - 1 : idx + 1;
		if ( swap < 0 || swap >= items.length ) return;
		const a = items[ idx ];
		items[ idx ] = items[ swap ];
		items[ swap ] = a;
		const payload = items.map( ( f, i ) => ( { field_id: f.id, sort_order: i + 1 } ) );

		try {
			await ConfigKit.request(
				'/templates/' + state.viewing.id + '/steps/' + state.selectedStepId + '/fields/reorder',
				{ method: 'POST', body: { items: payload } }
			);
			await refreshFields();
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function saveField() {
		if ( state.busy || ! state.viewing || ! state.editingField ) return;
		state.busy = true;
		render();

		const rec = state.editingField;
		const payload = {
			label: rec.label,
			helper_text: rec.helper_text || null,
			field_kind: rec.field_kind,
			input_type: rec.input_type,
			display_type: rec.display_type,
			value_source: rec.value_source,
			source_config: rec.source_config || {},
			behavior: rec.behavior,
			pricing_mode: rec.pricing_mode || null,
			pricing_value: rec.pricing_value === '' || rec.pricing_value === null ? null : rec.pricing_value,
			is_required: !! rec.is_required,
			default_value: rec.default_value || null,
			show_in_cart: !! rec.show_in_cart,
			show_in_checkout: !! rec.show_in_checkout,
			show_in_admin_order: !! rec.show_in_admin_order,
			show_in_customer_email: !! rec.show_in_customer_email,
			version_hash: rec.version_hash,
		};

		try {
			const resp = await ConfigKit.request(
				'/templates/' + state.viewing.id + '/fields/' + rec.id,
				{ method: 'PUT', body: payload }
			);
			state.editingField = resp.record;
			state.dirty = false;
			state.message = { kind: 'success', text: 'Field saved.' };
			state.fieldErrors = {};
			await refreshFields();
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	async function deleteField() {
		if ( ! state.viewing || ! state.editingField ) return;
		if ( ! window.confirm( 'Delete this field? This action is permanent.' ) ) return;
		try {
			await ConfigKit.request(
				'/templates/' + state.viewing.id + '/fields/' + state.editingField.id,
				{ method: 'DELETE' }
			);
			state.editingField = null;
			await refreshFields();
			state.message = { kind: 'success', text: 'Field deleted.' };
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	// ---- Wizard ----

	function openWizard() {
		if ( ! state.selectedStepId ) {
			window.alert( 'Pick a step first.' );
			return;
		}
		state.wizard = {
			step: 1,
			kindChoice: null,
			sourceChoice: null,
			label: '',
			fieldKey: '',
		};
		clearMessages();
		setUrl( { field_action: 'new' } );
		render();
	}

	function closeWizard() {
		state.wizard = null;
		setUrl( { field_action: null } );
		render();
	}

	function wizardNext() {
		const w = state.wizard;
		if ( ! w ) return;
		const choice = FIELD_KIND_CHOICES.find( ( c ) => c.id === w.kindChoice );
		if ( w.step === 1 ) {
			if ( ! choice ) {
				state.message = { kind: 'error', text: 'Pick what the customer does first.' };
				render();
				return;
			}
			// Skip step 2 when there is no source choice for this kind.
			if ( ( SOURCE_CHOICES_BY_KIND[ w.kindChoice ] || [] ).length === 0 ) {
				w.sourceChoice = ( choice.axes.value_source ) || 'manual_options';
				w.step = 3;
			} else {
				w.step = 2;
			}
		} else if ( w.step === 2 ) {
			if ( ! w.sourceChoice ) {
				state.message = { kind: 'error', text: 'Pick where options come from.' };
				render();
				return;
			}
			w.step = 3;
		}
		render();
	}

	function wizardBack() {
		const w = state.wizard;
		if ( ! w ) return;
		if ( w.step === 3 && ( SOURCE_CHOICES_BY_KIND[ w.kindChoice ] || [] ).length === 0 ) {
			w.step = 1;
		} else if ( w.step > 1 ) {
			w.step -= 1;
		}
		render();
	}

	async function wizardSave() {
		if ( state.busy ) return;
		const w = state.wizard;
		if ( ! w ) return;
		const choice = FIELD_KIND_CHOICES.find( ( c ) => c.id === w.kindChoice );
		if ( ! choice ) return;
		if ( ! w.label || ! w.fieldKey ) {
			state.message = { kind: 'error', text: 'Name and field_key are required.' };
			render();
			return;
		}
		state.busy = true;
		render();

		const axes = Object.assign( {}, choice.axes );
		axes.value_source = w.sourceChoice || axes.value_source || 'manual_options';

		const sourceConfig = { type: axes.value_source };
		if ( axes.value_source === 'library' ) sourceConfig.libraries = [];
		if ( axes.value_source === 'woo_products' ) sourceConfig.product_skus = [];
		if ( axes.value_source === 'woo_category' ) sourceConfig.category_slug = '';
		if ( axes.value_source === 'lookup_table' ) {
			sourceConfig.lookup_table_key = '';
			sourceConfig.dimension = 'width';
		}

		const payload = Object.assign(
			{
				field_key: w.fieldKey,
				label: w.label,
			},
			axes,
			{ source_config: sourceConfig }
		);

		try {
			const resp = await ConfigKit.request(
				'/templates/' + state.viewing.id + '/steps/' + state.selectedStepId + '/fields',
				{ method: 'POST', body: payload }
			);
			state.editingField = resp.record;
			await refreshFields();
			await refreshFieldOptions();
			state.wizard = null;
			state.message = { kind: 'success', text: 'Field created. Configure it on the right.' };
			setUrl( { field_action: null, field_id: state.editingField.id } );
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	// ---- Manual options inline editor ----

	async function addManualOption() {
		if ( ! state.editingField ) return;
		const optionKey = window.prompt( 'New option key (snake_case, 3-64 chars):' );
		if ( ! optionKey ) return;
		const label = window.prompt( 'Label (display text):' );
		if ( ! label ) return;
		try {
			await ConfigKit.request(
				'/fields/' + state.editingField.id + '/options',
				{ method: 'POST', body: { option_key: optionKey, label: label, price: 0, is_active: true } }
			);
			await refreshFieldOptions();
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function saveManualOption( option ) {
		if ( ! state.editingField ) return;
		try {
			await ConfigKit.request(
				'/fields/' + state.editingField.id + '/options/' + option.id,
				{
					method: 'PUT',
					body: {
						label: option.label,
						price: option.price === '' || option.price === null ? null : option.price,
						sale_price: option.sale_price === '' || option.sale_price === null ? null : option.sale_price,
						image_url: option.image_url || null,
						is_active: !! option.is_active,
						sort_order: option.sort_order,
						version_hash: option.version_hash,
					},
				}
			);
			await refreshFieldOptions();
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function deleteManualOption( optionId ) {
		if ( ! state.editingField ) return;
		if ( ! window.confirm( 'Soft-delete this option?' ) ) return;
		try {
			await ConfigKit.request(
				'/fields/' + state.editingField.id + '/options/' + optionId,
				{ method: 'DELETE' }
			);
			await refreshFieldOptions();
			render();
		} catch ( err ) {
			showError( err );
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
			setUrl( { action: null, id: templateId, step_id: stepId, step_action: 'edit', field_id: null, field_action: null } );
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
					{ 'data-label': 'Name' },
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
				el( 'td', { 'data-label': 'template_key' }, el( 'code', null, t.template_key ) ),
				el( 'td', { 'data-label': 'Family' }, t.family_key || '—' ),
				el(
					'td',
					{ 'data-label': 'Status' },
					el(
						'span',
						{ class: 'configkit-badge configkit-badge--' + statusBadge( t.status ) },
						t.status
					)
				),
				el( 'td', { 'data-label': 'Published version' }, t.published_version_id ? 'v' + t.published_version_id : '—' ),
				el( 'td', { 'data-label': 'Used by' }, '0' ),
				el( 'td', { 'data-label': 'Last edited' }, t.updated_at || '—' ),
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

		// Template header.
		const header = el( 'div', { class: 'configkit-form configkit-detail-header' } );
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
		const publishedLabel = t.published_version_id
			? 'Published v' + t.published_version_id
			: ( t.status === 'published' ? 'Published' : 'Draft' );

		const nextVersionDisplay = ( ( state.versions && state.versions.items && state.versions.items.length > 0 )
			? ( state.versions.items[ 0 ].version_number + 1 )
			: 1 );

		header.appendChild( el(
			'div',
			{ class: 'configkit-form__footer configkit-detail-toolbar' },
			el( 'span', { class: 'configkit-saved-indicator' }, publishedLabel ),
			el( 'button', { type: 'button', class: 'button', onClick: () => loadTemplateForEdit( t.id ) }, 'Edit metadata' ),
			el(
				'button',
				{ type: 'button', class: 'button', onClick: openRulesDrawer },
				'Rules (' + ( state.rules.total || 0 ) + ')'
			),
			el( 'button', { type: 'button', class: 'button', onClick: openVersionsDrawer }, 'Versions' ),
			el( 'button', { type: 'button', class: 'button', onClick: runValidation }, 'Validate' ),
			el(
				'button',
				{ type: 'button', class: 'button button-primary', onClick: requestPublish },
				'Publish v' + nextVersionDisplay
			),
			el( 'button', { type: 'button', class: 'button', onClick: cancelToList }, 'Back to list' )
		) );
		wrap.appendChild( header );

		if ( state.validationOpen && state.validation ) {
			wrap.appendChild( renderValidationPanel() );
		}
		if ( state.versionsOpen ) {
			wrap.appendChild( renderVersionsDrawer() );
		}
		if ( state.publishConfirm ) {
			wrap.appendChild( renderPublishConfirm() );
		}

		// Three-pane builder.
		const panes = el( 'div', { class: 'configkit-builder' } );
		panes.appendChild( renderStepsPane() );
		panes.appendChild( renderFieldsPane() );
		panes.appendChild( renderSettingsPane() );
		wrap.appendChild( panes );

		// Wizard modal overlay (rendered last so it sits on top).
		if ( state.wizard ) {
			wrap.appendChild( renderWizard() );
		}

		// Rules drawer overlay.
		if ( state.rulesOpen ) {
			wrap.appendChild( renderRulesDrawer() );
		}

		return wrap;
	}

	function renderStepsPane() {
		const t = state.viewing;
		const pane = el( 'div', { class: 'configkit-builder__pane configkit-builder__steps' } );
		pane.appendChild( el(
			'div',
			{ class: 'configkit-builder__pane-header' },
			el( 'h3', null, 'Steps' ),
			el( 'button', { type: 'button', class: 'button button-small', onClick: showNewStepForm }, '+ Step' )
		) );

		const steps = state.steps.items || [];
		if ( steps.length === 0 ) {
			pane.appendChild( el(
				'p',
				{ class: 'configkit-empty__hint' },
				'No steps yet. Add at least one to start placing fields.'
			) );
			return pane;
		}

		const list = el( 'ul', { class: 'configkit-step-list' } );
		steps.forEach( ( s, i ) => {
			const isSel = s.id === state.selectedStepId;
			list.appendChild( el(
				'li',
				{ class: 'configkit-step-list__item' + ( isSel ? ' is-selected' : '' ) },
				el(
					'div',
					{ class: 'configkit-step-list__order' },
					el(
						'button',
						{
							type: 'button',
							class: 'button-link',
							disabled: i === 0,
							onClick: () => reorderStep( s.id, 'up' ),
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
							title: 'Move down',
						},
						'▼'
					)
				),
				el(
					'a',
					{
						href: '#',
						class: 'configkit-step-list__name',
						onClick: ( ev ) => { ev.preventDefault(); selectStep( s.id ); },
					},
					s.label
				),
				el( 'code', { class: 'configkit-step-list__key' }, s.step_key ),
				el(
					'button',
					{
						type: 'button',
						class: 'button-link configkit-step-list__edit',
						onClick: () => loadStep( t.id, s.id ),
						title: 'Edit step',
					},
					'Edit'
				)
			) );
		} );
		pane.appendChild( list );
		return pane;
	}

	function renderFieldsPane() {
		const t = state.viewing;
		const pane = el( 'div', { class: 'configkit-builder__pane configkit-builder__fields' } );

		const selectedStep = ( state.steps.items || [].find ).call( state.steps.items || [], ( s ) => s.id === state.selectedStepId )
			|| ( state.steps.items || [] ).find( ( s ) => s.id === state.selectedStepId );
		// (Workaround keeps lint happy if items is empty.)

		pane.appendChild( el(
			'div',
			{ class: 'configkit-builder__pane-header' },
			el( 'h3', null, 'Fields' + ( selectedStep ? ' in ' + selectedStep.label : '' ) ),
			el( 'button', {
				type: 'button',
				class: 'button button-small',
				disabled: ! state.selectedStepId,
				onClick: openWizard,
			}, '+ Field' )
		) );

		if ( ! state.selectedStepId ) {
			pane.appendChild( el( 'p', { class: 'configkit-empty__hint' }, 'Select a step to see its fields.' ) );
			return pane;
		}

		const fields = state.fields.items || [];
		if ( fields.length === 0 ) {
			pane.appendChild( el( 'p', { class: 'configkit-empty__hint' }, 'No fields yet. Click "+ Field" to launch the wizard.' ) );
			return pane;
		}

		const list = el( 'ul', { class: 'configkit-field-list' } );
		fields.forEach( ( f, i ) => {
			const isSel = state.editingField && state.editingField.id === f.id;
			list.appendChild( el(
				'li',
				{ class: 'configkit-field-list__item' + ( isSel ? ' is-selected' : '' ) },
				el(
					'div',
					{ class: 'configkit-field-list__order' },
					el(
						'button',
						{
							type: 'button',
							class: 'button-link',
							disabled: i === 0,
							onClick: () => reorderField( f.id, 'up' ),
							title: 'Move up',
						},
						'▲'
					),
					el(
						'button',
						{
							type: 'button',
							class: 'button-link',
							disabled: i === fields.length - 1,
							onClick: () => reorderField( f.id, 'down' ),
							title: 'Move down',
						},
						'▼'
					)
				),
				el(
					'a',
					{
						href: '#',
						class: 'configkit-field-list__name',
						onClick: ( ev ) => { ev.preventDefault(); selectField( f.id ); },
					},
					f.label
				),
				el( 'code', { class: 'configkit-field-list__key' }, f.field_key ),
				el( 'span', { class: 'configkit-field-list__source' }, f.value_source )
			) );
		} );
		pane.appendChild( list );
		return pane;
	}

	function renderSettingsPane() {
		const pane = el( 'div', { class: 'configkit-builder__pane configkit-builder__settings' } );
		pane.appendChild( el(
			'div',
			{ class: 'configkit-builder__pane-header' },
			el( 'h3', null, 'Settings' )
		) );

		if ( ! state.editingField ) {
			pane.appendChild( el( 'p', { class: 'configkit-empty__hint' }, 'Select a field to edit it.' ) );
			return pane;
		}

		pane.appendChild( renderFieldEditor() );
		return pane;
	}

	function renderFieldEditor() {
		const f = state.editingField;
		const wrap = el( 'div', { class: 'configkit-field-editor' } );

		// Owner-friendly summary instead of raw axis labels.
		const kindMatch = FIELD_KIND_CHOICES.find( ( c ) => c.axes.field_kind === f.field_kind && ( c.axes.input_type === f.input_type || ( c.axes.input_type === undefined ) ) ) || null;
		wrap.appendChild( el(
			'p',
			{ class: 'description' },
			kindMatch ? kindMatch.label : ( f.field_kind + ' / ' + ( f.input_type || '—' ) ),
			' · Stored as: ',
			el( 'code', null, f.field_kind + '/' + ( f.input_type || 'null' ) + '/' + f.display_type + '/' + f.value_source + '/' + f.behavior )
		) );

		// Basics
		wrap.appendChild( fieldset( 'Basics', [
			textField( 'Label', 'label', f.label, ( v ) => {
				f.label = v;
				state.dirty = true;
			} ),
			textField( 'Helper text', 'helper_text', f.helper_text || '', ( v ) => {
				f.helper_text = v;
				state.dirty = true;
			} ),
		] ) );

		// Source
		if ( f.value_source === 'library' ) {
			const libs = ( f.source_config && f.source_config.libraries ) || [];
			wrap.appendChild( fieldset( 'Source · libraries', [
				el( 'p', { class: 'description' }, 'Comma-separated library_keys.' ),
				textField( 'library_keys', 'libraries_csv', libs.join( ',' ), ( v ) => {
					f.source_config = Object.assign( {}, f.source_config, {
						type: 'library',
						libraries: v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
					} );
					state.dirty = true;
				} ),
				fieldErrors( 'source_config' ),
			] ) );
		} else if ( f.value_source === 'woo_category' ) {
			wrap.appendChild( fieldset( 'Source · Woo category', [
				textField( 'category_slug', 'category_slug', ( f.source_config && f.source_config.category_slug ) || '', ( v ) => {
					f.source_config = Object.assign( {}, f.source_config, { type: 'woo_category', category_slug: v } );
					state.dirty = true;
				} ),
				fieldErrors( 'source_config' ),
			] ) );
		} else if ( f.value_source === 'woo_products' ) {
			const skus = ( f.source_config && f.source_config.product_skus ) || [];
			wrap.appendChild( fieldset( 'Source · Woo products', [
				el( 'p', { class: 'description' }, 'Comma-separated product SKUs.' ),
				textField( 'product_skus', 'product_skus', skus.join( ',' ), ( v ) => {
					f.source_config = Object.assign( {}, f.source_config, {
						type: 'woo_products',
						product_skus: v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
					} );
					state.dirty = true;
				} ),
				fieldErrors( 'source_config' ),
			] ) );
		} else if ( f.value_source === 'lookup_table' ) {
			wrap.appendChild( fieldset( 'Source · Lookup table', [
				textField( 'lookup_table_key', 'lookup_table_key', ( f.source_config && f.source_config.lookup_table_key ) || '', ( v ) => {
					f.source_config = Object.assign( {}, f.source_config, { type: 'lookup_table', lookup_table_key: v } );
					state.dirty = true;
				} ),
				selectFieldRow(
					'dimension',
					'dimension',
					[
						[ 'width', 'width' ],
						[ 'height', 'height' ],
						[ 'price_group', 'price_group' ],
					],
					( f.source_config && f.source_config.dimension ) || 'width',
					( v ) => {
						f.source_config = Object.assign( {}, f.source_config, { type: 'lookup_table', dimension: v } );
						state.dirty = true;
					}
				),
				fieldErrors( 'source_config' ),
			] ) );
		} else if ( f.value_source === 'manual_options' ) {
			wrap.appendChild( renderManualOptions( f ) );
		}

		// Display style — only for input/addon kinds.
		if ( f.field_kind === 'input' || f.field_kind === 'addon' ) {
			wrap.appendChild( fieldset( 'How should it look?', [
				selectFieldRow(
					'Display style',
					'display_type',
					DISPLAY_STYLE_CHOICES.map( ( c ) => [ c.id, c.label ] ),
					f.display_type,
					( v ) => {
						f.display_type = v;
						state.dirty = true;
					}
				),
			] ) );
		}

		// Pricing.
		wrap.appendChild( fieldset( 'Pricing', [
			selectFieldRow(
				'Pricing mode',
				'pricing_mode',
				PRICING_MODE_CHOICES,
				f.pricing_mode || 'none',
				( v ) => {
					f.pricing_mode = v;
					state.dirty = true;
				}
			),
			numberFieldRow(
				'Pricing value (NOK)',
				'pricing_value',
				f.pricing_value === null || f.pricing_value === '' ? '' : f.pricing_value,
				( v ) => {
					f.pricing_value = v;
					state.dirty = true;
				},
				{ allowFloat: true }
			),
			el( 'p', { class: 'description' }, 'fixed = flat per selection · per_unit = ×count · per_m2 = ×area · lookup_dimension = via lookup table · none = no price impact.' ),
		] ) );

		// Show in cart/checkout/admin/email.
		wrap.appendChild( fieldset( 'Show this field in', [
			checkboxField( 'Cart line summary', 'show_in_cart', !! f.show_in_cart, ( v ) => {
				f.show_in_cart = v;
				state.dirty = true;
			} ),
			checkboxField( 'Checkout', 'show_in_checkout', !! f.show_in_checkout, ( v ) => {
				f.show_in_checkout = v;
				state.dirty = true;
			} ),
			checkboxField( 'Admin order', 'show_in_admin_order', !! f.show_in_admin_order, ( v ) => {
				f.show_in_admin_order = v;
				state.dirty = true;
			} ),
			checkboxField( 'Customer email', 'show_in_customer_email', !! f.show_in_customer_email, ( v ) => {
				f.show_in_customer_email = v;
				state.dirty = true;
			} ),
		] ) );

		// Required + default.
		wrap.appendChild( fieldset( 'Required + default', [
			checkboxField( 'Required', 'is_required', !! f.is_required, ( v ) => {
				f.is_required = v;
				state.dirty = true;
			} ),
			textField( 'Default value (option_key / number / library:item)', 'default_value', f.default_value || '', ( v ) => {
				f.default_value = v;
				state.dirty = true;
			}, { mono: true } ),
		] ) );

		// Advanced (collapsed).
		const adv = el( 'details', { class: 'configkit-advanced' } );
		adv.appendChild( el( 'summary', null, 'Advanced' ) );
		adv.appendChild( fieldset( 'Identity', [
			el( 'p', { class: 'description' },
				'field_key: ',
				el( 'code', null, f.field_key ),
				' (immutable). Sort order: ',
				el( 'code', null, String( f.sort_order ) ),
				'.'
			),
		] ) );
		adv.appendChild( fieldset( 'Raw 5-axis (read-only)', [
			el( 'pre', { class: 'configkit-json' }, JSON.stringify( {
				field_kind: f.field_kind,
				input_type: f.input_type,
				display_type: f.display_type,
				value_source: f.value_source,
				behavior: f.behavior,
			}, null, 2 ) ),
		] ) );
		wrap.appendChild( adv );

		// Footer.
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el(
				'button',
				{ type: 'button', class: 'button button-primary', disabled: state.busy, onClick: saveField },
				state.busy ? 'Saving…' : 'Save field'
			),
			el( 'button', { type: 'button', class: 'button button-link-delete', onClick: deleteField }, 'Delete' )
		) );

		return wrap;
	}

	function renderManualOptions( f ) {
		const wrap = el( 'fieldset', { class: 'configkit-fieldset' }, el( 'legend', null, 'Options' ) );
		const opts = state.fieldOptions.items || [];
		if ( opts.length === 0 ) {
			wrap.appendChild( el( 'p', { class: 'description' }, 'No options yet.' ) );
		} else {
			const table = el( 'table', { class: 'wp-list-table widefat striped configkit-options-table' } );
			const thead = el( 'thead', null, el(
				'tr',
				null,
				el( 'th', null, 'option_key' ),
				el( 'th', null, 'Label' ),
				el( 'th', null, 'Price' ),
				el( 'th', null, 'Sale' ),
				el( 'th', null, 'Active' ),
				el( 'th', null, '' )
			) );
			table.appendChild( thead );
			const tbody = el( 'tbody' );
			opts.forEach( ( opt ) => {
				tbody.appendChild( el(
					'tr',
					null,
					el( 'td', null, el( 'code', null, opt.option_key ) ),
					el(
						'td',
						null,
						el( 'input', {
							type: 'text',
							value: opt.label,
							onChange: ( ev ) => { opt.label = ev.target.value; saveManualOption( opt ); },
						} )
					),
					el(
						'td',
						null,
						el( 'input', {
							type: 'number',
							step: '0.01',
							value: opt.price === null ? '' : String( opt.price ),
							onChange: ( ev ) => { opt.price = ev.target.value === '' ? null : parseFloat( ev.target.value ); saveManualOption( opt ); },
						} )
					),
					el(
						'td',
						null,
						el( 'input', {
							type: 'number',
							step: '0.01',
							value: opt.sale_price === null ? '' : String( opt.sale_price ),
							onChange: ( ev ) => { opt.sale_price = ev.target.value === '' ? null : parseFloat( ev.target.value ); saveManualOption( opt ); },
						} )
					),
					el(
						'td',
						null,
						el( 'input', {
							type: 'checkbox',
							checked: !! opt.is_active,
							onChange: ( ev ) => { opt.is_active = ev.target.checked; saveManualOption( opt ); },
						} )
					),
					el(
						'td',
						{ class: 'configkit-actions' },
						el( 'button', { type: 'button', class: 'button button-link-delete', onClick: () => deleteManualOption( opt.id ) }, '✕' )
					)
				) );
			} );
			table.appendChild( tbody );
			wrap.appendChild( table );
		}
		wrap.appendChild( el( 'button', { type: 'button', class: 'button', onClick: addManualOption }, '+ Add option' ) );
		// Mark `f` used to silence lint; the option list comes from state already.
		void f;
		return wrap;
	}

	function renderWizard() {
		const w = state.wizard;
		const overlay = el( 'div', { class: 'configkit-modal-overlay' } );
		const modal = el( 'div', { class: 'configkit-modal' } );
		overlay.appendChild( modal );

		modal.appendChild( el( 'h2', null, 'Add a field' + ( w.step > 1 ? ' (step ' + w.step + ' of 3)' : '' ) ) );

		if ( state.message ) modal.appendChild( messageBanner( state.message ) );

		if ( w.step === 1 ) {
			modal.appendChild( el( 'p', null, 'What does the customer do here?' ) );
			const list = el( 'div', { class: 'configkit-wizard-choices' } );
			FIELD_KIND_CHOICES.filter( ( c ) => ! c.advanced ).forEach( ( c ) => {
				list.appendChild( renderWizardChoice( c, w.kindChoice, ( id ) => { w.kindChoice = id; render(); } ) );
			} );
			const advWrap = el( 'details', { class: 'configkit-advanced' } );
			advWrap.appendChild( el( 'summary', null, 'Show advanced' ) );
			FIELD_KIND_CHOICES.filter( ( c ) => c.advanced ).forEach( ( c ) => {
				advWrap.appendChild( renderWizardChoice( c, w.kindChoice, ( id ) => { w.kindChoice = id; render(); } ) );
			} );
			list.appendChild( advWrap );
			modal.appendChild( list );
		} else if ( w.step === 2 ) {
			modal.appendChild( el( 'p', null, 'Where do the options come from?' ) );
			const list = el( 'div', { class: 'configkit-wizard-choices' } );
			( SOURCE_CHOICES_BY_KIND[ w.kindChoice ] || [] ).forEach( ( c ) => {
				list.appendChild( renderWizardChoice( c, w.sourceChoice, ( id ) => { w.sourceChoice = id; render(); } ) );
			} );
			modal.appendChild( list );
		} else if ( w.step === 3 ) {
			modal.appendChild( el( 'p', null, 'Field name and key.' ) );
			modal.appendChild( textField( 'Field name', 'wiz_label', w.label, ( v ) => {
				w.label = v;
				if ( ! w.fieldKey ) {
					w.fieldKey = slugify( v );
					render();
				}
			} ) );
			modal.appendChild( textField( 'field_key', 'wiz_field_key', w.fieldKey, ( v ) => {
				w.fieldKey = v;
				render();
			}, { mono: true, help: 'Lowercase, snake_case, 3–64 chars. Locked after save.' } ) );
		}

		modal.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			w.step > 1
				? el( 'button', { type: 'button', class: 'button', onClick: wizardBack }, 'Back' )
				: null,
			w.step < 3
				? el( 'button', { type: 'button', class: 'button button-primary', onClick: wizardNext }, 'Continue' )
				: el( 'button', {
					type: 'button',
					class: 'button button-primary',
					disabled: state.busy,
					onClick: wizardSave,
				}, state.busy ? 'Saving…' : 'Save and configure' ),
			el( 'button', { type: 'button', class: 'button', onClick: closeWizard }, 'Cancel' )
		) );

		return overlay;
	}

	function renderWizardChoice( choice, current, onPick ) {
		const isSel = choice.id === current;
		return el(
			'button',
			{
				type: 'button',
				class: 'configkit-wizard-choice' + ( isSel ? ' is-selected' : '' ),
				onClick: () => onPick( choice.id ),
			},
			el( 'strong', null, choice.label ),
			choice.help ? el( 'span', { class: 'description' }, choice.help ) : null
		);
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

	// ---- Rules drawer ----

	async function openRulesDrawer() {
		state.rulesOpen = true;
		clearMessages();
		await refreshRules();
		render();
	}

	function closeRulesDrawer() {
		state.rulesOpen = false;
		state.editingRule = null;
		clearMessages();
		render();
	}

	async function refreshRules() {
		if ( ! state.viewing ) return;
		try {
			const data = await ConfigKit.request( '/templates/' + state.viewing.id + '/rules' );
			state.rules = data;
		} catch ( err ) {
			showError( err );
		}
	}

	async function newRuleInDrawer() {
		state.editingRule = blankRuleForm();
		clearMessages();
		render();
	}

	async function loadRuleForEdit( ruleId ) {
		try {
			const resp = await ConfigKit.request( '/templates/' + state.viewing.id + '/rules/' + ruleId );
			state.editingRule = ruleFormFromRecord( resp.record );
			clearMessages();
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function deleteRule( ruleId ) {
		if ( ! window.confirm( 'Soft-delete this rule? It will be marked inactive.' ) ) return;
		try {
			await ConfigKit.request(
				'/templates/' + state.viewing.id + '/rules/' + ruleId,
				{ method: 'DELETE' }
			);
			await refreshRules();
			state.editingRule = null;
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	async function toggleRuleActive( rule ) {
		try {
			const spec = rule.spec || { when: { always: true }, then: [] };
			await ConfigKit.request(
				'/templates/' + state.viewing.id + '/rules/' + rule.id,
				{
					method: 'PUT',
					body: {
						name: rule.name,
						priority: rule.priority,
						sort_order: rule.sort_order,
						is_active: ! rule.is_active,
						spec: spec,
						version_hash: rule.version_hash,
					},
				}
			);
			await refreshRules();
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	function blankRuleForm() {
		return {
			id: 0,
			rule_key: '',
			name: '',
			priority: 100,
			sort_order: 0,
			is_active: true,
			conjunction: 'all',
			conditions: [ blankCondition() ],
			actions: [ blankAction() ],
			version_hash: '',
			jsonMode: false,
			jsonText: '',
			jsonLocked: false,
		};
	}

	function blankCondition() {
		return { field: '', op: 'equals', value: '' };
	}

	function blankAction() {
		return { action: 'show_step', step: '', field: '', option: '', value: '', message: '', level: 'info', label: '', amount: '', percent_of_base: '', lookup_table_key: '', filter_tag: '' };
	}

	function ruleFormFromRecord( record ) {
		const form = {
			id: record.id,
			rule_key: record.rule_key,
			name: record.name,
			priority: record.priority,
			sort_order: record.sort_order,
			is_active: !! record.is_active,
			version_hash: record.version_hash,
			jsonMode: false,
			jsonText: JSON.stringify( record.spec || {}, null, 2 ),
			jsonLocked: false,
			conjunction: 'all',
			conditions: [],
			actions: [],
		};
		const spec = record.spec || {};
		// Parse `when` — only flat all/any/atomic/always shapes round-trip cleanly.
		const w = spec.when;
		if ( w && typeof w === 'object' ) {
			if ( w.all || w.any ) {
				form.conjunction = w.all ? 'all' : 'any';
				const arr = w.all || w.any;
				if ( Array.isArray( arr ) && arr.every( ( c ) => c && typeof c === 'object' && 'field' in c && 'op' in c ) ) {
					form.conditions = arr.map( ( c ) => ( {
						field: c.field || '',
						op: c.op || 'equals',
						value: serializeOpValue( c.op, c.value ),
					} ) );
				} else {
					form.jsonLocked = true;
				}
			} else if ( 'always' in w ) {
				form.conjunction = w.always ? 'all_always' : 'all_never';
				form.conditions = [];
			} else if ( 'field' in w && 'op' in w ) {
				form.conditions = [ { field: w.field, op: w.op, value: serializeOpValue( w.op, w.value ) } ];
			} else {
				// not / nested groups → JSON-only
				form.jsonLocked = true;
			}
		}
		// Parse `then` — list of action records.
		const then = spec.then;
		if ( Array.isArray( then ) ) {
			form.actions = then.map( ( a ) => actionRecordToFormRow( a ) );
		}
		return form;
	}

	function serializeOpValue( op, value ) {
		if ( op === 'between' && Array.isArray( value ) ) return value.join( ',' );
		if ( ( op === 'in' || op === 'not_in' ) && Array.isArray( value ) ) return value.join( ',' );
		if ( value === null || value === undefined ) return '';
		return String( value );
	}

	function actionRecordToFormRow( a ) {
		return Object.assign( blankAction(), {
			action: a.action || 'show_step',
			field: a.field || '',
			step: a.step || '',
			option: a.option || '',
			value: a.value !== undefined ? String( a.value ) : '',
			message: a.message || '',
			level: a.level || 'info',
			label: a.label || '',
			amount: a.amount !== undefined ? String( a.amount ) : '',
			percent_of_base: a.percent_of_base !== undefined ? String( a.percent_of_base ) : '',
			lookup_table_key: a.lookup_table_key || '',
			filter_tag: a.filter && a.filter.tag ? a.filter.tag : '',
		} );
	}

	function ruleFormToSpec( form ) {
		// when
		let when;
		if ( form.conjunction === 'all_always' ) {
			when = { always: true };
		} else if ( form.conjunction === 'all_never' ) {
			when = { always: false };
		} else {
			const conds = ( form.conditions || [] ).map( ( c ) => atomToSpec( c ) );
			if ( conds.length === 1 ) {
				when = conds[ 0 ];
			} else {
				when = { [ form.conjunction === 'any' ? 'any' : 'all' ]: conds };
			}
		}
		// then
		const then = ( form.actions || [] ).map( ( a ) => actionFormRowToSpec( a ) );
		return { when: when, then: then };
	}

	function atomToSpec( c ) {
		const out = { field: c.field, op: c.op };
		if ( c.op === 'is_selected' || c.op === 'is_empty' ) {
			// no value
		} else if ( c.op === 'between' ) {
			const parts = String( c.value || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( ( s ) => s !== '' );
			out.value = parts.map( ( p ) => Number.isFinite( +p ) ? +p : p );
		} else if ( c.op === 'in' || c.op === 'not_in' ) {
			out.value = String( c.value || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( ( s ) => s !== '' );
		} else if ( c.op === 'greater_than' || c.op === 'less_than' ) {
			out.value = Number.isFinite( +c.value ) ? +c.value : c.value;
		} else {
			out.value = c.value;
		}
		return out;
	}

	function actionFormRowToSpec( a ) {
		const t = a.action;
		const out = { action: t };
		if ( [ 'show_field', 'hide_field', 'require_field', 'reset_value' ].includes( t ) ) {
			out.field = a.field;
		} else if ( [ 'show_step', 'hide_step' ].includes( t ) ) {
			out.step = a.step;
		} else if ( t === 'disable_option' ) {
			out.field = a.field;
			out.option = a.option;
		} else if ( t === 'filter_source' ) {
			out.field = a.field;
			out.filter = { tag: a.filter_tag };
		} else if ( t === 'set_default' ) {
			out.field = a.field;
			out.value = a.value;
		} else if ( t === 'switch_lookup_table' ) {
			out.lookup_table_key = a.lookup_table_key;
		} else if ( t === 'add_surcharge' ) {
			out.label = a.label;
			if ( a.amount !== '' ) out.amount = Number( a.amount );
			else if ( a.percent_of_base !== '' ) out.percent_of_base = Number( a.percent_of_base );
		} else if ( t === 'show_warning' ) {
			out.message = a.message;
			out.level = a.level || 'info';
		} else if ( t === 'block_add_to_cart' ) {
			out.message = a.message;
		}
		return out;
	}

	async function saveRule() {
		if ( state.busy || ! state.viewing || ! state.editingRule ) return;
		state.busy = true;
		render();

		const form = state.editingRule;
		let spec;
		if ( form.jsonMode ) {
			try {
				spec = JSON.parse( form.jsonText );
			} catch ( e ) {
				state.message = { kind: 'error', text: 'Invalid JSON: ' + e.message };
				state.busy = false;
				render();
				return;
			}
		} else {
			spec = ruleFormToSpec( form );
		}

		const payload = {
			rule_key: form.rule_key,
			name: form.name,
			priority: form.priority,
			sort_order: form.sort_order,
			is_active: !! form.is_active,
			spec: spec,
		};

		let success = false;
		try {
			if ( form.id > 0 ) {
				payload.version_hash = form.version_hash;
				const resp = await ConfigKit.request(
					'/templates/' + state.viewing.id + '/rules/' + form.id,
					{ method: 'PUT', body: payload }
				);
				state.editingRule = ruleFormFromRecord( resp.record );
			} else {
				const resp = await ConfigKit.request(
					'/templates/' + state.viewing.id + '/rules',
					{ method: 'POST', body: payload }
				);
				state.editingRule = ruleFormFromRecord( resp.record );
			}
			success = true;
			state.message = { kind: 'success', text: 'Rule saved.' };
			await refreshRules();
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
		void success;
	}

	function switchRuleEditorToJson() {
		if ( ! state.editingRule ) return;
		state.editingRule.jsonText = JSON.stringify(
			ruleFormToSpec( state.editingRule ),
			null,
			2
		);
		state.editingRule.jsonMode = true;
		render();
	}

	function switchRuleEditorToStructured() {
		if ( ! state.editingRule ) return;
		try {
			const spec = JSON.parse( state.editingRule.jsonText );
			// Create a synthetic record + reparse.
			const fake = {
				id: state.editingRule.id,
				rule_key: state.editingRule.rule_key,
				name: state.editingRule.name,
				priority: state.editingRule.priority,
				sort_order: state.editingRule.sort_order,
				is_active: state.editingRule.is_active,
				version_hash: state.editingRule.version_hash,
				spec: spec,
			};
			const reparsed = ruleFormFromRecord( fake );
			if ( reparsed.jsonLocked ) {
				state.message = { kind: 'error', text: 'This spec uses nested groups or NOT — keep using JSON mode.' };
				state.editingRule.jsonMode = true;
				render();
				return;
			}
			state.editingRule = reparsed;
			state.editingRule.jsonMode = false;
			clearMessages();
		} catch ( e ) {
			state.message = { kind: 'error', text: 'Invalid JSON: ' + e.message };
		}
		render();
	}

	// ---- Rules drawer rendering ----

	function renderRulesDrawer() {
		const overlay = el( 'div', { class: 'configkit-modal-overlay configkit-rules-drawer' } );
		const drawer  = el( 'div', { class: 'configkit-modal configkit-rules-drawer__panel' } );
		overlay.appendChild( drawer );

		drawer.appendChild( el(
			'div',
			{ class: 'configkit-builder__pane-header' },
			el( 'h2', null, state.editingRule ? ( state.editingRule.id > 0 ? 'Edit rule: ' + state.editingRule.name : 'New rule' ) : 'Rules' ),
			el( 'button', { type: 'button', class: 'button', onClick: closeRulesDrawer }, 'Close' )
		) );

		if ( state.message ) drawer.appendChild( messageBanner( state.message ) );

		if ( state.editingRule ) {
			drawer.appendChild( renderRuleEditor() );
		} else {
			drawer.appendChild( renderRuleList() );
		}

		return overlay;
	}

	function renderRuleList() {
		const wrap = el( 'div' );

		wrap.appendChild( el(
			'div',
			{ class: 'configkit-list__header' },
			el( 'button', { type: 'button', class: 'button button-primary', onClick: newRuleInDrawer }, '+ New rule' )
		) );

		const items = state.rules.items || [];
		if ( items.length === 0 ) {
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No rules yet.' ),
				el(
					'p',
					{ class: 'configkit-empty__hint' },
					'Rules let you show/hide fields, filter options, add surcharges, and more based on the customer\u2019s selections.'
				)
			) );
			return wrap;
		}

		const table = el(
			'table',
			{ class: 'wp-list-table widefat striped configkit-rules-table' },
			el(
				'thead',
				null,
				el(
					'tr',
					null,
					el( 'th', null, 'Name' ),
					el( 'th', null, 'rule_key' ),
					el( 'th', null, 'WHEN' ),
					el( 'th', null, 'THEN' ),
					el( 'th', null, 'Priority' ),
					el( 'th', null, 'Active' ),
					el( 'th', { class: 'configkit-actions' }, '' )
				)
			)
		);
		const tbody = el( 'tbody' );
		items.forEach( ( r ) => {
			tbody.appendChild( el(
				'tr',
				null,
				el(
					'td',
					null,
					el( 'a', {
						href: '#',
						onClick: ( ev ) => { ev.preventDefault(); loadRuleForEdit( r.id ); },
					}, r.name )
				),
				el( 'td', null, el( 'code', null, r.rule_key ) ),
				el( 'td', null, summarizeWhen( r.spec ) ),
				el( 'td', null, summarizeThen( r.spec ) ),
				el( 'td', null, String( r.priority ) ),
				el(
					'td',
					null,
					el( 'input', {
						type: 'checkbox',
						checked: !! r.is_active,
						onChange: () => toggleRuleActive( r ),
					} )
				),
				el(
					'td',
					{ class: 'configkit-actions' },
					el( 'button', { type: 'button', class: 'button', onClick: () => loadRuleForEdit( r.id ) }, 'Edit' ),
					' ',
					el( 'button', { type: 'button', class: 'button button-link-delete', onClick: () => deleteRule( r.id ) }, '✕' )
				)
			) );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	function summarizeWhen( spec ) {
		const w = ( spec && spec.when ) || {};
		if ( 'always' in w ) return w.always ? 'always' : 'never';
		if ( w.all || w.any ) {
			const arr = w.all || w.any;
			const conj = w.all ? 'AND' : 'OR';
			if ( Array.isArray( arr ) && arr.every( ( c ) => c && c.field ) ) {
				return arr.map( ( c ) => atomSummary( c ) ).join( ' ' + conj + ' ' );
			}
			return '(complex)';
		}
		if ( w.not ) return 'NOT (' + ( w.not.field ? atomSummary( w.not ) : '…' ) + ')';
		if ( w.field ) return atomSummary( w );
		return '';
	}

	function atomSummary( c ) {
		const v = ( c.value === undefined || c.value === null ) ? '' : ( Array.isArray( c.value ) ? c.value.join( ',' ) : String( c.value ) );
		return c.field + ' ' + c.op + ( v !== '' ? ' ' + v : '' );
	}

	function summarizeThen( spec ) {
		const then = ( spec && spec.then ) || [];
		if ( ! Array.isArray( then ) || then.length === 0 ) return '';
		return then.map( ( a ) => actionSummary( a ) ).join( ' · ' );
	}

	function actionSummary( a ) {
		switch ( a.action ) {
			case 'show_field':       return 'show ' + ( a.field || '' );
			case 'hide_field':       return 'hide ' + ( a.field || '' );
			case 'show_step':        return 'show step ' + ( a.step || '' );
			case 'hide_step':        return 'hide step ' + ( a.step || '' );
			case 'require_field':    return 'require ' + ( a.field || '' );
			case 'disable_option':   return 'disable ' + ( a.field || '' ) + ':' + ( a.option || '' );
			case 'filter_source':    return 'filter ' + ( a.field || '' );
			case 'set_default':      return 'default ' + ( a.field || '' ) + '=' + String( a.value );
			case 'reset_value':      return 'reset ' + ( a.field || '' );
			case 'switch_lookup_table': return 'switch lookup ' + ( a.lookup_table_key || '' );
			case 'add_surcharge':    return 'surcharge ' + ( a.label || '' ) + ( 'amount' in a ? ' +' + a.amount : '' ) + ( 'percent_of_base' in a ? ' +' + a.percent_of_base + '%' : '' );
			case 'show_warning':     return 'warn: ' + ( a.message || '' ).slice( 0, 30 );
			case 'block_add_to_cart': return 'block: ' + ( a.message || '' ).slice( 0, 30 );
			default:                 return a.action;
		}
	}

	function renderRuleEditor() {
		const f = state.editingRule;
		const wrap = el( 'div' );

		if ( f.jsonMode ) {
			wrap.appendChild( el( 'p', { class: 'description' }, 'Editing raw spec_json. The schema is enforced server-side; click "Switch to structured" to go back if your spec is flat.' ) );
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-field' },
				el( 'label', null, 'spec_json' ),
				el( 'textarea', {
					class: 'configkit-json',
					rows: 18,
					value: f.jsonText,
					onInput: ( ev ) => { f.jsonText = ev.target.value; },
				} )
			) );
		} else {
			// Basics
			wrap.appendChild( fieldset( 'Basics', [
				textField( 'Rule name', 'rule_name', f.name, ( v ) => {
					f.name = v;
					if ( ! f.rule_key && f.id === 0 ) {
						f.rule_key = slugify( v );
						render();
					}
				} ),
				textField( 'rule_key', 'rule_key', f.rule_key, ( v ) => { f.rule_key = v; }, {
					mono: true,
					disabled: f.id > 0,
					help: f.id > 0 ? 'Immutable after save.' : 'Lowercase, snake_case, 3-64 chars. Locked after save.',
				} ),
				numberFieldRow( 'Priority', 'priority', f.priority, ( v ) => { f.priority = v; } ),
				checkboxField( 'Active', 'is_active', !! f.is_active, ( v ) => { f.is_active = v; } ),
			] ) );

			// WHEN
			wrap.appendChild( renderRuleWhen( f ) );

			// THEN
			wrap.appendChild( renderRuleThen( f ) );
		}

		// Footer + JSON toggle.
		const footerKids = [
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: saveRule,
			}, state.busy ? 'Saving…' : ( f.id > 0 ? 'Save' : 'Create rule' ) ),
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => { state.editingRule = null; render(); },
			}, 'Back to list' ),
			f.jsonMode
				? el( 'button', { type: 'button', class: 'button', onClick: switchRuleEditorToStructured }, 'Switch to structured' )
				: el( 'button', { type: 'button', class: 'button', onClick: switchRuleEditorToJson }, 'Switch to JSON' ),
		];
		if ( f.id > 0 ) {
			footerKids.push( el( 'button', {
				type: 'button',
				class: 'button button-link-delete',
				onClick: () => deleteRule( f.id ),
			}, 'Soft delete' ) );
		}
		wrap.appendChild( el( 'div', { class: 'configkit-form__footer' }, ...footerKids ) );

		return wrap;
	}

	function renderRuleWhen( f ) {
		const wrap = el( 'fieldset', { class: 'configkit-fieldset' }, el( 'legend', null, 'WHEN' ) );

		// Conjunction selector (all / any / always / never).
		wrap.appendChild( selectFieldRow(
			'Conjunction',
			'conjunction',
			[
				[ 'all',        'all conditions match (AND)' ],
				[ 'any',        'any condition matches (OR)' ],
				[ 'all_always', 'always (no conditions)' ],
				[ 'all_never',  'never (rule disabled by spec)' ],
			],
			f.conjunction,
			( v ) => { f.conjunction = v; render(); }
		) );

		if ( f.conjunction === 'all_always' || f.conjunction === 'all_never' ) {
			return wrap;
		}

		const fieldChoices = ( ( state.steps.items || [] ).flatMap( () => [] ) ); // placeholder
		const allFieldKeys = collectAllFieldKeysForTemplate();

		( f.conditions || [] ).forEach( ( c, i ) => {
			const row = el( 'div', { class: 'configkit-rule-row' } );
			row.appendChild( selectFieldRow(
				'Field',
				'cond_field_' + i,
				allFieldKeys.map( ( k ) => [ k, k ] ),
				c.field,
				( v ) => { c.field = v; render(); }
			) );
			row.appendChild( selectFieldRow(
				'Operator',
				'cond_op_' + i,
				RULE_OPERATORS,
				c.op,
				( v ) => { c.op = v; render(); }
			) );
			if ( c.op !== 'is_selected' && c.op !== 'is_empty' ) {
				row.appendChild( textField( 'Value', 'cond_val_' + i, c.value, ( v ) => { c.value = v; }, {
					help: c.op === 'between' ? 'min,max' : ( ( c.op === 'in' || c.op === 'not_in' ) ? 'comma-separated' : '' ),
				} ) );
			}
			row.appendChild( el( 'button', {
				type: 'button',
				class: 'button button-link-delete',
				onClick: () => { f.conditions.splice( i, 1 ); render(); },
				title: 'Remove condition',
			}, '✕' ) );
			wrap.appendChild( row );
		} );

		wrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button',
			onClick: () => {
				f.conditions = ( f.conditions || [] ).slice();
				f.conditions.push( blankCondition() );
				render();
			},
		}, '+ Add condition' ) );

		void fieldChoices;
		return wrap;
	}

	function renderRuleThen( f ) {
		const wrap = el( 'fieldset', { class: 'configkit-fieldset' }, el( 'legend', null, 'THEN' ) );

		const stepChoices = ( state.steps.items || [] ).map( ( s ) => [ s.step_key, s.step_key + ' · ' + s.label ] );
		const allFieldKeys = collectAllFieldKeysForTemplate();
		const fieldChoices = allFieldKeys.map( ( k ) => [ k, k ] );

		( f.actions || [] ).forEach( ( a, i ) => {
			const row = el( 'div', { class: 'configkit-rule-row' } );
			row.appendChild( selectFieldRow(
				'Action',
				'act_type_' + i,
				RULE_ACTIONS,
				a.action,
				( v ) => { a.action = v; render(); }
			) );
			// Per-action target inputs.
			if ( [ 'show_field', 'hide_field', 'require_field', 'reset_value' ].includes( a.action ) ) {
				row.appendChild( selectFieldRow( 'Field', 'act_field_' + i, fieldChoices, a.field, ( v ) => { a.field = v; } ) );
			} else if ( [ 'show_step', 'hide_step' ].includes( a.action ) ) {
				row.appendChild( selectFieldRow( 'Step', 'act_step_' + i, stepChoices, a.step, ( v ) => { a.step = v; } ) );
			} else if ( a.action === 'disable_option' ) {
				row.appendChild( selectFieldRow( 'Field', 'act_field_' + i, fieldChoices, a.field, ( v ) => { a.field = v; } ) );
				row.appendChild( textField( 'option_key', 'act_opt_' + i, a.option, ( v ) => { a.option = v; }, { mono: true } ) );
			} else if ( a.action === 'filter_source' ) {
				row.appendChild( selectFieldRow( 'Field', 'act_field_' + i, fieldChoices, a.field, ( v ) => { a.field = v; } ) );
				row.appendChild( textField( 'Filter tag', 'act_tag_' + i, a.filter_tag, ( v ) => { a.filter_tag = v; } ) );
			} else if ( a.action === 'set_default' ) {
				row.appendChild( selectFieldRow( 'Field', 'act_field_' + i, fieldChoices, a.field, ( v ) => { a.field = v; } ) );
				row.appendChild( textField( 'Value', 'act_val_' + i, a.value, ( v ) => { a.value = v; } ) );
			} else if ( a.action === 'switch_lookup_table' ) {
				row.appendChild( textField( 'lookup_table_key', 'act_lt_' + i, a.lookup_table_key, ( v ) => { a.lookup_table_key = v; }, { mono: true } ) );
			} else if ( a.action === 'add_surcharge' ) {
				row.appendChild( textField( 'Label', 'act_label_' + i, a.label, ( v ) => { a.label = v; } ) );
				row.appendChild( numberFieldRow( 'Amount (NOK)', 'act_amt_' + i, a.amount, ( v ) => { a.amount = v; a.percent_of_base = ''; }, { allowFloat: true } ) );
				row.appendChild( numberFieldRow( 'Percent of base', 'act_pct_' + i, a.percent_of_base, ( v ) => { a.percent_of_base = v; a.amount = ''; }, { allowFloat: true } ) );
			} else if ( a.action === 'show_warning' ) {
				row.appendChild( textField( 'Message', 'act_msg_' + i, a.message, ( v ) => { a.message = v; } ) );
				row.appendChild( selectFieldRow( 'Level', 'act_lvl_' + i, [ [ 'info', 'info' ], [ 'warning', 'warning' ], [ 'error', 'error' ] ], a.level, ( v ) => { a.level = v; } ) );
			} else if ( a.action === 'block_add_to_cart' ) {
				row.appendChild( textField( 'Message', 'act_msg_' + i, a.message, ( v ) => { a.message = v; } ) );
			}
			row.appendChild( el( 'button', {
				type: 'button',
				class: 'button button-link-delete',
				onClick: () => { f.actions.splice( i, 1 ); render(); },
				title: 'Remove action',
			}, '✕' ) );
			wrap.appendChild( row );
		} );

		wrap.appendChild( el( 'button', {
			type: 'button',
			class: 'button',
			onClick: () => {
				f.actions = ( f.actions || [] ).slice();
				f.actions.push( blankAction() );
				render();
			},
		}, '+ Add action' ) );

		return wrap;
	}

	function collectAllFieldKeysForTemplate() {
		// Aggregate field_keys from the steps cached on state. The detail
		// view only fetches fields for the SELECTED step; so the rules
		// editor falls back to fetching the full template field index
		// the first time it runs.
		const steps = state.steps.items || [];
		const keys = [];
		steps.forEach( ( s ) => {
			if ( state._allFieldsByStep && state._allFieldsByStep[ s.step_key ] ) {
				state._allFieldsByStep[ s.step_key ].forEach( ( fk ) => keys.push( fk ) );
			}
		} );
		// If we haven't built the index yet, kick off a fetch (fire and forget).
		if ( ! state._allFieldsByStep ) {
			state._allFieldsByStep = {};
			Promise.all( steps.map( ( s ) =>
				ConfigKit.request( '/templates/' + state.viewing.id + '/steps/' + s.id + '/fields' )
					.then( ( data ) => {
						state._allFieldsByStep[ s.step_key ] = ( data.items || [] ).map( ( f ) => f.field_key );
					} )
					.catch( () => { state._allFieldsByStep[ s.step_key ] = []; } )
			) ).then( render );
		}
		return Array.from( new Set( keys ) );
	}

	// ---- Validation, publish, version history ----

	async function runValidation() {
		if ( ! state.viewing ) return;
		state.busy = true;
		render();
		try {
			const data = await ConfigKit.request(
				'/templates/' + state.viewing.id + '/validate',
				{ method: 'POST', body: {} }
			);
			state.validation = data;
			state.validationOpen = true;
			clearMessages();
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	function closeValidation() {
		state.validationOpen = false;
		render();
	}

	async function requestPublish() {
		if ( ! state.viewing ) return;
		state.busy = true;
		render();
		try {
			const data = await ConfigKit.request(
				'/templates/' + state.viewing.id + '/validate',
				{ method: 'POST', body: {} }
			);
			state.validation = data;
			if ( ! data.valid ) {
				state.validationOpen = true;
				state.message = { kind: 'error', text: 'Cannot publish — fix the errors below first.' };
			} else {
				state.publishConfirm = { validation: data };
			}
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	function cancelPublishConfirm() {
		state.publishConfirm = null;
		render();
	}

	async function confirmPublish() {
		if ( ! state.viewing || ! state.publishConfirm ) return;
		state.busy = true;
		render();
		try {
			const data = await ConfigKit.request(
				'/templates/' + state.viewing.id + '/publish',
				{ method: 'POST', body: {} }
			);
			state.publishConfirm = null;
			state.viewing = Object.assign( {}, state.viewing, {
				status: 'published',
				published_version_id: data.record.id,
			} );
			state.message = { kind: 'success', text: 'Published version ' + data.record.version_number + '.' };
			await refreshVersions();
		} catch ( err ) {
			showError( err );
		} finally {
			state.busy = false;
		}
		render();
	}

	async function openVersionsDrawer() {
		state.versionsOpen = true;
		await refreshVersions();
		render();
	}

	function closeVersionsDrawer() {
		state.versionsOpen = false;
		state.viewingVersion = null;
		render();
	}

	async function refreshVersions() {
		if ( ! state.viewing ) return;
		try {
			const data = await ConfigKit.request( '/templates/' + state.viewing.id + '/versions' );
			state.versions = data;
		} catch ( err ) {
			showError( err );
		}
	}

	async function viewVersionSnapshot( versionId ) {
		try {
			const data = await ConfigKit.request(
				'/templates/' + state.viewing.id + '/versions/' + versionId
			);
			state.viewingVersion = data.record;
			render();
		} catch ( err ) {
			showError( err );
			render();
		}
	}

	function renderValidationPanel() {
		const v = state.validation;
		const wrap = el( 'div', { class: 'configkit-form configkit-validation-panel' } );
		const cls  = v.valid && v.warnings.length === 0
			? 'notice notice-success inline'
			: ( v.valid ? 'notice notice-warning inline' : 'notice notice-error inline' );
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-list__header' },
			el( 'h3', null, v.valid ? ( v.warnings.length === 0 ? 'All clean' : 'Valid (with warnings)' ) : 'Pre-publish issues' ),
			el( 'button', { type: 'button', class: 'button', onClick: closeValidation }, 'Close' )
		) );
		wrap.appendChild( el( 'p', { class: cls }, el( 'strong', null,
			v.valid ? ( v.warnings.length === 0 ? 'No errors and no warnings.' : v.warnings.length + ' warning(s).' )
			        : v.errors.length + ' error(s) and ' + v.warnings.length + ' warning(s).'
		) ) );

		if ( v.errors.length > 0 ) {
			wrap.appendChild( renderIssueList( 'Errors', v.errors, 'error' ) );
		}
		if ( v.warnings.length > 0 ) {
			wrap.appendChild( renderIssueList( 'Warnings', v.warnings, 'warning' ) );
		}
		return wrap;
	}

	function renderIssueList( title, issues, kind ) {
		const list = el( 'ul', { class: 'configkit-issue-list configkit-issue-list--' + kind } );
		issues.forEach( ( i ) => {
			list.appendChild( el(
				'li',
				null,
				el( 'span', { class: 'configkit-issue-list__type' }, ( i.object_type || '' ) + ':' ),
				' ',
				el( 'code', null, i.object_key || '' ),
				' — ',
				i.message
			) );
		} );
		return el( 'div', null,
			el( 'h4', null, title ),
			list
		);
	}

	function renderPublishConfirm() {
		const overlay = el( 'div', { class: 'configkit-modal-overlay' } );
		const modal   = el( 'div', { class: 'configkit-modal' } );
		overlay.appendChild( modal );
		const next = ( state.versions && state.versions.items && state.versions.items.length > 0 )
			? ( state.versions.items[ 0 ].version_number + 1 )
			: 1;
		modal.appendChild( el( 'h2', null, 'Publish version ' + next + '?' ) );
		modal.appendChild( el( 'p', null,
			'Validation passed. Publishing creates an immutable snapshot of the current draft. Existing carts keep their pinned version; new configurations use this new version.'
		) );
		if ( state.publishConfirm.validation && state.publishConfirm.validation.warnings.length > 0 ) {
			modal.appendChild( el( 'p', { class: 'description' },
				state.publishConfirm.validation.warnings.length + ' warning(s) present — review them in the Validate panel before publishing.'
			) );
		}
		modal.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', {
				type: 'button',
				class: 'button button-primary',
				disabled: state.busy,
				onClick: confirmPublish,
			}, state.busy ? 'Publishing…' : 'Publish' ),
			el( 'button', { type: 'button', class: 'button', onClick: cancelPublishConfirm }, 'Cancel' )
		) );
		return overlay;
	}

	function renderVersionsDrawer() {
		const overlay = el( 'div', { class: 'configkit-modal-overlay configkit-versions-drawer' } );
		const drawer  = el( 'div', { class: 'configkit-modal configkit-versions-drawer__panel' } );
		overlay.appendChild( drawer );

		drawer.appendChild( el(
			'div',
			{ class: 'configkit-builder__pane-header' },
			el( 'h2', null, state.viewingVersion ? 'Version v' + state.viewingVersion.version_number : 'Version history' ),
			el( 'button', { type: 'button', class: 'button', onClick: closeVersionsDrawer }, 'Close' )
		) );

		if ( state.viewingVersion ) {
			drawer.appendChild( renderVersionSnapshot() );
		} else {
			drawer.appendChild( renderVersionList() );
		}

		return overlay;
	}

	function renderVersionList() {
		const wrap = el( 'div' );
		const items = state.versions.items || [];
		if ( items.length === 0 ) {
			wrap.appendChild( el(
				'div',
				{ class: 'configkit-empty' },
				el( 'p', null, 'No published versions yet.' ),
				el( 'p', { class: 'configkit-empty__hint' }, 'Click "Publish" to create version 1.' )
			) );
			return wrap;
		}

		const table = el(
			'table',
			{ class: 'wp-list-table widefat striped' },
			el( 'thead', null, el(
				'tr',
				null,
				el( 'th', null, 'Version' ),
				el( 'th', null, 'Status' ),
				el( 'th', null, 'Published' ),
				el( 'th', null, 'Used by' ),
				el( 'th', null, '' )
			) )
		);
		const tbody = el( 'tbody' );
		items.forEach( ( v ) => {
			tbody.appendChild( el(
				'tr',
				null,
				el( 'td', null, 'v' + v.version_number ),
				el(
					'td',
					null,
					el(
						'span',
						{ class: 'configkit-badge configkit-badge--' + ( v.status === 'published' ? 'active' : 'inactive' ) },
						v.status
					)
				),
				el( 'td', null, v.published_at || '—' ),
				el( 'td', null, '— / —' ),
				el(
					'td',
					{ class: 'configkit-actions' },
					el(
						'button',
						{ type: 'button', class: 'button', onClick: () => viewVersionSnapshot( v.id ) },
						'View'
					)
				)
			) );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	function renderVersionSnapshot() {
		const v = state.viewingVersion;
		const wrap = el( 'div' );
		wrap.appendChild( el( 'p', { class: 'description' },
			'Read-only snapshot. Published ' + ( v.published_at || '—' ) + ' · ' + v.status
		) );
		const snap = v.snapshot || {};
		wrap.appendChild( el( 'h4', null, 'Template' ) );
		wrap.appendChild( el( 'pre', { class: 'configkit-json' }, JSON.stringify( snap.template || {}, null, 2 ) ) );
		wrap.appendChild( el( 'h4', null, 'Steps (' + ( ( snap.steps || [] ).length ) + ')' ) );
		wrap.appendChild( el( 'pre', { class: 'configkit-json' }, JSON.stringify( snap.steps || [], null, 2 ) ) );
		wrap.appendChild( el( 'h4', null, 'Fields (' + ( ( snap.fields || [] ).length ) + ')' ) );
		wrap.appendChild( el( 'pre', { class: 'configkit-json' }, JSON.stringify( snap.fields || [], null, 2 ) ) );
		wrap.appendChild( el( 'h4', null, 'Field options (' + ( ( snap.field_options || [] ).length ) + ')' ) );
		wrap.appendChild( el( 'pre', { class: 'configkit-json' }, JSON.stringify( snap.field_options || [], null, 2 ) ) );
		wrap.appendChild( el( 'h4', null, 'Rules (' + ( ( snap.rules || [] ).length ) + ')' ) );
		wrap.appendChild( el( 'pre', { class: 'configkit-json' }, JSON.stringify( snap.rules || [], null, 2 ) ) );
		wrap.appendChild( el(
			'div',
			{ class: 'configkit-form__footer' },
			el( 'button', {
				type: 'button',
				class: 'button',
				onClick: () => { state.viewingVersion = null; render(); },
			}, 'Back to versions list' )
		) );
		return wrap;
	}

	function init() {
		const params = new URLSearchParams( window.location.search );
		const action = params.get( 'action' );
		const id = parseInt( params.get( 'id' ) || '0', 10 );
		const stepAction = params.get( 'step_action' );
		const stepId = parseInt( params.get( 'step_id' ) || '0', 10 );
		const fieldId = parseInt( params.get( 'field_id' ) || '0', 10 );
		const fieldAction = params.get( 'field_action' );
		const savedMessage = consumeSavedFlag();

		if ( action === 'new' ) {
			showNewForm();
		} else if ( action === 'edit' && id > 0 ) {
			loadTemplateForEdit( id );
		} else if ( id > 0 && stepAction === 'new' ) {
			openTemplateDetail( id ).then( () => showNewStepForm() );
		} else if ( id > 0 && stepId > 0 && stepAction === 'edit' ) {
			loadStep( id, stepId );
		} else if ( id > 0 ) {
			openTemplateDetail( id, {
				selectStepId: stepId > 0 ? stepId : 0,
				selectFieldId: fieldId > 0 ? fieldId : 0,
			} ).then( () => {
				if ( fieldAction === 'new' && state.selectedStepId ) openWizard();
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
