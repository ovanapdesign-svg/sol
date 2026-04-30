<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Engines;

use ConfigKit\Engines\LookupEngine;
use ConfigKit\Engines\PricingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.2b.1 — coverage for `PricingEngine::resolveLibraryItemPrice()`.
 *
 * The Phase 2 PricingEngineTest (kept untouched) covers the line-item
 * + breakdown surface with the stock pricing modes. This file adds
 * the new resolver path: 5 sources × bundle composition × override
 * map per PRICING_SOURCE_MODEL §3.
 */
final class PricingEngineResolveTest extends TestCase {

	private function engine( StubPriceProvider $provider ): PricingEngine {
		return new PricingEngine( new LookupEngine(), $provider );
	}

	private function item( array $overrides = [] ): array {
		return array_replace( [
			'library_key' => 'motors_somfy',
			'item_key'    => 'somfy_io_premium',
			'price'       => 4500.0,
			'price_source' => 'configkit',
			'item_type'   => 'simple_option',
		], $overrides );
	}

	// ---- price_source = 'configkit' ---------------------------------------

	public function test_resolve_library_item_price_configkit_source(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$price  = $engine->resolveLibraryItemPrice( $this->item() );
		$this->assertSame( 4500.0, $price );
	}

	public function test_resolve_library_item_price_configkit_with_null_price_returns_null(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$price  = $engine->resolveLibraryItemPrice( $this->item( [ 'price' => null ] ) );
		$this->assertNull( $price );
	}

	public function test_resolve_library_item_price_default_source_is_configkit(): void {
		// price_source is missing → engine should default to 'configkit'.
		$engine = $this->engine( new StubPriceProvider() );
		$item   = $this->item();
		unset( $item['price_source'] );
		$this->assertSame( 4500.0, $engine->resolveLibraryItemPrice( $item ) );
	}

	// ---- price_source = 'woo' ---------------------------------------------

	public function test_resolve_library_item_price_woo_source_fetches_from_provider(): void {
		$engine = $this->engine( new StubPriceProvider( [ 123 => 5200.0 ] ) );
		$price  = $engine->resolveLibraryItemPrice( $this->item( [
			'price'         => null,
			'price_source'  => 'woo',
			'woo_product_id' => 123,
		] ) );
		$this->assertSame( 5200.0, $price );
	}

	public function test_resolve_library_item_price_woo_source_returns_null_when_woo_missing(): void {
		$engine = $this->engine( new StubPriceProvider() ); // no prices
		$price  = $engine->resolveLibraryItemPrice( $this->item( [
			'price'          => null,
			'price_source'   => 'woo',
			'woo_product_id' => 999,
		] ) );
		$this->assertNull( $price );
	}

	public function test_resolve_library_item_price_woo_source_with_zero_id_returns_null(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$price  = $engine->resolveLibraryItemPrice( $this->item( [
			'price'          => null,
			'price_source'   => 'woo',
			'woo_product_id' => 0,
		] ) );
		$this->assertNull( $price );
	}

	// ---- price_source = 'fixed_bundle' ------------------------------------

	public function test_resolve_library_item_price_fixed_bundle_returns_bundle_price(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$price  = $engine->resolveLibraryItemPrice( [
			'library_key'        => 'packages',
			'item_key'           => 'somfy_io_premium_pakke',
			'item_type'          => 'bundle',
			'price_source'       => 'fixed_bundle',
			'bundle_fixed_price' => 8990.0,
		] );
		$this->assertSame( 8990.0, $price );
	}

	public function test_resolve_library_item_price_fixed_bundle_with_null_returns_null(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$price  = $engine->resolveLibraryItemPrice( [
			'library_key'        => 'packages',
			'item_key'           => 'broken',
			'item_type'          => 'bundle',
			'price_source'       => 'fixed_bundle',
			'bundle_fixed_price' => null,
		] );
		$this->assertNull( $price );
	}

	// ---- price_source = 'bundle_sum' --------------------------------------

