<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

/**
 * Product binding lives in WooCommerce post meta on the product post.
 * This is the only layer that touches `get_post_meta` /
 * `update_post_meta` / `delete_post_meta` for binding state.
 *
 * Storage keys per PRODUCT_BINDING_SPEC.md §12.
 */
class ProductBindingRepository {

	public const META_ENABLED            = '_configkit_enabled';
	public const META_TEMPLATE_KEY       = '_configkit_template_key';
	public const META_TEMPLATE_VERSION   = '_configkit_template_version_id';
	public const META_LOOKUP_TABLE_KEY   = '_configkit_lookup_table_key';
	public const META_FAMILY_KEY         = '_configkit_family_key';
	public const META_FRONTEND_MODE      = '_configkit_frontend_mode';
	public const META_DEFAULTS_JSON      = '_configkit_defaults_json';
	public const META_ALLOWED_SOURCES    = '_configkit_allowed_sources_json';
	public const META_PRICING_OVERRIDES  = '_configkit_pricing_overrides_json';
	public const META_FIELD_OVERRIDES    = '_configkit_field_overrides_json';
	public const META_VERSION_HASH       = '_configkit_binding_version_hash';
	public const META_UPDATED_AT         = '_configkit_binding_updated_at';

	public function __construct( private \wpdb $wpdb ) {}

	/**
	 * Read the full binding state for a product. Returns hydrated arrays
	 * (decoded JSON, casted booleans/ints) — never raw meta strings.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $product_id ): ?array {
		if ( $product_id <= 0 ) {
			return null;
		}
		// Confirm the post exists (and is a product).
		$post_type = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT post_type FROM `{$this->wpdb->posts}` WHERE ID = %d",
				$product_id
			)
		);
		if ( $post_type === null ) {
			return null;
		}

		return [
			'product_id'           => $product_id,
			'enabled'              => $this->get_bool( $product_id, self::META_ENABLED ),
			'template_key'         => $this->get_string_or_null( $product_id, self::META_TEMPLATE_KEY ),
			'template_version_id'  => $this->get_int( $product_id, self::META_TEMPLATE_VERSION ),
			'lookup_table_key'     => $this->get_string_or_null( $product_id, self::META_LOOKUP_TABLE_KEY ),
			'family_key'           => $this->get_string_or_null( $product_id, self::META_FAMILY_KEY ),
			'frontend_mode'        => $this->get_string_default( $product_id, self::META_FRONTEND_MODE, 'stepper' ),
			'defaults'             => $this->get_object( $product_id, self::META_DEFAULTS_JSON ),
			'allowed_sources'      => $this->get_object( $product_id, self::META_ALLOWED_SOURCES ),
			'pricing_overrides'    => $this->get_object( $product_id, self::META_PRICING_OVERRIDES ),
			'field_overrides'      => $this->get_object( $product_id, self::META_FIELD_OVERRIDES ),
			'updated_at'           => $this->get_string_or_null( $product_id, self::META_UPDATED_AT ),
			'version_hash'         => $this->get_string_default( $product_id, self::META_VERSION_HASH, '' ),
		];
	}

	/**
	 * Persist the full binding state. Computes a fresh version_hash on
	 * every write.
	 *
	 * @param array<string,mixed> $data
	 */
	public function save( int $product_id, array $data ): string {
		$now  = $this->now();
		$hash = sha1( $now . (string) $product_id );

		$this->set_bool( $product_id, self::META_ENABLED, ! empty( $data['enabled'] ) );
		$this->set_string( $product_id, self::META_TEMPLATE_KEY, $data['template_key'] ?? null );
		$this->set_int( $product_id, self::META_TEMPLATE_VERSION, isset( $data['template_version_id'] ) ? (int) $data['template_version_id'] : 0 );
		$this->set_string( $product_id, self::META_LOOKUP_TABLE_KEY, $data['lookup_table_key'] ?? null );
		$this->set_string( $product_id, self::META_FAMILY_KEY, $data['family_key'] ?? null );
		$this->set_string( $product_id, self::META_FRONTEND_MODE, isset( $data['frontend_mode'] ) ? (string) $data['frontend_mode'] : 'stepper' );
		$this->set_object( $product_id, self::META_DEFAULTS_JSON, is_array( $data['defaults'] ?? null ) ? $data['defaults'] : [] );
		$this->set_object( $product_id, self::META_ALLOWED_SOURCES, is_array( $data['allowed_sources'] ?? null ) ? $data['allowed_sources'] : [] );
		$this->set_object( $product_id, self::META_PRICING_OVERRIDES, is_array( $data['pricing_overrides'] ?? null ) ? $data['pricing_overrides'] : [] );
		$this->set_object( $product_id, self::META_FIELD_OVERRIDES, is_array( $data['field_overrides'] ?? null ) ? $data['field_overrides'] : [] );
		\update_post_meta( $product_id, self::META_UPDATED_AT, $now );
		\update_post_meta( $product_id, self::META_VERSION_HASH, $hash );

		return $hash;
	}

