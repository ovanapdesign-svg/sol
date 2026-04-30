<?php
declare(strict_types=1);

namespace ConfigKit\Adapters;

/**
 * Phase 4 dalis 3 — production binding for `WooSkuResolver`. Uses
 * `wc_get_product_id_by_sku()` for the SKU path and `wc_get_product()`
 * for existence checks. Both functions are guarded so this class
 * gracefully no-ops in environments where WooCommerce is missing.
 *
 * Lives in src/Adapters/ to keep src/Engines/ pure.
 */
final class WooSkuResolverImpl implements WooSkuResolver {

	public function resolveBySku( string $sku ): ?int {
		$sku = trim( $sku );
		if ( $sku === '' ) return null;
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) return null;
		$id = (int) \wc_get_product_id_by_sku( $sku );
		return $id > 0 ? $id : null;
	}

	public function productExists( int $woo_product_id ): bool {
		if ( $woo_product_id <= 0 ) return false;
		if ( ! function_exists( 'wc_get_product' ) ) return false;
		$product = \wc_get_product( $woo_product_id );
		return is_object( $product );
	}
}
