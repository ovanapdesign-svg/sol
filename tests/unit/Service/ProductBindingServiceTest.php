<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\ProductBindingService;
use PHPUnit\Framework\TestCase;

final class ProductBindingServiceTest extends TestCase {

	private StubProductBindingRepository $repo;
	private ProductBindingService $service;
	private const PRODUCT_ID = 4242;

	protected function setUp(): void {
		$this->repo    = new StubProductBindingRepository();
		$this->repo->register_product( self::PRODUCT_ID );
		$this->service = new ProductBindingService( $this->repo );
	}

	public function test_get_returns_blank_state_for_unbound_product(): void {
		$record = $this->service->get( self::PRODUCT_ID );
		$this->assertNotNull( $record );
		$this->assertFalse( $record['enabled'] );
		$this->assertNull( $record['template_key'] );
		$this->assertSame( '', $record['version_hash'] );
	}

	public function test_get_returns_null_for_unknown_product(): void {
		$this->assertNull( $this->service->get( 99999 ) );
	}

	public function test_first_save_does_not_require_version_hash(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'enabled'      => true,
			'template_key' => 'markise_motorisert',
		], '' );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['record']['enabled'] );
		$this->assertSame( 'markise_motorisert', $result['record']['template_key'] );
		$this->assertNotEmpty( $result['record']['version_hash'] );
	}

	public function test_subsequent_save_requires_matching_version_hash(): void {
		$first  = $this->service->update( self::PRODUCT_ID, [ 'enabled' => true ], '' );
		$hash   = $first['record']['version_hash'];
		$second = $this->service->update( self::PRODUCT_ID, [ 'enabled' => false ], 'wrong-hash' );
		$this->assertFalse( $second['ok'] );
		$this->assertSame( 'conflict', $second['errors'][0]['code'] );

		$third = $this->service->update( self::PRODUCT_ID, [ 'enabled' => false ], $hash );
		$this->assertTrue( $third['ok'] );
		$this->assertFalse( $third['record']['enabled'] );
	}

	public function test_unknown_product_returns_not_found(): void {
		$result = $this->service->update( 99999, [ 'enabled' => true ], '' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_invalid_frontend_mode_is_rejected(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'enabled'       => true,
			'frontend_mode' => 'magic-mode',
		], '' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'frontend_mode', $result['errors'][0]['field'] );
	}

	public function test_negative_template_version_id_is_rejected(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'template_version_id' => -3,
		], '' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'template_version_id', $result['errors'][0]['field'] );
	}

	public function test_pricing_overrides_validate_numeric_and_enums(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'pricing_overrides' => [
				'minimum_price' => 'not-a-number',
				'sale_mode'     => 'fancy_sale',
				'vat_display'   => 'mars_units',
			],
		], '' );
		$this->assertFalse( $result['ok'] );
		$messages = array_column( $result['errors'], 'message' );
		$this->assertNotEmpty( array_filter( $messages, static fn( $m ) => str_contains( $m, 'minimum_price' ) ) );
		$this->assertNotEmpty( array_filter( $messages, static fn( $m ) => str_contains( $m, 'sale_mode' ) ) );
		$this->assertNotEmpty( array_filter( $messages, static fn( $m ) => str_contains( $m, 'vat_display' ) ) );
	}

	public function test_negative_pricing_value_is_rejected(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'pricing_overrides' => [ 'minimum_price' => -10 ],
		], '' );
		$this->assertFalse( $result['ok'] );
	}

	public function test_allowed_sources_must_be_array_per_field(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'allowed_sources' => [ 'fabric_color' => 'oops, a string' ],
		], '' );
		$this->assertFalse( $result['ok'] );
	}

	public function test_field_overrides_must_be_keyed_by_object(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'field_overrides' => [ 'fabric_color' => 'lock-it' ],
		], '' );
		$this->assertFalse( $result['ok'] );
	}

	public function test_item_price_overrides_round_trip_and_force_product_override_source(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'item_price_overrides' => [
				'textiles_dickson:6500-bw' => [ 'price' => 1290.0, 'reason' => 'Promo' ],
			],
		], '' );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$rec = $result['record'];
		$this->assertArrayHasKey( 'textiles_dickson:6500-bw', $rec['item_price_overrides'] );
		$this->assertSame( 1290.0, $rec['item_price_overrides']['textiles_dickson:6500-bw']['price'] );
		// Service must always force `price_source` on save so the engine
		// resolver can dispatch on it.
		$this->assertSame( 'product_override', $rec['item_price_overrides']['textiles_dickson:6500-bw']['price_source'] );
		$this->assertSame( 'Promo', $rec['item_price_overrides']['textiles_dickson:6500-bw']['reason'] );
	}

	public function test_item_price_override_with_blank_price_is_dropped(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'item_price_overrides' => [
				'textiles_dickson:6500-bw' => [ 'price' => '' ],
				'textiles_dickson:6500-cm' => [ 'price' => 999.0 ],
			],
		], '' );
		$this->assertTrue( $result['ok'] );
		$rec = $result['record'];
		$this->assertArrayNotHasKey( 'textiles_dickson:6500-bw', $rec['item_price_overrides'] );
		$this->assertArrayHasKey( 'textiles_dickson:6500-cm', $rec['item_price_overrides'] );
	}

	public function test_item_price_override_negative_price_is_rejected(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'item_price_overrides' => [
				'textiles_dickson:6500-bw' => [ 'price' => -50.0 ],
			],
		], '' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'item_price_overrides', $result['errors'][0]['field'] );
	}

	public function test_item_price_override_invalid_key_format_is_rejected(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'item_price_overrides' => [
				'no_colon_key' => [ 'price' => 100.0 ],
			],
		], '' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_key', $result['errors'][0]['code'] );
	}

	public function test_full_payload_round_trips(): void {
		$result = $this->service->update( self::PRODUCT_ID, [
			'enabled'              => true,
			'template_key'         => 'markise_motorisert',
			'template_version_id'  => 0,
			'lookup_table_key'     => 'markise_2d_v1',
			'family_key'           => 'markiser',
			'frontend_mode'        => 'stepper',
			'defaults'             => [ 'control_type' => 'manual' ],
			'allowed_sources'      => [
				'fabric_color' => [
					'allowed_libraries' => [ 'textiles_dickson' ],
					'excluded_items'    => [ 'textiles_dickson:6500-bw' ],
				],
			],
			'pricing_overrides'    => [
				'base_price_fallback' => 1500,
				'minimum_price'       => 999,
				'sale_mode'           => 'discount_percent',
				'discount_percent'    => 10,
				'vat_display'         => 'incl_vat',
				'allowed_price_groups' => [ 'A', 'B' ],
			],
			'field_overrides'      => [
				'control_type' => [ 'lock' => 'manual' ],
			],
		], '' );

		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$rec = $result['record'];
		$this->assertTrue( $rec['enabled'] );
		$this->assertSame( 'markise_motorisert', $rec['template_key'] );
		$this->assertSame( 'markiser', $rec['family_key'] );
		$this->assertSame( [ 'textiles_dickson:6500-bw' ], $rec['allowed_sources']['fabric_color']['excluded_items'] );
		$this->assertSame( 'manual', $rec['field_overrides']['control_type']['lock'] );
		$this->assertSame( 10, $rec['pricing_overrides']['discount_percent'] );
	}
}
