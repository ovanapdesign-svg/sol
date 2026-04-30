<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

/**
 * Read/write `wp_configkit_import_rows` per DATA_MODEL.md §3.16.
 *
 * Every parsed row in a batch becomes one row here regardless of
 * validity — silent skipping is forbidden by IMPORT_WIZARD_SPEC §12.
 *
 * Severity values: 'green' (valid), 'yellow' (warning), 'red' (error).
 *
 * Action values: 'insert', 'update', 'skip', 'delete'. The `action`
 * column is filled at validation time so the preview can show
 * "insert N / update N / skip N" tallies.
 */
class ImportRowRepository {

	public const TABLE = 'configkit_import_rows';

	public const SEVERITY_GREEN  = 'green';
	public const SEVERITY_YELLOW = 'yellow';
	public const SEVERITY_RED    = 'red';

	public const ACTION_INSERT = 'insert';
	public const ACTION_UPDATE = 'update';
	public const ACTION_SKIP   = 'skip';
	public const ACTION_DELETE = 'delete';

	public function __construct( private \wpdb $wpdb ) {}

	private function table(): string {
		return $this->wpdb->prefix . self::TABLE;
	}

	/**
	 * Bulk-insert rows for a batch. Single multi-row INSERT for
	 * speed — the spec allows up to 5000 rows synchronously.
	 *
	 * @param list<array<string,mixed>> $rows
	 */
	public function bulk_create( string $batch_key, array $rows ): int {
		if ( count( $rows ) === 0 ) return 0;
		$now = $this->now();

		// Chunked multi-row insert keeps the SQL statement under
		// `max_allowed_packet` while still being roughly an order of
		// magnitude faster than per-row inserts.
		$inserted = 0;
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$placeholders = [];
			$values       = [];
			foreach ( $chunk as $row ) {
				$placeholders[] = '( %s, %d, %s, %s, %s, %s, %s, %s, %s, %s )';
				$values[] = $batch_key;
				$values[] = (int) ( $row['row_number'] ?? 0 );
				$values[] = (string) ( $row['action'] ?? self::ACTION_INSERT );
				$values[] = (string) ( $row['object_type'] ?? '' );
				$values[] = (string) ( $row['object_key'] ?? '' );
				$values[] = (string) ( $row['severity'] ?? self::SEVERITY_GREEN );
				$values[] = (string) ( $row['message'] ?? '' );
				$values[] = is_array( $row['raw_data'] ?? null )
					? (string) wp_json_encode( $row['raw_data'] )
					: ( $row['raw_data_json'] ?? '' );
				$values[] = is_array( $row['normalized_data'] ?? null )
					? (string) wp_json_encode( $row['normalized_data'] )
					: ( $row['normalized_data_json'] ?? '' );
				$values[] = $now;
			}
			$sql = "INSERT INTO `{$this->table()}` ( batch_key, row_number, action, object_type, object_key, severity, message, raw_data_json, normalized_data_json, created_at ) VALUES "
				. implode( ', ', $placeholders );
			$result = $this->wpdb->query( $this->wpdb->prepare( $sql, ...$values ) );
			if ( $result === false ) {
				throw new \RuntimeException( 'Failed to insert import rows: ' . (string) $this->wpdb->last_error );
			}
			$inserted += (int) $result;
		}
		return $inserted;
	}

	public function delete_for_batch( string $batch_key ): int {
		$result = $this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM `{$this->table()}` WHERE batch_key = %s", $batch_key )
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to delete import rows: ' . (string) $this->wpdb->last_error );
		}
		return (int) $result;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all_for_batch( string $batch_key ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$this->table()}` WHERE batch_key = %s ORDER BY row_number ASC",
				$batch_key
			),
			ARRAY_A
		) ?: [];
		return array_values( array_map( [ $this, 'hydrate' ], $rows ) );
	}

	/**
	 * Paginated row listing for the preview UI.
	 *
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list_for_batch( string $batch_key, array $filters = [], int $page = 1, int $per_page = 100 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		$where  = 'batch_key = %s';
		$params = [ $batch_key ];
		if ( ! empty( $filters['severity'] ) ) {
			$where   .= ' AND severity = %s';
			$params[] = (string) $filters['severity'];
		}

		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", ...$params )
		);

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE {$where} ORDER BY row_number ASC LIMIT %d OFFSET %d",
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
	 * @return array{green:int, yellow:int, red:int, insert:int, update:int, skip:int, delete:int, total:int}
	 */
	public function counts( string $batch_key ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT severity, action, COUNT(*) AS n FROM `{$this->table()}` WHERE batch_key = %s GROUP BY severity, action",
				$batch_key
			),
			ARRAY_A
		) ?: [];
		$out = [
			'green'  => 0, 'yellow' => 0, 'red' => 0,
			'insert' => 0, 'update' => 0, 'skip' => 0, 'delete' => 0,
			'total'  => 0,
		];
		foreach ( $rows as $row ) {
			$n  = (int) $row['n'];
			$sv = (string) $row['severity'];
			$ac = (string) $row['action'];
			if ( isset( $out[ $sv ] ) ) $out[ $sv ] += $n;
			if ( isset( $out[ $ac ] ) ) $out[ $ac ] += $n;
			$out['total'] += $n;
		}
		return $out;
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
		$raw = null;
		if ( isset( $row['raw_data_json'] ) && is_string( $row['raw_data_json'] ) && $row['raw_data_json'] !== '' ) {
			$d = json_decode( $row['raw_data_json'], true );
			$raw = is_array( $d ) ? $d : null;
		}
		$norm = null;
		if ( isset( $row['normalized_data_json'] ) && is_string( $row['normalized_data_json'] ) && $row['normalized_data_json'] !== '' ) {
			$d = json_decode( $row['normalized_data_json'], true );
			$norm = is_array( $d ) ? $d : null;
		}
		return [
			'id'             => (int) $row['id'],
			'batch_key'      => (string) ( $row['batch_key'] ?? '' ),
			'row_number'     => (int) ( $row['row_number'] ?? 0 ),
			'action'         => (string) ( $row['action'] ?? '' ),
			'object_type'    => (string) ( $row['object_type'] ?? '' ),
			'object_key'     => (string) ( $row['object_key'] ?? '' ),
			'severity'       => (string) ( $row['severity'] ?? '' ),
			'message'        => (string) ( $row['message'] ?? '' ),
			'raw_data'       => $raw,
			'normalized_data' => $norm,
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
		];
	}
}
