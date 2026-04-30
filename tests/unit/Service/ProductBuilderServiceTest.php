<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Admin\ProductTypeRecipes;
use ConfigKit\Service\AutoManagedRegistry;
use ConfigKit\Service\FamilyService;
use ConfigKit\Service\LibraryItemService;
use ConfigKit\Service\LibraryService;
use ConfigKit\Service\LookupCellService;
use ConfigKit\Service\LookupTableService;
use ConfigKit\Service\ModuleService;
use ConfigKit\Service\ProductBuilderService;
use ConfigKit\Service\ProductBuilderState;
use ConfigKit\Service\TemplateService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.3 — Simple Mode orchestrator. set_product_type() must
 * silently provision the family + template skeleton so the owner's
 * first action ("I have a Markise") leaves the product in a state
 * the rest of the builder can extend.
 */
final class ProductBuilderServiceTest extends TestCase {

	private const PRODUCT_ID = 4242;

	/** @var array<int,array<string,mixed>> */
	private array $product_meta = [];
	/** @var array<string,array<string,mixed>> */
	private array $option_store = [];

	private ProductBuilderService $service;
	private StubFamilyRepository $families;
	private StubTemplateRepository $templates;
	private StubLookupTableRepository $lookup_tables;
	private StubLookupCellRepository $lookup_cells;
	private StubModuleRepository $modules;
	private StubLibraryRepository $libraries;
	private StubLibraryItemRepository $items;
	private ProductBuilderState $state;
	private AutoManagedRegistry $registry;

	protected function setUp(): void {
		$this->product_meta = [];
		$this->option_store = [];

		$this->families      = new StubFamilyRepository();
		$this->templates     = new StubTemplateRepository();
		$this->lookup_tables = new StubLookupTableRepository();
		$this->lookup_cells  = new StubLookupCellRepository();
		$this->modules       = new StubModuleRepository();
		$this->libraries     = new StubLibraryRepository();
		$this->items         = new StubLibraryItemRepository();

		$this->state = new ProductBuilderState(
			fn ( int $pid ): array => $this->product_meta[ $pid ] ?? [],
			function ( int $pid, array $data ): void { $this->product_meta[ $pid ] = $data; },
		);

		$this->registry = new AutoManagedRegistry(
			fn (): array => $this->option_store,
			function ( array $data ): void { $this->option_store = $data; },
		);

		$this->service = new ProductBuilderService(
			new TemplateService( $this->templates ),
			$this->templates,
			new FamilyService( $this->families ),
			$this->families,
			$this->state,
			$this->registry,
			new LookupTableService( $this->lookup_tables, $this->lookup_cells ),
			$this->lookup_tables,
			new LookupCellService( $this->lookup_cells, $this->lookup_tables ),
			$this->lookup_cells,
			new ModuleService( $this->modules ),
			$this->modules,
			new LibraryService( $this->libraries, $this->modules ),
			$this->libraries,
			new LibraryItemService( $this->items, $this->libraries, $this->modules ),
			$this->items,
		);
	}

	public function test_set_product_type_creates_family_and_template_skeleton(): void {
		$result = $this->service->set_product_type( self::PRODUCT_ID, ProductTypeRecipes::TYPE_MARKISE );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		// Family seeded with the recipe's family_key.
		$this->assertNotNull( $this->families->find_by_key( 'markiser' ) );

		// Template stamped with product-id-keyed slug + tagged in the
		// auto-managed registry so Advanced admin can show a 🔧 badge.
		$template = $this->templates->find_by_key( 'product_4242_markise' );
		$this->assertNotNull( $template );
		$this->assertSame( 'markiser', $template['family_key'] );
		$this->assertTrue( $this->registry->is_auto_managed( AutoManagedRegistry::TYPE_TEMPLATE, 'product_4242_markise' ) );

		// State persisted to product meta.
		$state = $this->service->get_state( self::PRODUCT_ID );
		$this->assertSame( 'markise', $state['product_type'] );
		$this->assertSame( 'product_4242_markise', $state['template_key'] );
		$this->assertSame( 'markiser', $state['family_key'] );
		$this->assertTrue( $state['auto_managed'] );
	}

