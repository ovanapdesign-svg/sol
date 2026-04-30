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
			renderStepper(),
			el( 'div', { class: 'configkit-c__layout' }, [
				renderBody(),
				renderPriceSidebar(),
			] ),
			renderStickyBar(),
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
		var visible = visibleSteps();
		if ( visible.length === 0 ) {
			return el( 'div', { class: 'configkit-c__body' }, [ el( 'p', null, [ 'This template has no steps yet.' ] ) ] );
		}
		if ( state.stepIndex >= visible.length ) state.stepIndex = visible.length - 1;
		if ( state.stepIndex < 0 ) state.stepIndex = 0;
		var current = visible[ state.stepIndex ];
		return el( 'div', { class: 'configkit-c__body' }, [
			renderStepContent( current ),
			renderStepNav( visible ),
		] );
	}

	function visibleSteps() {
		var snapshot = state.snapshot;
		return ( snapshot.steps || [] ).slice()
			.sort( function ( a, b ) { return ( a.sort_order || 0 ) - ( b.sort_order || 0 ); } )
			.filter( function ( s ) { return ! state.derived.hiddenSteps[ s.step_key ]; } );
	}

	function renderStepper() {
		var visible = visibleSteps();
		if ( visible.length === 0 ) return null;
		var nav = el( 'ol', { class: 'configkit-c__stepper' }, visible.map( function ( step, i ) {
			var status = i < state.stepIndex
				? 'configkit-c__stepper-item--done'
				: ( i === state.stepIndex
					? 'configkit-c__stepper-item--current'
					: 'configkit-c__stepper-item--pending' );
			return el( 'li', {
				class: 'configkit-c__stepper-item ' + status,
				onClick: function () {
					if ( i <= state.stepIndex ) {
						state.stepIndex = i;
						render();
					}
				},
				role: 'button',
				tabindex: i <= state.stepIndex ? '0' : '-1',
				'aria-current': i === state.stepIndex ? 'step' : 'false',
			}, [
				el( 'span', { class: 'configkit-c__stepper-num' }, [ String( i + 1 ) ] ),
				el( 'span', { class: 'configkit-c__stepper-label' }, [ step.label ] ),
			] );
		} ) );
		return nav;
	}

	function renderStepNav( visible ) {
		var atFirst = state.stepIndex === 0;
		var atLast  = state.stepIndex === visible.length - 1;
		return el( 'div', { class: 'configkit-c__step-nav' }, [
			el( 'button', {
				type: 'button',
				class: 'configkit-c__nav-btn configkit-c__nav-btn--secondary',
				disabled: atFirst,
				onClick: function () { if ( ! atFirst ) { state.stepIndex--; render(); window.scrollTo( { top: 0, behavior: 'smooth' } ); } },
			}, [ '← Back' ] ),
			atLast
				? el( 'button', {
					type: 'button',
					class: 'configkit-c__nav-btn configkit-c__nav-btn--primary',
					onClick: function () {
						addToCart();
					},
				}, [ 'Add to cart' ] )
				: el( 'button', {
					type: 'button',
					class: 'configkit-c__nav-btn configkit-c__nav-btn--primary',
					onClick: function () { state.stepIndex++; render(); window.scrollTo( { top: 0, behavior: 'smooth' } ); },
				}, [ 'Continue →' ] ),
		] );
	}

	function renderPriceSidebar() {
		var p = state.price || {};
		var s = state.snapshot || {};
		var children = [
			el( 'header', { class: 'configkit-c__sidebar-header' }, [
				el( 'p', { class: 'configkit-c__sidebar-eyebrow' }, [ ( s.template && s.template.name ) || 'Configuration' ] ),
				el( 'p', { class: 'configkit-c__sidebar-amount' }, [ formatPrice( p.subtotal ) ] ),
				p.vatLabel && p.vatLabel !== 'inherit' && p.vatLabel !== 'off'
					? el( 'p', { class: 'configkit-c__sidebar-vat' }, [ p.vatLabel === 'incl_vat' ? 'incl. VAT' : 'excl. VAT' ] )
					: null,
				p.calculating ? el( 'p', { class: 'configkit-c__sidebar-status' }, [ 'Calculating…' ] ) : null,
				p.subtotal === null && p.reason ? el( 'p', { class: 'configkit-c__sidebar-status' }, [ priceReasonMessage( p.reason ) ] ) : null,
			] ),
			renderBreakdown( p ),
			renderSummaryActions(),
		];
		return el( 'aside', { class: 'configkit-c__sidebar' }, children );
	}

	function renderStickyBar() {
		var p = state.price || {};
		return el( 'div', { class: 'configkit-c__sticky' }, [
			el( 'div', { class: 'configkit-c__sticky-row' }, [
				el( 'div', { class: 'configkit-c__sticky-amount' }, [
					el( 'span', { class: 'configkit-c__sticky-amount-value' }, [ formatPrice( p.subtotal ) ] ),
					p.vatLabel && p.vatLabel !== 'inherit' && p.vatLabel !== 'off'
						? el( 'span', { class: 'configkit-c__sticky-vat' }, [ p.vatLabel === 'incl_vat' ? 'incl. VAT' : 'excl. VAT' ] )
						: null,
				] ),
				el( 'button', {
					type: 'button',
					class: 'configkit-c__nav-btn configkit-c__nav-btn--primary configkit-c__sticky-btn',
					onClick: function () {
						addToCart();
					},
				}, [ 'Add to cart' ] ),
			] ),
		] );
	}

	function renderBreakdown( price ) {
		var rows = ( price && price.fields ) || [];
		var hasAny = rows.length > 0 || price.base !== null;
		if ( ! hasAny ) {
			return el( 'p', { class: 'configkit-c__breakdown-empty' }, [ 'Pick options to see your price.' ] );
		}
		return el( 'ul', { class: 'configkit-c__breakdown' }, [
			price.base !== null
				? el( 'li', { class: 'configkit-c__breakdown-row configkit-c__breakdown-row--base' }, [
					el( 'span', null, [ 'Base' ] ),
					el( 'span', null, [ formatPrice( price.base ) ] ),
				] )
				: null
		].concat( rows.map( function ( r ) {
			return el( 'li', { class: 'configkit-c__breakdown-row' }, [
				el( 'span', null, [ r.label ] ),
				el( 'span', null, [ ( r.amount > 0 ? '+' : '' ) + formatPrice( r.amount ) ] ),
			] );
		} ) ) );
	}

	function renderSummaryActions() {
		var visible = visibleSteps();
		var atLast = state.stepIndex === visible.length - 1;
		if ( ! atLast ) return null;
		return el( 'div', { class: 'configkit-c__sidebar-cta' }, [
			el( 'button', {
				type: 'button',
				class: 'configkit-c__nav-btn configkit-c__nav-btn--primary configkit-c__nav-btn--block',
				disabled: state.cartBusy,
				onClick: addToCart,
			}, [ state.cartBusy ? 'Adding…' : 'Add to cart' ] ),
			state.cartError ? el( 'p', { class: 'configkit-c__cart-error' }, [ state.cartError ] ) : null,
		] );
	}

	function priceReasonMessage( reason ) {
		switch ( reason ) {
			case 'awaiting_dimensions': return 'Enter width and height to see your price.';
			case 'no_cell':              return 'No price found for this combination — try different dimensions.';
			case 'exceeds_max_dimensions': return 'Dimensions exceed this product\u2019s maximum.';
			case 'exceeds_min_dimensions': return 'Dimensions below this product\u2019s minimum.';
			case 'fallback':             return 'Using fallback price.';
		}
		return '';
	}

	function formatPrice( n ) {
		if ( n === null || n === undefined || Number.isNaN( n ) ) return '—';
		var locale = ( document.documentElement.lang || 'en' );
		try {
			return new Intl.NumberFormat( locale, {
				style: 'currency',
				currency: 'NOK',
				maximumFractionDigits: 0,
			} ).format( Number( n ) );
		} catch ( e ) {
			return Math.round( Number( n ) ) + ' kr';
		}
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
				renderCardMedia( c, opts ),
				el( 'span', { class: 'configkit-c__card-label' }, [ c.label ] ),
				c.helper_text && ! opts.swatch ? el( 'span', { class: 'configkit-c__card-helper' }, [ c.helper_text ] ) : null,
				selected ? el( 'span', { class: 'configkit-c__card-check', 'aria-hidden': 'true' }, [ '✓' ] ) : null,
			] );
		} ) );
	}

	function renderCardMedia( choice, opts ) {
		if ( choice.image_url ) {
			return el( 'img', {
				src:     choice.image_url,
				alt:     choice.label,
				loading: 'lazy',
				class:   'configkit-c__card-image',
			}, [] );
		}
		// Color-family fallback — render a solid swatch using the
		// item's color_family if the picker is in swatch mode.
		var colorFamily = ( choice.item && choice.item.color_family ) || null;
		var bg = colorFamilyToCss( colorFamily );
		return el( 'span', {
			class: 'configkit-c__card-placeholder' + ( opts && opts.swatch ? ' configkit-c__card-placeholder--swatch' : '' ),
			style: bg ? ( 'background:' + bg + ';' ) : null,
		}, [
			! bg ? el( 'span', { class: 'configkit-c__card-placeholder-initial' }, [ ( choice.label || '?' ).charAt( 0 ).toUpperCase() ] ) : null,
		] );
	}

	function colorFamilyToCss( name ) {
		if ( ! name || typeof name !== 'string' ) return null;
		var map = {
			white:  '#fafafa',
			black:  '#222',
			grey:   '#9aa0a6',
			gray:   '#9aa0a6',
			beige:  '#e7d8b1',
			brown:  '#8b5a2b',
			red:    '#d63838',
			orange: '#f59e0b',
			yellow: '#f3d23a',
			green:  '#3f8a4a',
			blue:   '#2271b1',
			purple: '#8b5cf6',
			pink:   '#ec4899',
			natural: '#d6c8a3',
			neutral: '#bfb9ac',
		};
		var key = name.toLowerCase().trim();
		return map[ key ] || null;
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

	async function addToCart() {
		if ( state.cartBusy ) return;
		state.cartBusy = true;
		state.cartError = null;
		render();
		try {
			var url = FRONT.restUrl + '/products/' + FRONT.productId + '/add-to-cart';
			var res = await fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': FRONT.nonce,
				},
				body: JSON.stringify( { selections: state.values, quantity: 1 } ),
			} );
			var payload = null;
			try { payload = await res.json(); } catch ( e ) { /* ignore */ }
			if ( ! res.ok ) {
				var msg = ( payload && payload.message ) || ( 'HTTP ' + res.status );
				if ( payload && payload.data && Array.isArray( payload.data.errors ) && payload.data.errors.length > 0 ) {
					msg = payload.data.errors.map( function ( e ) { return e.message; } ).join( ' ' );
				}
				throw new Error( msg );
			}
			if ( payload && payload.cart_url ) {
				window.location.href = payload.cart_url;
				return;
			}
			if ( FRONT.cartUrl ) window.location.href = FRONT.cartUrl;
		} catch ( err ) {
			state.cartError = err && err.message ? err.message : String( err );
		} finally {
			state.cartBusy = false;
			render();
		}
	}

	// Boot the app.
	boot();

	window.ConfigKitFrontend = {
		state: state,
		render: render,
		setValue: setValue,
		addToCart: addToCart,
	};
} )();
