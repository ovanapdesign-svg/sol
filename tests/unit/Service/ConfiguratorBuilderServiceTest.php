<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Service\AutoManagedRegistry;
use ConfigKit\Service\ConfiguratorBuilderService;
use ConfigKit\Service\LibraryService;
use ConfigKit\Service\LookupCellService;
use ConfigKit\Service\LookupTableService;
use ConfigKit\Service\ModuleService;
use ConfigKit\Service\ProductBuilderState;
use ConfigKit\Service\SectionListState;
use ConfigKit\Tests\Unit\Adapters\StubWooSkuResolver;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.4 — Yith-style section orchestrator. Verifies that
 * sections persist with stable element_ids, the right underlying
 * entity (lookup_table vs library) gets minted, drag-reorder
 * shuffles positions, and visibility input is sanitised.
 */
final class ConfiguratorBuilderServiceTest extends TestCase {

	private const PRODUCT_ID = 4242;

	/** @var array<int,array<string,mixed>> */
	private array $product_meta = [];
	/** @var array<int,array<int,array<string,mixed>>> */
	private array $section_store = [];
	/** @var array<string,array<string,mixed>> */
	private array $option_store = [];

	private ConfiguratorBuilderService $service;
	private StubModuleRepository $modules;
	private StubLibraryRepository $libraries;
	private StubLookupTableRepository $lookup_tables;
	private StubLookupCellRepository $lookup_cells;
	private SectionListState $sections;
	private StubLibraryItemRepository $items;
	private StubWooSkuResolver $woo_resolver;

	protected function setUp(): void {
		$this->product_meta  = [];
		$this->section_store = [];
		$this->option_store  = [];

		$this->modules       = new StubModuleRepository();
		$this->libraries     = new StubLibraryRepository();
		$this->lookup_tables = new StubLookupTableRepository();
		$this->lookup_cells  = new StubLookupCellRepository();

		$pb_state = new ProductBuilderState(
			fn ( int $pid ): array => $this->product_meta[ $pid ] ?? [],
			function ( int $pid, array $data ): void { $this->product_meta[ $pid ] = $data; },
		);
		$this->sections = new SectionListState(
			fn ( int $pid ): array => $this->section_store[ $pid ] ?? [],
			function ( int $pid, array $data ): void { $this->section_store[ $pid ] = $data; },
		);
		$registry = new AutoManagedRegistry(
			fn (): array => $this->option_store,
			function ( array $data ): void { $this->option_store = $data; },
		);

		$this->items = new StubLibraryItemRepository();
		$this->woo_resolver = new StubWooSkuResolver(
			[ 'SOMFY-MOT-25' => 5001, 'SOMFY-MOT-30' => 5002, 'SOMFY-REMOTE' => 5100 ]
		);
		$this->service = new ConfiguratorBuilderService(
			$this->sections,
			$pb_state,
			$registry,
			new LookupTableService( $this->lookup_tables, $this->lookup_cells ),
			$this->lookup_tables,
			new ModuleService( $this->modules ),
			new LibraryService( $this->libraries, $this->modules ),
			$this->libraries,
			new LookupCellService( $this->lookup_cells, $this->lookup_tables ),
			$this->lookup_cells,
			new \ConfigKit\Service\LibraryItemService( $this->items, $this->libraries, $this->modules ),
			$this->items,
			$this->modules,
			$this->woo_resolver,
		);
	}

	public function test_create_size_pricing_section_provisions_a_lookup_table(): void {
		$result = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_SIZE_PRICING, 'Markise pricing' );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		$section = $result['section'];
		$this->assertSame( 'size_pricing', $section['type'] );
		$this->assertSame( 'Markise pricing', $section['label'] );
		$this->assertStringStartsWith( 'sec_size_pricing_', $section['id'] );
		$this->assertNotEmpty( $section['lookup_table_key'] );

