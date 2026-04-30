<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Engines;

use ConfigKit\Engines\LookupEngine;
use ConfigKit\Engines\PricingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.2b.2 — coverage for the admin preview helper
 * `PricingEngine::resolveBundleBreakdown()`. The method underpins the
 * "Package breakdown" panel (UI_LABELS_MAPPING §9.2): per-component
 * resolved prices, qty multiplier, and a final total that respects
 * the bundle source (sum vs fixed).
 *
 * Accepts both the decoded list (from the admin form) and the JSON
 * blob (from repository hydration); both shapes are exercised here.
 */
final class PricingEngineBreakdownTest extends TestCase {

	private function engine( StubPriceProvider $provider ): PricingEngine {
		return new PricingEngine( new LookupEngine(), $provider );
	}

	public function test_breakdown_sums_woo_and_configkit_components(): void {
		$provider = new StubPriceProvider( prices: [ 200 => 1490.0 ] );
		$engine   = $this->engine( $provider );

		$item = [
			'price_source' => 'bundle_sum',
			'bundle_components' => [
				[ 'component_key' => 'motor', 'woo_product_id' => 200, 'qty' => 1, 'price_source' => 'woo' ],
				[ 'component_key' => 'sensor', 'woo_product_id' => 300, 'qty' => 2, 'price_source' => 'configkit', 'price' => 100.0 ],
			],
		];

		$breakdown = $engine->resolveBundleBreakdown( $item );
		$this->assertSame( 1490.0 + 200.0, $breakdown['total'] );
		$this->assertSame( 'bundle_sum', $breakdown['price_source'] );
		$this->assertCount( 2, $breakdown['components'] );
		$this->assertSame( 1490.0, $breakdown['components'][0]['unit_price'] );
		$this->assertSame( 1490.0, $breakdown['components'][0]['subtotal'] );
		$this->assertSame( 200.0,  $breakdown['components'][1]['subtotal'] );
	}

	public function test_breakdown_total_is_null_when_any_component_unresolved(): void {
		$provider = new StubPriceProvider(); // no woo prices
		$engine   = $this->engine( $provider );

		$item = [
			'price_source' => 'bundle_sum',
			'bundle_components' => [
				[ 'component_key' => 'motor', 'woo_product_id' => 200, 'qty' => 1, 'price_source' => 'woo' ],
			],
		];

		$breakdown = $engine->resolveBundleBreakdown( $item );
		$this->assertNull( $breakdown['total'] );
		$this->assertNull( $breakdown['components'][0]['unit_price'] );
		$this->assertNull( $breakdown['components'][0]['subtotal'] );
	}

	public function test_breakdown_uses_fixed_price_when_source_is_fixed_bundle(): void {
		$provider = new StubPriceProvider( prices: [ 200 => 1490.0 ] );
		$engine   = $this->engine( $provider );

		$item = [
			'price_source'       => 'fixed_bundle',
			'bundle_fixed_price' => 8990.0,
			'bundle_components'  => [
				[ 'component_key' => 'motor', 'woo_product_id' => 200, 'qty' => 1, 'price_source' => 'woo' ],
			],
		];

		$breakdown = $engine->resolveBundleBreakdown( $item );
		$this->assertSame( 8990.0, $breakdown['total'] );
		$this->assertSame( 8990.0, $breakdown['fixed_bundle_price'] );
		// Components still resolve so the owner sees stock context,
		// but the total ignores them per BUNDLE_MODEL §10.2.
		$this->assertSame( 1490.0, $breakdown['components'][0]['unit_price'] );
	}

	public function test_breakdown_accepts_legacy_json_payload(): void {
		$provider = new StubPriceProvider( prices: [ 200 => 1490.0 ] );
		$engine   = $this->engine( $provider );

		$item = [
			'price_source'           => 'bundle_sum',
			'bundle_components_json' => json_encode( [
				[ 'component_key' => 'motor', 'woo_product_id' => 200, 'qty' => 1, 'price_source' => 'woo' ],
			] ),
		];

		$breakdown = $engine->resolveBundleBreakdown( $item );
		$this->assertSame( 1490.0, $breakdown['total'] );
	}

	public function test_breakdown_qty_multiplier_applies(): void {
		$provider = new StubPriceProvider( prices: [ 200 => 100.0 ] );
		$engine   = $this->engine( $provider );

		$item = [
			'price_source' => 'bundle_sum',
			'bundle_components' => [
				[ 'component_key' => 'motor', 'woo_product_id' => 200, 'qty' => 3, 'price_source' => 'woo' ],
			],
		];

		$breakdown = $engine->resolveBundleBreakdown( $item );
		$this->assertSame( 100.0, $breakdown['components'][0]['unit_price'] );
		$this->assertSame( 300.0, $breakdown['components'][0]['subtotal'] );
		$this->assertSame( 300.0, $breakdown['total'] );
	}
}
