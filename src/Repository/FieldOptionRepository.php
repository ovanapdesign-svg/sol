<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

class FieldOptionRepository {

	public const TABLE = 'configkit_field_options';

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

	public function key_exists_in_field( string $template_key, string $field_key, string $option_key, ?int $exclude_id = null ): bool {
		$table = $this->table();
		if ( $exclude_id === null ) {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND field_key = %s AND option_key = %s LIMIT 1",
					$template_key,
					$field_key,
					$option_key
				)
			);
		} else {
			$value = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE template_key = %s AND field_key = %s AND option_key = %s AND id <> %d LIMIT 1",
					$template_key,
					$field_key,
					$option_key,
					$exclude_id
				)
			);
		}
		return $value !== null;
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function list_for_field( string $template_key, string $field_key ): array {
		$table = $this->table();
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE template_key = %s AND field_key = %s ORDER BY sort_order ASC, label ASC",
				$template_key,
				$field_key
			),
			ARRAY_A
		) ?: [];

		$items = array_values( array_map( [ $this, 'hydrate' ], $rows ) );
		return [ 'items' => $items, 'total' => count( $items ) ];
	}

	public function max_sort_order( string $template_key, string $field_key ): int {
		$table = $this->table();
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT MAX(sort_order) FROM `{$table}` WHERE template_key = %s AND field_key = %s",
				$template_key,
				$field_key
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
			throw new \RuntimeException( 'Failed to insert field option: ' . (string) $this->wpdb->last_error );
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
			throw new \RuntimeException( 'Failed to update field option: ' . (string) $this->wpdb->last_error );
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
			throw new \RuntimeException( 'Failed to soft delete field option: ' . (string) $this->wpdb->last_error );
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
			'id'           => (int) ( $row['id'] ?? 0 ),
			'template_key' => (string) ( $row['template_key'] ?? '' ),
			'field_key'    => (string) ( $row['field_key'] ?? '' ),
			'option_key'   => (string) ( $row['option_key'] ?? '' ),
			'label'        => (string) ( $row['label'] ?? '' ),
			'price'        => isset( $row['price'] ) && $row['price'] !== null ? (float) $row['price'] : null,
			'sale_price'   => isset( $row['sale_price'] ) && $row['sale_price'] !== null ? (float) $row['sale_price'] : null,
			'image_url'    => $row['image_url'] !== null && $row['image_url'] !== '' ? (string) $row['image_url'] : null,
			'description'  => $row['description'] !== null && $row['description'] !== '' ? (string) $row['description'] : null,
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
			'field_key'    => (string) ( $data['field_key'] ?? '' ),
			'option_key'   => (string) ( $data['option_key'] ?? '' ),
			'label'        => (string) ( $data['label'] ?? '' ),
			'price'        => isset( $data['price'] ) && $data['price'] !== '' && $data['price'] !== null ? (float) $data['price'] : null,
			'sale_price'   => isset( $data['sale_price'] ) && $data['sale_price'] !== '' && $data['sale_price'] !== null ? (float) $data['sale_price'] : null,
			'image_url'    => $data['image_url'] ?? null,
			'description'  => $data['description'] ?? null,
			'is_active'    => array_key_exists( 'is_active', $data ) && ! $data['is_active'] ? 0 : 1,
			'sort_order'   => (int) ( $data['sort_order'] ?? 0 ),
		];
	}
}
