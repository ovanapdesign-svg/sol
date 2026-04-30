<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Admin\ProductTypeRecipes;
use ConfigKit\Service\AutoManagedRegistry;
use ConfigKit\Service\FamilyService;
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
	private ProductBuilderState $state;
	private AutoManagedRegistry $registry;

	protected function setUp(): void {
		$this->product_meta = [];
		$this->option_store = [];

		$this->families  = new StubFamilyRepository();
		$this->templates = new StubTemplateRepository();

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

	public function test_state_marks_product_as_auto_managed_after_first_action(): void {
		$this->assertFalse( $this->state->is_auto_managed( self::PRODUCT_ID ) );
		$this->service->set_product_type( self::PRODUCT_ID, 'markise' );
		$this->assertTrue( $this->state->is_auto_managed( self::PRODUCT_ID ) );
	}
}
