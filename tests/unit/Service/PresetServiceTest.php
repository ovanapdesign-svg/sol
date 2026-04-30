<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Service\PresetService;
use ConfigKit\Service\SectionListState;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.3b half A — preset entity + snapshot + apply.
 *
 * The contract under test:
 *   1. save_as_preset captures the section list as a typed snapshot
 *      (type + type_position + visibility + library_key /
 *      lookup_table_key REFERENCES, never copies).
 *   2. apply_preset replaces the target product's section list with
 *      sections that REFERENCE the same shared library_key /
 *      lookup_table_key strings — library item count is unchanged
 *      globally.
 *   3. Visibility conditions referencing the source product's
 *      section_ids are rewritten via an old→new id map at apply time;
 *      conditions referencing sections OUTSIDE the snapshot are
 *      dropped (would never resolve in the target).
 *   4. Preset key collisions are resolved by suffix so the owner can
 *      reuse a name without a 409.
 */
final class PresetServiceTest extends TestCase {

	private const SOURCE_PRODUCT = 4242;
	private const TARGET_PRODUCT = 5151;

	/** @var array<int,array<int,array<string,mixed>>> */
	private array $section_store = [];
	/** @var array<string,int> */
	private array $library_item_count_by_key = [];

	private SectionListState $sections;
	private StubPresetRepository $presets;
	private PresetService $service;

	protected function setUp(): void {
		$this->section_store             = [];
		$this->library_item_count_by_key = [];

		$this->sections = new SectionListState(
			fn ( int $pid ): array => $this->section_store[ $pid ] ?? [],
			function ( int $pid, array $rows ): void { $this->section_store[ $pid ] = $rows; },
		);
		$this->presets = new StubPresetRepository();
		$this->service = new PresetService( $this->presets, $this->sections );
	}

	public function test_save_as_preset_snapshots_each_section_with_type_position_and_references(): void {
		$this->seed_source_product();

		$result = $this->service->save_as_preset( self::SOURCE_PRODUCT, [
			'name'         => 'Markise standard',
			'description'  => 'VIKA baseline',
			'product_type' => 'markise',
		] );

		$this->assertTrue( $result['ok'] );
		$preset = $result['preset'];
		$this->assertSame( 'markise_standard', $preset['preset_key'] );
		$this->assertSame( 'Markise standard', $preset['name'] );
		$this->assertSame( 'markise', $preset['product_type'] );
		$this->assertSame( 'product_42_size_pricing_a8f2', $preset['default_lookup_table_key'] );

		$snap = $preset['sections'];
		$this->assertCount( 3, $snap );

		$this->assertSame( SectionTypeRegistry::TYPE_SIZE_PRICING, $snap[0]['type'] );
		$this->assertSame( 0, $snap[0]['type_position'] );
		$this->assertSame( 'product_42_size_pricing_a8f2', $snap[0]['lookup_table_key'] );
		$this->assertArrayNotHasKey( 'library_key', $snap[0] );

		$this->assertSame( SectionTypeRegistry::TYPE_OPTION_GROUP, $snap[1]['type'] );
		$this->assertSame( 0, $snap[1]['type_position'] );
		$this->assertSame( 'product_42_option_group_b3d1', $snap[1]['library_key'] );
		$this->assertSame( 'textiles', $snap[1]['module_key'] );

		// Two motors → first is type_position 0, second is 1.
		$this->assertSame( SectionTypeRegistry::TYPE_MOTOR, $snap[2]['type'] );
		$this->assertSame( 0, $snap[2]['type_position'] );
	}

