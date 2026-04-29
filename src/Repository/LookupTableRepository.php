<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class LookupTableRepository {

	public const TABLE = 'configkit_lookup_tables';

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

	public function find_by_key( string $key ): ?array {
		$table = $this->table();
		/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$table}` WHERE lookup_table_key = %s", $key ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function key_exists( string $key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT id FROM `{$table}` WHERE lookup_table_key = %s LIMIT 1", $key )
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE lookup_table_key = %s AND id <> %d LIMIT 1",
					$key,
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
		if ( ! empty( $filters['family_key'] ) ) {
			$where   .= ' AND family_key = %s';
			$params[] = (string) $filters['family_key'];
		}
		if ( array_key_exists( 'is_active', $filters ) && $filters['is_active'] !== null ) {
			$where   .= ' AND is_active = %d';
			$params[] = $filters['is_active'] ? 1 : 0;
		}

		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
		$total     = (int) ( count( $params ) === 0
			? $this->wpdb->get_var( $count_sql )
			: $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, ...$params ) ) );

		$list_sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY name ASC LIMIT %d OFFSET %d";
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
			throw new \RuntimeException( 'Failed to insert lookup table: ' . (string) $this->wpdb->last_error );
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
			throw new \RuntimeException( 'Failed to update lookup table: ' . (string) $this->wpdb->last_error );
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
			throw new \RuntimeException( 'Failed to soft delete lookup table: ' . (string) $this->wpdb->last_error );
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
			'id'                   => (int) ( $row['id'] ?? 0 ),
			'lookup_table_key'     => (string) ( $row['lookup_table_key'] ?? '' ),
			'name'                 => (string) ( $row['name'] ?? '' ),
			'family_key'           => $this->null_or_string( $row['family_key'] ?? null ),
			'unit'                 => (string) ( $row['unit'] ?? 'mm' ),
			'supports_price_group' => (bool) (int) ( $row['supports_price_group'] ?? 0 ),
			'width_min'            => $this->null_or_int( $row['width_min'] ?? null ),
			'width_max'            => $this->null_or_int( $row['width_max'] ?? null ),
			'height_min'           => $this->null_or_int( $row['height_min'] ?? null ),
			'height_max'           => $this->null_or_int( $row['height_max'] ?? null ),
			'match_mode'           => (string) ( $row['match_mode'] ?? 'round_up' ),
			'import_source'        => $this->null_or_string( $row['import_source'] ?? null ),
			'last_imported_at'     => $this->null_or_string( $row['last_imported_at'] ?? null ),
			'is_active'            => (bool) (int) ( $row['is_active'] ?? 0 ),
			'created_at'           => (string) ( $row['created_at'] ?? '' ),
			'updated_at'           => (string) ( $row['updated_at'] ?? '' ),
			'version_hash'         => (string) ( $row['version_hash'] ?? '' ),
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		return [
			'lookup_table_key'     => (string) ( $data['lookup_table_key'] ?? '' ),
			'name'                 => (string) ( $data['name'] ?? '' ),
			'family_key'           => isset( $data['family_key'] ) && $data['family_key'] !== ''
				? (string) $data['family_key']
				: null,
			'unit'                 => (string) ( $data['unit'] ?? 'mm' ),
			'supports_price_group' => ! empty( $data['supports_price_group'] ) ? 1 : 0,
			'width_min'            => $this->nullable_int( $data['width_min'] ?? null ),
			'width_max'            => $this->nullable_int( $data['width_max'] ?? null ),
			'height_min'           => $this->nullable_int( $data['height_min'] ?? null ),
			'height_max'           => $this->nullable_int( $data['height_max'] ?? null ),
			'match_mode'           => (string) ( $data['match_mode'] ?? 'round_up' ),
			'is_active'            => ( $data['is_active'] ?? true ) ? 1 : 0,
		];
	}

	private function null_or_string( mixed $v ): ?string {
		if ( $v === null || $v === '' ) {
			return null;
		}
		return (string) $v;
	}

	private function null_or_int( mixed $v ): ?int {
		if ( $v === null || $v === '' ) {
			return null;
		}
		return (int) $v;
	}

	private function nullable_int( mixed $v ): ?int {
		if ( $v === null || $v === '' ) {
			return null;
		}
		return (int) $v;
	}
}
