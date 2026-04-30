<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Engines\PricingEngine;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\ProductBindingRepository;
use ConfigKit\Repository\StepRepository;

/**
 * Phase 4.2b.2 — admin "Test default configuration price" preview
 * (UI_LABELS_MAPPING.md §9.3).
 *
 * The full at-cart-time pricing flow involves rule engine, lookup
 * tables, field pricing modes, and VAT/rounding — too much to wire
 * for an admin preview that exists only to confirm overrides apply
 * correctly. This service therefore computes a focused subtotal:
 * for every binding default that resolves to a library item, it
 * resolves the item's price (honouring price source + per-product
 * overrides) and adds it to the total.
 *
 * Returns a structured payload the admin UI can render verbatim.
 *
 * The result intentionally does NOT include lookup-table base
 * price, rule surcharges, or VAT — Phase 4.2b.3 (cart-event wiring)
 * will introduce a snapshot pricing path that the full preview can
 * delegate to.
 */
final class TestDefaultPriceService {

	public function __construct(
		private ProductBindingRepository $bindings,
		private FieldRepository $fields,
		private StepRepository $steps,
		private LibraryItemRepository $library_items,
		private PricingEngine $pricing_engine,
	) {}

	/**
	 * @return array{
	 *   ok:bool,
	 *   subtotal:?float,
	 *   lines:list<array{field_key:string,item_label:string,library_key:string,item_key:string,price:?float,price_source:string,note:?string}>,
	 *   warnings:list<string>,
	 *   not_found?:bool
	 * }
	 */
	public function compute( int $product_id ): array {
		$binding = $this->bindings->find( $product_id );
		if ( $binding === null ) {
			return [ 'ok' => false, 'not_found' => true, 'subtotal' => null, 'lines' => [], 'warnings' => [] ];
		}
		$template_key = (string) ( $binding['template_key'] ?? '' );
		if ( $template_key === '' ) {
			return [ 'ok' => false, 'subtotal' => null, 'lines' => [], 'warnings' => [ 'Product binding has no template — pick one before previewing.' ] ];
		}

		$defaults  = is_array( $binding['defaults'] ?? null ) ? $binding['defaults'] : [];
		$overrides = is_array( $binding['item_price_overrides'] ?? null ) ? $binding['item_price_overrides'] : [];

		// Build a field_key → field map for the bound template.
		$fields_by_key = [];
		foreach ( $this->steps->list_in_template( $template_key )['items'] as $step ) {
			foreach ( $this->fields->list_in_step( $template_key, (string) $step['step_key'] )['items'] as $field ) {
				$fields_by_key[ (string) $field['field_key'] ] = $field;
			}
		}

		$lines    = [];
		$warnings = [];
		$subtotal = 0.0;
		$any_unresolved = false;

		foreach ( $defaults as $field_key => $value ) {
			$field = $fields_by_key[ $field_key ] ?? null;
			if ( $field === null ) {
				$warnings[] = sprintf( 'Default "%s" no longer exists in the template.', (string) $field_key );
				continue;
			}
			if ( ( $field['value_source'] ?? '' ) !== 'library' ) {
				continue; // Skip non-library fields — covered by Phase 4.2b.3.
			}
			if ( ! is_string( $value ) || $value === '' ) {
				continue;
			}

			$cfg  = is_array( $field['source_config'] ?? null ) ? $field['source_config'] : [];
			$libs = is_array( $cfg['libraries'] ?? null ) ? array_values( array_filter( $cfg['libraries'], 'is_string' ) ) : [];
			if ( count( $libs ) === 0 ) {
				continue;
			}

			$found = $this->library_items->search_global( [ 'q' => $value, 'library_keys' => $libs, 'is_active' => true ], 1, 50 );
			$item  = null;
			foreach ( $found['items'] as $candidate ) {
				if ( (string) $candidate['item_key'] === $value ) {
					$item = $candidate;
					break;
				}
			}
			if ( $item === null ) {
				$warnings[] = sprintf( 'Default item "%s" for field "%s" not found in allowed libraries.', $value, $field_key );
				continue;
			}

			$override_key = ( (string) $item['library_key'] ) . ':' . ( (string) $item['item_key'] );
			$resolved = $this->pricing_engine->resolveLibraryItemPrice( $item, $overrides );
			$applied_source = (string) ( $item['price_source'] ?? 'configkit' );
			$note = null;
			if ( isset( $overrides[ $override_key ] ) ) {
				$applied_source = 'product_override';
				$note = isset( $overrides[ $override_key ]['reason'] ) && $overrides[ $override_key ]['reason'] !== ''
					? (string) $overrides[ $override_key ]['reason']
					: null;
			}

			if ( $resolved === null ) {
				$any_unresolved = true;
			} else {
				$subtotal += $resolved;
			}

			$lines[] = [
				'field_key'    => (string) $field_key,
				'item_label'   => (string) ( $item['label'] ?? $item['item_key'] ),
				'library_key'  => (string) $item['library_key'],
				'item_key'     => (string) $item['item_key'],
				'price'        => $resolved,
				'price_source' => $applied_source,
				'note'         => $note,
			];
		}

		return [
			'ok'       => true,
			'subtotal' => $any_unresolved ? null : $subtotal,
			'lines'    => $lines,
			'warnings' => $warnings,
		];
	}
}
