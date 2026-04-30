<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Import\LibraryItemValidator;
use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Tests\Unit\Adapters\StubWooSkuResolver;
use ConfigKit\Tests\Unit\Service\StubLibraryItemRepository;
use ConfigKit\Tests\Unit\Service\StubLibraryRepository;
use ConfigKit\Tests\Unit\Service\StubModuleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 dalis 3 — coverage for the library-items import validator.
 * Stubs every dependency so the test stays hermetic; the validator
 * itself is the unit under test.
 */
final class LibraryItemValidatorTest extends TestCase {

	private StubLibraryRepository $libraries;
	private StubModuleRepository $modules;
	private StubLibraryItemRepository $items;
	private StubWooSkuResolver $woo;
	private LibraryItemValidator $validator;

	protected function setUp(): void {
		$this->libraries = new StubLibraryRepository();
		$this->modules   = new StubModuleRepository();
		$this->items     = new StubLibraryItemRepository();
		$this->woo       = new StubWooSkuResolver();
		$this->validator = new LibraryItemValidator(
			$this->libraries,
			$this->modules,
			$this->items,
			$this->woo
		);

		// Default fixture: a library with a fully-capable module.
		$this->modules->records[1] = $this->module_record( 'mod_textiles', [
			'supports_sku'              => true,
			'supports_price'            => true,
			'supports_brand'            => true,
			'supports_collection'       => true,
			'supports_color_family'     => true,
			'supports_filters'          => true,
			'supports_woo_product_link' => true,
		] );
		$this->libraries->records[1] = [
			'id' => 1, 'library_key' => 'lib_textiles', 'module_key' => 'mod_textiles', 'is_active' => true,
		];
	}

