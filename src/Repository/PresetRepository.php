<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

/**
 * Phase 4.3b half A — wp_configkit_presets gateway.
 *
 * Hydrate decodes `sections_json` into a list-of-maps so callers
 * never have to remember to json_decode. Soft delete writes
 * `deleted_at`; list() filters those out by default.
 *
 * Storage notes:
 *   - sections_json is the snapshot shape produced by
 *     PresetService::snapshot_product. It carries section type +
 *     position-within-type + library_key/lookup_table_key
 *     REFERENCES (never copies of items / cells), plus per-section
 *     metadata the editor needs (label, visibility, range_rows
 *     fallback for legacy reads).
 *   - default_lookup_table_key — stored as the lookup_table_key
 *     string (not numeric id) so the snapshot stays portable across
 *     environments and matches how Phase 4.4 keys flow.
 */
class PresetRepository {

	public const TABLE = 'configkit_presets';

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

	public function find_by_key( string $preset_key ): ?array {
		$table = $this->table();
		/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$table}` WHERE preset_key = %s", $preset_key ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function key_exists( string $preset_key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT id FROM `{$table}` WHERE preset_key = %s LIMIT 1", $preset_key )
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE preset_key = %s AND id <> %d LIMIT 1",
					$preset_key,
					$exclude_id
				)
			);
		}
		return $value !== null;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list( array $filters = [], int $page = 1, int $per_page = 100 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		$where  = '1=1';
		$params = [];
		// Soft-deleted presets stay hidden unless explicitly requested.
		$include_deleted = ! empty( $filters['include_deleted'] );
		if ( ! $include_deleted ) {
			$where .= ' AND deleted_at IS NULL';
		}
		if ( ! empty( $filters['product_type'] ) ) {
			$where   .= ' AND product_type = %s';
			$params[] = (string) $filters['product_type'];
		}

		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
		$total     = (int) ( count( $params ) === 0
			? $this->wpdb->get_var( $count_sql )
			: $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, ...$params ) ) );

		$list_sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $list_sql, ...array_merge( $params, [ $per_page, $offset ] ) ),
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
			throw new \RuntimeException( 'Failed to insert preset: ' . (string) $this->wpdb->last_error );
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
			throw new \RuntimeException( 'Failed to update preset: ' . (string) $this->wpdb->last_error );
		}
	}

	public function soft_delete( int $id ): void {
		$table = $this->table();
		$now   = $this->now();
		$result = $this->wpdb->update(
			$table,
			[ 'deleted_at' => $now, 'updated_at' => $now, 'version_hash' => sha1( $now . (string) $id ) ],
			[ 'id' => $id ]
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to soft delete preset: ' . (string) $this->wpdb->last_error );
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
		$sections_raw = $row['sections_json'] ?? '';
		$decoded = is_string( $sections_raw ) && $sections_raw !== '' ? json_decode( $sections_raw, true ) : [];
		$sections = is_array( $decoded ) ? $decoded : [];
		return [
			'id'                       => (int) ( $row['id'] ?? 0 ),
			'preset_key'               => (string) ( $row['preset_key'] ?? '' ),
			'name'                     => (string) ( $row['name'] ?? '' ),
			'description'              => $row['description'] !== null && $row['description'] !== '' ? (string) $row['description'] : null,
			'product_type'             => $row['product_type'] !== null && $row['product_type'] !== '' ? (string) $row['product_type'] : null,
			'sections'                 => $sections,
			'default_lookup_table_key' => $row['default_lookup_table_key'] !== null && $row['default_lookup_table_key'] !== '' ? (string) $row['default_lookup_table_key'] : null,
			'default_frontend_mode'    => (string) ( $row['default_frontend_mode'] ?? 'stepper' ),
			'created_by'               => (int) ( $row['created_by'] ?? 0 ),
			'created_at'               => (string) ( $row['created_at'] ?? '' ),
			'updated_at'               => (string) ( $row['updated_at'] ?? '' ),
			'version_hash'             => (string) ( $row['version_hash'] ?? '' ),
			'deleted_at'               => $row['deleted_at'] !== null && $row['deleted_at'] !== '' ? (string) $row['deleted_at'] : null,
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		$sections = $data['sections'] ?? [];
		if ( ! is_array( $sections ) ) $sections = [];
		return [
			'preset_key'               => (string) ( $data['preset_key'] ?? '' ),
			'name'                     => (string) ( $data['name'] ?? '' ),
			'description'              => isset( $data['description'] ) && $data['description'] !== '' ? (string) $data['description'] : null,
			'product_type'             => isset( $data['product_type'] ) && $data['product_type'] !== '' ? (string) $data['product_type'] : null,
			'sections_json'            => (string) wp_json_encode( $sections ),
			'default_lookup_table_key' => isset( $data['default_lookup_table_key'] ) && $data['default_lookup_table_key'] !== '' ? (string) $data['default_lookup_table_key'] : null,
			'default_frontend_mode'    => isset( $data['default_frontend_mode'] ) && $data['default_frontend_mode'] !== '' ? (string) $data['default_frontend_mode'] : 'stepper',
			'created_by'               => (int) ( $data['created_by'] ?? 0 ),
		];
	}
}
