<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\LibraryItemRepository;

final class StubLibraryItemRepository extends LibraryItemRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_library_and_key( string $library_key, string $item_key ): ?array {
		foreach ( $this->records as $rec ) {
			if ( $rec['library_key'] === $library_key && $rec['item_key'] === $item_key ) {
				return $rec;
			}
		}
		return null;
	}

	public function soft_delete_all_in_library( string $library_key ): int {
		$count = 0;
		foreach ( $this->records as $id => $rec ) {
			if ( $rec['library_key'] === $library_key && ! empty( $rec['is_active'] ) ) {
				$this->records[ $id ]['is_active']    = false;
				$this->records[ $id ]['updated_at']   = '2026-04-29 12:00:00';
				$this->records[ $id ]['version_hash'] = sha1( '2026-04-29 12:00:00' . $id );
				$count++;
			}
		}
		return $count;
	}

	public function sku_exists_in_library( string $library_key, string $sku, ?int $exclude_id = null ): bool {
		$sku = trim( $sku );
		if ( $sku === '' ) return false;
		foreach ( $this->records as $rec ) {
			if ( ( $rec['library_key'] ?? '' ) === $library_key
				&& ( $rec['sku'] ?? '' ) === $sku
				&& ! empty( $rec['is_active'] )
				&& ( $exclude_id === null || (int) $rec['id'] !== $exclude_id )
			) {
				return true;
			}
		}
		return false;
	}

	public function key_exists_in_library( string $library_key, string $item_key, ?int $exclude_id = null ): bool {
		foreach ( $this->records as $rec ) {
			if ( $rec['library_key'] === $library_key
				&& $rec['item_key'] === $item_key
				&& $rec['id'] !== $exclude_id
			) {
				return true;
			}
		}
		return false;
	}

	public function list_in_library( string $library_key, int $page = 1, int $per_page = 100 ): array {
		$items = array_values( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['library_key'] === $library_key
		) );
		return [
			'items'       => $items,
			'total'       => count( $items ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => count( $items ) === 0 ? 0 : 1,
		];
	}

	public function search_global( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		$items = array_values( $this->records );
		if ( ! empty( $filters['q'] ) ) {
			$needle = strtolower( (string) $filters['q'] );
			$items  = array_values( array_filter( $items, static function ( array $r ) use ( $needle ): bool {
				return str_contains( strtolower( (string) ( $r['label'] ?? '' ) ), $needle )
					|| str_contains( strtolower( (string) ( $r['item_key'] ?? '' ) ), $needle )
					|| str_contains( strtolower( (string) ( $r['sku'] ?? '' ) ), $needle );
			} ) );
		}
		if ( array_key_exists( 'is_active', $filters ) && $filters['is_active'] !== null ) {
			$wanted = (bool) $filters['is_active'];
			$items  = array_values( array_filter( $items, static fn( array $r ): bool => (bool) ( $r['is_active'] ?? false ) === $wanted ) );
		}
		if ( ! empty( $filters['library_keys'] ) && is_array( $filters['library_keys'] ) ) {
			$keys  = $filters['library_keys'];
			$items = array_values( array_filter( $items, static fn( array $r ): bool => in_array( $r['library_key'] ?? '', $keys, true ) ) );
		}

		$total  = count( $items );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$slice  = array_slice( $items, $offset, $per_page );

		return [
			'items'       => $slice,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total === 0 ? 0 : (int) ceil( $total / $per_page ),
		];
	}

	public function count_in_library( string $library_key ): int {
		return count( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['library_key'] === $library_key
		) );
	}

	public function create( array $data ): int {
		$id  = $this->next_id++;
		$now = '2026-04-29 10:00:00';
		$this->records[ $id ] = array_merge(
			[
				'id'              => $id,
				'sku'             => null,
				'short_label'     => null,
				'image_url'       => null,
				'main_image_url'  => null,
				'description'     => null,
				'price'           => null,
				'sale_price'      => null,
				'price_group_key' => '',
				'color_family'    => null,
				'woo_product_id'  => null,
				'filters'         => [],
				'compatibility'   => [],
				'attributes'      => [],
				'is_active'       => true,
				'sort_order'      => 0,
				'created_at'      => $now,
				'updated_at'      => $now,
				'version_hash'    => sha1( $now . $id ),
			],
			$data,
			[ 'id' => $id, 'version_hash' => sha1( $now . $id ) ]
		);
		return $id;
	}

	public function update( int $id, array $data ): void {
		if ( ! isset( $this->records[ $id ] ) ) {
			return;
		}
		$now = '2026-04-29 11:00:00';
		$this->records[ $id ] = array_merge(
			$this->records[ $id ],
			$data,
			[ 'id' => $id, 'updated_at' => $now, 'version_hash' => sha1( $now . $id ) ]
		);
	}

	public function soft_delete( int $id ): void {
		if ( ! isset( $this->records[ $id ] ) ) {
			return;
		}
		$now = '2026-04-29 12:00:00';
		$this->records[ $id ]['is_active']    = false;
		$this->records[ $id ]['updated_at']   = $now;
		$this->records[ $id ]['version_hash'] = sha1( $now . $id );
	}
}
