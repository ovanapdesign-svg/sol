/* global ConfigKit */
/**
 * Reusable Woo product picker.
 *
 * Phase 4.2b.2 — used by the library item form (woo_product_id field
 * for `price_source = 'woo'`, bundle component picker) and the
 * product binding override editor.
 *
 * UI labels per UI_LABELS_MAPPING.md §4. The picker exposes a search
 * box plus a result dropdown; selection renders as a chip with an ✕
 * to clear. Backend enum values never appear in the rendered UI.
 *
 *   const picker = ConfigKit.createWooProductPicker({
 *     mount: containerEl,
 *     initial: { id: 123, name: 'Telis 4 RTS', sku: 'TLS4RTS', price: 1490, thumbnail_url: null },
 *     placeholder: 'Search WooCommerce products…',
 *     onChange: (selection) => { ... }, // selection or null
 *   });
 *
 *   picker.value() // => { id, name, sku, price, thumbnail_url } | null
 *   picker.set( { id, name, sku, ... } )
 *   picker.clear()
 *
 * Keyboard:
 *   - ↑ / ↓ navigate results
 *   - Enter selects highlighted result
 *   - Esc closes the dropdown
 */
( function () {
	'use strict';

	if ( ! window.ConfigKit || typeof window.ConfigKit.request !== 'function' ) {
		return;
	}

	const DEBOUNCE_MS = 300;
	const MIN_QUERY_LENGTH = 2;

	function el( tag, attrs, ...children ) {
		const node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( ( key ) => {
				const value = attrs[ key ];
				if ( value === false || value === null || value === undefined ) return;
				if ( key === 'class' ) {
					node.className = value;
				} else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
					node.addEventListener( key.slice( 2 ).toLowerCase(), value );
				} else if ( key in node && typeof value === 'boolean' ) {
					node[ key ] = value;
				} else {
					node.setAttribute( key, String( value ) );
				}
			} );
		}
		children.flat().forEach( ( child ) => {
			if ( child === null || child === undefined || child === false ) return;
			node.appendChild(
				typeof child === 'string' ? document.createTextNode( child ) : child
			);
		} );
		return node;
	}

	function formatPrice( value ) {
		if ( value === null || value === undefined || value === '' ) return '';
		const num = Number( value );
		if ( ! Number.isFinite( num ) ) return '';
		return num.toLocaleString( undefined, { maximumFractionDigits: 0 } ) + ' kr';
	}

	function createWooProductPicker( opts ) {
		opts = opts || {};
		const mount = opts.mount;
		if ( ! mount ) throw new Error( 'createWooProductPicker: mount is required' );

		const placeholder = opts.placeholder || 'Search WooCommerce products…';
		const onChange = typeof opts.onChange === 'function' ? opts.onChange : () => {};

		let selected = opts.initial && opts.initial.id ? opts.initial : null;
		let results = [];
		let highlightIndex = -1;
		let debounceHandle = null;
		let lastQuery = '';
		let inflight = 0;

		const root = el( 'div', { class: 'configkit-woo-product-picker' } );
		const chip = el( 'div', { class: 'configkit-woo-product-picker__chip', hidden: true } );
		const input = el( 'input', {
			type: 'search',
			class: 'configkit-woo-product-picker__input',
			placeholder,
			autocomplete: 'off',
			role: 'combobox',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
		} );
		const dropdown = el( 'div', {
			class: 'configkit-woo-product-picker__dropdown',
			role: 'listbox',
			hidden: true,
		} );

		root.appendChild( chip );
		root.appendChild( input );
		root.appendChild( dropdown );
		mount.appendChild( root );

		function renderChip() {
			chip.innerHTML = '';
			if ( ! selected ) {
				chip.hidden = true;
				input.hidden = false;
				return;
			}
			chip.hidden = false;
			input.hidden = true;

			if ( selected.thumbnail_url ) {
				chip.appendChild( el( 'img', {
					class: 'configkit-woo-product-picker__thumb',
					src: selected.thumbnail_url,
					alt: '',
				} ) );
			}
			const text = el( 'span', { class: 'configkit-woo-product-picker__chip-text' } );
			text.appendChild( el( 'span', { class: 'configkit-woo-product-picker__name' }, selected.name || ( '#' + selected.id ) ) );
			if ( selected.sku ) {
				text.appendChild( el( 'span', { class: 'configkit-woo-product-picker__sku' }, ' (' + selected.sku + ')' ) );
			}
			if ( selected.price !== null && selected.price !== undefined && selected.price !== '' ) {
				text.appendChild( el( 'span', { class: 'configkit-woo-product-picker__price' }, ' — ' + formatPrice( selected.price ) ) );
			}
			chip.appendChild( text );
			chip.appendChild( el( 'button', {
				type: 'button',
				class: 'configkit-woo-product-picker__clear',
				'aria-label': 'Remove product',
				onClick: () => clear(),
			}, '✕' ) );
		}

		function renderDropdown() {
			dropdown.innerHTML = '';
			if ( results.length === 0 ) {
				dropdown.hidden = true;
				input.setAttribute( 'aria-expanded', 'false' );
				return;
			}
			dropdown.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );

			results.forEach( ( row, i ) => {
				const option = el( 'div', {
					class: 'configkit-woo-product-picker__option' + ( i === highlightIndex ? ' is-highlighted' : '' ),
					role: 'option',
					'aria-selected': i === highlightIndex ? 'true' : 'false',
					onMousedown: ( ev ) => {
						// Prevent input from blurring before click registers.
						ev.preventDefault();
						pick( row );
					},
					onMouseenter: () => {
						highlightIndex = i;
						renderDropdown();
					},
				} );
				if ( row.thumbnail_url ) {
					option.appendChild( el( 'img', {
						class: 'configkit-woo-product-picker__thumb',
						src: row.thumbnail_url,
						alt: '',
					} ) );
				} else {
					option.appendChild( el( 'span', { class: 'configkit-woo-product-picker__thumb is-empty', 'aria-hidden': 'true' } ) );
				}
				const meta = el( 'span', { class: 'configkit-woo-product-picker__meta' } );
				meta.appendChild( el( 'span', { class: 'configkit-woo-product-picker__name' }, row.name || ( '#' + row.id ) ) );
				if ( row.sku ) {
					meta.appendChild( el( 'span', { class: 'configkit-woo-product-picker__sku' }, ' (' + row.sku + ')' ) );
				}
				option.appendChild( meta );
				if ( row.price !== null && row.price !== undefined && row.price !== '' ) {
					option.appendChild( el( 'span', { class: 'configkit-woo-product-picker__price' }, formatPrice( row.price ) ) );
				}
				dropdown.appendChild( option );
			} );
		}

		async function search( query ) {
			lastQuery = query;
			const my = ++inflight;
			try {
				const path = '/woo-products?q=' + encodeURIComponent( query ) + '&per_page=20';
				const data = await window.ConfigKit.request( path );
				if ( my !== inflight ) return; // stale
				results = Array.isArray( data && data.items ) ? data.items : [];
				highlightIndex = results.length > 0 ? 0 : -1;
				renderDropdown();
			} catch ( err ) {
				if ( my !== inflight ) return;
				results = [];
				highlightIndex = -1;
				dropdown.innerHTML = '';
				dropdown.hidden = false;
				dropdown.appendChild( el( 'div', { class: 'configkit-woo-product-picker__error' },
					( err && err.message ) ? err.message : 'Search failed.'
				) );
			}
		}

		function scheduleSearch() {
			const q = input.value.trim();
			if ( debounceHandle ) clearTimeout( debounceHandle );
			if ( q.length < MIN_QUERY_LENGTH ) {
				results = [];
				renderDropdown();
				return;
			}
			debounceHandle = setTimeout( () => {
				if ( q !== lastQuery ) search( q );
			}, DEBOUNCE_MS );
		}

		function pick( row ) {
			selected = row || null;
			results = [];
			highlightIndex = -1;
			input.value = '';
			renderChip();
			renderDropdown();
			onChange( selected );
		}

		function clear() {
			selected = null;
			renderChip();
			input.focus();
			onChange( null );
		}

		input.addEventListener( 'input', scheduleSearch );
		input.addEventListener( 'focus', () => {
			if ( results.length > 0 ) renderDropdown();
		} );
		input.addEventListener( 'blur', () => {
			// Delay so click on dropdown row registers first.
			setTimeout( () => {
				dropdown.hidden = true;
				input.setAttribute( 'aria-expanded', 'false' );
			}, 150 );
		} );
		input.addEventListener( 'keydown', ( ev ) => {
			if ( ev.key === 'ArrowDown' ) {
				if ( results.length === 0 ) return;
				ev.preventDefault();
				highlightIndex = ( highlightIndex + 1 ) % results.length;
				renderDropdown();
			} else if ( ev.key === 'ArrowUp' ) {
				if ( results.length === 0 ) return;
				ev.preventDefault();
				highlightIndex = ( highlightIndex - 1 + results.length ) % results.length;
				renderDropdown();
			} else if ( ev.key === 'Enter' ) {
				if ( highlightIndex >= 0 && results[ highlightIndex ] ) {
					ev.preventDefault();
					pick( results[ highlightIndex ] );
				}
			} else if ( ev.key === 'Escape' ) {
				results = [];
				renderDropdown();
			}
		} );

		renderChip();

		return {
			value: () => ( selected ? Object.assign( {}, selected ) : null ),
			set: ( row ) => {
				selected = row && row.id ? row : null;
				renderChip();
				onChange( selected );
			},
			clear,
			focus: () => {
				if ( ! selected ) input.focus();
			},
			root,
		};
	}

	window.ConfigKit = window.ConfigKit || {};
	window.ConfigKit.createWooProductPicker = createWooProductPicker;
} )();