	public function test_set_product_type_is_idempotent_on_re_call(): void {
		$this->service->set_product_type( self::PRODUCT_ID, ProductTypeRecipes::TYPE_MARKISE );
		$first_template_count = count( $this->templates->records );
		$first_family_count   = count( $this->families->records );

		// Re-running with same product type doesn't double-create.
		$result = $this->service->set_product_type( self::PRODUCT_ID, ProductTypeRecipes::TYPE_MARKISE );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $first_template_count, count( $this->templates->records ) );
		$this->assertSame( $first_family_count,   count( $this->families->records ) );
	}

	public function test_unknown_product_type_returns_friendly_error(): void {
		$result = $this->service->set_product_type( self::PRODUCT_ID, 'spaceship' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'spaceship', $result['message'] );
		// Owner-friendly: never reveals template_key / family_key.
		$this->assertStringNotContainsString( 'template_key', $result['message'] );
	}

	public function test_get_state_returns_empty_array_for_unconfigured_product(): void {
		$this->assertSame( [], $this->service->get_state( 9999 ) );
	}

	public function test_recipes_list_includes_documented_types(): void {
		$ids = array_column( ProductTypeRecipes::all(), 'id' );
		foreach ( [ 'markise', 'screen', 'pergola', 'terrassetak', 'custom' ] as $expected ) {
			$this->assertContains( $expected, $ids );
		}
	}

	public function test_markise_recipe_lists_expected_blocks(): void {
		$recipe = ProductTypeRecipes::find( 'markise' );
		$this->assertNotNull( $recipe );
		foreach ( [ 'pricing', 'fabrics', 'profile_colors', 'operation', 'stang', 'motor' ] as $block ) {
			$this->assertContains( $block, $recipe['blocks'], 'markise recipe missing block: ' . $block );
		}
	}

	public function test_save_pricing_requires_a_product_type_first(): void {
		$result = $this->service->save_pricing_rows( self::PRODUCT_ID, [
			[ 'to_width' => 2400, 'to_height' => 2000, 'price' => 12000 ],
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'product type', strtolower( $result['message'] ) );
	}

	public function test_save_pricing_creates_lookup_table_with_round_up_match_mode(): void {
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );
		$result = $this->service->save_pricing_rows( self::PRODUCT_ID, [
			[ 'to_width' => 2100, 'to_height' => 2000, 'price' => 11000 ],
			[ 'to_width' => 2400, 'to_height' => 2000, 'price' => 12000 ],
			[ 'to_width' => 2400, 'to_height' => 2400, 'price' => 13500 ],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		$table = $this->lookup_tables->find_by_key( 'product_4242_pricing' );
		$this->assertNotNull( $table );
		$this->assertSame( 'round_up', $table['match_mode'] );
		$this->assertSame( 'mm', $table['unit'] );
		$this->assertCount( 3, $this->lookup_cells->records );

		// State now points at the new lookup_table_key.
		$this->assertSame( 'product_4242_pricing', $this->service->get_state( self::PRODUCT_ID )['lookup_table_key'] );
		$this->assertTrue( $this->registry->is_auto_managed( AutoManagedRegistry::TYPE_LOOKUP_TABLE, 'product_4242_pricing' ) );
	}

	public function test_save_pricing_replaces_previous_rows(): void {
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );
		$this->service->save_pricing_rows( self::PRODUCT_ID, [
			[ 'to_width' => 2100, 'to_height' => 2000, 'price' => 11000 ],
			[ 'to_width' => 2400, 'to_height' => 2000, 'price' => 12000 ],
		] );
		$this->assertCount( 2, $this->lookup_cells->records );

		// Re-save with one row → previous cells are wiped.
		$this->service->save_pricing_rows( self::PRODUCT_ID, [
			[ 'to_width' => 3000, 'to_height' => 2400, 'price' => 15000 ],
		] );
		$this->assertCount( 1, $this->lookup_cells->records );
		$only = array_values( $this->lookup_cells->records )[0];
		$this->assertSame( 3000, $only['width'] );
	}

	public function test_save_pricing_rejects_invalid_row(): void {
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );
		$result = $this->service->save_pricing_rows( self::PRODUCT_ID, [
			[ 'to_width' => 2100, 'to_height' => 2000, 'price' => 11000 ],
			[ 'to_width' => 0,    'to_height' => 2000, 'price' => 12000 ],
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_save_fabrics_provisions_textiles_module_and_per_product_library(): void {
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );

		$result = $this->service->save_fabrics( self::PRODUCT_ID, [
			[ 'name' => 'Beige', 'code' => 'U171', 'collection' => 'Orchestra', 'color_family' => 'beige', 'price_group' => 'I', 'extra_price' => 0, 'active' => true ],
			[ 'name' => 'Bordeaux', 'code' => 'U172', 'collection' => 'Orchestra', 'color_family' => 'red', 'price_group' => 'I', 'extra_price' => 0, 'active' => true ],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );

		// Textiles module created with the documented capabilities.
		$module = $this->modules->find_by_key( 'textiles' );
		$this->assertNotNull( $module );
		$this->assertTrue( $module['supports_brand'] );
		$this->assertTrue( $module['supports_color_family'] );

		// Library minted under Textiles, registered as auto-managed.
		$lib = $this->libraries->find_by_key( 'product_4242_fabrics' );
		$this->assertNotNull( $lib );
		$this->assertSame( 'textiles', $lib['module_key'] );
		$this->assertTrue( $this->registry->is_auto_managed( AutoManagedRegistry::TYPE_MODULE, 'textiles' ) );
		$this->assertTrue( $this->registry->is_auto_managed( AutoManagedRegistry::TYPE_LIBRARY, 'product_4242_fabrics' ) );

		// Items created — by SKU when present.
		$keys = array_column( $this->items->records, 'item_key' );
		$this->assertContains( 'u171', $keys );
		$this->assertContains( 'u172', $keys );

		// State carries the new library key.
		$this->assertSame( 'product_4242_fabrics', $this->service->get_state( self::PRODUCT_ID )['fabric_library_key'] );
	}

	public function test_save_fabrics_replaces_previous_set(): void {
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );
		$this->service->save_fabrics( self::PRODUCT_ID, [ [ 'name' => 'Beige', 'code' => 'U171' ] ] );
		$first_active_count = count( array_filter( $this->items->records, static fn ( $r ) => ! empty( $r['is_active'] ) ) );
		$this->assertSame( 1, $first_active_count );

		$this->service->save_fabrics( self::PRODUCT_ID, [
			[ 'name' => 'Sand',  'code' => 'U200' ],
			[ 'name' => 'Stone', 'code' => 'U201' ],
		] );
		$active = array_values( array_filter( $this->items->records, static fn ( $r ) => ! empty( $r['is_active'] ) ) );
		$this->assertCount( 2, $active );
		$keys = array_column( $active, 'item_key' );
		$this->assertContains( 'u200', $keys );
		$this->assertContains( 'u201', $keys );
		// Beige row is soft-deleted, not removed entirely.
		$inactive = array_values( array_filter( $this->items->records, static fn ( $r ) => empty( $r['is_active'] ) ) );
		$this->assertCount( 1, $inactive );
		$this->assertSame( 'u171', $inactive[0]['item_key'] );
	}

	public function test_save_fabrics_requires_name(): void {
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );
		$result = $this->service->save_fabrics( self::PRODUCT_ID, [
			[ 'name' => '', 'code' => 'U999' ],
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_state_marks_product_as_auto_managed_after_first_action(): void {
		$this->assertFalse( $this->state->is_auto_managed( self::PRODUCT_ID ) );
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );
		$this->assertTrue( $this->state->is_auto_managed( self::PRODUCT_ID ) );
	}
}
