<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class FieldRepository {

	public const TABLE = 'configkit_fields';

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

	public function key_exists_in_template( string $template_key, string $field_key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND field_key = %s LIMIT 1",
					$template_key,
					$field_key
				)
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND field_key = %s AND id <> %d LIMIT 1",
					$template_key,
					$field_key,
					$exclude_id
				)
			);
		}
		return $value !== null;
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function list_in_step( string $template_key, string $step_key ): array {
		$table = $this->table();
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE template_key = %s AND step_key = %s ORDER BY sort_order ASC, label ASC",
				$template_key,
				$step_key
			),
			ARRAY_A
		) ?: [];

		$items = array_values( array_map( [ $this, 'hydrate' ], $rows ) );
		return [ 'items' => $items, 'total' => count( $items ) ];
	}

	public function max_sort_order( string $template_key, string $step_key ): int {
		$table = $this->table();
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT MAX(sort_order) FROM `{$table}` WHERE template_key = %s AND step_key = %s",
				$template_key,
				$step_key
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
			throw new \RuntimeException( 'Failed to insert field: ' . (string) $this->wpdb->last_error );
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
			throw new \RuntimeException( 'Failed to update field: ' . (string) $this->wpdb->last_error );
		}
	}

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
			throw new \RuntimeException( 'Failed to reorder field: ' . (string) $this->wpdb->last_error );
		}
	}

	/**
	 * Real DELETE — schema has no is_active for fields. When publish lands
	 * in B5, field state is snapshotted into template_versions.snapshot_json.
	 */
	public function delete( int $id ): void {
		$table  = $this->table();
		$result = $this->wpdb->delete( $table, [ 'id' => $id ] );
		if ( $result === false ) {
			throw new \RuntimeException( 'Failed to delete field: ' . (string) $this->wpdb->last_error );
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
			'id'                     => (int) ( $row['id'] ?? 0 ),
			'template_key'           => (string) ( $row['template_key'] ?? '' ),
			'step_key'               => (string) ( $row['step_key'] ?? '' ),
			'field_key'              => (string) ( $row['field_key'] ?? '' ),
			'label'                  => (string) ( $row['label'] ?? '' ),
			'helper_text'            => $row['helper_text'] !== null && $row['helper_text'] !== '' ? (string) $row['helper_text'] : null,
			'field_kind'             => (string) ( $row['field_kind'] ?? '' ),
			'input_type'             => $row['input_type'] !== null && $row['input_type'] !== '' ? (string) $row['input_type'] : null,
			'display_type'           => (string) ( $row['display_type'] ?? '' ),
			'value_source'           => (string) ( $row['value_source'] ?? '' ),
			'source_config'          => $this->decode_object( $row['source_config_json'] ?? '' ),
			'behavior'               => (string) ( $row['behavior'] ?? '' ),
			'pricing_mode'           => $row['pricing_mode'] !== null && $row['pricing_mode'] !== '' ? (string) $row['pricing_mode'] : null,
			'pricing_value'          => isset( $row['pricing_value'] ) && $row['pricing_value'] !== null && $row['pricing_value'] !== '' ? (float) $row['pricing_value'] : null,
			'is_required'            => (bool) (int) ( $row['is_required'] ?? 0 ),
			'default_value'          => $row['default_value'] !== null && $row['default_value'] !== '' ? (string) $row['default_value'] : null,
			'show_in_cart'           => (bool) (int) ( $row['show_in_cart'] ?? 1 ),
			'show_in_checkout'       => (bool) (int) ( $row['show_in_checkout'] ?? 1 ),
			'show_in_admin_order'    => (bool) (int) ( $row['show_in_admin_order'] ?? 1 ),
			'show_in_customer_email' => (bool) (int) ( $row['show_in_customer_email'] ?? 1 ),
			'sort_order'             => (int) ( $row['sort_order'] ?? 0 ),
			'created_at'             => (string) ( $row['created_at'] ?? '' ),
			'updated_at'             => (string) ( $row['updated_at'] ?? '' ),
			'version_hash'           => (string) ( $row['version_hash'] ?? '' ),
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dehydrate( array $data ): array {
		return [
			'template_key'           => (string) ( $data['template_key'] ?? '' ),
			'step_key'               => (string) ( $data['step_key'] ?? '' ),
			'field_key'               => (string) ( $data['field_key'] ?? '' ),
			'label'                   => (string) ( $data['label'] ?? '' ),
			'helper_text'             => $data['helper_text'] ?? null,
			'field_kind'              => (string) ( $data['field_kind'] ?? '' ),
			'input_type'              => isset( $data['input_type'] ) && $data['input_type'] !== '' ? (string) $data['input_type'] : null,
			'display_type'            => (string) ( $data['display_type'] ?? 'plain' ),
			'value_source'            => (string) ( $data['value_source'] ?? '' ),
			'source_config_json'      => (string) wp_json_encode( (object) ( $data['source_config'] ?? new \stdClass() ) ),
			'behavior'                => (string) ( $data['behavior'] ?? '' ),
			'pricing_mode'            => isset( $data['pricing_mode'] ) && $data['pricing_mode'] !== '' ? (string) $data['pricing_mode'] : null,
			'pricing_value'           => isset( $data['pricing_value'] ) && $data['pricing_value'] !== '' && $data['pricing_value'] !== null ? (float) $data['pricing_value'] : null,
			'is_required'             => ! empty( $data['is_required'] ) ? 1 : 0,
			'default_value'           => $data['default_value'] ?? null,
			'show_in_cart'            => array_key_exists( 'show_in_cart', $data ) && ! $data['show_in_cart'] ? 0 : 1,
			'show_in_checkout'        => array_key_exists( 'show_in_checkout', $data ) && ! $data['show_in_checkout'] ? 0 : 1,
			'show_in_admin_order'     => array_key_exists( 'show_in_admin_order', $data ) && ! $data['show_in_admin_order'] ? 0 : 1,
			'show_in_customer_email'  => array_key_exists( 'show_in_customer_email', $data ) && ! $data['show_in_customer_email'] ? 0 : 1,
			'sort_order'              => (int) ( $data['sort_order'] ?? 0 ),
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
