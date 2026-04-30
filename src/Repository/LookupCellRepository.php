<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class LookupCellRepository {

	public const TABLE = 'configkit_lookup_cells';

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

	public function exists_in_table(
		string $lookup_table_key,
		int $width,
		int $height,
		string $price_group_key,
		?int $exclude_id = null
	): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE lookup_table_key = %s AND width = %d AND height = %d AND price_group_key = %s LIMIT 1",
					$lookup_table_key,
					$width,
					$height,
					$price_group_key
				)
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE lookup_table_key = %s AND width = %d AND height = %d AND price_group_key = %s AND id <> %d LIMIT 1",
					$lookup_table_key,
					$width,
					$height,
					$price_group_key,
					$exclude_id
				)
			);
		}
		return $value !== null;
	}

	public function has_cells_with_price_group( string $lookup_table_key ): bool {
		$table = $this->table();
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE lookup_table_key = %s AND price_group_key <> '' LIMIT 1",
				$lookup_table_key
			)
		);
		return $value !== null;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list_in_table( string $lookup_table_key, array $filters = [], int $page = 1, int $per_page = 200 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 1000, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		$where  = 'lookup_table_key = %s';
		$params = [ $lookup_table_key ];
		if ( array_key_exists( 'price_group_key', $filters ) && $filters['price_group_key'] !== null ) {
			$where   .= ' AND price_group_key = %s';
			$params[] = (string) $filters['price_group_key'];
		}

		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", ...$params )
		);

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE {$where} ORDER BY width ASC, height ASC, price_group_key ASC LIMIT %d OFFSET %d",
				...array_merge( $params, [ $per_page, $offset ] )
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

	/**
	 * @return array<string,mixed>
	 */
	public function stats( string $lookup_table_key ): array {
		$table = $this->table();
		/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT COUNT(*) AS cells, MIN(width) AS w_min, MAX(width) AS w_max, MIN(height) AS h_min, MAX(height) AS h_max, COUNT(DISTINCT price_group_key) AS price_groups FROM `{$table}` WHERE lookup_table_key = %s",
				$lookup_table_key
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return [
				'cells' => 0,
				'width_min' => null,
				'width_max' => null,
				'height_min' => null,
				'height_max' => null,
				'price_groups' => 0,
			];
		}

		return [
			'cells'        => (int) ( $row['cells'] ?? 0 ),
			'width_min'    => $row['w_min'] !== null ? (int) $row['w_min'] : null,
			'width_max'    => $row['w_max'] !== null ? (int) $row['w_max'] : null,
			'height_min'   => $row['h_min'] !== null ? (int) $row['h_min'] : null,
			'height_max'   => $row['h_max'] !== null ? (int) $row['h_max'] : null,
			'price_groups' => (int) ( $row['price_groups'] ?? 0 ),
		];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		$table = $this->table();
		$ok = $this->wpdb->insert( $table, $this->dehydrate( $data ) );
		if ( $ok === false || $ok === 0 ) {
			throw new \RuntimeException( 'Failed to insert lookup cell: ' . (string) $this->wpdb->last_error );
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): void {
		$table = $this->table();
		$result = $this->wpdb->update( $table, $this->dehydrate( $data ), [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to update lookup cell: ' . (string) $this->wpdb->last_error );
		}
	}

	public function delete( int $id ): void {
		$table = $this->table();
		$result = $this->wpdb->delete( $table, [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to delete lookup cell: ' . (string) $this->wpdb->last_error );
		}
	}

	/**
	 * Find a single cell by its business-key tuple. Used by the
	 * importer's idempotent insert/update path.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_coordinates(
		string $lookup_table_key,
		int $width,
		int $height,
		string $price_group_key
	): ?array {
		$table = $this->table();
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE lookup_table_key = %s AND width = %d AND height = %d AND price_group_key = %s LIMIT 1",
				$lookup_table_key,
				$width,
				$height,
				$price_group_key
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Hard-delete every cell in a lookup table. Used by the importer's
	 * "Replace all" mode after the owner explicitly confirms.
	 */
	public function delete_all_in_table( string $lookup_table_key ): int {
		$table  = $this->table();
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM `{$table}` WHERE lookup_table_key = %s",
				$lookup_table_key
			)
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to delete cells: ' . (string) $this->wpdb->last_error );
		}
		return (int) $result;
	}

	private function table(): string {
		return $this->wpdb->prefix . self::TABLE;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		return [
			'id'               => (int) ( $row['id'] ?? 0 ),
			'lookup_table_key' => (string) ( $row['lookup_table_key'] ?? '' ),
			'width'            => (int) ( $row['width'] ?? 0 ),
			'height'           => (int) ( $row['height'] ?? 0 ),
			'price_group_key'  => (string) ( $row['price_group_key'] ?? '' ),
			'price'            => (float) ( $row['price'] ?? 0 ),
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		return [
			'lookup_table_key' => (string) ( $data['lookup_table_key'] ?? '' ),
			'width'            => (int) ( $data['width'] ?? 0 ),
			'height'           => (int) ( $data['height'] ?? 0 ),
			'price_group_key'  => (string) ( $data['price_group_key'] ?? '' ),
			'price'            => (float) ( $data['price'] ?? 0 ),
		];
	}
}
