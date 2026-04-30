<?php
declare(strict_types=1);

namespace ConfigKit\Engines;

/**
 * Pricing Engine — pure-PHP, no WP dependencies.
 *
 * Composes a price breakdown from rule-engine output, a per-field pricing
 * map, pre-resolved selections, and a lookup table delegated to
 * LookupEngine. See PRICING_CONTRACT.md (DRAFT v1).
 *
 * The caller is responsible for resolving Woo / library / option data into
 * `selection_resolved` and `lookup.cells` before invoking the engine.
 */
final class PricingEngine {

	private PriceProvider $price_provider;

	/**
	 * @param PriceProvider|null $price_provider  PRICING_SOURCE_MODEL §5
	 *   adapter. Optional for backwards compatibility with the Phase 2
	 *   constructor signature; when omitted, the engine falls back to a
	 *   null-shaped provider so resolveLibraryItemPrice() with
	 *   `price_source = 'woo'` returns null instead of fatal-erroring.
	 *   Production code in `src/Plugin.php` always injects
	 *   `WooPriceProvider`; tests inject `StubPriceProvider`.
	 */
	public function __construct(
		private LookupEngine $lookup_engine,
		?PriceProvider $price_provider = null,
	) {
		$this->price_provider = $price_provider ?? new class implements PriceProvider {
			public function fetchWooProductPrice( int $woo_product_id ): ?float { return null; }
			public function hasWooStockManagement( int $woo_product_id ): bool { return false; }
		};
	}

	/**
	 * Resolve a library item's effective base price by walking the
	 * `price_source` ladder in PRICING_SOURCE_MODEL §3.
	 *
	 * @param array<string,mixed>                          $library_item
	 * @param array<string,array{price?:float|int|string}> $product_overrides
	 *        Map keyed by "library_key:item_key" → override entry from
	 *        `_configkit_item_price_overrides` post meta on the bound
	 *        Woo product (PRODUCT_BINDING_SPEC §18). Empty when no
	 *        binding-level overrides apply.
	 *
	 * @return float|null  Base price in store currency, or null when
	 *                     the configured source can't resolve.
	 */
	public function resolveLibraryItemPrice( array $library_item, array $product_overrides = [] ): ?float {
		// 1. Per-product binding override always wins
		//    (PRICING_SOURCE_MODEL §3 step 1).
		$override_key = ( $library_item['library_key'] ?? '' ) . ':' . ( $library_item['item_key'] ?? '' );
		if ( isset( $product_overrides[ $override_key ] ) ) {
			$entry = $product_overrides[ $override_key ];
			if ( isset( $entry['price'] ) && is_numeric( $entry['price'] ) ) {
				return (float) $entry['price'];
			}
			// An override with no usable price falls through to the
			// item's own resolution rather than nuking the price.
		}

		$source = (string) ( $library_item['price_source'] ?? 'configkit' );

		switch ( $source ) {
			case 'configkit':
				return isset( $library_item['price'] ) && is_numeric( $library_item['price'] )
					? (float) $library_item['price']
					: null;

			case 'woo':
				$woo_id = (int) ( $library_item['woo_product_id'] ?? 0 );
				return $this->price_provider->fetchWooProductPrice( $woo_id );

			case 'fixed_bundle':
				return isset( $library_item['bundle_fixed_price'] ) && is_numeric( $library_item['bundle_fixed_price'] )
					? (float) $library_item['bundle_fixed_price']
					: null;

			case 'bundle_sum':
				return $this->resolveBundleSumPrice( $library_item, $product_overrides );

			case 'product_override':
				// product_override should always come in via the override
				// map at step 1; arriving here means the library item
				// was authored with the value directly (not allowed per
				// PRICING_SOURCE_MODEL §2 validation, but let's be
				// robust). Fall back to the configkit-stored price.
				return isset( $library_item['price'] ) && is_numeric( $library_item['price'] )
					? (float) $library_item['price']
					: null;
		}

		return null;
	}

