<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\LibraryItemService;
use ConfigKit\Service\LibraryService;
use ConfigKit\Service\ModuleService;
use PHPUnit\Framework\TestCase;

final class LibraryItemServiceTest extends TestCase {

	private StubLibraryItemRepository $itemRepo;
	private StubLibraryRepository $libRepo;
	private StubModuleRepository $modRepo;
	private LibraryItemService $service;
	private int $library_id;

	protected function setUp(): void {
		$this->itemRepo = new StubLibraryItemRepository();
		$this->libRepo  = new StubLibraryRepository();
		$this->modRepo  = new StubModuleRepository();
		$this->service  = new LibraryItemService( $this->itemRepo, $this->libRepo, $this->modRepo );

		// Seed module + library
		$modSvc = new ModuleService( $this->modRepo );
		$modSvc->create( [
			'module_key'              => 'textiles',
			'name'                    => 'Textiles',
			'allowed_field_kinds'     => [ 'input' ],
			'attribute_schema'        => [
				'fabric_code' => 'string',
				'blackout'    => 'boolean',
			],
			'is_active'               => true,
			'supports_sku'            => true,
			'supports_image'          => true,
			'supports_price'          => true,
			'supports_filters'        => true,
			'supports_compatibility'  => true,
			'supports_price_group'    => true,
			'supports_brand'          => true,
		] );
		$libSvc = new LibraryService( $this->libRepo, $this->modRepo );
		$created = $libSvc->create( [
			'library_key' => 'textiles_dickson',
			'module_key'  => 'textiles',
			'name'        => 'Dickson Orchestra',
			'brand'       => 'Dickson',
			'is_active'   => true,
		] );
		$this->library_id = $created['id'];
	}

	private function valid_item( array $overrides = [] ): array {
		return array_replace(
			[
				'item_key'        => 'u171',
				'label'           => 'Dickson U171',
				'sku'             => 'DCK-U171',
				'image_url'       => 'https://example.com/u171.png',
				'price'           => 1200,
				'price_group_key' => 'II',
				'filters'         => [ 'eco', 'popular' ],
				'compatibility'   => [ 'markise' ],
				'attributes'      => [
					'fabric_code' => 'U171',
					'blackout'    => false,
				],
				'is_active'       => true,
			],
			$overrides
		);
	}

