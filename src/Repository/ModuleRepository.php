<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

/**
 * Wpdb-backed repository for `wp_configkit_modules`.
 *
 * The ONLY layer in Phase 3 that runs SQL against the modules table.
 * Service / controller / page layers use this. The repository deals in
 * "app shape" arrays — booleans (not 0/1), decoded JSON arrays — and
 * hides the column-level marshalling.
 */
class ModuleRepository {

	public const TABLE = 'configkit_modules';

	public const CAPABILITY_FLAGS = [
		'supports_sku',
		'supports_image',
		'supports_main_image',
		'supports_price',
		'supports_sale_price',
		'supports_filters',
		'supports_compatibility',
		'supports_price_group',
		'supports_brand',
		'supports_collection',
		'supports_color_family',
		'supports_woo_product_link',
	];

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

	public function find_by_key( string $module_key ): ?array {
		$table = $this->table();
		/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$table}` WHERE module_key = %s", $module_key ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function key_exists( string $module_key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT id FROM `{$table}` WHERE module_key = %s LIMIT 1", $module_key )
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE module_key = %s AND id <> %d LIMIT 1",
					$module_key,
					$exclude_id
				)
			);
		}
		return $value !== null;
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list( int $page = 1, int $per_page = 50 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		/** @var list<array<string,mixed>> $rows */
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		) ?: [];

		$items       = array_map( [ $this, 'hydrate' ], $rows );
		$total_pages = $total === 0 ? 0 : (int) ceil( $total / $per_page );

		return [
			'items'       => array_values( $items ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		$table = $this->table();
		$now   = $this->now();
		$row   = $this->dehydrate( $data );
		$row['is_builtin'] = 0;
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$row['version_hash'] = '';

		$ok = $this->wpdb->insert( $table, $row );
		if ( $ok === false || $ok === 0 ) {
			throw new \RuntimeException( 'Failed to insert module: ' . (string) $this->wpdb->last_error );
		}
		$id = (int) $this->wpdb->insert_id;

		$hash = sha1( $now . (string) $id );
		$this->wpdb->update( $table, [ 'version_hash' => $hash ], [ 'id' => $id ] );

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
		unset( $row['created_at'], $row['is_builtin'] );

		$result = $this->wpdb->update( $table, $row, [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to update module: ' . (string) $this->wpdb->last_error );
		}
	}

	public function soft_delete( int $id ): void {
		$table = $this->table();
		$now   = $this->now();
		$hash  = sha1( $now . (string) $id );
		$result = $this->wpdb->update(
			$table,
			[ 'is_active' => 0, 'updated_at' => $now, 'version_hash' => $hash ],
			[ 'id' => $id ]
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to soft delete module: ' . (string) $this->wpdb->last_error );
		}
	}

	private function table(): string {
		return $this->wpdb->prefix . self::TABLE;
	}

	private function now(): string {
		if ( function_exists( 'current_time' ) ) {
			return (string) \current_time( 'mysql', true );
		}
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$out = [
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'module_key'          => (string) ( $row['module_key'] ?? '' ),
			'name'                => (string) ( $row['name'] ?? '' ),
			'description'         => $row['description'] !== null && $row['description'] !== ''
				? (string) $row['description']
				: null,
			'allowed_field_kinds' => $this->decode_array( $row['allowed_field_kinds_json'] ?? '' ),
			'attribute_schema'    => $this->decode_object( $row['attribute_schema_json'] ?? '' ),
			'is_builtin'          => (bool) (int) ( $row['is_builtin'] ?? 0 ),
			'is_active'           => (bool) (int) ( $row['is_active'] ?? 0 ),
			'sort_order'          => (int) ( $row['sort_order'] ?? 0 ),
			'created_at'          => (string) ( $row['created_at'] ?? '' ),
			'updated_at'          => (string) ( $row['updated_at'] ?? '' ),
			'version_hash'        => (string) ( $row['version_hash'] ?? '' ),
		];

		foreach ( self::CAPABILITY_FLAGS as $flag ) {
			$out[ $flag ] = (bool) (int) ( $row[ $flag ] ?? 0 );
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		$row = [
			'module_key'              => (string) ( $data['module_key'] ?? '' ),
			'name'                    => (string) ( $data['name'] ?? '' ),
			'description'             => $data['description'] ?? null,
			'allowed_field_kinds_json' => (string) wp_json_encode( array_values( (array) ( $data['allowed_field_kinds'] ?? [] ) ) ),
			'attribute_schema_json'    => (string) wp_json_encode( (object) ( $data['attribute_schema'] ?? new \stdClass() ) ),
			'is_active'                => ( $data['is_active'] ?? true ) ? 1 : 0,
			'sort_order'               => (int) ( $data['sort_order'] ?? 0 ),
		];

		foreach ( self::CAPABILITY_FLAGS as $flag ) {
			$row[ $flag ] = ! empty( $data[ $flag ] ) ? 1 : 0;
		}

		return $row;
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
	 * @return array<string,string>
	 */
	private function decode_object( mixed $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$out = [];
		foreach ( $decoded as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