		$this->assertNotNull( $this->lookup_tables->find_by_key( $section['lookup_table_key'] ) );
	}

	public function test_create_option_group_section_provisions_textiles_module_and_library(): void {
		$result = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP, 'Dukfarge' );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$section = $result['section'];
		$this->assertSame( 'option_group', $section['type'] );
		$this->assertSame( 'textiles',     $section['module_key'] );
		$this->assertNotEmpty( $section['library_key'] );
		$this->assertNotNull( $this->modules->find_by_key( 'textiles' ) );
		$this->assertNotNull( $this->libraries->find_by_key( $section['library_key'] ) );
	}

	public function test_unknown_section_type_returns_friendly_error(): void {
		$result = $this->service->create_section( self::PRODUCT_ID, 'spaceship' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'spaceship', $result['message'] );
	}

	public function test_sections_persist_in_order_and_reorder_swaps_positions(): void {
		$this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_SIZE_PRICING );
		$this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP );
		$this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_MOTOR );

		$listing = $this->service->list_sections( self::PRODUCT_ID );
		$this->assertCount( 3, $listing['sections'] );
		$ids = array_column( $listing['sections'], 'id' );
		$this->assertSame( [ 0, 1, 2 ], array_column( $listing['sections'], 'position' ) );

		// Swap the last and first.
		$this->service->reorder_sections( self::PRODUCT_ID, [ $ids[2], $ids[0], $ids[1] ] );
		$reordered = $this->service->list_sections( self::PRODUCT_ID )['sections'];
		$this->assertSame( $ids[2], $reordered[0]['id'] );
		$this->assertSame( $ids[0], $reordered[1]['id'] );
		$this->assertSame( $ids[1], $reordered[2]['id'] );
	}

	public function test_update_section_changes_label_and_visibility(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP );
		$id = $created['section']['id'];

		$result = $this->service->update_section( self::PRODUCT_ID, $id, [
			'label' => 'Renamed',
			'visibility' => [
				'mode' => 'when',
				'conditions' => [ [ 'section_id' => 'sec_other', 'op' => 'equals', 'value' => 'motorized' ] ],
				'match' => 'all',
			],
		] );
		$this->assertTrue( $result['ok'] );
		$section = $result['section'];
		$this->assertSame( 'Renamed', $section['label'] );
		$this->assertSame( 'when',    $section['visibility']['mode'] );
		$this->assertCount( 1, $section['visibility']['conditions'] );
		$this->assertSame( 'sec_other', $section['visibility']['conditions'][0]['section_id'] );
	}

	public function test_visibility_always_clears_any_conditions(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP );
		$id = $created['section']['id'];

		$result = $this->service->update_section( self::PRODUCT_ID, $id, [
			'visibility' => [
				'mode' => 'always',
				'conditions' => [ [ 'section_id' => 'sec_other', 'op' => 'equals', 'value' => 'x' ] ],
			],
		] );
		$this->assertSame( [], $result['section']['visibility']['conditions'] );
	}

	public function test_delete_section_removes_it_and_unmarks_auto_managed(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP );
		$id  = $created['section']['id'];
		$key = $created['section']['library_key'];

		$result = $this->service->delete_section( self::PRODUCT_ID, $id );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 0, $result['sections'] );
		$registry = new AutoManagedRegistry(
			fn (): array => $this->option_store,
			function ( array $data ): void { $this->option_store = $data; },
		);
		$this->assertFalse( $registry->is_auto_managed( AutoManagedRegistry::TYPE_LIBRARY, $key ) );
	}

	public function test_save_range_rows_writes_lookup_cells_and_preserves_from_bounds(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_SIZE_PRICING, 'Pricing' );
		$id = $created['section']['id'];
		$result = $this->service->save_range_rows( self::PRODUCT_ID, $id, [
			[ 'width_from' => 1000, 'width_to' => 2100, 'height_from' => 1000, 'height_to' => 2000, 'price' => 11000, 'price_group_key' => 'I' ],
			[ 'width_from' => 2101, 'width_to' => 2400, 'height_from' => 1000, 'height_to' => 2000, 'price' => 12000, 'price_group_key' => 'I' ],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertCount( 2, $this->lookup_cells->records );

		$rows = $this->service->read_range_rows( self::PRODUCT_ID, $id );
		$this->assertSame( 1000, $rows[0]['width_from'] );
		$this->assertSame( 2100, $rows[0]['width_to'] );
		$this->assertSame( 'I',  $rows[0]['price_group_key'] );
	}

	public function test_save_range_rows_replaces_previous_set(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_SIZE_PRICING );
		$id = $created['section']['id'];
		$this->service->save_range_rows( self::PRODUCT_ID, $id, [
			[ 'width_from' => 0, 'width_to' => 2400, 'height_from' => 0, 'height_to' => 2000, 'price' => 12000 ],
		] );
		$this->assertCount( 1, $this->lookup_cells->records );
		$this->service->save_range_rows( self::PRODUCT_ID, $id, [
			[ 'width_from' => 0, 'width_to' => 3000, 'height_from' => 0, 'height_to' => 2400, 'price' => 16000 ],
			[ 'width_from' => 0, 'width_to' => 3500, 'height_from' => 0, 'height_to' => 2400, 'price' => 18000 ],
		] );
		$this->assertCount( 2, $this->lookup_cells->records );
	}

	public function test_save_range_rows_rejects_invalid_input(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_SIZE_PRICING );
		$id = $created['section']['id'];
		$result = $this->service->save_range_rows( self::PRODUCT_ID, $id, [
			[ 'width_to' => 0, 'height_to' => 2000, 'price' => 1000 ], // bad width
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_analyse_ranges_flags_overlap_and_gap(): void {
		$rows = [
			[ 'width_from' => 1000, 'width_to' => 2100, 'height_from' => 1000, 'height_to' => 2000, 'price' => 11000 ],
			[ 'width_from' => 2050, 'width_to' => 2400, 'height_from' => 1000, 'height_to' => 2000, 'price' => 12000 ],
		];
		$diag = $this->service->analyse_ranges( $rows );
		$this->assertCount( 1, $diag['overlaps'] );
		$this->assertSame( 0, $diag['overlaps'][0]['a'] );
		$this->assertSame( 1, $diag['overlaps'][0]['b'] );
		$this->assertFalse( $diag['ok'] );
	}

	public function test_analyse_ranges_clean_set_returns_ok(): void {
		$rows = [
			[ 'width_from' => 0,    'width_to' => 2100, 'height_from' => 0, 'height_to' => 2000, 'price' => 11000 ],
			[ 'width_from' => 2101, 'width_to' => 2400, 'height_from' => 0, 'height_to' => 2000, 'price' => 12000 ],
		];
		$diag = $this->service->analyse_ranges( $rows );
		$this->assertSame( [], $diag['overlaps'] );
		$this->assertTrue( $diag['ok'] );
	}

	public function test_save_options_creates_library_items_under_section_library(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP, 'Dukfarge' );
		$id  = $created['section']['id'];
		$key = $created['section']['library_key'];
		$result = $this->service->save_section_options( self::PRODUCT_ID, $id, [
			[ 'sku' => 'U171', 'label' => 'Beige Sand', 'price_group' => 'I' ],
			[ 'sku' => 'U172', 'label' => 'Grey Stone', 'price_group' => 'I' ],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		$items = array_values( array_filter( $this->items->records, static fn ( $r ) => $r['library_key'] === $key && ! empty( $r['is_active'] ) ) );
		$this->assertCount( 2, $items );
		$skus = array_column( $items, 'sku' );
		$this->assertContains( 'U171', $skus );
		$this->assertContains( 'U172', $skus );
	}

	public function test_save_options_replace_all_drops_prior_set(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP );
		$id = $created['section']['id'];

		$this->service->save_section_options( self::PRODUCT_ID, $id, [
			[ 'sku' => 'U171', 'label' => 'Beige' ],
		] );
		$first_active = count( array_filter( $this->items->records, static fn ( $r ) => ! empty( $r['is_active'] ) ) );
		$this->assertSame( 1, $first_active );

		$this->service->save_section_options( self::PRODUCT_ID, $id, [
			[ 'sku' => 'U200', 'label' => 'Sand' ],
			[ 'sku' => 'U201', 'label' => 'Stone' ],
		] );
		$active = array_values( array_filter( $this->items->records, static fn ( $r ) => ! empty( $r['is_active'] ) ) );
		$this->assertCount( 2, $active );
		$this->assertContains( 'U200', array_column( $active, 'sku' ) );
		$this->assertNotContains( 'U171', array_column( $active, 'sku' ) );
	}

	public function test_save_options_rejects_size_pricing_section(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_SIZE_PRICING );
		$result  = $this->service->save_section_options( self::PRODUCT_ID, $created['section']['id'], [ [ 'sku' => 'X', 'label' => 'Y' ] ] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'ranges endpoint', $result['message'] );
	}

	public function test_save_motor_resolves_woo_sku_and_records_woo_price_source(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_MOTOR, 'Motors' );
		$id      = $created['section']['id'];
		$result  = $this->service->save_section_options( self::PRODUCT_ID, $id, [
			[
				'sku'             => 'M25',
				'label'           => 'Somfy 25Nm',
				'woo_product_sku' => 'SOMFY-MOT-25',
				'price_source'    => 'woo',
			],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		$saved = array_values( array_filter( $this->items->records, static fn ( $r ) => ( $r['sku'] ?? '' ) === 'M25' && ! empty( $r['is_active'] ) ) );
		$this->assertCount( 1, $saved );
		$this->assertSame( 5001, $saved[0]['woo_product_id'] );
		$this->assertSame( 'woo', $saved[0]['price_source'] );
		$this->assertSame( 'simple_option', $saved[0]['item_type'] );
	}

	public function test_save_motor_bundle_collects_components_and_picks_bundle_sum(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_MOTOR );
		$id      = $created['section']['id'];
		$result  = $this->service->save_section_options( self::PRODUCT_ID, $id, [
			[
				'sku'   => 'PKG-25',
				'label' => 'Somfy package 25',
				'components' => [
					[ 'woo_product_sku' => 'SOMFY-MOT-25', 'qty' => 1, 'price_source' => 'woo' ],
					[ 'woo_product_sku' => 'SOMFY-REMOTE', 'qty' => 1, 'price_source' => 'woo' ],
				],
			],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		$bundle = array_values( array_filter( $this->items->records, static fn ( $r ) => ( $r['sku'] ?? '' ) === 'PKG-25' && ! empty( $r['is_active'] ) ) )[0] ?? null;
		$this->assertNotNull( $bundle );
		$this->assertSame( 'bundle', $bundle['item_type'] );
		$this->assertSame( 'bundle_sum', $bundle['price_source'] );
		$this->assertCount( 2, $bundle['bundle_components'] );
		$this->assertSame( 5001, $bundle['bundle_components'][0]['woo_product_id'] );
		$this->assertSame( 5100, $bundle['bundle_components'][1]['woo_product_id'] );
	}

	public function test_save_motor_bundle_with_fixed_price_uses_fixed_bundle_source(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_MOTOR );
		$id      = $created['section']['id'];
		$result  = $this->service->save_section_options( self::PRODUCT_ID, $id, [
			[
				'sku'                 => 'PKG-FIXED',
				'label'               => 'Fixed-price package',
				'bundle_fixed_price'  => 4999.0,
				'components'          => [
					[ 'woo_product_sku' => 'SOMFY-MOT-25', 'qty' => 1 ],
				],
			],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		$bundle = array_values( array_filter( $this->items->records, static fn ( $r ) => ( $r['sku'] ?? '' ) === 'PKG-FIXED' && ! empty( $r['is_active'] ) ) )[0] ?? null;
		$this->assertSame( 'fixed_bundle', $bundle['price_source'] );
		$this->assertSame( 4999.0, $bundle['bundle_fixed_price'] );
	}

	public function test_save_motor_bundle_with_no_resolvable_components_falls_back_to_simple(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_MOTOR );
		$id      = $created['section']['id'];
		$result  = $this->service->save_section_options( self::PRODUCT_ID, $id, [
			[
				'sku'        => 'PKG-EMPTY',
				'label'      => 'Empty package',
				'components' => [
					[ 'woo_product_sku' => 'UNKNOWN-SKU', 'qty' => 1 ],
				],
			],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		$row = array_values( array_filter( $this->items->records, static fn ( $r ) => ( $r['sku'] ?? '' ) === 'PKG-EMPTY' && ! empty( $r['is_active'] ) ) )[0];
		$this->assertSame( 'simple_option', $row['item_type'] );
		$this->assertSame( 'configkit', $row['price_source'] );
	}

	public function test_analyse_product_reports_setup_needed_for_empty_section(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP );
		$report  = $this->service->analyse_product( self::PRODUCT_ID );
		$this->assertSame( 1, $report['summary']['total'] );
		$this->assertSame( 'in_progress', $report['summary']['overall'] );
		$this->assertSame( 'setup_needed', $report['sections'][0]['status'] );
		$this->assertSame( $created['section']['id'], $report['sections'][0]['id'] );
	}

	public function test_analyse_product_marks_size_pricing_with_overlap_as_issues(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_SIZE_PRICING );
		$id      = $created['section']['id'];
		$this->service->save_range_rows( self::PRODUCT_ID, $id, [
			[ 'width_from' => 0, 'width_to' => 2000, 'height_from' => 0, 'height_to' => 2000, 'price' => 100 ],
			[ 'width_from' => 1500, 'width_to' => 2500, 'height_from' => 0, 'height_to' => 2000, 'price' => 200 ],
		] );
		$report = $this->service->analyse_product( self::PRODUCT_ID );
		$this->assertSame( 'issues', $report['sections'][0]['status'] );
		$this->assertSame( 'issues', $report['summary']['overall'] );
		$this->assertNotEmpty( $report['sections'][0]['issues'] );
	}

	public function test_analyse_product_flags_dangling_visibility_reference(): void {
		$created = $this->service->create_section( self::PRODUCT_ID, SectionTypeRegistry::TYPE_OPTION_GROUP );
		$id      = $created['section']['id'];
		$this->service->save_section_options( self::PRODUCT_ID, $id, [ [ 'sku' => 'X', 'label' => 'X' ] ] );
		$this->service->update_section( self::PRODUCT_ID, $id, [
			'visibility' => [
				'mode' => 'when',
				'match' => 'all',
				'conditions' => [
					[ 'section_id' => 'sec_motor_dead123', 'op' => 'equals', 'value' => 'foo' ],
				],
			],
		] );
		$report = $this->service->analyse_product( self::PRODUCT_ID );
		$this->assertSame( 'issues', $report['sections'][0]['status'] );
		$this->assertStringContainsString( 'unknown section', $report['sections'][0]['issues'][0] );
	}

	public function test_section_type_registry_lists_documented_types(): void {
		$ids = array_column( SectionTypeRegistry::all(), 'id' );
		foreach ( [ 'size_pricing', 'option_group', 'motor', 'manual_operation', 'controls', 'accessories', 'custom' ] as $expected ) {
			$this->assertContains( $expected, $ids );
		}
	}
}
