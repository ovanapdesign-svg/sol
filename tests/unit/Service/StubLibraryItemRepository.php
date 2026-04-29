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
