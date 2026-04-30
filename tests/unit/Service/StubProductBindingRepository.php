<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\ProductBindingRepository;

/**
 * In-memory stub for ProductBindingRepository. Skips wpdb + post_meta
 * entirely so binding/diagnostics services can be exercised without a
 * full WordPress install.
 */
final class StubProductBindingRepository extends ProductBindingRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];

	/** @var array<int,bool> */
	public array $known_products = [];

	public function __construct() {
		// Skip parent constructor — no \wpdb.
	}

	public function register_product( int $product_id ): void {
		$this->known_products[ $product_id ] = true;
	}

	public function find( int $product_id ): ?array {
		if ( $product_id <= 0 ) {
			return null;
		}
		if ( ! isset( $this->known_products[ $product_id ] ) ) {
			return null;
		}
		if ( isset( $this->records[ $product_id ] ) ) {
			return $this->records[ $product_id ];
		}
		// Return blank state for an unbinded product.
		return $this->blank( $product_id );
	}

	public function save( int $product_id, array $data ): string {
		$this->known_products[ $product_id ] = true;
		$now  = '2026-04-29 12:00:00';
		$hash = sha1( $now . $product_id . count( $this->records ) );
		$this->records[ $product_id ] = [
			'product_id'           => $product_id,
			'enabled'              => ! empty( $data['enabled'] ),
			'template_key'         => isset( $data['template_key'] ) && $data['template_key'] !== '' ? (string) $data['template_key'] : null,
			'template_version_id'  => isset( $data['template_version_id'] ) ? (int) $data['template_version_id'] : 0,
			'lookup_table_key'     => isset( $data['lookup_table_key'] ) && $data['lookup_table_key'] !== '' ? (string) $data['lookup_table_key'] : null,
			'family_key'           => isset( $data['family_key'] ) && $data['family_key'] !== '' ? (string) $data['family_key'] : null,
			'frontend_mode'        => isset( $data['frontend_mode'] ) && $data['frontend_mode'] !== '' ? (string) $data['frontend_mode'] : 'stepper',
			'defaults'             => is_array( $data['defaults'] ?? null ) ? $data['defaults'] : [],
			'allowed_sources'      => is_array( $data['allowed_sources'] ?? null ) ? $data['allowed_sources'] : [],
			'pricing_overrides'    => is_array( $data['pricing_overrides'] ?? null ) ? $data['pricing_overrides'] : [],
			'field_overrides'      => is_array( $data['field_overrides'] ?? null ) ? $data['field_overrides'] : [],
			'item_price_overrides' => is_array( $data['item_price_overrides'] ?? null ) ? $data['item_price_overrides'] : [],
			'updated_at'           => $now,
			'version_hash'         => $hash,
		];
		return $hash;
	}

	public function list_overview( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		$items = array_values( array_map(
			static fn( array $r ): array => [
				'product_id'        => $r['product_id'],
				'name'              => 'Stub product #' . $r['product_id'],
				'post_status'       => 'publish',
				'sku'               => '',
				'enabled'           => $r['enabled'],
				'family_key'        => $r['family_key'],
				'template_key'      => $r['template_key'],
				'lookup_table_key'  => $r['lookup_table_key'],
				'updated_at'        => $r['updated_at'],
				'edit_url'          => '/wp-admin/post.php?post=' . $r['product_id'] . '&action=edit#configkit_product_data',
			],
			$this->records
		) );
		if ( ! empty( $filters['family_key'] ) ) {
			$wanted = (string) $filters['family_key'];
			$items  = array_values( array_filter( $items, static fn( $i ) => $i['family_key'] === $wanted ) );
		}
		if ( array_key_exists( 'enabled', $filters ) && $filters['enabled'] !== null ) {
			$wanted = (bool) $filters['enabled'];
			$items  = array_values( array_filter( $items, static fn( $i ) => (bool) $i['enabled'] === $wanted ) );
		}
		return [
			'items'       => $items,
			'total'       => count( $items ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => count( $items ) === 0 ? 0 : 1,
		];
	}

	private function blank( int $product_id ): array {
		return [
			'product_id'           => $product_id,
			'enabled'              => false,
			'template_key'         => null,
			'template_version_id'  => 0,
			'lookup_table_key'     => null,
			'family_key'           => null,
			'frontend_mode'        => 'stepper',
			'defaults'             => [],
			'allowed_sources'      => [],
			'pricing_overrides'    => [],
			'field_overrides'      => [],
			'item_price_overrides' => [],
			'updated_at'           => null,
			'version_hash'         => '',
		];
	}
}
