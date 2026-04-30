<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.4 — wraps the per-product section list stored in
 * `_configkit_pb_sections` post meta. Each section is a small
 * record that points at an underlying entity (lookup_table or
 * library) which the existing orchestrator (ProductBuilderService)
 * already knows how to provision. Drag-reorder updates `position`,
 * not the storage order.
 *
 * Section record:
 *   {
 *     id:               'sec_a8f2',     // owner-visible Element ID
 *     type:             'size_pricing'  // SectionTypeRegistry id
 *     label:            'Size pricing',
 *     position:         0,
 *     lookup_table_key: 'product_42_sec_a8f2'  (size_pricing only)
 *     library_key:      'product_42_sec_a8f2'  (every other type)
 *     visibility:       { mode: 'always' | 'when',
 *                         conditions: [{ section_id, op, value }],
 *                         match: 'all' | 'any' }
 *   }
 *
 * Storage callbacks are injectable so unit tests can drive the
 * service without booting WordPress.
 */
final class SectionListState {

	public const META_KEY = '_configkit_pb_sections';

	/** @var (callable(int):list<array<string,mixed>>) */
	private $reader;
	/** @var (callable(int,list<array<string,mixed>>):void) */
	private $writer;

	/**
	 * @param (callable(int):list<array<string,mixed>>)|null     $reader
	 * @param (callable(int,list<array<string,mixed>>):void)|null $writer
	 */
	public function __construct( ?callable $reader = null, ?callable $writer = null ) {
		$this->reader = $reader ?? static function ( int $product_id ): array {
			$raw = function_exists( 'get_post_meta' ) ? \get_post_meta( $product_id, self::META_KEY, true ) : '';
			if ( is_array( $raw ) ) return array_values( $raw );
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) return array_values( $decoded );
			}
			return [];
		};
		$this->writer = $writer ?? static function ( int $product_id, array $list ): void {
			if ( ! function_exists( 'update_post_meta' ) ) return;
			\update_post_meta( $product_id, self::META_KEY, array_values( $list ) );
		};
	}

	/** @return list<array<string,mixed>> */
	public function list( int $product_id ): array {
		$rows = ( $this->reader )( $product_id );
		usort( $rows, static fn ( $a, $b ) => ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) ) );
		return $rows;
	}

	/** @return array<string,mixed>|null */
	public function find( int $product_id, string $section_id ): ?array {
		foreach ( $this->list( $product_id ) as $row ) {
			if ( ( $row['id'] ?? '' ) === $section_id ) return $row;
		}
		return null;
	}

	/** @param array<string,mixed> $section */
	public function add( int $product_id, array $section ): void {
		$rows = $this->list( $product_id );
		$rows[] = $section;
		( $this->writer )( $product_id, $rows );
	}

	/** @param array<string,mixed> $patch */
	public function update( int $product_id, string $section_id, array $patch ): bool {
		$rows  = $this->list( $product_id );
		$found = false;
		foreach ( $rows as $i => $row ) {
			if ( ( $row['id'] ?? '' ) !== $section_id ) continue;
			$rows[ $i ] = array_merge( $row, $patch );
			$found = true;
			break;
		}
		if ( $found ) ( $this->writer )( $product_id, $rows );
		return $found;
	}

	public function remove( int $product_id, string $section_id ): bool {
		$rows  = $this->list( $product_id );
		$next  = array_values( array_filter( $rows, static fn ( $r ) => ( $r['id'] ?? '' ) !== $section_id ) );
		if ( count( $next ) === count( $rows ) ) return false;
		( $this->writer )( $product_id, $next );
		return true;
	}

	/** @param list<string> $ordered_ids */
	public function reorder( int $product_id, array $ordered_ids ): void {
		$rows  = $this->list( $product_id );
		$by_id = [];
		foreach ( $rows as $row ) $by_id[ $row['id'] ?? '' ] = $row;
		$next = [];
		$pos  = 0;
		foreach ( $ordered_ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$row             = $by_id[ $id ];
				$row['position'] = $pos++;
				$next[]          = $row;
				unset( $by_id[ $id ] );
			}
		}
		// Append any rows the caller didn't mention so we never lose
		// data on a partial reorder.
		foreach ( $by_id as $row ) {
			$row['position'] = $pos++;
			$next[]          = $row;
		}
		( $this->writer )( $product_id, $next );
	}

	/**
	 * Generate a section element_id deterministic per section type +
	 * a 4-char random suffix. Owner sees this in the modal but never
	 * edits it.
	 */
	public static function mint_id( string $type ): string {
		$suffix = substr( bin2hex( random_bytes( 2 ) ), 0, 4 );
		$slug   = preg_replace( '/[^a-z0-9]+/', '_', strtolower( $type ) ) ?? '';
		$slug   = trim( $slug, '_' );
		return 'sec_' . ( $slug !== '' ? $slug . '_' : '' ) . $suffix;
	}
}
