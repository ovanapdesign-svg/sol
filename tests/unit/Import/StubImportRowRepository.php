<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Repository\ImportRowRepository;

final class StubImportRowRepository extends ImportRowRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function bulk_create( string $batch_key, array $rows ): int {
		foreach ( $rows as $row ) {
			$id = $this->next_id++;
			$this->records[ $id ] = [
				'id'              => $id,
				'batch_key'       => $batch_key,
				'row_number'      => (int) ( $row['row_number'] ?? 0 ),
				'action'          => (string) ( $row['action'] ?? self::ACTION_INSERT ),
				'object_type'     => (string) ( $row['object_type'] ?? '' ),
				'object_key'      => (string) ( $row['object_key'] ?? '' ),
				'severity'        => (string) ( $row['severity'] ?? self::SEVERITY_GREEN ),
				'message'         => (string) ( $row['message'] ?? '' ),
				'raw_data'        => is_array( $row['raw_data'] ?? null ) ? $row['raw_data'] : null,
				'normalized_data' => is_array( $row['normalized_data'] ?? null ) ? $row['normalized_data'] : null,
				'created_at'      => '2026-04-30 10:00:00',
			];
		}
		return count( $rows );
	}

	public function delete_for_batch( string $batch_key ): int {
		$deleted = 0;
		foreach ( $this->records as $id => $rec ) {
			if ( $rec['batch_key'] === $batch_key ) {
				unset( $this->records[ $id ] );
				$deleted++;
			}
		}
		return $deleted;
	}

	public function all_for_batch( string $batch_key ): array {
		$items = array_values( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['batch_key'] === $batch_key
		) );
		usort( $items, static fn( $a, $b ) => $a['row_number'] <=> $b['row_number'] );
		return $items;
	}

	public function list_for_batch( string $batch_key, array $filters = [], int $page = 1, int $per_page = 100 ): array {
		$items = $this->all_for_batch( $batch_key );
		if ( ! empty( $filters['severity'] ) ) {
			$items = array_values( array_filter( $items, static fn( $r ) => $r['severity'] === $filters['severity'] ) );
		}
		return [
			'items'       => $items,
			'total'       => count( $items ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => count( $items ) === 0 ? 0 : 1,
		];
	}

	public function counts( string $batch_key ): array {
		$out = [
			'green' => 0, 'yellow' => 0, 'red' => 0,
			'insert' => 0, 'update' => 0, 'skip' => 0, 'delete' => 0,
			'total' => 0,
		];
		foreach ( $this->all_for_batch( $batch_key ) as $r ) {
			if ( isset( $out[ $r['severity'] ] ) ) $out[ $r['severity'] ]++;
			if ( isset( $out[ $r['action'] ] ) ) $out[ $r['action'] ]++;
			$out['total']++;
		}
		return $out;
	}
}
