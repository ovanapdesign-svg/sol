<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\LibraryRepository;

final class StubLibraryRepository extends LibraryRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_key( string $library_key ): ?array {
		foreach ( $this->records as $rec ) {
			if ( $rec['library_key'] === $library_key ) {
				return $rec;
			}
		}
		return null;
	}

	public function key_exists( string $library_key, ?int $exclude_id = null ): bool {
		foreach ( $this->records as $rec ) {
			if ( $rec['library_key'] === $library_key && $rec['id'] !== $exclude_id ) {
				return true;
			}
		}
		return false;
	}

	public function list( array $filters = [], int $page = 1, int $per_page = 100 ): array {
		$items = array_values( $this->records );
		return [
			'items'       => $items,
			'total'       => count( $items ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => count( $items ) === 0 ? 0 : 1,
		];
	}

	public function create( array $data ): int {
		$id  = $this->next_id++;
		$now = '2026-04-29 10:00:00';
		$this->records[ $id ] = array_merge(
			[
				'id'           => $id,
				'description'  => null,
				'brand'        => null,
				'collection'   => null,
				'is_builtin'   => false,
				'is_active'    => true,
				'sort_order'   => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
				'version_hash' => sha1( $now . $id ),
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