	public function test_create_duplicate_sku_within_library_is_rejected(): void {
		// Phase 4 dalis 4 BUG 4 — owner used to get "validation_failed"
		// with no clue why. Now the duplicate SKU surfaces by name.
		$first = $this->service->create( $this->library_id, $this->valid_item() );
		$this->assertTrue( $first['ok'], 'first create failed: ' . json_encode( $first['errors'] ?? [] ) );

		$second = $this->service->create( $this->library_id, $this->valid_item( [
			'item_key' => 'u172',
			'sku'      => 'DCK-U171', // same SKU as the first row
		] ) );
		$this->assertFalse( $second['ok'] );
		$codes = array_column( $second['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
		$dup = array_values( array_filter( $second['errors'], static fn ( $e ) => ( $e['field'] ?? '' ) === 'sku' ) );
		$this->assertNotEmpty( $dup, 'expected an sku-field error' );
		$this->assertStringContainsString( 'DCK-U171', $dup[0]['message'] );
	}

	public function test_create_with_valid_item_succeeds(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'u171', $result['record']['item_key'] );
		$this->assertSame( 'DCK-U171', $result['record']['sku'] );
		$this->assertSame( 1200.0, $result['record']['price'] );
	}

	public function test_create_unknown_library_returns_library_not_found(): void {
		$result = $this->service->create( 9999, $this->valid_item() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'library_not_found', $result['errors'][0]['code'] );
	}

	public function test_create_missing_item_key_returns_required(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [ 'item_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'required', $result['errors'][0]['code'] );
	}

	public function test_create_two_char_item_key_is_rejected(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [ 'item_key' => 'aa' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_reserved_item_key_is_rejected(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [ 'item_key' => 'default' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'reserved', $codes );
	}

	public function test_create_duplicate_item_key_within_library_returns_duplicate(): void {
		$this->service->create( $this->library_id, $this->valid_item() );
		$result = $this->service->create( $this->library_id, $this->valid_item() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_with_unsupported_capability_field_is_rejected(): void {
		// Create a module that does NOT support sku.
		$modSvc = new ModuleService( $this->modRepo );
		$modSvc->create( [
			'module_key'          => 'sides',
			'name'                => 'Sides',
			'allowed_field_kinds' => [ 'input' ],
			'attribute_schema'    => [],
			'is_active'           => true,
		] );
		$libSvc = new LibraryService( $this->libRepo, $this->modRepo );
		$lib    = $libSvc->create( [
			'library_key' => 'sides_default',
			'module_key'  => 'sides',
			'name'        => 'Sides',
			'is_active'   => true,
		] );

		$result = $this->service->create( $lib['id'], [
			'item_key' => 'left',
			'label'    => 'Left',
			'sku'      => 'SIDE-L',
		] );

		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unsupported_capability', $codes );
	}

	public function test_create_attribute_outside_schema_is_rejected(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'attributes' => [ 'fabric_code' => 'U171', 'unknown_attr' => 'X' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unknown_attribute', $codes );
	}

	public function test_create_attribute_type_mismatch_is_rejected(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'attributes' => [ 'fabric_code' => 'U171', 'blackout' => 'yes' /* should be boolean */ ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'attribute_type_mismatch', $codes );
	}

	public function test_create_invalid_price_type_is_rejected(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'price' => 'not-a-number',
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_type', $codes );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->library_id, $this->valid_item() );
		$result  = $this->service->update(
			$this->library_id,
			$created['id'],
			$this->valid_item( [ 'label' => 'U171 (renamed)' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'U171 (renamed)', $result['record']['label'] );
	}

	public function test_update_with_stale_version_hash_returns_conflict(): void {
		$created = $this->service->create( $this->library_id, $this->valid_item() );
		$result  = $this->service->update(
			$this->library_id,
			$created['id'],
			$this->valid_item( [ 'label' => 'X' ] ),
			'stale-hash'
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_with_wrong_library_returns_not_found(): void {
		$created = $this->service->create( $this->library_id, $this->valid_item() );

		// Create a second library
		$libSvc = new LibraryService( $this->libRepo, $this->modRepo );
		$other  = $libSvc->create( [
			'library_key' => 'textiles_sandatex',
			'module_key'  => 'textiles',
			'name'        => 'Sandatex',
			'is_active'   => true,
		] );

		$result = $this->service->update(
			$other['id'],
			$created['id'],
			$this->valid_item(),
			$created['record']['version_hash']
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_soft_delete_marks_inactive(): void {
		$created = $this->service->create( $this->library_id, $this->valid_item() );
		$result  = $this->service->soft_delete( $this->library_id, $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->itemRepo->find_by_id( $created['id'] )['is_active'] );
	}

	public function test_list_for_unknown_library_returns_null(): void {
		$result = $this->service->list_for_library( 9999 );
		$this->assertNull( $result );
	}

	public function test_list_for_library_returns_only_its_items(): void {
		// Item in our library
		$this->service->create( $this->library_id, $this->valid_item( [ 'item_key' => 'item_a' ] ) );

		// New library + item in it
		$libSvc = new LibraryService( $this->libRepo, $this->modRepo );
		$other  = $libSvc->create( [
			'library_key' => 'textiles_sandatex',
			'module_key'  => 'textiles',
			'name'        => 'Sandatex',
			'is_active'   => true,
		] );
		$this->service->create( $other['id'], $this->valid_item( [ 'item_key' => 'item_b' ] ) );

		$listing = $this->service->list_for_library( $this->library_id );
		$this->assertSame( 1, $listing['total'] );
		$this->assertSame( 'item_a', $listing['items'][0]['item_key'] );
	}

	// ---- Phase 4.2b.1 — pricing source + bundle validation ----------------

	public function test_create_simple_option_can_save_without_bundle_fields(): void {
		// item_type defaults to 'simple_option', price_source to
		// 'configkit'. The Phase 4.2 validator should not reject the
		// pre-Phase-4.2 happy path.
		$result = $this->service->create( $this->library_id, $this->valid_item() );
		$this->assertTrue( $result['ok'], json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 'simple_option', $result['record']['item_type'] );
		$this->assertSame( 'configkit', $result['record']['price_source'] );
		$this->assertNull( $result['record']['bundle_fixed_price'] );
		$this->assertNull( $result['record']['cart_behavior'] );
	}

	public function test_create_woo_source_requires_woo_product_id(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'price_source'   => 'woo',
			'woo_product_id' => 0,
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
		$fields = array_column( $result['errors'], 'field' );
		$this->assertContains( 'woo_product_id', $fields );
	}

	public function test_create_simple_with_bundle_source_is_rejected(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'price_source' => 'bundle_sum',
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_for_simple', $codes );
	}

	public function test_create_bundle_requires_components(): void {
		// Module needs supports_woo_product_link for bundle
		// components — re-create with that capability via the stub.
		$this->modRepo->records[1]['supports_woo_product_link'] = true;
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'empty', $codes );
	}

	public function test_create_bundle_with_components_succeeds(): void {
		$this->modRepo->records[1]['supports_woo_product_link'] = true;
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components' => [
				[ 'component_key' => 'motor',  'woo_product_id' => 123, 'qty' => 1, 'price_source' => 'woo' ],
				[ 'component_key' => 'remote', 'woo_product_id' => 124, 'qty' => 1, 'price_source' => 'woo' ],
			],
		] ) );
		$this->assertTrue( $result['ok'], json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 'bundle', $result['record']['item_type'] );
		$this->assertSame( 'price_inside_main', $result['record']['cart_behavior'] );
		$this->assertSame( 'expanded', $result['record']['admin_order_display'] );
		$this->assertCount( 2, $result['record']['bundle_components'] );
	}

	public function test_create_bundle_component_with_zero_woo_id_is_rejected(): void {
		$this->modRepo->records[1]['supports_woo_product_link'] = true;
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components' => [
				[ 'component_key' => 'phantom', 'woo_product_id' => 0, 'qty' => 1, 'price_source' => 'woo' ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'missing_woo_product_id', $codes );
	}

	public function test_create_bundle_component_with_invalid_qty_is_rejected(): void {
		$this->modRepo->records[1]['supports_woo_product_link'] = true;
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components' => [
				[ 'component_key' => 'a', 'woo_product_id' => 123, 'qty' => 0, 'price_source' => 'woo' ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_qty', $codes );
	}

	public function test_create_bundle_component_with_invalid_source_is_rejected(): void {
		$this->modRepo->records[1]['supports_woo_product_link'] = true;
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'    => 'bundle',
			'price_source' => 'bundle_sum',
			'bundle_components' => [
				[ 'component_key' => 'a', 'woo_product_id' => 123, 'qty' => 1, 'price_source' => 'bundle_sum' ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_component_source', $codes );
	}

	public function test_create_fixed_bundle_requires_bundle_fixed_price(): void {
		$this->modRepo->records[1]['supports_woo_product_link'] = true;
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'    => 'bundle',
			'price_source' => 'fixed_bundle',
			'bundle_components' => [
				[ 'component_key' => 'a', 'woo_product_id' => 1, 'qty' => 1, 'price_source' => 'woo' ],
			],
			// bundle_fixed_price intentionally missing
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
		$fields = array_column( $result['errors'], 'field' );
		$this->assertContains( 'bundle_fixed_price', $fields );
	}

	public function test_create_fixed_bundle_with_price_succeeds(): void {
		$this->modRepo->records[1]['supports_woo_product_link'] = true;
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'        => 'bundle',
			'price_source'     => 'fixed_bundle',
			'bundle_fixed_price' => 8990.0,
			'bundle_components' => [
				[ 'component_key' => 'a', 'woo_product_id' => 1, 'qty' => 1, 'price_source' => 'woo' ],
			],
		] ) );
		$this->assertTrue( $result['ok'], json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 8990.0, $result['record']['bundle_fixed_price'] );
	}

	public function test_create_invalid_item_type_is_rejected(): void {
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type' => 'bogus_value',
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_simple_item_clears_bundle_fields_on_save(): void {
		// Owner toggled Package off but their old bundle_fixed_price
		// is still in the request. Sanitizer must wipe it.
		$result = $this->service->create( $this->library_id, $this->valid_item( [
			'item_type'         => 'simple_option',
			'price_source'      => 'configkit',
			'bundle_fixed_price' => 8990.0,
			'cart_behavior'     => 'add_child_lines',
			'admin_order_display' => 'collapsed',
			'bundle_components' => [
				[ 'component_key' => 'leftover', 'woo_product_id' => 1, 'qty' => 1, 'price_source' => 'woo' ],
			],
		] ) );
		$this->assertTrue( $result['ok'], json_encode( $result['errors'] ?? [] ) );
		$this->assertNull( $result['record']['bundle_fixed_price'] );
		$this->assertNull( $result['record']['cart_behavior'] );
		$this->assertNull( $result['record']['admin_order_display'] );
		$this->assertNull( $result['record']['bundle_components'] );
	}
}
