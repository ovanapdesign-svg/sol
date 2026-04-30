<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.3 — wraps the per-product Simple Mode metadata stored in
 * `_configkit_pb_meta` post meta. The orchestrator reads + writes
 * this state to remember which entities (template, lookup table,
 * libraries, etc.) it has already created for a given product so
 * subsequent block saves can update those entities idempotently.
 *
 * Shape:
 *   {
 *     "product_type":     "markise",
 *     "builder_version":  1,
 *     "auto_managed":     true,
 *     "template_key":     "product_42_markise",
 *     "family_key":       "markiser",
 *     "lookup_table_key": "product_42_pricing",
 *     "fabric_library_key":   "product_42_fabrics",
 *     "color_library_key":    "product_42_colors",
 *     "stang_library_key":    "product_42_stangs",
 *     "motor_library_key":    "product_42_motors",
 *     "control_library_key":  "product_42_controls",
 *     "accessory_library_key":"product_42_accessories"
 *   }
 *
 * The wrapper uses an injectable storage callback so unit tests can
 * exercise it without booting WordPress.
 */
final class ProductBuilderState {

	public const META_KEY = '_configkit_pb_meta';

	public const ENTITY_KEYS = [
		'template_key',
		'family_key',
		'lookup_table_key',
		'fabric_library_key',
		'color_library_key',
		'stang_library_key',
		'motor_library_key',
		'control_library_key',
		'accessory_library_key',
	];

	/** @var (callable(int):array<string,mixed>) */
	private $reader;
	/** @var (callable(int,array<string,mixed>):void) */
	private $writer;

	/**
	 * @param (callable(int):array<string,mixed>)|null     $reader
	 * @param (callable(int,array<string,mixed>):void)|null $writer
	 */
	public function __construct( ?callable $reader = null, ?callable $writer = null ) {
		$this->reader = $reader ?? static function ( int $product_id ): array {
			$raw = function_exists( 'get_post_meta' ) ? \get_post_meta( $product_id, self::META_KEY, true ) : '';
			if ( is_array( $raw ) ) return $raw;
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) return $decoded;
			}
			return [];
		};
		$this->writer = $writer ?? static function ( int $product_id, array $data ): void {
			if ( ! function_exists( 'update_post_meta' ) ) return;
			\update_post_meta( $product_id, self::META_KEY, $data );
		};
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get( int $product_id ): array {
		return ( $this->reader )( $product_id );
	}

	/**
	 * @param array<string,mixed> $patch  merged on top of existing meta.
	 * @return array<string,mixed>        the post-merge state
	 */
	public function patch( int $product_id, array $patch ): array {
		$current = $this->get( $product_id );
		$next    = array_merge( $current, $patch );
		$next['auto_managed']    = true;
		$next['builder_version'] = (int) ( $next['builder_version'] ?? 1 );
		( $this->writer )( $product_id, $next );
		return $next;
	}

	public function get_string( int $product_id, string $key ): ?string {
		$state = $this->get( $product_id );
		$value = $state[ $key ] ?? null;
		return is_string( $value ) && $value !== '' ? $value : null;
	}

	public function product_type( int $product_id ): ?string {
		return $this->get_string( $product_id, 'product_type' );
	}

	public function is_auto_managed( int $product_id ): bool {
		$state = $this->get( $product_id );
		return ! empty( $state['auto_managed'] );
	}
}
