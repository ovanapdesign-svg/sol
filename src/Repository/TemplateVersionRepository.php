<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class TemplateVersionRepository {

	public const TABLE = 'configkit_template_versions';

	public function __construct( private \wpdb $wpdb ) {}

	public function find_by_id( int $id ): ?array {
		$table = $this->table();
		/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function find_by_template_and_number( string $template_key, int $version_number ): ?array {
		$table = $this->table();
		/** @var array<string,mixed>|null $row */
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE template_key = %s AND version_number = %d",
				$template_key,
				$version_number
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function list_for_template( string $template_key ): array {
		$table = $this->table();
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE template_key = %s ORDER BY version_number DESC",
				$template_key
			),
			ARRAY_A
		) ?: [];
		$items = array_values( array_map( [ $this, 'hydrate' ], $rows ) );
		return [ 'items' => $items, 'total' => count( $items ) ];
	}

	public function max_version_number( string $template_key ): int {
		$table = $this->table();
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT MAX(version_number) FROM `{$table}` WHERE template_key = %s",
				$template_key
			)
		);
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		$table = $this->table();
		$now   = $this->now();
		$row   = [
			'template_key'   => (string) ( $data['template_key'] ?? '' ),
			'version_number' => (int) ( $data['version_number'] ?? 1 ),
			'status'         => (string) ( $data['status'] ?? 'published' ),
			'snapshot_json'  => (string) ( $data['snapshot_json'] ?? '{}' ),
			'published_at'   => $data['published_at'] ?? $now,
			'published_by'   => $data['published_by'] ?? null,
			'notes'          => $data['notes'] ?? null,
			'created_at'     => $now,
		];

		$ok = $this->wpdb->insert( $table, $row );
		if ( $ok === false || $ok === 0 ) {
			throw new \RuntimeException( 'Failed to insert template version: ' . (string) $this->wpdb->last_error );
		}
		return (int) $this->wpdb->insert_id;
	}

	private function table(): string {
		return $this->wpdb->prefix . self::TABLE;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? (string) \current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$snapshot = $this->decode_object( $row['snapshot_json'] ?? '' );
		return [
			'id'             => (int) ( $row['id'] ?? 0 ),
			'template_key'   => (string) ( $row['template_key'] ?? '' ),
			'version_number' => (int) ( $row['version_number'] ?? 0 ),
			'status'         => (string) ( $row['status'] ?? 'published' ),
			'snapshot'       => $snapshot,
			'published_at'   => $row['published_at'] !== null && $row['published_at'] !== '' ? (string) $row['published_at'] : null,
			'published_by'   => isset( $row['published_by'] ) && $row['published_by'] !== null ? (int) $row['published_by'] : null,
			'notes'          => $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : null,
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decode_object( mixed $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
