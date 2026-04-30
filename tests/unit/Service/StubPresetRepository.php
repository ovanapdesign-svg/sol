<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\PresetRepository;

/**
 * Phase 4.3b half A — in-memory stub mirroring PresetRepository so
 * PresetService can be exercised without booting wpdb.
 */
final class StubPresetRepository extends PresetRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_key( string $preset_key ): ?array {
		foreach ( $this->records as $rec ) {
			if ( $rec['preset_key'] === $preset_key ) return $rec;
		}
		return null;
	}

	public function key_exists( string $preset_key, ?int $exclude_id = null ): bool {
		foreach ( $this->records as $rec ) {
			if ( $rec['preset_key'] === $preset_key && $rec['id'] !== $exclude_id ) return true;
		}
		return false;
	}

	public function list( array $filters = [], int $page = 1, int $per_page = 100 ): array {
		$include_deleted = ! empty( $filters['include_deleted'] );
		$items = array_values( array_filter(
			$this->records,
			static function ( array $r ) use ( $include_deleted, $filters ): bool {
				if ( ! $include_deleted && ! empty( $r['deleted_at'] ) ) return false;
				if ( ! empty( $filters['product_type'] ) && ( $r['product_type'] ?? null ) !== $filters['product_type'] ) return false;
				return true;
			}
		) );
		// Newest first to match the real repo ordering.
		usort( $items, static fn ( $a, $b ) => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) );
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

	public function create( array $data ): int {
		$id  = $this->next_id++;
		$now = '2026-04-30 10:00:00';
		// Match the real repo's hydrated shape: the JSON column is
		// stored as the decoded `sections` array so callers don't have
		// to remember to decode.
		$this->records[ $id ] = array_merge(
			[
				'id'                       => $id,
				'description'              => null,
				'product_type'             => null,
				'sections'                 => [],
				'default_lookup_table_key' => null,
				'default_frontend_mode'    => 'stepper',
				'created_by'               => 0,
				'created_at'               => $now,
				'updated_at'               => $now,
				'version_hash'             => sha1( $now . $id ),
				'deleted_at'               => null,
			],
			$data,
			[ 'id' => $id, 'version_hash' => sha1( $now . $id ) ]
		);
		return $id;
	}

	public function update( int $id, array $data ): void {
		if ( ! isset( $this->records[ $id ] ) ) return;
		$now = '2026-04-30 11:00:00';
		$this->records[ $id ] = array_merge(
			$this->records[ $id ],
			$data,
			[ 'id' => $id, 'updated_at' => $now, 'version_hash' => sha1( $now . $id ) ]
		);
	}

	public function soft_delete( int $id ): void {
		if ( ! isset( $this->records[ $id ] ) ) return;
		$now = '2026-04-30 12:00:00';
		$this->records[ $id ]['deleted_at']   = $now;
		$this->records[ $id ]['updated_at']   = $now;
		$this->records[ $id ]['version_hash'] = sha1( $now . $id );
	}
}
