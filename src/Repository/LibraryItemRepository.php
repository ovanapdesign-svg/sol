<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class LibraryItemRepository {

	public const TABLE = 'configkit_library_items';

	public function __construct( private \wpdb $wpdb ) {}

	public function find_by_id( int $id ): ?array {
		$table = $this->table();
		/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function key_exists_in_library( string $library_key, string $item_key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE library_key = %s AND item_key = %s LIMIT 1",
					$library_key,
					$item_key
				)
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE library_key = %s AND item_key = %s AND id <> %d LIMIT 1",
					$library_key,
					$item_key,
					$exclude_id
				)
			);
		}
		return $value !== null;
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list_in_library( string $library_key, int $page = 1, int $per_page = 100 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE library_key = %s", $library_key )
		);

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE library_key = %s ORDER BY sort_order ASC, label ASC LIMIT %d OFFSET %d",
				$library_key,
				$per_page,
				$offset
			),
			ARRAY_A
		) ?: [];

		return [
			'items'       => array_values( array_map( [ $this, 'hydrate' ], $rows ) ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total === 0 ? 0 : (int) ceil( $total / $per_page ),
		];
	}

	public function count_in_library( string $library_key ): int {
		$table = $this->table();
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE library_key = %s", $library_key )
		);
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		$table = $this->table();
		$now   = $this->now();
		$row   = $this->dehydrate( $data );
		$row['created_at']   = $now;
		$row['updated_at']   = $now;
		$row['version_hash'] = '';

		$ok = $this->wpdb->insert( $table, $row );
		if ( $ok === false || $ok === 0 ) {
			throw new \RuntimeException( 'Failed to insert library item: ' . (string) $this->wpdb->last_error );
		}
		$id = (int) $this->wpdb->insert_id;
		$this->wpdb->update( $table, [ 'version_hash' => sha1( $now . (string) $id ) ], [ 'id' => $id ] );
		return $id;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): void {
		$table = $this->table();
		$now   = $this->now();
		$row   = $this->dehydrate( $data );
		$row['updated_at']   = $now;
		$row['version_hash'] = sha1( $now . (string) $id );
		unset( $row['created_at'] );

		$result = $this->wpdb->update( $table, $row, [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to update library item: ' . (string) $this->wpdb->last_error );
		}
	}

	public function soft_delete( int $id ): void {
		$table = $this->table();
		$now   = $this->now();
		$result = $this->wpdb->update(
			$table,
			[ 'is_active' => 0, 'updated_at' => $now, 'version_hash' => sha1( $now . (string) $id ) ],
			[ 'id' => $id ]
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to soft delete library item: ' . (string) $this->wpdb->last_error );
		}
	}

	private function table(): string {
		return $this->wpdb->prefix . self::TABLE;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? (string) \current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		return [
			'id'              => (int) ( $row['id'] ?? 0 ),
			'library_key'     => (string) ( $row['library_key'] ?? '' ),
			'item_key'        => (string) ( $row['item_key'] ?? '' ),
			'sku'             => $this->null_or_string( $row['sku'] ?? null ),
			'label'           => (string) ( $row['label'] ?? '' ),
			'short_label'     => $this->null_or_string( $row['short_label'] ?? null ),
			'image_url'       => $this->null_or_string( $row['image_url'] ?? null ),
			'main_image_url'  => $this->null_or_string( $row['main_image_url'] ?? null ),
			'description'     => $this->null_or_string( $row['description'] ?? null ),
			'price'           => $this->null_or_float( $row['price'] ?? null ),
			// Phase 4.2 — see PRICING_SOURCE_MODEL §2 / §4 for the
			// columns introduced in migration 0017.
			'price_source'         => (string) ( $row['price_source'] ?? 'configkit' ),
			'bundle_fixed_price'   => $this->null_or_float( $row['bundle_fixed_price'] ?? null ),
			'item_type'            => (string) ( $row['item_type'] ?? 'simple_option' ),
			'bundle_components'    => $this->decode_list_of_objects( $row['bundle_components_json'] ?? '' ),
			'cart_behavior'        => $this->null_or_string( $row['cart_behavior'] ?? null ),
			'admin_order_display'  => $this->null_or_string( $row['admin_order_display'] ?? null ),
			'sale_price'      => $this->null_or_float( $row['sale_price'] ?? null ),
			'price_group_key' => (string) ( $row['price_group_key'] ?? '' ),
			'color_family'    => $this->null_or_string( $row['color_family'] ?? null ),
			'woo_product_id'  => isset( $row['woo_product_id'] ) && $row['woo_product_id'] !== null
				? (int) $row['woo_product_id']
				: null,
			'filters'         => $this->decode_array( $row['filters_json'] ?? '' ),
			'compatibility'   => $this->decode_array( $row['compatibility_json'] ?? '' ),
			'attributes'      => $this->decode_object( $row['attributes_json'] ?? '' ),
			'is_active'       => (bool) (int) ( $row['is_active'] ?? 0 ),
			'sort_order'      => (int) ( $row['sort_order'] ?? 0 ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
			'version_hash'    => (string) ( $row['version_hash'] ?? '' ),
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		// Phase 4.2 — normalise the new columns. Bundle-only fields
		// (bundle_components_json / cart_behavior / admin_order_display
		// / bundle_fixed_price) are forced to null when item_type is
		// 'simple_option' so toggling Package off cleans up the row
		// instead of leaving stale state behind.
		$item_type      = (string) ( $data['item_type'] ?? 'simple_option' );
		$is_bundle      = $item_type === 'bundle';
		$price_source   = (string) ( $data['price_source'] ?? 'configkit' );

		$components = $is_bundle && is_array( $data['bundle_components'] ?? null )
			? array_values( $data['bundle_components'] )
			: null;
		$bundle_components_json = $components === null
			? null
			: (string) wp_json_encode( $components );

		return [
			'library_key'        => (string) ( $data['library_key'] ?? '' ),
			'item_key'           => (string) ( $data['item_key'] ?? '' ),
			'sku'                => $data['sku'] ?? null,
			'label'              => (string) ( $data['label'] ?? '' ),
			'short_label'        => $data['short_label'] ?? null,
			'image_url'          => $data['image_url'] ?? null,
			'main_image_url'     => $data['main_image_url'] ?? null,
			'description'        => $data['description'] ?? null,
			'price'              => isset( $data['price'] ) && $data['price'] !== '' && $data['price'] !== null
				? (float) $data['price']
				: null,
			// Phase 4.2 columns ↓
			'price_source'           => $price_source,
			'bundle_fixed_price'     => $is_bundle && isset( $data['bundle_fixed_price'] )
				&& $data['bundle_fixed_price'] !== '' && $data['bundle_fixed_price'] !== null
					? (float) $data['bundle_fixed_price']
					: null,
			'item_type'              => $item_type,
			'bundle_components_json' => $bundle_components_json,
			'cart_behavior'          => $is_bundle && ! empty( $data['cart_behavior'] )
				? (string) $data['cart_behavior']
				: null,
			'admin_order_display'    => $is_bundle && ! empty( $data['admin_order_display'] )
				? (string) $data['admin_order_display']
				: null,
			'sale_price'         => isset( $data['sale_price'] ) && $data['sale_price'] !== '' && $data['sale_price'] !== null
				? (float) $data['sale_price']
				: null,
			'price_group_key'    => (string) ( $data['price_group_key'] ?? '' ),
			'color_family'       => $data['color_family'] ?? null,
			'woo_product_id'     => isset( $data['woo_product_id'] ) && $data['woo_product_id'] !== null
				? (int) $data['woo_product_id']
				: null,
			'filters_json'       => (string) wp_json_encode( array_values( (array) ( $data['filters'] ?? [] ) ) ),
			'compatibility_json' => (string) wp_json_encode( array_values( (array) ( $data['compatibility'] ?? [] ) ) ),
			'attributes_json'    => (string) wp_json_encode( (object) ( $data['attributes'] ?? new \stdClass() ) ),
			'is_active'          => ( $data['is_active'] ?? true ) ? 1 : 0,
			'sort_order'         => (int) ( $data['sort_order'] ?? 0 ),
		];
	}

	private function null_or_string( mixed $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		return (string) $value;
	}

	private function null_or_float( mixed $value ): ?float {
		if ( $value === null || $value === '' ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * @return list<string>
	 */
	private function decode_array( mixed $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		return array_values( array_filter( $decoded, 'is_string' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decode_object( mixed $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Phase 4.2 — decode a JSON list of association arrays
	 * (`bundle_components_json`). Unlike `decode_array` (filters to
	 * strings) and `decode_object` (single map), this returns a
	 * zero-indexed list of objects.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function decode_list_of_objects( mixed $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		return array_values( array_filter( $decoded, 'is_array' ) );
	}
}