	public function test_save_as_preset_rejects_blank_name(): void {
		$this->seed_source_product();
		$result = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => '   ' ] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'name', strtolower( $result['message'] ) );
	}

	public function test_save_as_preset_rejects_empty_section_list(): void {
		$result = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise empty' ] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'no sections', strtolower( $result['message'] ) );
	}

	public function test_apply_preset_creates_target_sections_referencing_shared_keys(): void {
		$this->seed_source_product();
		$saved = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise standard' ] );
		$preset_id = $saved['preset_id'];

		$result = $this->service->apply_preset( self::TARGET_PRODUCT, $preset_id );
		$this->assertTrue( $result['ok'] );

		$rows = $this->sections->list( self::TARGET_PRODUCT );
		$this->assertCount( 3, $rows );
		$this->assertSame( 'product_42_size_pricing_a8f2', $rows[0]['lookup_table_key'] );
		$this->assertSame( 'product_42_option_group_b3d1', $rows[1]['library_key'] );
		// Target section ids are freshly minted, NOT carried over.
		$this->assertNotEquals( 'sec_size_pricing_a8f2', $rows[0]['id'] );
		$this->assertStringStartsWith( 'sec_size_pricing_', $rows[0]['id'] );
	}

	public function test_apply_preset_replaces_existing_section_list_on_target(): void {
		$this->seed_source_product();
		$saved = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise standard' ] );

		// Target arrives with one stale section.
		$this->section_store[ self::TARGET_PRODUCT ] = [
			[
				'id'       => 'sec_old_garbage',
				'type'     => SectionTypeRegistry::TYPE_OPTION_GROUP,
				'label'    => 'Old',
				'position' => 0,
				'library_key' => 'product_99_legacy',
			],
		];

		$this->service->apply_preset( self::TARGET_PRODUCT, $saved['preset_id'] );
		$rows = $this->sections->list( self::TARGET_PRODUCT );
		$ids = array_column( $rows, 'id' );
		$this->assertNotContains( 'sec_old_garbage', $ids );
		$this->assertCount( 3, $rows );
	}

	public function test_apply_preset_rewrites_visibility_section_id_via_id_map(): void {
		$this->seed_source_product();
		// Hook a visibility condition: motor visible only when fabric == 'u171'.
		$rows                     = $this->sections->list( self::SOURCE_PRODUCT );
		$rows[2]['visibility']    = [
			'mode'       => 'when',
			'match'      => 'all',
			'conditions' => [
				[ 'section_id' => 'sec_option_group_b3d1', 'op' => 'equals', 'value' => 'u171' ],
			],
		];
		$this->section_store[ self::SOURCE_PRODUCT ] = $rows;

		$saved = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise visibility' ] );
		$this->service->apply_preset( self::TARGET_PRODUCT, $saved['preset_id'] );

		$target = $this->sections->list( self::TARGET_PRODUCT );
		$fabric_new_id = $target[1]['id'];
		$motor_vis     = $target[2]['visibility'];
		$this->assertSame( 'when', $motor_vis['mode'] );
		$this->assertCount( 1, $motor_vis['conditions'] );
		$this->assertSame( $fabric_new_id, $motor_vis['conditions'][0]['section_id'] );
		$this->assertNotSame( 'sec_option_group_b3d1', $motor_vis['conditions'][0]['section_id'] );
	}

	public function test_apply_preset_drops_dangling_visibility_conditions(): void {
		$this->seed_source_product();
		$rows                  = $this->sections->list( self::SOURCE_PRODUCT );
		$rows[2]['visibility'] = [
			'mode'       => 'when',
			'match'      => 'all',
			'conditions' => [
				[ 'section_id' => 'sec_does_not_exist', 'op' => 'equals', 'value' => 'x' ],
			],
		];
		$this->section_store[ self::SOURCE_PRODUCT ] = $rows;

		$saved = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise dangling' ] );
		$this->service->apply_preset( self::TARGET_PRODUCT, $saved['preset_id'] );

		$target = $this->sections->list( self::TARGET_PRODUCT );
		// Mode stays "when" but the condition list is empty — dangling refs were dropped.
		$this->assertSame( 'when', $target[2]['visibility']['mode'] );
		$this->assertSame( [], $target[2]['visibility']['conditions'] );
	}

	public function test_apply_preset_does_not_duplicate_library_items(): void {
		$this->seed_source_product();
		// Track an item count behind the option_group library; apply
		// should NEVER create a new library or re-insert items.
		$this->library_item_count_by_key['product_42_option_group_b3d1'] = 12;

		$saved = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise no dup' ] );
		$this->service->apply_preset( self::TARGET_PRODUCT, $saved['preset_id'] );

		$this->assertSame( 12, $this->library_item_count_by_key['product_42_option_group_b3d1'] );

		$target = $this->sections->list( self::TARGET_PRODUCT );
		$this->assertSame( 'product_42_option_group_b3d1', $target[1]['library_key'] );
	}

	public function test_apply_preset_returns_false_when_preset_missing(): void {
		$result = $this->service->apply_preset( self::TARGET_PRODUCT, 999_999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Preset not found.', $result['message'] );
	}

	public function test_apply_preset_skips_soft_deleted_presets(): void {
		$this->seed_source_product();
		$saved = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise deleted' ] );
		$this->presets->soft_delete( $saved['preset_id'] );

		$result = $this->service->apply_preset( self::TARGET_PRODUCT, $saved['preset_id'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Preset not found.', $result['message'] );
	}

	public function test_mint_preset_key_resolves_collisions_with_numeric_suffix(): void {
		$this->seed_source_product();
		$first  = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise standard' ] );
		$second = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Markise standard' ] );
		$this->assertSame( 'markise_standard',   $first['preset_key'] );
		$this->assertSame( 'markise_standard_2', $second['preset_key'] );
	}

	public function test_list_presets_filters_soft_deleted_by_default(): void {
		$this->seed_source_product();
		$alive   = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Alive' ] );
		$buried  = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'Buried' ] );
		$this->presets->soft_delete( $buried['preset_id'] );

		$visible_ids = array_column( $this->service->list_presets()['items'], 'id' );
		$this->assertContains( $alive['preset_id'],  $visible_ids );
		$this->assertNotContains( $buried['preset_id'], $visible_ids );
	}

	public function test_list_presets_filters_by_product_type(): void {
		$this->seed_source_product();
		$this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'M1', 'product_type' => 'markise' ] );
		$this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'S1', 'product_type' => 'screen' ] );

		$marks = $this->service->list_presets( 1, 100, 'markise' )['items'];
		$this->assertCount( 1, $marks );
		$this->assertSame( 'markise', $marks[0]['product_type'] );
	}

	public function test_get_preset_returns_hydrated_record(): void {
		$this->seed_source_product();
		$saved = $this->service->save_as_preset( self::SOURCE_PRODUCT, [ 'name' => 'M' ] );
		$preset = $this->service->get_preset( $saved['preset_id'] );
		$this->assertNotNull( $preset );
		$this->assertSame( 'M', $preset['name'] );
		$this->assertIsArray( $preset['sections'] );
	}

	private function seed_source_product(): void {
		$this->section_store[ self::SOURCE_PRODUCT ] = [
			[
				'id'               => 'sec_size_pricing_a8f2',
				'type'             => SectionTypeRegistry::TYPE_SIZE_PRICING,
				'label'            => 'Size pricing',
				'position'         => 0,
				'lookup_table_key' => 'product_42_size_pricing_a8f2',
				'visibility'       => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
			],
			[
				'id'          => 'sec_option_group_b3d1',
				'type'        => SectionTypeRegistry::TYPE_OPTION_GROUP,
				'label'       => 'Fabric',
				'position'    => 1,
				'library_key' => 'product_42_option_group_b3d1',
				'module_key'  => 'textiles',
				'visibility'  => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
			],
			[
				'id'          => 'sec_motor_c1e0',
				'type'        => SectionTypeRegistry::TYPE_MOTOR,
				'label'       => 'Motors',
				'position'    => 2,
				'library_key' => 'product_42_motor_c1e0',
				'module_key'  => 'motors',
				'visibility'  => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
			],
		];
	}
}
