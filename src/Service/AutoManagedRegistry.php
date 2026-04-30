<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.3 — single source of truth for "this entity was created by
 * the Product Builder". Stored as one WordPress option so the admin
 * lists can render a 🔧 badge without per-entity column queries.
 *
 * Keys are namespaced by entity type:
 *   "module:textiles"             → { product_id, role }
 *   "library:product_42_fabrics"  → { product_id, role }
 *   "lookup_table:product_42_pricing" → { product_id, role }
 *   "template:product_42_markise" → { product_id, role }
 *
 * Storage is injectable so unit tests run without WordPress.
 */
final class AutoManagedRegistry {

	public const OPTION_KEY = 'configkit_pb_auto_managed_v1';

	public const TYPE_MODULE       = 'module';
	public const TYPE_LIBRARY      = 'library';
	public const TYPE_LOOKUP_TABLE = 'lookup_table';
	public const TYPE_TEMPLATE     = 'template';

	/** @var (callable():array<string,array<string,mixed>>) */
	private $reader;
	/** @var (callable(array<string,array<string,mixed>>):void) */
	private $writer;

	public function __construct( ?callable $reader = null, ?callable $writer = null ) {
		$this->reader = $reader ?? static function (): array {
			if ( ! function_exists( 'get_option' ) ) return [];
			$raw = \get_option( self::OPTION_KEY, [] );
			return is_array( $raw ) ? $raw : [];
		};
		$this->writer = $writer ?? static function ( array $data ): void {
			if ( ! function_exists( 'update_option' ) ) return;
			\update_option( self::OPTION_KEY, $data, false );
		};
	}

	public function mark( string $type, string $key, int $product_id, string $role = '' ): void {
		if ( $type === '' || $key === '' ) return;
		$data = ( $this->reader )();
		$data[ $type . ':' . $key ] = [
			'product_id' => $product_id,
			'role'       => $role,
		];
		( $this->writer )( $data );
	}

	public function unmark( string $type, string $key ): void {
		if ( $type === '' || $key === '' ) return;
		$data = ( $this->reader )();
		unset( $data[ $type . ':' . $key ] );
		( $this->writer )( $data );
	}

	public function is_auto_managed( string $type, string $key ): bool {
		if ( $type === '' || $key === '' ) return false;
		$data = ( $this->reader )();
		return isset( $data[ $type . ':' . $key ] );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function lookup( string $type, string $key ): ?array {
		if ( $type === '' || $key === '' ) return null;
		$data = ( $this->reader )();
		return $data[ $type . ':' . $key ] ?? null;
	}

	/**
	 * Snapshot grouped by entity type — what the admin lists need
	 * to render badges in one round-trip.
	 *
	 * @return array{
	 *   module:list<array{key:string,product_id:int,role:string}>,
	 *   library:list<array{key:string,product_id:int,role:string}>,
	 *   lookup_table:list<array{key:string,product_id:int,role:string}>,
	 *   template:list<array{key:string,product_id:int,role:string}>
	 * }
	 */
	public function snapshot(): array {
		$out = [
			'module'       => [],
			'library'      => [],
			'lookup_table' => [],
			'template'     => [],
		];
		foreach ( ( $this->reader )() as $compound => $row ) {
			$parts = explode( ':', (string) $compound, 2 );
			if ( count( $parts ) !== 2 ) continue;
			[ $type, $key ] = $parts;
			if ( ! isset( $out[ $type ] ) ) continue;
			$out[ $type ][] = [
				'key'        => $key,
				'product_id' => (int) ( $row['product_id'] ?? 0 ),
				'role'       => (string) ( $row['role'] ?? '' ),
			];
		}
		return $out;
	}
}
