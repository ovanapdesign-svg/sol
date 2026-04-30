<?php
declare(strict_types=1);

namespace ConfigKit\Adapters;

/**
 * Phase 4 dalis 3 — adapter contract that lets the library-items
 * importer translate a `woo_product_sku` cell into a `woo_product_id`
 * without the validator booting WooCommerce. Stub implementations live
 * in tests/.
 */
interface WooSkuResolver {

	/**
	 * Resolve a Woo product SKU to a product id. Returns null when:
	 *  - the SKU is empty
	 *  - WooCommerce is not active
	 *  - no product matches
	 */
	public function resolveBySku( string $sku ): ?int;

	/**
	 * Whether a Woo product with this id exists. Used by the validator
	 * to verify file-supplied `woo_product_id` values without making
	 * the validator depend on `wc_get_product()`.
	 */
	public function productExists( int $woo_product_id ): bool;
}
