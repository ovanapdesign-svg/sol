<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class RuleRepository {

	public const TABLE = 'configkit_rules';

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

	public function key_exists_in_template( string $template_key, string $rule_key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND rule_key = %s LIMIT 1",
					$template_key,
					$rule_key
				)
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND rule_key = %s AND id <> %d LIMIT 1",
					$template_key,
					$rule_key,
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
				"SELECT * FROM `{$table}` WHERE template_key = %s ORDER BY priority ASC, sort_order ASC, name ASC",
				$template_key
			),
			ARRAY_A
		) ?: [];
		$items = array_values( array_map( [ $this, 'hydrate' ], $rows ) );
		return [ 'items' => $items, 'total' => count( $items ) ];
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
			throw new \RuntimeException( 'Failed to insert rule: ' . (string) $this->wpdb->last_error );
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
			throw new \RuntimeException( 'Failed to update rule: ' . (string) $this->wpdb->last_error );
		}
	}

	public function set_priority_and_sort( int $id, int $priority, int $sort_order ): void {
		$table = $this->table();
		$now   = $this->now();
		$result = $this->wpdb->update(
			$table,
			[
				'priority'     => $priority,
				'sort_order'   => $sort_order,
				'updated_at'   => $now,
				'version_hash' => sha1( $now . (string) $id ),
			],
			[ 'id' => $id ]
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to reorder rule: ' . (string) $this->wpdb->last_error );
		}
	}

	public function soft_delete( int $id ): void {
		$table = $this->table();
		$now   = $this->now();
		$result = $this->wpdb->update(
			$table,
			[ 'is_active' => 0, 'updated_at' => $now, 'version_hash' => sha1( $now . (string) $id ) ],
			[ 'id' => $id ]
		);
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to soft delete rule: ' . (string) $this->wpdb->last_error );
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
		$spec = $this->decode_object( $row['spec_json'] ?? '' );
		return [
			'id'           => (int) ( $row['id'] ?? 0 ),
			'template_key' => (string) ( $row['template_key'] ?? '' ),
			'rule_key'     => (string) ( $row['rule_key'] ?? '' ),
			'name'         => (string) ( $row['name'] ?? '' ),
			'spec'         => $spec,
			'priority'     => (int) ( $row['priority'] ?? 0 ),
			'is_active'    => (bool) (int) ( $row['is_active'] ?? 1 ),
			'sort_order'   => (int) ( $row['sort_order'] ?? 0 ),
			'created_at'   => (string) ( $row['created_at'] ?? '' ),
			'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
			'version_hash' => (string) ( $row['version_hash'] ?? '' ),
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		return [
			'template_key' => (string) ( $data['template_key'] ?? '' ),
			'rule_key'     => (string) ( $data['rule_key'] ?? '' ),
			'name'         => (string) ( $data['name'] ?? '' ),
			'spec_json'    => (string) wp_json_encode( (object) ( $data['spec'] ?? new \stdClass() ) ),
			'priority'     => (int) ( $data['priority'] ?? 100 ),
			'is_active'    => array_key_exists( 'is_active', $data ) && ! $data['is_active'] ? 0 : 1,
			'sort_order'   => (int) ( $data['sort_order'] ?? 0 ),
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