	private function module_record( string $key, array $caps ): array {
		$rec = [ 'id' => 1, 'module_key' => $key, 'name' => $key ];
		foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
			$rec[ $flag ] = ! empty( $caps[ $flag ] );
		}
		return $rec;
	}

	private function row( array $norm, int $row_number = 1 ): array {
		return [
			'row_number'   => $row_number,
			'source_sheet' => 'Sheet1',
			'source_cell'  => 'A' . ( $row_number + 1 ),
			'raw'          => $norm,
			'normalized'   => array_merge( [
				'library_key'        => null,
				'item_key'           => null,
				'label'              => null,
				'short_label'        => null,
				'description'        => null,
				'sku'                => null,
				'price'              => null,
				'sale_price'         => null,
				'price_group_key'    => '',
				'woo_product_id'     => null,
				'woo_product_sku'    => null,
				'price_source'       => null,
				'item_type'          => null,
				'is_active'          => true,
				'sort_order'         => 0,
				'attributes'         => [],
				'unknown_columns'    => [],
			], $norm ),
		];
	}

	public function test_clean_row_passes_with_action_insert(): void {
		$out = $this->validator->validate(
			[ $this->row( [ 'library_key' => 'lib_textiles', 'item_key' => 'red_001', 'label' => 'Red', 'price' => 100.0 ] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_GREEN, $out[0]['severity'] );
		$this->assertSame( ImportRowRepository::ACTION_INSERT, $out[0]['action'] );
	}

	public function test_missing_library_key_in_target_unset(): void {
		// Target empty: library lookup returns null → row red.
		$out = $this->validator->validate(
			[ $this->row( [ 'library_key' => null, 'item_key' => 'red', 'label' => 'Red' ] ) ],
			[ 'target_library_key' => '', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_RED, $out[0]['severity'] );
		$messages = array_column( $out[0]['errors'], 'message' );
		$this->assertNotEmpty( array_filter( $messages, static fn ( $m ) => str_contains( $m, 'library_key' ) ) );
	}

	public function test_invalid_item_key_shape_is_rejected(): void {
		$out = $this->validator->validate(
			[ $this->row( [ 'library_key' => 'lib_textiles', 'item_key' => 'BAD-key', 'label' => 'L' ] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_RED, $out[0]['severity'] );
		$this->assertSame( 'item_key', $out[0]['errors'][0]['field'] );
	}

	public function test_duplicate_in_file_demotes_prior_row(): void {
		$out = $this->validator->validate(
			[
				$this->row( [ 'library_key' => 'lib_textiles', 'item_key' => 'red_001', 'label' => 'Red v1' ], 1 ),
				$this->row( [ 'library_key' => 'lib_textiles', 'item_key' => 'red_001', 'label' => 'Red v2' ], 2 ),
			],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_YELLOW, $out[0]['severity'] );
		$this->assertSame( ImportRowRepository::ACTION_SKIP, $out[0]['action'] );
		$this->assertSame( ImportRowRepository::SEVERITY_GREEN, $out[1]['severity'] );
	}

	public function test_woo_product_sku_resolves_to_id(): void {
		$this->woo->sku_to_id = [ 'SOMFY-SO30IO' => 4242 ];
		$out = $this->validator->validate(
			[ $this->row( [
				'library_key'     => 'lib_textiles',
				'item_key'        => 'sonesse',
				'label'           => 'Sonesse',
				'woo_product_sku' => 'SOMFY-SO30IO',
			] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertNotSame( ImportRowRepository::SEVERITY_RED, $out[0]['severity'] );
		$this->assertSame( 4242, $out[0]['normalized']['woo_product_id'] );
	}

	public function test_unknown_woo_product_sku_fails_row(): void {
		$out = $this->validator->validate(
			[ $this->row( [
				'library_key'     => 'lib_textiles',
				'item_key'        => 'sonesse',
				'label'           => 'Sonesse',
				'woo_product_sku' => 'GHOST',
			] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_RED, $out[0]['severity'] );
	}

	public function test_capability_mismatch_warns_and_drops_column(): void {
		// Module without supports_brand — file carries brand, expect warning + drop.
		$this->modules->records[1] = $this->module_record( 'mod_textiles', [
			'supports_sku'   => true,
			'supports_price' => true,
		] );
		$out = $this->validator->validate(
			[ $this->row( [
				'library_key' => 'lib_textiles',
				'item_key'    => 'red_001',
				'label'       => 'Red',
				'attributes'  => [ 'brand' => 'Dickson' ],
			] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_YELLOW, $out[0]['severity'] );
		$this->assertArrayNotHasKey( 'brand', $out[0]['normalized']['attributes'] );
	}

	public function test_invalid_price_source_enum_is_rejected(): void {
		$out = $this->validator->validate(
			[ $this->row( [
				'library_key'  => 'lib_textiles',
				'item_key'     => 'red_001',
				'label'        => 'Red',
				'price_source' => 'magic',
			] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_RED, $out[0]['severity'] );
	}

	public function test_mismatched_file_library_key_is_rejected(): void {
		$out = $this->validator->validate(
			[ $this->row( [
				'library_key' => 'wrong_lib',
				'item_key'    => 'red_001',
				'label'       => 'Red',
			] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_RED, $out[0]['severity'] );
	}

	public function test_existing_item_yields_action_update(): void {
		$this->items->records[1] = [
			'id' => 1, 'library_key' => 'lib_textiles', 'item_key' => 'red_001', 'label' => 'old',
			'is_active' => true,
		];
		$out = $this->validator->validate(
			[ $this->row( [ 'library_key' => 'lib_textiles', 'item_key' => 'red_001', 'label' => 'Red' ] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::ACTION_UPDATE, $out[0]['action'] );
	}

	public function test_negative_price_rejected(): void {
		$out = $this->validator->validate(
			[ $this->row( [
				'library_key' => 'lib_textiles',
				'item_key'    => 'red_001',
				'label'       => 'Red',
				'price'       => -5.0,
			] ) ],
			[ 'target_library_key' => 'lib_textiles', 'mode' => 'insert_update' ]
		);
		$this->assertSame( ImportRowRepository::SEVERITY_RED, $out[0]['severity'] );
	}
}
