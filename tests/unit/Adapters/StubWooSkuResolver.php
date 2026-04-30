<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Adapters;

use ConfigKit\Adapters\WooSkuResolver;

/**
 * Phase 4 dalis 3 — in-memory test double for `WooSkuResolver`.
 * `prices_by_sku` doubles as the existence map (since SKU implies a
 * product); explicit `existing_ids` covers the "id provided" path.
 */
final class StubWooSkuResolver implements WooSkuResolver {

	/**
	 * @param array<string,int> $sku_to_id
	 * @param array<int,bool>   $existing_ids
	 */
	public function __construct(
		public array $sku_to_id = [],
		public array $existing_ids = [],
	) {}

	public function resolveBySku( string $sku ): ?int {
		return $this->sku_to_id[ $sku ] ?? null;
	}

	public function productExists( int $woo_product_id ): bool {
		if ( ! empty( $this->existing_ids[ $woo_product_id ] ) ) return true;
		return in_array( $woo_product_id, $this->sku_to_id, true );
	}
}
