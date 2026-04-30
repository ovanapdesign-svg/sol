<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Adapters;

use ConfigKit\Adapters\ProductSearchProvider;

/**
 * Phase 4.2b.2 — in-memory test double for `ProductSearchProvider`.
 * Tests preload `$products` (id-keyed), `search()` does naive
 * substring match against name + sku, `find()` returns null for
 * missing ids. Keeps WooProductsControllerTest hermetic.
 */
final class StubProductSearchProvider implements ProductSearchProvider {

	/**
	 * @param array<int,array{id:int,name:string,sku:string,price:?float,thumbnail_url:?string,status:string}> $products
	 */
	public function __construct( public array $products = [] ) {}

	public int $search_calls = 0;

	public function search( string $query, int $limit, int $page ): array {
		$this->search_calls++;
		$query = trim( $query );
		$matches = array_values( array_filter( $this->products, static function ( $p ) use ( $query ): bool {
			if ( $query === '' ) return true;
			$needle = strtolower( $query );
			return str_contains( strtolower( $p['name'] ), $needle )
				|| str_contains( strtolower( $p['sku'] ), $needle );
		} ) );

		$total  = count( $matches );
		$offset = max( 0, ( $page - 1 ) * $limit );
		$slice  = array_slice( $matches, $offset, $limit );

		return [
			'items'    => $slice,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $limit,
		];
	}

	public function find( int $woo_product_id ): ?array {
		return $this->products[ $woo_product_id ] ?? null;
	}
}