	/**
	 * Lightweight overview row for the Products list page. Joins
	 * postmeta to the product post for display data Woo natively
	 * stores.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list_overview( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$posts    = $this->wpdb->posts;
		$meta     = $this->wpdb->postmeta;

		$where  = "p.post_type = 'product' AND p.post_status IN ( 'publish', 'draft', 'pending', 'private' )";
		$params = [];

		if ( ! empty( $filters['family_key'] ) ) {
			$where    .= " AND EXISTS ( SELECT 1 FROM `{$meta}` mm WHERE mm.post_id = p.ID AND mm.meta_key = %s AND mm.meta_value = %s )";
			$params[]  = self::META_FAMILY_KEY;
			$params[]  = (string) $filters['family_key'];
		}
		if ( array_key_exists( 'enabled', $filters ) && $filters['enabled'] !== null ) {
			$wanted    = $filters['enabled'] ? '1' : '0';
			$where    .= " AND COALESCE( ( SELECT mm.meta_value FROM `{$meta}` mm WHERE mm.post_id = p.ID AND mm.meta_key = %s LIMIT 1 ), '0' ) = %s";
			$params[]  = self::META_ENABLED;
			$params[]  = $wanted;
		}

		$count_sql = "SELECT COUNT(*) FROM `{$posts}` p WHERE {$where}";
		$total     = (int) ( count( $params ) === 0
			? $this->wpdb->get_var( $count_sql )
			: $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, ...$params ) ) );

		$list_sql = "SELECT p.ID, p.post_title, p.post_status FROM `{$posts}` p WHERE {$where} ORDER BY p.post_title ASC LIMIT %d OFFSET %d";
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $list_sql, ...array_merge( $params, [ $per_page, $offset ] ) ),
			ARRAY_A
		) ?: [];

		$items = [];
		foreach ( $rows as $row ) {
			$id    = (int) $row['ID'];
			$items[] = [
				'product_id'        => $id,
				'name'              => (string) $row['post_title'],
				'post_status'       => (string) $row['post_status'],
				'sku'               => $this->get_string_default( $id, '_sku', '' ),
				'enabled'           => $this->get_bool( $id, self::META_ENABLED ),
				'family_key'        => $this->get_string_or_null( $id, self::META_FAMILY_KEY ),
				'template_key'      => $this->get_string_or_null( $id, self::META_TEMPLATE_KEY ),
				'lookup_table_key'  => $this->get_string_or_null( $id, self::META_LOOKUP_TABLE_KEY ),
				'updated_at'        => $this->get_string_or_null( $id, self::META_UPDATED_AT ),
				'edit_url'          => $this->edit_url_with_tab( $id ),
			];
		}

		return [
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total === 0 ? 0 : (int) ceil( $total / $per_page ),
		];
	}

	private function edit_url_with_tab( int $product_id ): string {
		if ( function_exists( 'admin_url' ) ) {
			return \admin_url( 'post.php?post=' . $product_id . '&action=edit#configkit_product_data' );
		}
		return '/wp-admin/post.php?post=' . $product_id . '&action=edit#configkit_product_data';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? (string) \current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function get_bool( int $post_id, string $key ): bool {
		$value = \get_post_meta( $post_id, $key, true );
		return ! empty( $value ) && $value !== '0' && $value !== 'false';
	}

	private function get_int( int $post_id, string $key ): int {
		$value = \get_post_meta( $post_id, $key, true );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function get_string_or_null( int $post_id, string $key ): ?string {
		$value = \get_post_meta( $post_id, $key, true );
		if ( $value === '' || $value === null || $value === false ) {
			return null;
		}
		return (string) $value;
	}

	private function get_string_default( int $post_id, string $key, string $default ): string {
		$value = \get_post_meta( $post_id, $key, true );
		if ( $value === '' || $value === null || $value === false ) {
			return $default;
		}
		return (string) $value;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_object( int $post_id, string $key ): array {
		$value = \get_post_meta( $post_id, $key, true );
		if ( ! is_string( $value ) || $value === '' ) {
			return [];
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private function set_bool( int $post_id, string $key, bool $value ): void {
		\update_post_meta( $post_id, $key, $value ? '1' : '0' );
	}

	private function set_int( int $post_id, string $key, int $value ): void {
		\update_post_meta( $post_id, $key, (string) $value );
	}

	private function set_string( int $post_id, string $key, ?string $value ): void {
		if ( $value === null || $value === '' ) {
			\delete_post_meta( $post_id, $key );
			return;
		}
		\update_post_meta( $post_id, $key, $value );
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function set_object( int $post_id, string $key, array $value ): void {
		\update_post_meta( $post_id, $key, (string) wp_json_encode( $value ) );
	}
}
