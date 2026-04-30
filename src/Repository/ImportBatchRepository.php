<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

/**
 * Read/write `wp_configkit_import_batches` per DATA_MODEL.md §3.15.
 *
 * Every uploaded file becomes exactly one row here. The batch state
 * machine ('received' → 'parsing' → 'parsed' → 'validated' →
 * 'committing' → 'applied' / 'failed' / 'cancelled') is enforced by
 * `ImportRunner`; this repo just stores transitions verbatim.
 */
class ImportBatchRepository {

	public const TABLE = 'configkit_import_batches';

	public const STATE_RECEIVED   = 'received';
	public const STATE_PARSING    = 'parsing';
	public const STATE_PARSED     = 'parsed';
	public const STATE_VALIDATED  = 'validated';
	public const STATE_COMMITTING = 'committing';
	public const STATE_APPLIED    = 'applied';
	public const STATE_FAILED     = 'failed';
	public const STATE_CANCELLED  = 'cancelled';

	public function __construct( private \wpdb $wpdb ) {}

	private function table(): string {
		return $this->wpdb->prefix . self::TABLE;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		$ok = $this->wpdb->insert(
			$this->table(),
			[
				'batch_key'       => (string) $data['batch_key'],
				'import_type'     => (string) $data['import_type'],
				'filename'        => (string) ( $data['filename'] ?? '' ),
				'status'          => (string) ( $data['status'] ?? self::STATE_RECEIVED ),
				'dry_run'         => ! empty( $data['dry_run'] ) ? 1 : 0,
				'created_by'      => (int) ( $data['created_by'] ?? 0 ),
				'created_at'      => (string) ( $data['created_at'] ?? $this->now() ),
				'committed_at'    => $data['committed_at'] ?? null,
				'summary_json'    => isset( $data['summary'] ) && is_array( $data['summary'] )
					? (string) wp_json_encode( $data['summary'] )
					: null,
				'rollback_status' => $data['rollback_status'] ?? null,
				'notes'           => $data['notes'] ?? null,
			]
		);
		if ( $ok === false || $ok === 0 ) {
			throw new \RuntimeException( 'Failed to insert import batch: ' . (string) $this->wpdb->last_error );
		}
		return (int) $this->wpdb->insert_id;
	}

	public function find_by_id( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$this->table()}` WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function find_by_key( string $batch_key ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$this->table()}` WHERE batch_key = %s", $batch_key ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * @param array<string,mixed> $patch
	 */
	public function update( int $id, array $patch ): void {
		$row = [];
		if ( array_key_exists( 'status', $patch ) ) $row['status'] = (string) $patch['status'];
		if ( array_key_exists( 'committed_at', $patch ) ) $row['committed_at'] = $patch['committed_at'];
		if ( array_key_exists( 'rollback_status', $patch ) ) $row['rollback_status'] = $patch['rollback_status'];
		if ( array_key_exists( 'notes', $patch ) ) $row['notes'] = $patch['notes'];
		if ( array_key_exists( 'summary', $patch ) ) {
			$row['summary_json'] = is_array( $patch['summary'] )
				? (string) wp_json_encode( $patch['summary'] )
				: null;
		}
		if ( count( $row ) === 0 ) return;
		$result = $this->wpdb->update( $this->table(), $row, [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to update import batch: ' . (string) $this->wpdb->last_error );
		}
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		$where  = '1=1';
		$params = [];
		if ( ! empty( $filters['import_type'] ) ) {
			$where   .= ' AND import_type = %s';
			$params[] = (string) $filters['import_type'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = (string) $filters['status'];
		}

		$total = (int) ( count( $params ) === 0
			? $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" )
			: $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", ...$params ) ) );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
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

	private function now(): string {
		return function_exists( 'current_time' )
			? (string) \current_time( 'mysql', true )
			: gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$summary = null;
		if ( isset( $row['summary_json'] ) && is_string( $row['summary_json'] ) && $row['summary_json'] !== '' ) {
			$decoded = json_decode( $row['summary_json'], true );
			$summary = is_array( $decoded ) ? $decoded : null;
		}
		return [
			'id'              => (int) $row['id'],
			'batch_key'       => (string) ( $row['batch_key'] ?? '' ),
			'import_type'     => (string) ( $row['import_type'] ?? '' ),
			'filename'        => (string) ( $row['filename'] ?? '' ),
			'status'          => (string) ( $row['status'] ?? '' ),
			'dry_run'         => ! empty( $row['dry_run'] ),
			'created_by'      => (int) ( $row['created_by'] ?? 0 ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'committed_at'    => $row['committed_at'] ?? null,
			'summary'         => $summary,
			'rollback_status' => $row['rollback_status'] ?? null,
			'notes'           => $row['notes'] ?? null,
		];
	}
}
