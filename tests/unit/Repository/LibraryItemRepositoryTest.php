<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Repository;

use ConfigKit\Repository\LibraryItemRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 4.2b.1 — round-trip coverage for the six new columns
 * introduced by migration 0017 (price_source, bundle_fixed_price,
 * item_type, bundle_components_json, cart_behavior,
 * admin_order_display).
 *
 * Uses reflection to exercise the private hydrate / dehydrate
 * helpers — these are the contract surface for the new columns;
 * full integration via wpdb is covered indirectly by the service
 * tests (which use the in-memory stub repo).
 */
final class LibraryItemRepositoryTest extends TestCase {

	private function repo(): LibraryItemRepository {
		// The constructor takes \wpdb; we never call its DB methods,
		// only the private serialisation helpers.
		return new LibraryItemRepository( new \wpdb() );
	}

	private function callPrivate( object $object, string $method, array $args ) {
		$ref = new ReflectionClass( $object );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $object, ...$args );
	}

	public function test_hydrate_reads_pricing_bundle_columns(): void {
		$row = [
			'id'                     => '7',
			'library_key'            => 'motors_somfy',
			'item_key'               => 'somfy_io_premium',
			'label'                  => 'Somfy IO Premium',
			'price'                  => '4500.00',
			'price_source'           => 'configkit',
			'bundle_fixed_price'     => null,
			'item_type'              => 'simple_option',
			'bundle_components_json' => '',
			'cart_behavior'          => null,
			'admin_order_display'    => null,
			'is_active'              => '1',
		];
		$hydrated = $this->callPrivate( $this->repo(), 'hydrate', [ $row ] );
		$this->assertSame( 'configkit', $hydrated['price_source'] );
		$this->assertNull( $hydrated['bundle_fixed_price'] );
		$this->assertSame( 'simple_option', $hydrated['item_type'] );
		$this->assertSame( [], $hydrated['bundle_components'] );
		$this->assertNull( $hydrated['cart_behavior'] );
		$this->assertNull( $hydrated['admin_order_display'] );
	}

	public function test_hydrate_decodes_bundle_components_json(): void {
		$json = json_encode( [
			[ 'component_key' => 'motor', 'woo_product_id' => 123, 'qty' => 1, 'price_source' => 'woo' ],
		] );
		$row = [
			'item_type'              => 'bundle',
			'price_source'           => 'bundle_sum',
			'bundle_components_json' => $json,
			'cart_behavior'          => 'price_inside_main',
			'admin_order_display'    => 'expanded',
		];
		$hydrated = $this->callPrivate( $this->repo(), 'hydrate', [ $row ] );
		$this->assertSame( 'bundle', $hydrated['item_type'] );
		$this->assertCount( 1, $hydrated['bundle_components'] );
		$this->assertSame( 123, $hydrated['bundle_components'][0]['woo_product_id'] );
		$this->assertSame( 'price_inside_main', $hydrated['cart_behavior'] );
		$this->assertSame( 'expanded', $hydrated['admin_order_display'] );
	}

	public function test_hydrate_defaults_legacy_rows_to_simple_configkit(): void {
		// Simulate a row from before migration 0017 ran (columns
		// missing entirely). Hydrate must apply documented defaults
		// rather than blow up.
		$row = [
			'id'          => '42',
			'library_key' => 'old_lib',
			'item_key'    => 'legacy',
			'label'       => 'Legacy item',
			'price'       => '100',
		];
		$hydrated = $this->callPrivate( $this->repo(), 'hydrate', [ $row ] );
		$this->assertSame( 'configkit', $hydrated['price_source'] );
		$this->assertSame( 'simple_option', $hydrated['item_type'] );
		$this->assertSame( [], $hydrated['bundle_components'] );
	}

	public function test_dehydrate_writes_pricing_bundle_columns(): void {
		$data = [
			'library_key'   => 'packages',
			'item_key'      => 'somfy_io_premium_pakke',
			'label'         => 'Somfy IO Premium Pakke',
			'item_type'     => 'bundle',
			'price_source'  => 'fixed_bundle',
			'bundle_fixed_price' => 8990.0,
			'cart_behavior' => 'price_inside_main',
			'admin_order_display' => 'expanded',
			'bundle_components' => [
				[ 'component_key' => 'motor', 'woo_product_id' => 123, 'qty' => 1, 'price_source' => 'woo' ],
			],
		];
		$row = $this->callPrivate( $this->repo(), 'dehydrate', [ $data ] );
		$this->assertSame( 'bundle', $row['item_type'] );
		$this->assertSame( 'fixed_bundle', $row['price_source'] );
		$this->assertSame( 8990.0, $row['bundle_fixed_price'] );
		$this->assertSame( 'price_inside_main', $row['cart_behavior'] );
		$this->assertSame( 'expanded', $row['admin_order_display'] );
		$this->assertIsString( $row['bundle_components_json'] );
		$decoded = json_decode( (string) $row['bundle_components_json'], true );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 123, $decoded[0]['woo_product_id'] );
	}

	public function test_dehydrate_clears_bundle_fields_when_simple_option(): void {
		$data = [
			'library_key'        => 'colors',
			'item_key'           => 'red',
			'label'              => 'Red',
			'item_type'          => 'simple_option',
			'price_source'       => 'configkit',
			// stale bundle fields the owner forgot to clear
			'bundle_fixed_price' => 8990.0,
			'cart_behavior'      => 'add_child_lines',
			'admin_order_display' => 'collapsed',
			'bundle_components'  => [
				[ 'component_key' => 'leftover', 'woo_product_id' => 1, 'qty' => 1 ],
			],
		];
		$row = $this->callPrivate( $this->repo(), 'dehydrate', [ $data ] );
		$this->assertSame( 'simple_option', $row['item_type'] );
		$this->assertNull( $row['bundle_fixed_price'] );
		$this->assertNull( $row['cart_behavior'] );
		$this->assertNull( $row['admin_order_display'] );
		$this->assertNull( $row['bundle_components_json'] );
	}

	public function test_dehydrate_default_price_source_is_configkit(): void {
		$row = $this->callPrivate( $this->repo(), 'dehydrate', [
			[ 'library_key' => 'l', 'item_key' => 'k', 'label' => 'L' ],
		] );
		$this->assertSame( 'configkit', $row['price_source'] );
		$this->assertSame( 'simple_option', $row['item_type'] );
	}
}
