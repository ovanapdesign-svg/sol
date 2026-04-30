/* global window */
/**
 * Storefront-side JS ports of the rule + lookup + pricing engines.
 *
 * Important: this is a deliberate SUBSET of the PHP engines covering
 * the operators and actions a real markise-style product needs (the
 * Sologtak migration target). A faithful 1:1 port is tracked as a
 * Phase 5 deliverable. Server-side re-evaluation on add-to-cart (see
 * src/Frontend/AddToCartController.php in CHUNK 6) is the source of
 * truth — this client logic is for live UX only and never authorises
 * a price.
 *
 * Subset coverage:
 *   RuleEngine  — operators: equals / not_equals / greater_than /
 *                less_than / in / not_in / is_selected / is_empty /
 *                always; combinators: all / any / not. Actions:
 *                show_step / hide_step / show_field / hide_field /
 *                require_field / set_default / filter_source /
 *                disable_option / reset_value.
 *   LookupEngine — 2D match by width × height (+ optional
 *                price_group_key). Modes: exact / round_up /
 *                nearest. Bounding-box check via table's
 *                width_min/max + height_min/max.
 *   PricingEngine — modes: fixed / per_unit / per_m2 /
 *                lookup_dimension / none. Sale-price precedence
 *                inside library items. VAT toggle (incl/excl/off).
 *                Minimum-price floor + product surcharge from the
 *                binding's pricing_overrides. Discount % applied
 *                when sale_mode = 'discount_percent'.
 *
 * Out of scope for this chunk (server still enforces): addons /
 * full Woo-product availability, complex rule actions
 * (set_price_modifier, clear_other), 3D lookups, VAT delegation
 * to Woo at compute time.
 */