	/**
	 * Sum the resolved prices of every component in a `bundle_sum`
	 * library item (BUNDLE_MODEL §4 + decision §10.4 — each component
	 * resolves its own `price_source` independently).
	 *
	 * Returns null when ANY component fails to resolve so the caller
	 * can decide whether to surface a warning. The caller (PricingEngine
	 * line item builder) is responsible for the spec §3 step 3
	 * sale / surcharge / discount / floor layering.
	 *
	 * @param array<string,mixed> $library_item
	 * @param array<string,array{price?:float|int|string}> $product_overrides
	 */
	private function resolveBundleSumPrice( array $library_item, array $product_overrides ): ?float {
		$json = $library_item['bundle_components_json'] ?? null;
		if ( ! is_string( $json ) || $json === '' ) return null;
		$components = json_decode( $json, true );
		if ( ! is_array( $components ) || count( $components ) === 0 ) return null;

		$total = 0.0;
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) return null;
			$qty = (int) ( $component['qty'] ?? 1 );
			if ( $qty <= 0 ) $qty = 1;

			$component_price = $this->resolveComponentPrice( $component );
			if ( $component_price === null ) return null;

			$total += $component_price * $qty;
		}
		return $total;
	}

	/**
	 * Resolve one bundle component's contribution. Components carry a
	 * subset of the price_source enum: 'woo', 'configkit', 'fixed_bundle'.
	 * 'bundle_sum' / 'product_override' are not legal at the component
	 * level (BUNDLE_MODEL §3 validation).
	 *
	 * @param array<string,mixed> $component
	 */
	private function resolveComponentPrice( array $component ): ?float {
		$source = (string) ( $component['price_source'] ?? 'woo' );
		switch ( $source ) {
			case 'woo':
				$woo_id = (int) ( $component['woo_product_id'] ?? 0 );
				return $this->price_provider->fetchWooProductPrice( $woo_id );
			case 'configkit':
				$price = $component['configkit_price'] ?? $component['price'] ?? null;
				return is_numeric( $price ) ? (float) $price : null;
			case 'fixed_bundle':
				$price = $component['fixed_price'] ?? null;
				return is_numeric( $price ) ? (float) $price : null;
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function calculate( array $input ): array {
		$rule_output       = $input['rule_engine_output'] ?? [];
		$fields_state      = $rule_output['fields'] ?? [];
		$rule_surcharges   = $rule_output['surcharges'] ?? [];
		$rule_blocked      = (bool) ( $rule_output['blocked'] ?? false );
		$rule_block_reason = $rule_output['block_reason'] ?? null;
		$rule_warnings     = $rule_output['warnings'] ?? [];

		$lookup_input        = $input['lookup'] ?? [];
		$lookup_table_key    = (string) ( $rule_output['lookup_table_key'] ?? $lookup_input['table_key'] ?? '' );
		$lookup_match_mode   = (string) ( $lookup_input['match_mode'] ?? LookupEngine::MODE_ROUND_UP );
		$lookup_supports_pg  = (bool) ( $lookup_input['supports_price_group'] ?? false );
		$lookup_cells        = $lookup_input['cells'] ?? [];

		/** @var array<string,mixed> $selections */
		$selections = $input['selections'] ?? [];
		/** @var array<string,mixed> $selection_resolved */
		$selection_resolved = $input['selection_resolved'] ?? [];
		/** @var array<string,array<string,mixed>> $field_pricing */
		$field_pricing = $input['field_pricing'] ?? [];

		$dimensions               = $input['dimensions'] ?? [];
		$width_field_key          = $dimensions['width_field_key'] ?? null;
		$height_field_key         = $dimensions['height_field_key'] ?? null;
		$effective_price_group    = (string) ( $dimensions['effective_price_group_key'] ?? '' );

		$config         = $input['config'] ?? [];
		$currency       = (string) ( $config['currency'] ?? 'NOK' );
		$vat_mode       = (string) ( $config['vat_mode'] ?? 'off' );
		$vat_rate       = (float) ( $config['vat_rate_percent'] ?? 0.0 );
		$rounding       = (string) ( $config['rounding'] ?? 'round_half_up' );
		$rounding_step  = (float) ( $config['rounding_step'] ?? 1.0 );
		$minimum_price  = $config['minimum_price'] ?? null;

		$lines    = [];
		$warnings = [];
		$blocked  = $rule_blocked;
		$block_reason = $rule_block_reason;

		foreach ( $rule_warnings as $w ) {
			$warnings[] = $w;
		}

		// 1. Base price from lookup
		$base_price = 0.0;
		$lookup_match = null;
		if ( $lookup_table_key !== '' ) {
			$req_w = $width_field_key !== null ? (int) ( $selections[ $width_field_key ] ?? 0 ) : 0;
			$req_h = $height_field_key !== null ? (int) ( $selections[ $height_field_key ] ?? 0 ) : 0;
			$lookup_match = $this->lookup_engine->match( [
				'lookup_table_key'     => $lookup_table_key,
				'width'                => $req_w,
				'height'               => $req_h,
				'price_group_key'      => $effective_price_group,
				'supports_price_group' => $lookup_supports_pg,
				'match_mode'           => $lookup_match_mode,
				'cells'                => $lookup_cells,
			] );
			if ( $lookup_match['matched'] ) {
				$base_price = (float) $lookup_match['price'];
				$lines[] = [
					'type'   => 'base',
					'label'  => 'Grunnpris etter mål',
					'amount' => $base_price,
					'source' => [
						'kind'             => 'lookup_table',
						'lookup_table_key' => $lookup_table_key,
						'matched_cell'     => $lookup_match['cell'],
						'match_strategy'   => $lookup_match['match_strategy'],
					],
				];
			} else {
				$blocked = true;
				$block_reason = $block_reason ?? 'Denne kombinasjonen mangler pris. Kontakt oss.';
			}
		}

		// 2. Per-field pricing contributions
		foreach ( $selections as $field_key => $value ) {
			if ( ! $this->is_visible( $fields_state, $field_key ) ) {
				continue;
			}
			if ( $this->is_empty_value( $value ) ) {
				continue;
			}

			$pricing = $field_pricing[ $field_key ] ?? null;
			if ( is_array( $pricing ) ) {
				$mode = (string) ( $pricing['pricing_mode'] ?? 'none' );
				$pv   = $pricing['pricing_value'];
				$pv   = is_numeric( $pv ) ? (float) $pv : 0.0;

				switch ( $mode ) {
					case 'fixed':
						$lines[] = [
							'type'      => 'surcharge',
							'label'     => $this->resolved_label( $selection_resolved, $field_key, $field_key ),
							'field_key' => $field_key,
							'amount'    => $pv,
							'source'    => [ 'kind' => 'field_pricing', 'pricing_mode' => 'fixed' ],
						];
						break;
					case 'per_unit':
						$count = is_array( $value ) ? count( $value ) : 1;
						$amount = $pv * $count;
						$lines[] = [
							'type'      => 'surcharge',
							'label'     => $this->resolved_label( $selection_resolved, $field_key, $field_key ),
							'field_key' => $field_key,
							'amount'    => $amount,
							'source'    => [ 'kind' => 'field_pricing', 'pricing_mode' => 'per_unit', 'unit_count' => $count ],
						];
						break;
					case 'per_m2':
						if ( $width_field_key === null || $height_field_key === null ) {
							$warnings[] = [
								'message' => 'pricing.per_m2_dimensions_missing',
								'level'   => 'warning',
							];
							break;
						}
						$w = (float) ( $selections[ $width_field_key ] ?? 0 );
						$h = (float) ( $selections[ $height_field_key ] ?? 0 );
						$m2 = ( $w * $h ) / 1_000_000.0;
						$amount = $pv * $m2;
						$lines[] = [
							'type'      => 'surcharge',
							'label'     => $this->resolved_label( $selection_resolved, $field_key, $field_key ),
							'field_key' => $field_key,
							'amount'    => $amount,
							'source'    => [
								'kind'         => 'field_pricing',
								'pricing_mode' => 'per_m2',
								'square_meters' => $m2,
							],
						];
						break;
					case 'lookup_dimension':
					case 'none':
					default:
						// no contribution from pricing_mode
						break;
				}
			}

			// Selection-resolved price (library item, manual option, addon)
			$resolved = $selection_resolved[ $field_key ] ?? null;
			if ( $resolved === null ) {
				continue;
			}

			if ( is_array( $resolved ) && $this->is_list_of_resolved( $resolved ) ) {
				foreach ( $resolved as $entry ) {
					$line = $this->build_resolved_line( $entry, $field_key, $blocked, $block_reason );
					if ( $line !== null ) {
						$lines[] = $line;
					}
				}
			} elseif ( is_array( $resolved ) ) {
				$line = $this->build_resolved_line( $resolved, $field_key, $blocked, $block_reason );
				if ( $line !== null ) {
					$lines[] = $line;
				}
			}
		}

		// 3. Rule surcharges
		foreach ( $rule_surcharges as $sur ) {
			if ( ! is_array( $sur ) ) {
				continue;
			}
			if ( array_key_exists( 'amount', $sur ) ) {
				$lines[] = [
					'type'   => 'surcharge',
					'label'  => (string) ( $sur['label'] ?? '' ),
					'amount' => (float) $sur['amount'],
					'source' => [ 'kind' => 'rule_surcharge' ],
				];
			} elseif ( array_key_exists( 'percent_of_base', $sur ) ) {
				$amount = $base_price * ( (float) $sur['percent_of_base'] ) / 100.0;
				$lines[] = [
					'type'   => 'surcharge',
					'label'  => (string) ( $sur['label'] ?? '' ),
					'amount' => $amount,
					'source' => [
						'kind'            => 'rule_surcharge',
						'percent_of_base' => (float) $sur['percent_of_base'],
					],
				];
			}
		}

		// 4. Sum
		$subtotal = 0.0;
		foreach ( $lines as $line ) {
			$subtotal += (float) ( $line['amount'] ?? 0 );
		}

		// 5. Negative clamp
		if ( $subtotal < 0.0 ) {
			$subtotal = 0.0;
			$warnings[] = [
				'message' => 'pricing.negative_clamped',
				'level'   => 'info',
			];
		}

		// 6. Minimum price floor
		if ( is_numeric( $minimum_price ) && $subtotal < (float) $minimum_price ) {
			$diff = (float) $minimum_price - $subtotal;
			$lines[] = [
				'type'   => 'min_price_floor',
				'label'  => 'Minimumspris-tillegg',
				'amount' => $diff,
				'source' => [ 'kind' => 'min_price_floor', 'minimum_price' => (float) $minimum_price ],
			];
			$subtotal = (float) $minimum_price;
			$warnings[] = [
				'message' => sprintf( 'Minimumspris er %s.', (string) $minimum_price ),
				'level'   => 'info',
			];
		}

		// 7. VAT + rounding
		[ $total, $vat_block ] = $this->apply_vat_and_rounding(
			$subtotal,
			$vat_mode,
			$vat_rate,
			$rounding,
			$rounding_step
		);

		// 8. Blocked → zero out total but keep breakdown context
		if ( $blocked ) {
			$total = 0.0;
		}

		return [
			'currency'         => $currency,
			'total'            => $total,
			'lines'            => $lines,
			'vat'              => $vat_block,
			'warnings'         => $warnings,
			'blocked'          => $blocked,
			'block_reason'     => $block_reason,
			'lookup_match'     => $lookup_match,
		];
	}

	/**
	 * @param array<string,array<string,mixed>> $fields_state
	 */
	private function is_visible( array $fields_state, string $field_key ): bool {
		if ( ! isset( $fields_state[ $field_key ] ) ) {
			return true; // no rule output yet → assume visible
		}
		return (bool) ( $fields_state[ $field_key ]['visible'] ?? true );
	}

	private function is_empty_value( mixed $v ): bool {
		if ( $v === null || $v === '' ) {
			return true;
		}
		if ( is_array( $v ) && count( $v ) === 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $resolved
	 */
	private function is_list_of_resolved( array $resolved ): bool {
		if ( ! array_is_list( $resolved ) ) {
			return false;
		}
		foreach ( $resolved as $entry ) {
			if ( ! is_array( $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>|null
	 */
	private function build_resolved_line( array $entry, string $field_key, bool &$blocked, ?string &$block_reason ): ?array {
		$kind = (string) ( $entry['kind'] ?? '' );

		// Woo addon availability check (caller pre-resolves; engine just enforces)
		if ( $kind === 'woo_product' && array_key_exists( 'available', $entry ) ) {
			if ( ! (bool) $entry['available'] ) {
				$blocked = true;
				$block_reason = $block_reason ?? sprintf(
					'Tilbehør utilgjengelig: %s',
					(string) ( $entry['label'] ?? $entry['sku'] ?? '' )
				);
				return null;
			}
		}

		$price = $this->effective_price( $entry );
		if ( $price === null ) {
			return null; // nothing priced — skip line
		}

		$source = [ 'kind' => $kind ];
		foreach ( [ 'library_key', 'item_key', 'price_group_key', 'option_key', 'sku', 'product_id' ] as $key ) {
			if ( array_key_exists( $key, $entry ) ) {
				$source[ $key ] = $entry[ $key ];
			}
		}
		if ( isset( $entry['sale_price'] ) && $entry['sale_price'] !== null ) {
			$source['regular_price'] = isset( $entry['price'] ) ? (float) $entry['price'] : null;
			$source['sale_price']    = (float) $entry['sale_price'];
			$source['applied']       = 'sale';
		}

		return [
			'type'      => $kind === 'woo_product' ? 'addon' : 'option',
			'label'     => (string) ( $entry['label'] ?? '' ),
			'field_key' => $field_key,
			'amount'    => $price,
			'source'    => $source,
		];
	}

	/**
	 * @param array<string,mixed> $entry
	 */
	private function effective_price( array $entry ): ?float {
		if ( isset( $entry['sale_price'] ) && is_numeric( $entry['sale_price'] ) ) {
			return (float) $entry['sale_price'];
		}
		if ( isset( $entry['price'] ) && is_numeric( $entry['price'] ) ) {
			return (float) $entry['price'];
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $selection_resolved
	 */
	private function resolved_label( array $selection_resolved, string $field_key, string $fallback ): string {
		$entry = $selection_resolved[ $field_key ] ?? null;
		if ( is_array( $entry ) ) {
			if ( array_is_list( $entry ) ) {
				$labels = [];
				foreach ( $entry as $e ) {
					if ( is_array( $e ) && isset( $e['label'] ) ) {
						$labels[] = (string) $e['label'];
					}
				}
				if ( count( $labels ) > 0 ) {
					return implode( ', ', $labels );
				}
			} elseif ( isset( $entry['label'] ) ) {
				return (string) $entry['label'];
			}
		}
		return $fallback;
	}

	/**
	 * @return array{0:float,1:array<string,mixed>}
	 */
	private function apply_vat_and_rounding(
		float $subtotal,
		string $vat_mode,
		float $vat_rate,
		string $rounding,
		float $rounding_step
	): array {
		switch ( $vat_mode ) {
			case 'incl_vat':
				$total       = $this->round_to_step( $subtotal, $rounding, $rounding_step );
				$amount_incl = $vat_rate > 0
					? $total * $vat_rate / ( 100.0 + $vat_rate )
					: 0.0;
				$vat_block   = [
					'mode'            => 'incl_vat',
					'rate_percent'    => $vat_rate,
					'amount_included' => $amount_incl,
				];
				return [ $total, $vat_block ];

			case 'excl_vat':
				$vat_amount  = $subtotal * $vat_rate / 100.0;
				$total       = $this->round_to_step( $subtotal + $vat_amount, $rounding, $rounding_step );
				$vat_block   = [
					'mode'         => 'excl_vat',
					'rate_percent' => $vat_rate,
					'amount_added' => $vat_amount,
				];
				return [ $total, $vat_block ];

			case 'off':
			default:
				$total     = $this->round_to_step( $subtotal, $rounding, $rounding_step );
				$vat_block = [
					'mode'         => 'off',
					'rate_percent' => 0.0,
					'amount'       => 0.0,
				];
				return [ $total, $vat_block ];
		}
	}

	private function round_to_step( float $value, string $mode, float $step ): float {
		if ( $step <= 0.0 ) {
			return $value;
		}
		$units = $value / $step;
		switch ( $mode ) {
			case 'round_up':
				$rounded = (float) ceil( $units );
				break;
			case 'round_down':
				$rounded = (float) floor( $units );
				break;
			case 'round_half_even':
				$floor = floor( $units );
				$diff  = $units - $floor;
				if ( $diff < 0.5 ) {
					$rounded = $floor;
				} elseif ( $diff > 0.5 ) {
					$rounded = $floor + 1.0;
				} else {
					$rounded = ( ( (int) $floor ) % 2 === 0 ) ? $floor : $floor + 1.0;
				}
				break;
			case 'round_half_up':
			default:
				$rounded = (float) floor( $units + 0.5 );
				break;
		}
		return $rounded * $step;
	}
}
