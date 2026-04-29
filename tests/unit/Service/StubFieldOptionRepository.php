<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\FieldOptionRepository;

final class StubFieldOptionRepository extends FieldOptionRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function key_exists_in_field( string $template_key, string $field_key, string $option_key, ?int $exclude_id = null ): bool {
		foreach ( $this->records as $rec ) {
			if (
				$rec['template_key'] === $template_key
				&& $rec['field_key'] === $field_key
				&& $rec['option_key'] === $option_key
				&& $rec['id'] !== $exclude_id
			) {
				return true;
			}
		}
		return false;
	}

	public function list_for_field( string $template_key, string $field_key ): array {
		$items = array_values( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['template_key'] === $template_key && $r['field_key'] === $field_key
		) );
		usort(
			$items,
			static fn( array $a, array $b ): int => ( (int) $a['sort_order'] ) <=> ( (int) $b['sort_order'] )
		);
		return [ 'items' => $items, 'total' => count( $items ) ];
	}

	public function max_sort_order( string $template_key, string $field_key ): int {
		$max = 0;
		foreach ( $this->records as $rec ) {
			if (
				$rec['template_key'] === $template_key
				&& $rec['field_key'] === $field_key
				&& (int) $rec['sort_order'] > $max
			) {
				$max = (int) $rec['sort_order'];
			}
		}
		return $max;
	}

	public function create( array $data ): int {
		$id  = $this->next_id++;
		$now = '2026-04-30 10:00:00';
		$this->records[ $id ] = array_merge(
			[
				'id'           => $id,
				'price'        => null,
				'sale_price'   => null,
				'image_url'    => null,
				'description'  => null,
				'is_active'    => true,
				'sort_order'   => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
				'version_hash' => sha1( $now . $id ),
			],
			$data,
			[ 'id' => $id, 'version_hash' => sha1( $now . $id ) ]
		);
		return $id;
	}

	public function update( int $id, array $data ): void {
		if ( ! isset( $this->records[ $id ] ) ) {
			return;
		}
		$now = '2026-04-30 11:00:00';
		$this->records[ $id ] = array_merge(
			$this->records[ $id ],
			$data,
			[ 'id' => $id, 'updated_at' => $now, 'version_hash' => sha1( $now . $id ) ]
		);
	}

	public function soft_delete( int $id ): void {
		if ( ! isset( $this->records[ $id ] ) ) {
			return;
		}
		$now = '2026-04-30 12:00:00';
		$this->records[ $id ]['is_active']     = false;
		$this->records[ $id ]['updated_at']    = $now;
		$this->records[ $id ]['version_hash']  = sha1( $now . $id );
	}
}
