<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Engines;

use ConfigKit\Engines\PriceProvider;

/**
 * Test double for `ConfigKit\Engines\PriceProvider`. Holds two
 * hash-keyed maps: one of woo_product_id → resolved price (null
 * means "missing"), one of woo_product_id → stock-management bool.
 *
 * Lets PricingEngine tests exercise `price_source = 'woo'` and the
 * stock-aware branches without touching WordPress. Fixed value per
 * id is enough — tests that need to flip behaviour mid-run just
 * mutate the public arrays.
 */
final class StubPriceProvider implements PriceProvider {

	/**
	 * @param array<int,float|null> $prices
	 * @param array<int,bool>       $stock_managed
	 */
	public function __construct(
		public array $prices = [],
		public array $stock_managed = [],
	) {}

	public function fetchWooProductPrice( int $woo_product_id ): ?float {
		if ( ! array_key_exists( $woo_product_id, $this->prices ) ) {
			return null;
		}
		$value = $this->prices[ $woo_product_id ];
		return $value === null ? null : (float) $value;
	}

	public function hasWooStockManagement( int $woo_product_id ): bool {
		return ! empty( $this->stock_managed[ $woo_product_id ] );
	}
}
