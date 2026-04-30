<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Repository\ImportBatchRepository;

final class StubImportBatchRepository extends ImportBatchRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function create( array $data ): int {
		$id = $this->next_id++;
		$this->records[ $id ] = [
			'id'              => $id,
			'batch_key'       => (string) $data['batch_key'],
			'import_type'     => (string) $data['import_type'],
			'filename'        => (string) ( $data['filename'] ?? '' ),
			'status'          => (string) ( $data['status'] ?? self::STATE_RECEIVED ),
			'dry_run'         => ! empty( $data['dry_run'] ),
			'created_by'      => (int) ( $data['created_by'] ?? 0 ),
			'created_at'      => '2026-04-30 10:00:00',
			'committed_at'    => null,
			'summary'         => is_array( $data['summary'] ?? null ) ? $data['summary'] : null,
			'rollback_status' => null,
			'notes'           => $data['notes'] ?? null,
		];
		return $id;
	}

	public function find_by_id( int $id ): ?array {
		return isset( $this->records[ $id ] ) ? $this->records[ $id ] : null;
	}

	public function find_by_key( string $batch_key ): ?array {
		foreach ( $this->records as $rec ) {
			if ( $rec['batch_key'] === $batch_key ) return $rec;
		}
		return null;
	}

	public function update( int $id, array $patch ): void {
		if ( ! isset( $this->records[ $id ] ) ) return;
		$row = $this->records[ $id ];
		if ( array_key_exists( 'status', $patch ) )          $row['status'] = (string) $patch['status'];
		if ( array_key_exists( 'committed_at', $patch ) )    $row['committed_at'] = $patch['committed_at'];
		if ( array_key_exists( 'rollback_status', $patch ) ) $row['rollback_status'] = $patch['rollback_status'];
		if ( array_key_exists( 'notes', $patch ) )           $row['notes'] = $patch['notes'];
		if ( array_key_exists( 'summary', $patch ) )         $row['summary'] = is_array( $patch['summary'] ) ? $patch['summary'] : null;
		$this->records[ $id ] = $row;
	}

	public function list( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		$items = array_values( $this->records );
		return [
			'items'       => $items,
			'total'       => count( $items ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => count( $items ) === 0 ? 0 : 1,
		];
	}
}
