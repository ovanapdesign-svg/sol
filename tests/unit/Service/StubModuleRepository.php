<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\ModuleRepository;

/**
 * In-memory stub for ModuleRepository. Lets ModuleServiceTest exercise
 * the real validation + sanitization logic without a database. Mirrors
 * just the methods ModuleService calls.
 */
final class StubModuleRepository extends ModuleRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];

	private int $next_id = 1;

	public function __construct() {
		// Skip parent constructor — no \wpdb.
	}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_key( string $module_key ): ?array {
		foreach ( $this->records as $rec ) {
			if ( $rec['module_key'] === $module_key ) {
				return $rec;
			}
		}
		return null;
	}

	public function key_exists( string $module_key, ?int $exclude_id = null ): bool {
		foreach ( $this->records as $rec ) {
			if ( $rec['module_key'] === $module_key && $rec['id'] !== $exclude_id ) {
				return true;
			}
		}
		return false;
	}

	public function list( int $page = 1, int $per_page = 50 ): array {
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
		$id = $this->next_id++;
		$record = array_merge(
			$this->blank(),
			$data,
			[
				'id'           => $id,
				'is_builtin'   => false,
				'created_at'   => '2026-04-29 10:00:00',
				'updated_at'   => '2026-04-29 10:00:00',
				'version_hash' => sha1( '2026-04-29 10:00:00' . $id ),
			]
		);
		$this->records[ $id ] = $record;
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
			[
				'id'           => $id,
				'updated_at'   => $now,
				'version_hash' => sha1( $now . $id ),
			]
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

	/**
	 * @return array<string,mixed>
	 */
	private function blank(): array {
		$rec = [
			'id'                  => 0,
			'module_key'          => '',
			'name'                => '',
			'description'         => null,
			'allowed_field_kinds' => [],
			'attribute_schema'    => [],
			'is_builtin'          => false,
			'is_active'           => true,
			'sort_order'          => 0,
			'created_at'          => '',
			'updated_at'          => '',
			'version_hash'        => '',
		];
		foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
			$rec[ $flag ] = false;
		}
		return $rec;
	}
}
