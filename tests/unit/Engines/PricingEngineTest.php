<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Engines;

use ConfigKit\Engines\LookupEngine;
use ConfigKit\Engines\PricingEngine;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase {

	private PricingEngine $engine;

	protected function setUp(): void {
		$this->engine = new PricingEngine( new LookupEngine() );
	}

	private function base_input( array $overrides = [] ): array {
		return array_replace_recursive(
			[
				'rule_engine_output' => [
					'fields'           => [
						'width_mm'      => [ 'visible' => true ],
						'height_mm'     => [ 'visible' => true ],
						'control_type'  => [ 'visible' => true ],
						'fabric_color'  => [ 'visible' => true ],
						'sensor_addon'  => [ 'visible' => true ],
					],
					'lookup_table_key' => 'markise_2d_v1',
					'surcharges'       => [],
					'warnings'         => [],
					'blocked'          => false,
					'block_reason'     => null,
				],
				'lookup' => [
					'match_mode'           => 'round_up',
					'supports_price_group' => false,
					'cells'                => [
						[ 'width' => 4000, 'height' => 3000, 'price_group_key' => '', 'price' => 8900.0 ],
						[ 'width' => 5000, 'height' => 3000, 'price_group_key' => '', 'price' => 9700.0 ],
					],
				],
				'selections' => [
					'width_mm'  => 4000,
					'height_mm' => 3000,
				],
				'selection_resolved' => [],
				'field_pricing'      => [],
				'dimensions'         => [
					'width_field_key'           => 'width_mm',
					'height_field_key'          => 'height_mm',
					'effective_price_group_key' => '',
				],
				'config' => [
					'currency'         => 'NOK',
					'vat_mode'         => 'off',
					'vat_rate_percent' => 0.0,
					'rounding'         => 'round_half_up',
					'rounding_step'    => 1.0,
					'minimum_price'    => null,
				],
			],
			$overrides
		);
	}

	// ---- Pricing modes ----

	public function test_fixed_pricing_mode_adds_flat_amount(): void {
		$input = $this->base_input( [
			'selections'    => [ 'control_type' => 'motorized' ],
			'field_pricing' => [
				'control_type' => [ 'pricing_mode' => 'fixed', 'pricing_value' => 2490 ],
			],
		] );
		$out = $this->engine->calculate( $input );
		// base 8900 + control 2490 = 11390
		$this->assertSame( 11390.0, $out['total'] );
	}

	public function test_per_unit_pricing_multiplies_by_count(): void {
		$input = $this->base_input( [
			'selections'    => [ 'sensor_addon' => [ 'A', 'B', 'C' ] ],
			'field_pricing' => [
				'sensor_addon' => [ 'pricing_mode' => 'per_unit', 'pricing_value' => 500 ],
			],
		] );
		$out = $this->engine->calculate( $input );
		// base 8900 + 3*500 = 10400
		$this->assertSame( 10400.0, $out['total'] );
	}

	public function test_per_m2_pricing_uses_width_height(): void {
		// 4000mm * 3000mm = 12,000,000 mm² = 12 m². 100 NOK/m² → 1200.
		$input = $this->base_input( [
			'selections'    => [ 'fabric_color' => 'plain' ],
			'field_pricing' => [
				'fabric_color' => [ 'pricing_mode' => 'per_m2', 'pricing_value' => 100 ],
			],
		] );
		$out = $this->engine->calculate( $input );
		// base 8900 + 1200 = 10100
		$this->assertSame( 10100.0, $out['total'] );
	}

	public function test_per_m2_without_dimensions_emits_warning_and_skips(): void {
		$input = $this->base_input( [
			'selections'    => [ 'fabric_color' => 'plain' ],
			'field_pricing' => [
				'fabric_color' => [ 'pricing_mode' => 'per_m2', 'pricing_value' => 100 ],
			],
			'dimensions' => [
				'width_field_key' => null,
				'height_field_key' => null,
				'effective_price_group_key' => '',
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 8900.0, $out['total'] );
		$this->assertContains(
			'pricing.per_m2_dimensions_missing',
			array_column( $out['warnings'], 'message' )
		);
	}

	public function test_lookup_dimension_fields_do_not_contribute_directly(): void {
		// width_mm is value_source=lookup_table; pricing_mode=lookup_dimension contributes nothing.
		$input = $this->base_input( [
			'field_pricing' => [
				'width_mm' => [ 'pricing_mode' => 'lookup_dimension', 'pricing_value' => null ],
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 8900.0, $out['total'] );
	}

	public function test_none_mode_is_zero(): void {
		$input = $this->base_input( [
			'selections'    => [ 'fabric_color' => 'plain' ],
			'field_pricing' => [
				'fabric_color' => [ 'pricing_mode' => 'none', 'pricing_value' => null ],
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 8900.0, $out['total'] );
	}

	public function test_fixed_with_null_pricing_value_treated_as_zero(): void {
		$input = $this->base_input( [
			'selections'    => [ 'control_type' => 'manual' ],
			'field_pricing' => [
				'control_type' => [ 'pricing_mode' => 'fixed', 'pricing_value' => null ],
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 8900.0, $out['total'] );
	}

	// ---- Hidden field exclusion ----

	public function test_hidden_field_does_not_contribute(): void {
		$input = $this->base_input( [
			'rule_engine_output' => [
				'fields' => [
					'control_type' => [ 'visible' => false ],
				],
			],
			'selections'    => [ 'control_type' => 'motorized' ],
			'field_pricing' => [
				'control_type' => [ 'pricing_mode' => 'fixed', 'pricing_value' => 2490 ],
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 8900.0, $out['total'] );
	}

	// ---- Sale price precedence ----

	public function test_sale_price_takes_precedence_over_regular_price(): void {
		$input = $this->base_input( [
			'selections' => [ 'fabric_color' => 'textiles_dickson:u171' ],
			'selection_resolved' => [
				'fabric_color' => [
					'kind'       => 'library_item',
					'label'      => 'Dickson U171',
					'price'      => 1200.0,
					'sale_price' => 980.0,
					'library_key' => 'textiles_dickson',
					'item_key'   => 'u171',
				],
			],
		] );
		$out = $this->engine->calculate( $input );
		// base 8900 + 980 (sale) = 9880
		$this->assertSame( 9880.0, $out['total'] );

		$option_lines = array_filter( $out['lines'], fn( $l ) => ( $l['type'] ?? '' ) === 'option' );
		$option_line  = array_values( $option_lines )[0];
		$this->assertSame( 'sale', $option_line['source']['applied'] );
	}

	public function test_library_item_with_null_price_contributes_zero(): void {
		$input = $this->base_input( [
			'selections' => [ 'fabric_color' => 'textiles_dickson:u100' ],
			'selection_resolved' => [
				'fabric_color' => [
					'kind'  => 'library_item',
					'label' => 'Plain',
					'price' => null,
				],
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 8900.0, $out['total'] );
		$this->assertFalse( $out['blocked'] );
	}

	// ---- Rounding ----

	public function test_rounding_round_half_up(): void {
		$input = $this->base_input( [
			'selections'    => [ 'fabric_color' => 'x' ],
			'field_pricing' => [
				'fabric_color' => [ 'pricing_mode' => 'fixed', 'pricing_value' => 480.37 ],
			],
			'config' => [
				'rounding'      => 'round_half_up',
				'rounding_step' => 1.0,
			],
		] );
		$out = $this->engine->calculate( $input );
		// 8900 + 480.37 = 9380.37 → 9380 (half-up at step 1)
		$this->assertSame( 9380.0, $out['total'] );
	}

	public function test_rounding_round_up_step_10(): void {
		$input = $this->base_input( [
			'selections'    => [ 'fabric_color' => 'x' ],
			'field_pricing' => [
				'fabric_color' => [ 'pricing_mode' => 'fixed', 'pricing_value' => 1.0 ],
			],
			'config' => [
				'rounding'      => 'round_up',
				'rounding_step' => 10.0,
			],
		] );
		$out = $this->engine->calculate( $input );
		// 8901 → 8910
		$this->assertSame( 8910.0, $out['total'] );
	}

	public function test_rounding_round_down_step_10(): void {
		$input = $this->base_input( [
			'selections'    => [ 'fabric_color' => 'x' ],
			'field_pricing' => [
				'fabric_color' => [ 'pricing_mode' => 'fixed', 'pricing_value' => 9.99 ],
			],
			'config' => [
				'rounding'      => 'round_down',
				'rounding_step' => 10.0,
			],
		] );
		$out = $this->engine->calculate( $input );
		// 8909.99 → 8900
		$this->assertSame( 8900.0, $out['total'] );
	}

	public function test_rounding_round_half_even(): void {
		// 8902.50 with step 5 → units = 1780.5 → banker's rounds to 1780 (even) → 8900.
		$input = $this->base_input( [
			'selections'    => [ 'fabric_color' => 'x' ],
			'field_pricing' => [
				'fabric_color' => [ 'pricing_mode' => 'fixed', 'pricing_value' => 2.50 ],
			],
			'config' => [
				'rounding'      => 'round_half_even',
				'rounding_step' => 5.0,
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 8900.0, $out['total'] );
	}

	// ---- VAT ----

	public function test_vat_off_no_vat_line(): void {
		$input = $this->base_input();
		$out   = $this->engine->calculate( $input );
		$this->assertSame( 'off', $out['vat']['mode'] );
		$this->assertSame( 0.0, $out['vat']['amount'] );
	}

	public function test_vat_incl_reports_amount_included(): void {
		$input = $this->base_input( [
			'config' => [
				'vat_mode'         => 'incl_vat',
				'vat_rate_percent' => 25.0,
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 'incl_vat', $out['vat']['mode'] );
		$this->assertSame( 25.0, $out['vat']['rate_percent'] );
		// 8900 includes VAT; amount_included = 8900 * 25 / 125 = 1780
		$this->assertEqualsWithDelta( 1780.0, $out['vat']['amount_included'], 0.01 );
		$this->assertSame( 8900.0, $out['total'] );
	}

	public function test_vat_excl_adds_vat_to_total(): void {
		$input = $this->base_input( [
			'config' => [
				'vat_mode'         => 'excl_vat',
				'vat_rate_percent' => 25.0,
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 'excl_vat', $out['vat']['mode'] );
		// subtotal 8900 + 25% = 11125, rounded
		$this->assertSame( 11125.0, $out['total'] );
		$this->assertEqualsWithDelta( 2225.0, $out['vat']['amount_added'], 0.01 );
	}

	// ---- Lookup mismatch / blocking ----

	public function test_no_lookup_match_blocks(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 9999, 'height_mm' => 9999 ],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertTrue( $out['blocked'] );
		$this->assertNotNull( $out['block_reason'] );
		$this->assertSame( 0.0, $out['total'] );
	}

	public function test_no_lookup_table_returns_total_zero_no_block(): void {
		$input = $this->base_input( [
			'rule_engine_output' => [
				'lookup_table_key' => null,
			],
			'lookup' => [ 'cells' => [] ],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertFalse( $out['blocked'] );
		$this->assertSame( 0.0, $out['total'] );
	}

	public function test_woo_addon_unavailable_blocks(): void {
		$input = $this->base_input( [
			'selections' => [ 'sensor_addon' => [ 'SOM-IO-WIND-300' ] ],
			'selection_resolved' => [
				'sensor_addon' => [
					[
						'kind'       => 'woo_product',
						'sku'        => 'SOM-IO-WIND-300',
						'product_id' => 12345,
						'label'      => 'Wind sensor',
						'price'      => 1790.0,
						'available'  => false,
					],
				],
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertTrue( $out['blocked'] );
		$this->assertStringContainsString( 'Tilbeh', (string) $out['block_reason'] );
	}

	// ---- Surcharges from rule results ----

	public function test_rule_surcharge_amount(): void {
		$input = $this->base_input( [
			'rule_engine_output' => [
				'surcharges' => [
					[ 'label' => 'Storformat', 'amount' => 1500.0 ],
				],
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 10400.0, $out['total'] );

		$surcharge_lines = array_values(
			array_filter( $out['lines'], fn( $l ) => ( $l['source']['kind'] ?? '' ) === 'rule_surcharge' )
		);
		$this->assertCount( 1, $surcharge_lines );
		$this->assertSame( 1500.0, $surcharge_lines[0]['amount'] );
	}

	public function test_rule_surcharge_percent_of_base(): void {
		$input = $this->base_input( [
			'rule_engine_output' => [
				'surcharges' => [
					[ 'label' => 'Volume', 'percent_of_base' => 5 ],
				],
			],
		] );
		$out = $this->engine->calculate( $input );
		// 5% of 8900 = 445; total = 9345
		$this->assertSame( 9345.0, $out['total'] );
	}

	// ---- Minimum price floor ----

	public function test_minimum_price_floor_applied(): void {
		$input = $this->base_input( [
			'lookup' => [
				'cells' => [
					[ 'width' => 4000, 'height' => 3000, 'price_group_key' => '', 'price' => 100.0 ],
				],
			],
			'config' => [
				'minimum_price' => 5000.0,
			],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 5000.0, $out['total'] );

		$floor_lines = array_values(
			array_filter( $out['lines'], fn( $l ) => ( $l['type'] ?? '' ) === 'min_price_floor' )
		);
		$this->assertCount( 1, $floor_lines );
		$this->assertSame( 4900.0, $floor_lines[0]['amount'] );
	}

	// ---- Negative clamp ----

	public function test_negative_total_clamps_to_zero(): void {
		$input = $this->base_input( [
			'rule_engine_output' => [
				'lookup_table_key' => null, // no base
				'surcharges' => [
					[ 'label' => 'discount', 'amount' => -1000.0 ],
				],
			],
			'lookup' => [ 'cells' => [] ],
		] );
		$out = $this->engine->calculate( $input );
		$this->assertSame( 0.0, $out['total'] );
		$this->assertContains(
			'pricing.negative_clamped',
			array_column( $out['warnings'], 'message' )
		);
	}
}
