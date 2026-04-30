<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.3b half B — wraps the per-product setup-source / preset
 * link / overrides storage in `_configkit_setup_source` post meta.
 *
 * Structural deviation from the spec (which proposed four separate
 * meta keys + a column on ProductBindingRepository): we collapse the
 * four owner-facing fields (setup_source, preset_id,
 * source_product_id, overrides) into ONE post meta record so a save
 * is a single update_post_meta call. ProductBindingRepository stays
 * untouched because setup_source is a builder-time concern; the
 * runtime binding (DB table) is consumed by the frontend renderer
 * + cart and shouldn't carry builder lifecycle state.
 *
 * Shape:
 *   {
 *     "setup_source":      "start_blank" | "use_preset" | "link_to_setup",
 *     "preset_id":         int,                          // when use_preset
 *     "source_product_id": int,                          // when link_to_setup
 *     "overrides":         array<string,mixed>           // flat-path map
 *   }
 *
 * Overrides keys are dotted paths so resetOverride / writeOverride
 * can address an individual key without walking nested maps:
 *   - lookup_table_key                                  → string
 *   - price_overrides.{type}.{pos}.{item_key}.price     → number
 *   - hidden_options.{type}.{pos}                       → list<string>
 *   - default_values.{type}.{pos}.{field}               → mixed
 *   - min_dimensions.{type}.{pos}.{field}               → mixed
 *   - max_dimensions.{type}.{pos}.{field}               → mixed
 */
final class SetupSourceState {

	public const META_KEY = '_configkit_setup_source';

	public const SOURCE_BLANK   = 'start_blank';
	public const SOURCE_PRESET  = 'use_preset';
	public const SOURCE_LINK    = 'link_to_setup';

	public const ALL_SOURCES = [
		self::SOURCE_BLANK,
		self::SOURCE_PRESET,
		self::SOURCE_LINK,
	];

	/** @var (callable(int):array<string,mixed>) */
	private $reader;
	/** @var (callable(int,array<string,mixed>):void) */
	private $writer;

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
	 * @return array{setup_source:string,preset_id:int,source_product_id:int,overrides:array<string,mixed>}
	 */
	public function get( int $product_id ): array {
		$raw = ( $this->reader )( $product_id );
		$source = isset( $raw['setup_source'] ) && in_array( $raw['setup_source'], self::ALL_SOURCES, true )
			? (string) $raw['setup_source']
			: self::SOURCE_BLANK;
		return [
			'setup_source'      => $source,
			'preset_id'         => (int) ( $raw['preset_id'] ?? 0 ),
			'source_product_id' => (int) ( $raw['source_product_id'] ?? 0 ),
			'overrides'         => is_array( $raw['overrides'] ?? null ) ? $raw['overrides'] : [],
		];
	}

	/**
	 * @param array<string,mixed> $patch
	 */
	public function patch( int $product_id, array $patch ): array {
		$current = $this->get( $product_id );
		$next = array_merge( $current, $patch );
		// Make sure we never persist nonsense: setup_source is bounded;
		// preset_id / source_product_id default to 0 when absent.
		if ( ! in_array( $next['setup_source'] ?? '', self::ALL_SOURCES, true ) ) {
			$next['setup_source'] = self::SOURCE_BLANK;
		}
		$next['preset_id']         = (int) ( $next['preset_id'] ?? 0 );
		$next['source_product_id'] = (int) ( $next['source_product_id'] ?? 0 );
		if ( ! is_array( $next['overrides'] ?? null ) ) $next['overrides'] = [];
		( $this->writer )( $product_id, $next );
		return $next;
	}

	/**
	 * Set a single override at the supplied dotted path. Empty values
	 * are silently dropped — owners use resetOverride to remove keys.
	 */
	public function set_override( int $product_id, string $path, mixed $value ): array {
		$path = trim( $path );
		if ( $path === '' ) return $this->get( $product_id );
		$current = $this->get( $product_id );
		$current['overrides'][ $path ] = $value;
		( $this->writer )( $product_id, $current );
		return $current;
	}

	/**
	 * Remove a single override key. Returns true if the key existed,
	 * false otherwise (so callers can surface a useful 404).
	 */
	public function unset_override( int $product_id, string $path ): bool {
		$path = trim( $path );
		if ( $path === '' ) return false;
		$current = $this->get( $product_id );
		if ( ! array_key_exists( $path, $current['overrides'] ) ) return false;
		unset( $current['overrides'][ $path ] );
		( $this->writer )( $product_id, $current );
		return true;
	}

	public function clear( int $product_id ): void {
		( $this->writer )( $product_id, [
			'setup_source'      => self::SOURCE_BLANK,
			'preset_id'         => 0,
			'source_product_id' => 0,
			'overrides'         => [],
		] );
	}
}