	public function test_resolve_library_item_price_bundle_sum_sums_components(): void {
		$engine = $this->engine( new StubPriceProvider( [ 200 => 480.0 ] ) );
		$item = [
			'library_key'  => 'hand_systems',
			'item_key'     => 'manual_crank_basic',
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components_json' => json_encode( [
				[ 'component_key' => 'handle', 'woo_product_id' => 200, 'qty' => 1, 'price_source' => 'woo' ],
				[ 'component_key' => 'rod',    'woo_product_id' => 201, 'qty' => 1, 'price_source' => 'configkit', 'configkit_price' => 350.0 ],
				[ 'component_key' => 'bracket','woo_product_id' => 202, 'qty' => 2, 'price_source' => 'configkit', 'configkit_price' => 90.0 ],
			] ),
		];
		$price = $engine->resolveLibraryItemPrice( $item );
		// 480 (woo) + 350 (configkit) + 2*90 (configkit) = 1010
		$this->assertSame( 1010.0, $price );
	}

	public function test_resolve_library_item_price_bundle_sum_with_mixed_sources(): void {
		$engine = $this->engine( new StubPriceProvider( [ 100 => 4500.0 ] ) );
		$item = [
			'library_key'  => 'packages',
			'item_key'     => 'mixed',
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components_json' => json_encode( [
				[ 'component_key' => 'a', 'woo_product_id' => 100, 'qty' => 1, 'price_source' => 'woo' ],
				[ 'component_key' => 'b', 'woo_product_id' => 101, 'qty' => 1, 'price_source' => 'configkit', 'configkit_price' => 1290.0 ],
				[ 'component_key' => 'c', 'woo_product_id' => 102, 'qty' => 1, 'price_source' => 'fixed_bundle', 'fixed_price' => 890.0 ],
			] ),
		];
		// 4500 + 1290 + 890 = 6680
		$this->assertSame( 6680.0, $engine->resolveLibraryItemPrice( $item ) );
	}

	public function test_resolve_library_item_price_bundle_sum_returns_null_when_component_unresolvable(): void {
		$engine = $this->engine( new StubPriceProvider() ); // no prices
		$item = [
			'library_key'  => 'packages',
			'item_key'     => 'broken',
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components_json' => json_encode( [
				[ 'component_key' => 'missing', 'woo_product_id' => 999, 'qty' => 1, 'price_source' => 'woo' ],
			] ),
		];
		$this->assertNull( $engine->resolveLibraryItemPrice( $item ) );
	}

	public function test_resolve_library_item_price_bundle_sum_qty_default_is_one(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$item = [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components_json' => json_encode( [
				[ 'component_key' => 'a', 'woo_product_id' => 1, 'price_source' => 'configkit', 'configkit_price' => 100.0 ],
			] ),
		];
		// qty missing → defaults to 1. Total = 100.
		$this->assertSame( 100.0, $engine->resolveLibraryItemPrice( $item ) );
	}

	// ---- product_override priority ---------------------------------------

	public function test_product_override_wins_over_library_default(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$overrides = [
			'motors_somfy:somfy_io_premium' => [ 'price' => 4200.0 ],
		];
		$price = $engine->resolveLibraryItemPrice( $this->item(), $overrides );
		$this->assertSame( 4200.0, $price );
	}

	public function test_product_override_wins_over_woo_source(): void {
		$engine = $this->engine( new StubPriceProvider( [ 123 => 5200.0 ] ) );
		$overrides = [
			'motors_somfy:somfy_io_premium' => [ 'price' => 4200.0 ],
		];
		$price = $engine->resolveLibraryItemPrice( $this->item( [
			'price'          => null,
			'price_source'   => 'woo',
			'woo_product_id' => 123,
		] ), $overrides );
		$this->assertSame( 4200.0, $price );
	}

	public function test_product_override_wins_over_bundle_sum(): void {
		$engine = $this->engine( new StubPriceProvider( [ 100 => 4500.0 ] ) );
		$item = [
			'library_key'  => 'packages',
			'item_key'     => 'somfy_io_premium_pakke',
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components_json' => json_encode( [
				[ 'component_key' => 'a', 'woo_product_id' => 100, 'qty' => 1, 'price_source' => 'woo' ],
			] ),
		];
		$overrides = [
			'packages:somfy_io_premium_pakke' => [ 'price' => 7990.0 ],
		];
		$this->assertSame( 7990.0, $engine->resolveLibraryItemPrice( $item, $overrides ) );
	}

	public function test_override_with_non_numeric_price_falls_through_to_item_resolution(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$overrides = [
			'motors_somfy:somfy_io_premium' => [ 'price' => 'not-a-number' ],
		];
		$price = $engine->resolveLibraryItemPrice( $this->item(), $overrides );
		$this->assertSame( 4500.0, $price, 'malformed overrides must not nuke the item price' );
	}

	// ---- defensive paths --------------------------------------------------

	public function test_resolve_library_item_price_unknown_source_returns_null(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$price  = $engine->resolveLibraryItemPrice( $this->item( [ 'price_source' => 'no_such_source' ] ) );
		$this->assertNull( $price );
	}

	public function test_resolve_library_item_price_empty_bundle_components_returns_null(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$item = [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components_json' => json_encode( [] ),
		];
		$this->assertNull( $engine->resolveLibraryItemPrice( $item ) );
	}

	public function test_resolve_library_item_price_invalid_json_components_returns_null(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$item = [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components_json' => '{ not json',
		];
		$this->assertNull( $engine->resolveLibraryItemPrice( $item ) );
	}

	public function test_resolve_library_item_price_missing_components_json_returns_null(): void {
		$engine = $this->engine( new StubPriceProvider() );
		$item = [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
		];
		$this->assertNull( $engine->resolveLibraryItemPrice( $item ) );
	}

	public function test_resolve_library_item_price_product_override_value_falls_back_to_configkit(): void {
		// 'product_override' is not a legal price_source on the item;
		// validator catches it on save, but the resolver also handles
		// it defensively by falling back to the stored price.
		$engine = $this->engine( new StubPriceProvider() );
		$item = $this->item( [ 'price_source' => 'product_override' ] );
		$this->assertSame( 4500.0, $engine->resolveLibraryItemPrice( $item ) );
	}

	public function test_omitting_price_provider_defaults_to_null_for_woo_source(): void {
		// When PricingEngine is built without injecting a provider,
		// woo-sourced items resolve to null instead of fatal-erroring.
		$engine = new PricingEngine( new LookupEngine() );
		$price  = $engine->resolveLibraryItemPrice( [
			'price_source'   => 'woo',
			'woo_product_id' => 123,
		] );
		$this->assertNull( $price );
	}
}
