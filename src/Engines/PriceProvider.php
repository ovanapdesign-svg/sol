<?php
declare(strict_types=1);

namespace ConfigKit\Engines;

/**
 * Adapter contract that lets PricingEngine resolve woo-sourced items
 * without ever touching the WooCommerce product store directly.
 *
 * The interface lives in src/Engines/ because it is consumed by the
 * engine, but it is intentionally PURE — the file imports nothing
 * from WordPress or WooCommerce. The concrete production binding is
 * ConfigKit\Adapters\WooPriceProvider (lives in src/Adapters/),
 * tests use ConfigKit\Tests\Unit\Engines\StubPriceProvider.
 *
 * The src/Engines/ purity invariant remains in force after this file
 * lands.
 *
 * Spec: PRICING_SOURCE_MODEL.md §5.
 */
interface PriceProvider {

	/**
	 * Resolve a Woo product's effective price in store currency.
	 *
	 * Returns null when:
	 *   - `$woo_product_id <= 0`
	 *   - the product does not exist
	 *   - the product is unavailable (e.g. trashed)
	 *   - the product has no price assigned
	 *
	 * Implementations may cache or freeze prices (e.g. snapshot at
	 * add-to-cart per PRICING_SOURCE_MODEL §9 decision 2). The engine
	 * does not care about the freezing strategy — it just calls.
	 */
	public function fetchWooProductPrice( int $woo_product_id ): ?float;

	/**
	 * Whether the Woo product has Woo's stock management enabled.
	 *
	 * Used by stock-aware logic downstream (BUNDLE_MODEL §6: a
	 * component with `stock_behavior = 'check_components'` only
	 * blocks the order when this returns true). The engine itself
	 * does not consume this — it's exposed on the interface so the
	 * cart wiring (a future chunk) can read it from the same
	 * adapter rather than duplicating Woo lookups.
	 */
	public function hasWooStockManagement( int $woo_product_id ): bool;
}
