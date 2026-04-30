/* global CONFIGKIT_FRONT */
/**
 * ConfigKit storefront configurator.
 *
 * Architecture (vanilla JS, no framework):
 *  - Bootstrap fetches /products/{id}/render-data on load.
 *  - State holds: snapshot (template + binding + libraries +
 *    lookup), values (per field), step pointer, derived (rule-eval
 *    output: visibility + filtered options + locked values), price
 *    (lookup + per-field deltas + addons).
 *  - Render is a single render() that wipes the mount and rebuilds
 *    from state. Cheap because the configurator has tens of
 *    elements, not hundreds.
 *  - Field renderers per FIELD_MODEL §8: number, radio plain,
 *    radio cards, swatch grid, checkbox cards, lookup-dimension
 *    number inputs, display-only headings.
 *  - Live re-eval (rules + price) lives in CHUNK 3 — wired here as
 *    stubs that the next chunk fills in.
 */
( function () {
	'use strict';

	var mount = document.getElementById( 'configkit-configurator' );
	if ( ! mount ) return;
	if ( typeof window.CONFIGKIT_FRONT === 'undefined' ) return;

	var FRONT = window.CONFIGKIT_FRONT;

	var state = {
		ready:    false,
		error:    null,
		snapshot: null,           // full payload from /render-data
		values:   {},             // field_key → current value
		stepIndex: 0,             // active step (stepper mode)
		derived: {                // rule-eval output (CHUNK 3)
			hiddenSteps:  {},     // step_key → true
			hiddenFields: {},     // field_key → true
			requiredFields: {},   // field_key → true
			lockedValues: {},     // field_key → value
			disabledOptions: {},  // field_key → { option_key → true }
			filteredItems: {},    // field_key → list<item_key> (subset)
			ruleNotes: [],
		},
		price: {                  // pricing-engine output (CHUNK 3)
			subtotal: null,
			breakdown: [],
			currency: 'NOK',
			calculating: false,
		},
	};

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				var v = attrs[ key ];
				if ( v === false || v === null || v === undefined ) return;
				if ( key === 'class' ) node.className = v;
				else if ( key === 'html' ) node.innerHTML = v;
				else if ( key.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
					node.addEventListener( key.slice( 2 ).toLowerCase(), v );
				} else if ( key in node && typeof v === 'boolean' ) {
					node[ key ] = v;
				} else {
					node.setAttribute( key, String( v ) );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) return;
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	// Boot ----------------------------------------------------------------

	async function boot() {
		try {
			var url = FRONT.restUrl + '/products/' + FRONT.productId + '/render-data';
			var res = await fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': FRONT.nonce },
			} );
			if ( ! res.ok ) {
				var payload = null;
				try { payload = await res.json(); } catch ( e ) { /* ignore */ }
				throw new Error( ( payload && payload.message ) || ( 'HTTP ' + res.status ) );
			}
			var data = await res.json();
			state.snapshot = data;
			state.values   = computeInitialValues( data );
			state.ready    = true;
			recompute();
			render();
		} catch ( err ) {
			state.error = err && err.message ? err.message : String( err );
			render();
		}
	}

	function computeInitialValues( snapshot ) {
		var values = {};
		var defaults = ( snapshot && snapshot.binding && snapshot.binding.defaults ) || {};
		var overrides = ( snapshot && snapshot.binding && snapshot.binding.field_overrides ) || {};
		( snapshot.fields || [] ).forEach( function ( f ) {
			// Locked override always wins.
			if ( overrides[ f.field_key ] && Object.prototype.hasOwnProperty.call( overrides[ f.field_key ], 'lock' ) ) {
				values[ f.field_key ] = overrides[ f.field_key ].lock;
				return;
			}
			if ( Object.prototype.hasOwnProperty.call( defaults, f.field_key ) ) {
				values[ f.field_key ] = defaults[ f.field_key ];
				return;
			}
			if ( overrides[ f.field_key ] && Object.prototype.hasOwnProperty.call( overrides[ f.field_key ], 'preselect' ) ) {
				values[ f.field_key ] = overrides[ f.field_key ].preselect;
				return;
			}
			// Type defaults so render stays predictable.
			if ( f.input_type === 'checkbox' ) values[ f.field_key ] = [];
			else if ( f.input_type === 'number' ) values[ f.field_key ] = '';
			else values[ f.field_key ] = '';
		} );
		return values;
	}

	function setValue( fieldKey, value ) {
		state.values[ fieldKey ] = value;
		recompute();
		render();
	}

	function recompute() {
		state.price.calculating = true;
		var engines = window.ConfigKitEngines;
		if ( ! engines || ! state.snapshot ) {
			state.price.calculating = false;
			return;
		}
		var ctx = { snapshot: state.snapshot, values: state.values, derived: state.derived };
		state.derived = engines.evalRules( ctx );
		// Re-evaluation may have queued set_default mutations into ctx.values.
		state.values = ctx.values;
		var price = engines.computePrice( {
			snapshot: state.snapshot,
			values:   state.values,
			derived:  state.derived,
		} );
		state.price.subtotal = price.subtotal;
		state.price.breakdown = price.fields;
		state.price.base = price.base;
		state.price.reason = price.reason;
		state.price.vatLabel = price.vatLabel;
		state.price.priceGroupKey = price.price_group_key;
		state.price.resolvedDims = price.resolved_dims;
		state.price.calculating = false;
	}

	// Render --------------------------------------------------------------

	function render() {
		mount.replaceChildren();
		if ( state.error ) {
			mount.appendChild( renderError( state.error ) );
			return;
		}
		if ( ! state.ready ) {
			mount.appendChild( renderSkeleton() );
			return;
		}

		var wrap = el( 'div', { class: 'configkit-c' }, [
			renderHeader(),
			renderBody(),
		] );
		mount.appendChild( wrap );
	}

	function renderError( msg ) {
		return el( 'div', { class: 'configkit-c__error' }, [
			el( 'h2', null, [ 'Configurator unavailable' ] ),
			el( 'p', null, [ "We couldn't load this product's options. Please refresh the page; if the problem persists, contact support." ] ),
			el( 'details', null, [
				el( 'summary', null, [ 'Technical details' ] ),
				el( 'pre', null, [ msg ] ),
			] ),
		] );
	}

	function renderSkeleton() {
		return el( 'div', { class: 'configkit-frontend__loading', 'aria-busy': 'true' }, [
			el( 'div', { class: 'configkit-frontend__skeleton-bar' }, [] ),
			el( 'div', { class: 'configkit-frontend__skeleton-bar' }, [] ),
			el( 'div', { class: 'configkit-frontend__skeleton-bar' }, [] ),
		] );
	}

	function renderHeader() {
		var s = state.snapshot || {};
		var name = ( s.template && s.template.name ) || 'Configure';
		return el( 'header', { class: 'configkit-c__header' }, [
			el( 'h2', { class: 'configkit-c__title' }, [ name ] ),
		] );
	}

	function renderBody() {
		var snapshot = state.snapshot;
		// Stepper mode is the only one shipped in this chunk.
		var steps = ( snapshot.steps || [] ).slice().sort( function ( a, b ) {
			return ( a.sort_order || 0 ) - ( b.sort_order || 0 );
		} );
		var visible = steps.filter( function ( s ) { return ! state.derived.hiddenSteps[ s.step_key ]; } );
		if ( visible.length === 0 ) {
			return el( 'p', null, [ 'This template has no steps yet.' ] );
		}
		if ( state.stepIndex >= visible.length ) state.stepIndex = visible.length - 1;
		var current = visible[ state.stepIndex ];
		return el( 'div', { class: 'configkit-c__body' }, [
			renderStepContent( current ),
		] );
	}

	function renderStepContent( step ) {
		var fields = ( state.snapshot.fields || [] )
			.filter( function ( f ) { return f.step_key === step.step_key; } )
			.filter( function ( f ) { return ! state.derived.hiddenFields[ f.field_key ]; } )
			.sort( function ( a, b ) { return ( a.sort_order || 0 ) - ( b.sort_order || 0 ); } );
		return el( 'section', { class: 'configkit-c__step' }, [
			el( 'div', { class: 'configkit-c__step-head' }, [
				el( 'h3', { class: 'configkit-c__step-title' }, [ step.label ] ),
				step.helper_text ? el( 'p', { class: 'configkit-c__step-helper' }, [ step.helper_text ] ) : null,
			] ),
			el( 'div', { class: 'configkit-c__fields' }, fields.map( renderField ) ),
		] );
	}

	function renderField( field ) {
		var children = [
			el( 'div', { class: 'configkit-c__field-head' }, [
				el( 'label', { class: 'configkit-c__field-label' }, [
					field.label,
					field.is_required || state.derived.requiredFields[ field.field_key ]
						? el( 'span', { class: 'configkit-c__required' }, [ ' *' ] )
						: null,
				] ),
				field.helper_text ? el( 'p', { class: 'configkit-c__field-helper' }, [ field.helper_text ] ) : null,
			] ),
			renderFieldInput( field ),
		];
		return el( 'div', { class: 'configkit-c__field configkit-c__field--' + ( field.field_kind || 'input' ) }, children );
	}

	function renderFieldInput( field ) {
		// Dispatch by (field_kind, input_type, display_type, value_source).
		if ( field.field_kind === 'display' ) {
			return renderDisplay( field );
		}
		if ( field.field_kind === 'lookup' ) {
			return renderNumber( field );
		}
		if ( field.input_type === 'number' ) {
			return renderNumber( field );
		}
		if ( field.input_type === 'radio' || field.input_type === 'select' ) {
			if ( field.display_type === 'cards' )       return renderRadioCards( field );
			if ( field.display_type === 'swatch_grid' ) return renderRadioCards( field, { swatch: true } );
			return renderRadioPlain( field );
		}
		if ( field.input_type === 'checkbox' ) {
			if ( field.display_type === 'cards' ) return renderCheckboxCards( field );
			return renderCheckboxList( field );
		}
		return renderTextFallback( field );
	}

	function renderDisplay( field ) {
		var heading = field.helper_text || field.label;
		return el( 'p', { class: 'configkit-c__display' }, [ heading ] );
	}

	function renderNumber( field ) {
		var raw = state.values[ field.field_key ];
		var value = ( raw === '' || raw == null ) ? '' : String( raw );
		var min  = field.min  != null ? Number( field.min )  : null;
		var max  = field.max  != null ? Number( field.max )  : null;
		var step = field.step != null ? Number( field.step ) : 1;
		var unitText = field.unit ? ' ' + field.unit : '';

		function commit( v ) {
			if ( v === '' ) { setValue( field.field_key, '' ); return; }
			var num = Number( v );
			if ( Number.isNaN( num ) ) return;
			if ( min !== null && num < min ) num = min;
			if ( max !== null && num > max ) num = max;
			setValue( field.field_key, num );
		}

		var input = el( 'input', {
			type: 'number',
			class: 'configkit-c__number-input',
			value: value,
			min: min !== null ? String( min ) : null,
			max: max !== null ? String( max ) : null,
			step: String( step ),
			'aria-label': field.label,
			onInput: function ( ev ) { commit( ev.target.value ); },
		}, [] );

		var locked = isLocked( field );
		if ( locked ) input.disabled = true;

		return el( 'div', { class: 'configkit-c__number' }, [
			el( 'button', {
				type: 'button',
				class: 'configkit-c__number-step',
				'aria-label': 'Decrease',
				disabled: locked,
				onClick: function () {
					var current = state.values[ field.field_key ];
					var n = current === '' || current == null ? ( min !== null ? min : 0 ) : Number( current );
					commit( n - step );
				},
			}, [ '−' ] ),
			input,
			el( 'span', { class: 'configkit-c__number-unit' }, [ unitText ] ),
			el( 'button', {
				type: 'button',
				class: 'configkit-c__number-step',
				'aria-label': 'Increase',
				disabled: locked,
				onClick: function () {
					var current = state.values[ field.field_key ];
					var n = current === '' || current == null ? ( min !== null ? min : 0 ) : Number( current );
					commit( n + step );
				},
			}, [ '+' ] ),
		] );
	}

	function renderRadioPlain( field ) {
		var choices = collectChoicesFor( field );
		var current = state.values[ field.field_key ];
		var locked  = isLocked( field );
		return el( 'div', { class: 'configkit-c__radio-list', role: 'radiogroup' }, choices.map( function ( c ) {
			var disabled = locked || isOptionDisabled( field, c.key );
			return el( 'label', { class: 'configkit-c__radio-row' + ( disabled ? ' is-disabled' : '' ) }, [
				el( 'input', {
					type: 'radio',
					name: 'cf_' + field.field_key,
					value: c.key,
					checked: current === c.key,
					disabled: disabled,
					onChange: function () { setValue( field.field_key, c.key ); },
				}, [] ),
				el( 'span', { class: 'configkit-c__radio-label' }, [ c.label ] ),
			] );
		} ) );
	}

	function renderRadioCards( field, opts ) {
		opts = opts || {};
		var choices = collectChoicesFor( field );
		var current = state.values[ field.field_key ];
		var locked  = isLocked( field );
		return el( 'div', { class: 'configkit-c__cards' + ( opts.swatch ? ' configkit-c__cards--swatch' : '' ) }, choices.map( function ( c ) {
			var selected = current === c.key;
			var disabled = locked || isOptionDisabled( field, c.key );
			var classes = 'configkit-c__card'
				+ ( selected ? ' is-selected' : '' )
				+ ( disabled ? ' is-disabled' : '' );
			return el( 'button', {
				type: 'button',
				class: classes,
				disabled: disabled,
				'aria-pressed': selected ? 'true' : 'false',
				onClick: function () { if ( ! disabled ) setValue( field.field_key, c.key ); },
			}, [
				c.image_url
					? el( 'img', { src: c.image_url, alt: c.label, loading: 'lazy', class: 'configkit-c__card-image' }, [] )
					: el( 'span', { class: 'configkit-c__card-placeholder' }, [] ),
				el( 'span', { class: 'configkit-c__card-label' }, [ c.label ] ),
				c.helper_text ? el( 'span', { class: 'configkit-c__card-helper' }, [ c.helper_text ] ) : null,
			] );
		} ) );
	}

	function renderCheckboxList( field ) {
		var choices = collectChoicesFor( field );
		var current = state.values[ field.field_key ];
		if ( ! Array.isArray( current ) ) current = current ? [ current ] : [];
		var locked = isLocked( field );

		function toggle( key ) {
			if ( locked ) return;
			var next = current.slice();
			var idx = next.indexOf( key );
			if ( idx === -1 ) next.push( key );
			else next.splice( idx, 1 );
			setValue( field.field_key, next );
		}

		return el( 'div', { class: 'configkit-c__check-list' }, choices.map( function ( c ) {
			var disabled = locked || isOptionDisabled( field, c.key );
			return el( 'label', { class: 'configkit-c__check-row' + ( disabled ? ' is-disabled' : '' ) }, [
				el( 'input', {
					type: 'checkbox',
					checked: current.indexOf( c.key ) !== -1,
					disabled: disabled,
					onChange: function () { toggle( c.key ); },
				}, [] ),
				el( 'span', null, [ c.label ] ),
			] );
		} ) );
	}

	function renderCheckboxCards( field ) {
		var choices = collectChoicesFor( field );
		var current = state.values[ field.field_key ];
		if ( ! Array.isArray( current ) ) current = current ? [ current ] : [];
		var locked = isLocked( field );

		function toggle( key ) {
			if ( locked ) return;
			var next = current.slice();
			var idx = next.indexOf( key );
			if ( idx === -1 ) next.push( key );
			else next.splice( idx, 1 );
			setValue( field.field_key, next );
		}

		return el( 'div', { class: 'configkit-c__cards' }, choices.map( function ( c ) {
			var selected = current.indexOf( c.key ) !== -1;
			var disabled = locked || isOptionDisabled( field, c.key );
			return el( 'button', {
				type: 'button',
				class: 'configkit-c__card' + ( selected ? ' is-selected' : '' ) + ( disabled ? ' is-disabled' : '' ),
				disabled: disabled,
				'aria-pressed': selected ? 'true' : 'false',
				onClick: function () { toggle( c.key ); },
			}, [
				c.image_url
					? el( 'img', { src: c.image_url, alt: c.label, loading: 'lazy', class: 'configkit-c__card-image' }, [] )
					: el( 'span', { class: 'configkit-c__card-placeholder' }, [] ),
				el( 'span', { class: 'configkit-c__card-label' }, [ c.label ] ),
			] );
		} ) );
	}

	function renderTextFallback( field ) {
		var raw = state.values[ field.field_key ];
		return el( 'input', {
			type: 'text',
			class: 'configkit-c__text-input',
			value: ( raw == null ) ? '' : String( raw ),
			'aria-label': field.label,
			onInput: function ( ev ) { setValue( field.field_key, ev.target.value ); },
		}, [] );
	}

	// Helpers -------------------------------------------------------------

	/**
	 * Collect the option / item list for a field, honoring its
	 * value_source plus any binding-level allowed_sources filter.
	 * Each choice is { key, label, image_url, helper_text, item }.
	 */
	function collectChoicesFor( field ) {
		var snapshot = state.snapshot;
		var allowed = ( snapshot.binding.allowed_sources || {} )[ field.field_key ] || {};

		// Manual options.
		if ( field.value_source === 'manual_options' ) {
			var allowedOptions = Array.isArray( allowed.allowed_options ) ? allowed.allowed_options : null;
			return ( snapshot.field_options || [] )
				.filter( function ( o ) { return o.field_key === field.field_key && o.is_active; } )
				.filter( function ( o ) { return allowedOptions === null || allowedOptions.indexOf( o.option_key ) !== -1; } )
				.sort( function ( a, b ) { return ( a.sort_order || 0 ) - ( b.sort_order || 0 ); } )
				.map( function ( o ) {
					return {
						key: o.option_key,
						label: o.label,
						image_url: o.image_url || null,
						helper_text: o.helper_text || null,
						option: o,
					};
				} );
		}

		// Library-backed.
		if ( field.value_source === 'library' ) {
			var cfg = field.source_config || {};
			var libraries = Array.isArray( cfg.libraries ) ? cfg.libraries : [];
			var allowedLibs = Array.isArray( allowed.allowed_libraries ) && allowed.allowed_libraries.length > 0
				? allowed.allowed_libraries
				: libraries;
			var excluded = Array.isArray( allowed.excluded_items ) ? allowed.excluded_items : [];
			var byKey = {};
			( snapshot.libraries || [] ).forEach( function ( l ) { byKey[ l.library_key ] = l; } );

			var out = [];
			allowedLibs.forEach( function ( lk ) {
				var lib = byKey[ lk ];
				if ( ! lib ) return;
				lib.items.forEach( function ( item ) {
					if ( ! item.is_active ) return;
					if ( excluded.indexOf( lk + ':' + item.item_key ) !== -1 ) return;
					out.push( {
						key: lk + ':' + item.item_key,
						label: item.label,
						image_url: item.image_url || item.main_image_url || null,
						helper_text: item.short_label || null,
						item: item,
						library_key: lk,
					} );
				} );
			} );
			return out;
		}

		// Lookup-table fields use width/height as values directly.
		return [];
	}

	function isLocked( field ) {
		var overrides = state.snapshot.binding.field_overrides || {};
		return overrides[ field.field_key ]
			&& Object.prototype.hasOwnProperty.call( overrides[ field.field_key ], 'lock' );
	}

	function isOptionDisabled( field, optionKey ) {
		var disabled = state.derived.disabledOptions[ field.field_key ];
		return disabled && disabled[ optionKey ] === true;
	}

	// Boot the app.
	boot();

	// Expose for the upcoming chunks (rule engine, pricing engine,
	// add-to-cart) so they can mutate state and trigger render.
	window.ConfigKitFrontend = {
		state: state,
		render: render,
		setValue: setValue,
	};
} )();
