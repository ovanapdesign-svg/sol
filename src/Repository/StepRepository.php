<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class StepRepository {

	public const TABLE = 'configkit_steps';

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

	public function key_exists_in_template( string $template_key, string $step_key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND step_key = %s LIMIT 1",
					$template_key,
					$step_key
				)
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND step_key = %s AND id <> %d LIMIT 1",
					$template_key,
					$step_key,
					$exclude_id
				)
			);
		}
		return $value !== null;
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function list_in_template( string $template_key ): array {
		$table = $this->table();
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE template_key = %s ORDER BY sort_order ASC, label ASC",
				$template_key
			),
			ARRAY_A
		) ?: [];

		$items = array_values( array_map( [ $this, 'hydrate' ], $rows ) );
		return [
			'items' => $items,
			'total' => count( $items ),
		];
	}

	public function max_sort_order( string $template_key ): int {
		$table = $this->table();
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT MAX(sort_order) FROM `{$table}` WHERE template_key = %s",
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
		$row   = $this->dehydrate( $data );
		$row['created_at']   = $now;
		$row['updated_at']   = $now;
		$row['version_hash'] = '';

		$ok = $this->wpdb->insert( $table, $row );
		if ( $ok === false || $ok === 0 ) {
			throw new \RuntimeException( 'Failed to insert step: ' . (string) $this->wpdb->last_error );
		}
		$id = (int) $this->wpdb->insert_id;
		$this->wpdb->update( $table, [ 'version_hash' => sha1( $now . (string) $id ) ], [ 'id' => $id ] );
		return $id;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): void {
		$table = $this->table();
		$now   = $this->now();
		$row   = $this->dehydrate( $data );
		$row['updated_at']   = $now;
		$row['version_hash'] = sha1( $now . (string) $id );
		unset( $row['created_at'] );

		$result = $this->wpdb->update( $table, $row, [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to update step: ' . (string) $this->wpdb->last_error );
		}
	}

	/**
	 * Updates only the sort_order on a single step. Touches updated_at +
	 * version_hash so two clients reordering the same step still trip
	 * optimistic locking elsewhere.
	 */
	public function set_sort_order( int $id, int $sort_order ): void {
		$table = $this->table();
		$now   = $this->now();
		$result = $this->wpdb->update(
			$table,
			[
				'sort_order'   => $sort_order,
				'updated_at'   => $now,
				'version_hash' => sha1( $now . (string) $id ),
			],
			[ 'id' => $id ]
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to reorder step: ' . (string) $this->wpdb->last_error );
		}
	}

	/**
	 * Real DELETE. The schema has no is_active column for steps; when
	 * publishing lands in B5, deleted steps stay in the version snapshot
	 * via wp_configkit_template_versions.
	 */
	public function delete( int $id ): void {
		$table  = $this->table();
		$result = $this->wpdb->delete( $table, [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to delete step: ' . (string) $this->wpdb->last_error );
		}
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
		return [
			'id'                       => (int) ( $row['id'] ?? 0 ),
			'template_key'             => (string) ( $row['template_key'] ?? '' ),
			'step_key'                 => (string) ( $row['step_key'] ?? '' ),
			'label'                    => (string) ( $row['label'] ?? '' ),
			'description'              => $row['description'] !== null && $row['description'] !== '' ? (string) $row['description'] : null,
			'sort_order'               => (int) ( $row['sort_order'] ?? 0 ),
			'is_required'              => (bool) (int) ( $row['is_required'] ?? 0 ),
			'is_collapsed_by_default'  => (bool) (int) ( $row['is_collapsed_by_default'] ?? 0 ),
			'created_at'               => (string) ( $row['created_at'] ?? '' ),
			'updated_at'               => (string) ( $row['updated_at'] ?? '' ),
			'version_hash'             => (string) ( $row['version_hash'] ?? '' ),
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		return [
			'template_key'            => (string) ( $data['template_key'] ?? '' ),
			'step_key'                => (string) ( $data['step_key'] ?? '' ),
			'label'                   => (string) ( $data['label'] ?? '' ),
			'description'             => $data['description'] ?? null,
			'sort_order'              => (int) ( $data['sort_order'] ?? 0 ),
			'is_required'             => ! empty( $data['is_required'] ) ? 1 : 0,
			'is_collapsed_by_default' => ! empty( $data['is_collapsed_by_default'] ) ? 1 : 0,
		];
	}
}
