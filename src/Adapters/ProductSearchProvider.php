<?php
declare(strict_types=1);

namespace ConfigKit\Adapters;

/**
 * Adapter contract for the Woo product picker. The admin-side REST
 * controller searches Woo products through this interface so the
 * picker can be unit-tested without booting WordPress / WooCommerce.
 *
 * Each result row is a flat associative array shaped for the picker
 * UI: id, name, sku, price (numeric or null), thumbnail_url, status.
 *
 * @phpstan-type ProductRow array{
 *   id:int,
 *   name:string,
 *   sku:string,
 *   price:?float,
 *   thumbnail_url:?string,
 *   status:string
 * }
 */
interface ProductSearchProvider {

	/**
	 * Search Woo products by name or SKU. Empty `$query` returns the
	 * most recent products (so the picker can show "all products"
	 * mode when the owner clears the search box).
	 *
	 * Implementations cap `$limit` defensively; the controller already
	 * enforces 1–50 but adapters should not trust it blindly.
	 *
	 * @return array{items:list<ProductRow>,total:int,page:int,per_page:int}
	 */
	public function search( string $query, int $limit, int $page ): array;

	/**
	 * Resolve a single product by id. Returns null when the product
	 * does not exist or is trashed. Used by the picker to render
	 * already-selected rows (initial state hydration).
	 *
	 * @return ProductRow|null
	 */
	public function find( int $woo_product_id ): ?array;
}