( function () {
	'use strict';

	// ------- RuleEngine ----------------------------------------------------

	function evalRules( ctx ) {
		var derived = {
			hiddenSteps:    {},
			hiddenFields:   {},
			requiredFields: {},
			lockedValues:   {},
			disabledOptions: {},
			filteredItems:  {},
			ruleNotes:      [],
		};

		var rules = ( ctx.snapshot.rules || [] )
			.filter( function ( r ) { return r.is_active; } )
			.slice()
			.sort( function ( a, b ) {
				if ( ( a.priority || 100 ) !== ( b.priority || 100 ) ) {
					return ( a.priority || 100 ) - ( b.priority || 100 );
				}
				return ( a.sort_order || 0 ) - ( b.sort_order || 0 );
			} );

		// set_default actions are queued and applied at the end so they
		// never beat an explicit value — matches PHP §6.3 step 5.
		var pendingDefaults = [];

		rules.forEach( function ( rule ) {
			if ( ! evalCondition( rule.spec && rule.spec.when, ctx ) ) return;
			var actions = ( rule.spec && rule.spec.then ) || [];
			actions.forEach( function ( action ) {
				applyAction( action, derived, ctx, pendingDefaults );
			} );
		} );

		// Apply queued defaults if the field is empty.
		pendingDefaults.forEach( function ( pd ) {
			var current = ctx.values[ pd.field ];
			var isEmpty = current === '' || current === null || current === undefined
				|| ( Array.isArray( current ) && current.length === 0 );
			if ( isEmpty ) {
				ctx.values[ pd.field ] = pd.value;
			}
		} );

		return derived;
	}

	function evalCondition( cond, ctx ) {
		if ( ! cond || typeof cond !== 'object' ) return false;
		if ( Object.prototype.hasOwnProperty.call( cond, 'always' ) ) return true;
		if ( cond.all && Array.isArray( cond.all ) ) {
			return cond.all.every( function ( sub ) { return evalCondition( sub, ctx ); } );
		}
		if ( cond.any && Array.isArray( cond.any ) ) {
			return cond.any.some( function ( sub ) { return evalCondition( sub, ctx ); } );
		}
		if ( cond.not ) {
			return ! evalCondition( cond.not, ctx );
		}
		// Atomic.
		if ( ! cond.field ) return false;
		var current = ctx.values[ cond.field ];
		var op = cond.op;
		var target = cond.value;
		switch ( op ) {
			case 'equals':       return looseEqual( current, target );
			case 'not_equals':   return ! looseEqual( current, target );
			case 'greater_than': return Number( current ) >  Number( target );
			case 'less_than':    return Number( current ) <  Number( target );
			case 'between':
				if ( Array.isArray( target ) && target.length === 2 ) {
					return Number( current ) >= Number( target[ 0 ] ) && Number( current ) <= Number( target[ 1 ] );
				}
				return false;
			case 'in':
				return Array.isArray( target ) && target.some( function ( t ) { return looseEqual( current, t ); } );
			case 'not_in':
				return Array.isArray( target ) && ! target.some( function ( t ) { return looseEqual( current, t ); } );
			case 'is_selected':
				return current !== '' && current !== null && current !== undefined
					&& ! ( Array.isArray( current ) && current.length === 0 );
			case 'is_empty':
				return current === '' || current === null || current === undefined
					|| ( Array.isArray( current ) && current.length === 0 );
			case 'contains':
				if ( Array.isArray( current ) ) {
					return current.some( function ( c ) { return looseEqual( c, target ); } );
				}
				return false;
		}
		return false;
	}

	function looseEqual( a, b ) {
		if ( a == null && b == null ) return true;
		if ( typeof a === 'number' || typeof b === 'number' ) {
			return Number( a ) === Number( b );
		}
		return String( a ) === String( b );
	}

	function applyAction( action, derived, ctx, pendingDefaults ) {
		if ( ! action || typeof action !== 'object' ) return;
		switch ( action.action ) {
			case 'show_step':
				if ( action.step ) delete derived.hiddenSteps[ action.step ];
				break;
			case 'hide_step':
				if ( action.step ) derived.hiddenSteps[ action.step ] = true;
				break;
			case 'show_field':
				if ( action.field ) delete derived.hiddenFields[ action.field ];
				break;
			case 'hide_field':
				if ( action.field ) derived.hiddenFields[ action.field ] = true;
				break;
			case 'require_field':
				if ( action.field ) derived.requiredFields[ action.field ] = true;
				break;
			case 'reset_value':
				if ( action.field ) {
					ctx.values[ action.field ] = '';
				}
				break;
			case 'set_default':
				if ( action.field ) {
					pendingDefaults.push( { field: action.field, value: action.value } );
				}
				break;
			case 'disable_option':
				if ( action.field && action.option ) {
					derived.disabledOptions[ action.field ] = derived.disabledOptions[ action.field ] || {};
					derived.disabledOptions[ action.field ][ action.option ] = true;
				}
				break;
			case 'filter_source':
				if ( action.field && Array.isArray( action.allowed ) ) {
					derived.filteredItems[ action.field ] = action.allowed.slice();
				}
				break;
			// Other actions (set_price_modifier, clear_other, etc.)
			// are server-only in this chunk.
			default:
				break;
		}
	}

	// ------- LookupEngine --------------------------------------------------

	/**
	 * Resolve a price from the lookup table for a given (width,
	 * height, price_group_key) tuple. Returns null when no cell
	 * matches, or { price, cell, reason: null } on success.
	 */
	function resolveLookup( opts ) {
		var table = opts.table;
		if ( ! table ) return null;
		var w = Number( opts.width );
		var h = Number( opts.height );
		if ( ! Number.isFinite( w ) || ! Number.isFinite( h ) ) return null;

		// Bounding-box check.
		if ( table.width_min  != null && w < Number( table.width_min  ) ) return { price: null, cell: null, reason: 'exceeds_min_dimensions' };
		if ( table.height_min != null && h < Number( table.height_min ) ) return { price: null, cell: null, reason: 'exceeds_min_dimensions' };
		if ( table.width_max  != null && w > Number( table.width_max  ) ) return { price: null, cell: null, reason: 'exceeds_max_dimensions' };
		if ( table.height_max != null && h > Number( table.height_max ) ) return { price: null, cell: null, reason: 'exceeds_max_dimensions' };

		var pg = opts.price_group_key || '';
		var candidates = ( table.cells || [] ).filter( function ( c ) {
			return ( c.price_group_key || '' ) === pg;
		} );
		if ( candidates.length === 0 && pg !== '' ) {
			// Fall back to empty-pg cells if owner forgot to enable
			// the price-group axis.
			candidates = ( table.cells || [] ).filter( function ( c ) { return ( c.price_group_key || '' ) === ''; } );
		}
		if ( candidates.length === 0 ) return { price: null, cell: null, reason: 'no_cell' };

		var mode = table.match_mode || 'round_up';
		var winner = null;

		if ( mode === 'exact' ) {
			candidates.forEach( function ( c ) {
				if ( c.width === w && c.height === h ) winner = c;
			} );
		} else if ( mode === 'round_up' ) {
			candidates.forEach( function ( c ) {
				if ( c.width >= w && c.height >= h ) {
					if ( ! winner || ( c.width < winner.width ) || ( c.width === winner.width && c.height < winner.height ) ) {
						winner = c;
					}
				}
			} );
		} else if ( mode === 'nearest' ) {
			var bestDist = Infinity;
			candidates.forEach( function ( c ) {
				var dw = c.width - w, dh = c.height - h;
				var dist = Math.sqrt( dw * dw + dh * dh );
				if ( dist < bestDist ) { bestDist = dist; winner = c; }
			} );
		}

		if ( ! winner ) return { price: null, cell: null, reason: 'no_cell' };
		return { price: Number( winner.price ), cell: winner, reason: null };
	}

	// ------- PricingEngine -------------------------------------------------

	/**
	 * Compute a live subtotal from current values + snapshot.
	 *
	 * Returns:
	 *   {
	 *     base:      number | null,    // lookup contribution (or fallback)
	 *     fields:    list<{field_key, label, amount}>,
	 *     subtotal:  number | null,
	 *     vatLabel:  'incl' | 'excl' | 'off' | 'inherit',
	 *     reason:    string | null,    // why subtotal is null
	 *     price_group_key: string | null,
	 *     resolved_dims: { width, height } | null,
	 *   }
	 */
	function computePrice( ctx ) {
		var snapshot = ctx.snapshot;
		var binding  = snapshot.binding || {};
		var pricing  = binding.pricing_overrides || {};
		var fields   = snapshot.fields || [];
		var values   = ctx.values || {};

		var widthFieldKey  = findLookupDimensionField( fields, 'width' );
		var heightFieldKey = findLookupDimensionField( fields, 'height' );

		var width  = widthFieldKey  ? Number( values[ widthFieldKey  ] ) : NaN;
		var height = heightFieldKey ? Number( values[ heightFieldKey ] ) : NaN;
		var resolved_dims = ( Number.isFinite( width ) && Number.isFinite( height ) )
			? { width: width, height: height }
			: null;

		var pg = derivePriceGroup( ctx );
		var base = null;
		var reason = null;

		if ( snapshot.lookup_table && resolved_dims !== null ) {
			var resolved = resolveLookup( {
				table: snapshot.lookup_table,
				width: width,
				height: height,
				price_group_key: pg,
			} );
			if ( resolved && resolved.price !== null ) {
				base = resolved.price;
			} else if ( resolved ) {
				reason = resolved.reason;
			}
		}

		// Fallback per binding override.
		if ( base === null && pricing.base_price_fallback != null && pricing.base_price_fallback !== '' ) {
			base = Number( pricing.base_price_fallback );
			reason = reason || 'fallback';
		}

		var fieldRows = [];

		fields.forEach( function ( f ) {
			if ( ctx.derived.hiddenFields[ f.field_key ] ) return;
			var amount = pricingAmountFor( f, values, resolved_dims, snapshot );
			if ( amount === 0 || amount === null ) return;
			fieldRows.push( { field_key: f.field_key, label: f.label, amount: amount } );
		} );

		var subtotal = ( base || 0 );
		fieldRows.forEach( function ( r ) { subtotal += r.amount; } );

		// Surcharge.
		if ( pricing.product_surcharge != null && pricing.product_surcharge !== '' ) {
			var surcharge = Number( pricing.product_surcharge );
			if ( surcharge !== 0 ) {
				fieldRows.push( { field_key: '__surcharge', label: 'Surcharge', amount: surcharge } );
				subtotal += surcharge;
			}
		}

		// Discount.
		if ( pricing.sale_mode === 'discount_percent' && pricing.discount_percent != null && pricing.discount_percent !== '' ) {
			var pct = Number( pricing.discount_percent );
			if ( pct > 0 ) {
				var discount = -1 * subtotal * ( pct / 100 );
				fieldRows.push( { field_key: '__discount', label: 'Discount (' + pct + '%)', amount: round( discount, 2 ) } );
				subtotal = subtotal + discount;
			}
		}

		// Minimum price floor.
		if ( pricing.minimum_price != null && pricing.minimum_price !== '' ) {
			var floor = Number( pricing.minimum_price );
			if ( subtotal < floor ) subtotal = floor;
		}

		if ( base === null && fieldRows.length === 0 ) {
			return {
				base: null,
				fields: [],
				subtotal: null,
				vatLabel: pricing.vat_display || 'inherit',
				reason: reason || 'awaiting_dimensions',
				price_group_key: pg,
				resolved_dims: resolved_dims,
			};
		}

		return {
			base: base,
			fields: fieldRows,
			subtotal: round( subtotal, 2 ),
			vatLabel: pricing.vat_display || 'inherit',
			reason: reason,
			price_group_key: pg,
			resolved_dims: resolved_dims,
		};
	}

	function pricingAmountFor( field, values, resolved_dims, snapshot ) {
		var mode   = field.pricing_mode;
		var amount = field.pricing_amount != null ? Number( field.pricing_amount ) : 0;

		// Library items can carry their own price (sale > base).
		if ( field.value_source === 'library' ) {
			var pickedKey = values[ field.field_key ];
			if ( ! pickedKey ) return 0;
			var item = findLibraryItem( snapshot, pickedKey );
			if ( ! item ) return 0;
			var p = ( item.sale_price != null && Number( item.sale_price ) > 0 )
				? Number( item.sale_price )
				: ( item.price != null ? Number( item.price ) : 0 );
			if ( ! mode || mode === 'fixed' || mode === 'none' || mode === null ) return p;
			if ( mode === 'per_unit' ) {
				var qty = Array.isArray( values[ field.field_key ] ) ? values[ field.field_key ].length : 1;
				return p * qty;
			}
			if ( mode === 'per_m2' && resolved_dims ) {
				return p * ( resolved_dims.width * resolved_dims.height ) / 1000000;
			}
			return p;
		}

		if ( mode === 'fixed' ) {
			var picked = values[ field.field_key ];
			if ( picked === '' || picked === null || picked === undefined ) return 0;
			if ( Array.isArray( picked ) && picked.length === 0 ) return 0;
			return amount;
		}
		if ( mode === 'per_unit' ) {
			var picked2 = values[ field.field_key ];
			if ( Array.isArray( picked2 ) ) return amount * picked2.length;
			if ( picked2 === '' || picked2 == null ) return 0;
			return amount;
		}
		if ( mode === 'per_m2' ) {
			if ( ! resolved_dims ) return 0;
			return amount * ( resolved_dims.width * resolved_dims.height ) / 1000000;
		}
		// 'lookup_dimension' / 'none' / null → no per-field contribution.
		return 0;
	}

	function findLookupDimensionField( fields, dimension ) {
		var hit = null;
		fields.forEach( function ( f ) {
			if ( f.field_kind !== 'lookup' ) return;
			var sc = f.source_config || {};
			if ( ( sc.dimension || f.field_key ).indexOf( dimension ) !== -1 ) hit = f.field_key;
		} );
		// Fallback to common conventions.
		if ( hit ) return hit;
		var match = null;
		fields.forEach( function ( f ) {
			if ( match ) return;
			if ( f.field_key === dimension || f.field_key === dimension + '_mm' ) match = f.field_key;
		} );
		return match;
	}

	function derivePriceGroup( ctx ) {
		var snapshot = ctx.snapshot;
		var values   = ctx.values || {};
		// Look at the first library-backed selection: the picked
		// item's price_group_key (per spec) wins.
		var fields = snapshot.fields || [];
		for ( var i = 0; i < fields.length; i++ ) {
			var f = fields[ i ];
			if ( f.value_source !== 'library' ) continue;
			var picked = values[ f.field_key ];
			if ( ! picked || Array.isArray( picked ) ) continue;
			var item = findLibraryItem( snapshot, picked );
			if ( item && item.price_group_key ) return String( item.price_group_key );
		}
		return '';
	}

	function findLibraryItem( snapshot, compoundKey ) {
		if ( typeof compoundKey !== 'string' || compoundKey.indexOf( ':' ) === -1 ) return null;
		var parts = compoundKey.split( ':' );
		var libKey = parts[ 0 ];
		var itemKey = parts.slice( 1 ).join( ':' );
		var libs = snapshot.libraries || [];
		for ( var i = 0; i < libs.length; i++ ) {
			if ( libs[ i ].library_key !== libKey ) continue;
			var items = libs[ i ].items || [];
			for ( var j = 0; j < items.length; j++ ) {
				if ( items[ j ].item_key === itemKey ) return items[ j ];
			}
		}
		return null;
	}

	function round( n, decimals ) {
		var f = Math.pow( 10, decimals );
		return Math.round( n * f ) / f;
	}

	// Expose to configurator.js.
	window.ConfigKitEngines = {
		evalRules:    evalRules,
		resolveLookup: resolveLookup,
		computePrice: computePrice,
	};
} )();
