<?php
declare(strict_types=1);

namespace ConfigKit\Adapters;

use ConfigKit\Engines\PriceProvider;

/**
 * Production binding for `ConfigKit\Engines\PriceProvider`. This file
 * deliberately lives OUTSIDE `src/Engines/` so the engine purity grep
 * keeps passing — `WooPriceProvider` is the place where ConfigKit
 * touches `wc_get_product()`.
 *
 * Spec: PRICING_SOURCE_MODEL.md §5 (production binding).
 */
final class WooPriceProvider implements PriceProvider {

	public function fetchWooProductPrice( int $woo_product_id ): ?float {
		$product = $this->resolve_product( $woo_product_id );
		if ( $product === null ) return null;
		$price = $product->get_price();
		// `WC_Product::get_price()` can return string '', null, or a
		// numeric string. Coerce numeric strings to float; everything
		// else is "no price" for our purposes.
		if ( ! is_numeric( $price ) ) return null;
		return (float) $price;
	}

	public function hasWooStockManagement( int $woo_product_id ): bool {
		$product = $this->resolve_product( $woo_product_id );
		if ( $product === null ) return false;
		return (bool) $product->managing_stock();
	}

	/**
	 * @return object|null  WC_Product on success, null otherwise. We
	 * don't tighten the type to WC_Product because the WC class isn't
	 * always available at lint time (e.g. PHPStan with WC absent).
	 */
	private function resolve_product( int $woo_product_id ) {
		if ( $woo_product_id <= 0 ) return null;
		if ( ! function_exists( 'wc_get_product' ) ) return null;
		$product = \wc_get_product( $woo_product_id );
		return is_object( $product ) ? $product : null;
	}
}
