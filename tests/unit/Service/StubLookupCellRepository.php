<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\LookupCellRepository;

final class StubLookupCellRepository extends LookupCellRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function exists_in_table(
		string $lookup_table_key,
		int $width,
		int $height,
		string $price_group_key,
		?int $exclude_id = null
	): bool {
		foreach ( $this->records as $rec ) {
			if (
				$rec['lookup_table_key'] === $lookup_table_key
				&& (int) $rec['width'] === $width
				&& (int) $rec['height'] === $height
				&& $rec['price_group_key'] === $price_group_key
				&& $rec['id'] !== $exclude_id
			) {
				return true;
			}
		}
		return false;
	}

	public function has_cells_with_price_group( string $lookup_table_key ): bool {
		foreach ( $this->records as $rec ) {
			if ( $rec['lookup_table_key'] === $lookup_table_key && $rec['price_group_key'] !== '' ) {
				return true;
			}
		}
		return false;
	}

	public function list_in_table( string $lookup_table_key, array $filters = [], int $page = 1, int $per_page = 200 ): array {
		$items = array_values( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['lookup_table_key'] === $lookup_table_key
		) );
		if ( array_key_exists( 'price_group_key', $filters ) && $filters['price_group_key'] !== null ) {
			$pg    = (string) $filters['price_group_key'];
			$items = array_values( array_filter(
				$items,
				static fn( array $r ): bool => $r['price_group_key'] === $pg
			) );
		}
		return [
			'items'       => $items,
			'total'       => count( $items ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => count( $items ) === 0 ? 0 : 1,
		];
	}

	public function stats( string $lookup_table_key ): array {
		$items = array_values( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['lookup_table_key'] === $lookup_table_key
		) );
		if ( count( $items ) === 0 ) {
			return [
				'cells'        => 0,
				'width_min'    => null,
				'width_max'    => null,
				'height_min'   => null,
				'height_max'   => null,
				'price_groups' => 0,
			];
		}
		return [
			'cells'        => count( $items ),
			'width_min'    => min( array_map( static fn( $r ) => (int) $r['width'], $items ) ),
			'width_max'    => max( array_map( static fn( $r ) => (int) $r['width'], $items ) ),
			'height_min'   => min( array_map( static fn( $r ) => (int) $r['height'], $items ) ),
			'height_max'   => max( array_map( static fn( $r ) => (int) $r['height'], $items ) ),
			'price_groups' => count( array_unique( array_map(
				static fn( $r ) => (string) $r['price_group_key'],
				$items
			) ) ),
		];
	}

	public function create( array $data ): int {
		$id = $this->next_id++;
		$this->records[ $id ] = array_merge(
			[ 'id' => $id, 'price_group_key' => '', 'price' => 0.0 ],
			$data,
			[ 'id' => $id ]
		);
		return $id;
	}

	public function update( int $id, array $data ): void {
		if ( ! isset( $this->records[ $id ] ) ) {
			return;
		}
		$this->records[ $id ] = array_merge( $this->records[ $id ], $data, [ 'id' => $id ] );
	}

	public function delete( int $id ): void {
		unset( $this->records[ $id ] );
	}
}
