<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\FieldRepository;

final class StubFieldRepository extends FieldRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];
	private int $next_id = 1;

	public function __construct() {}

	public function find_by_id( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function key_exists_in_template( string $template_key, string $field_key, ?int $exclude_id = null ): bool {
		foreach ( $this->records as $rec ) {
			if (
				$rec['template_key'] === $template_key
				&& $rec['field_key'] === $field_key
				&& $rec['id'] !== $exclude_id
			) {
				return true;
			}
		}
		return false;
	}

	public function list_in_step( string $template_key, string $step_key ): array {
		$items = array_values( array_filter(
			$this->records,
			static fn( array $r ): bool => $r['template_key'] === $template_key && $r['step_key'] === $step_key
		) );
		usort(
			$items,
			static fn( array $a, array $b ): int => ( (int) $a['sort_order'] ) <=> ( (int) $b['sort_order'] )
		);
		return [ 'items' => $items, 'total' => count( $items ) ];
	}

	public function max_sort_order( string $template_key, string $step_key ): int {
		$max = 0;
		foreach ( $this->records as $rec ) {
			if (
				$rec['template_key'] === $template_key
				&& $rec['step_key'] === $step_key
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
				'id'                     => $id,
				'helper_text'            => null,
				'input_type'             => null,
				'pricing_mode'           => null,
				'pricing_value'          => null,
				'is_required'            => false,
				'default_value'          => null,
				'show_in_cart'           => true,
				'show_in_checkout'       => true,
				'show_in_admin_order'    => true,
				'show_in_customer_email' => true,
				'sort_order'             => 0,
				'created_at'             => $now,
				'updated_at'             => $now,
				'version_hash'           => sha1( $now . $id ),
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

	public function set_sort_order( int $id, int $sort_order ): void {
		if ( ! isset( $this->records[ $id ] ) ) {
			return;
		}
		$now = '2026-04-30 12:00:00';
		$this->records[ $id ]['sort_order']    = $sort_order;
		$this->records[ $id ]['updated_at']    = $now;
		$this->records[ $id ]['version_hash']  = sha1( $now . $id );
	}

	public function delete( int $id ): void {
		unset( $this->records[ $id ] );
	}
}
