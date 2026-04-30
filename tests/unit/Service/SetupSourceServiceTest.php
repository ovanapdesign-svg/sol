<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Service\OverrideApplier;
use ConfigKit\Service\SectionListState;
use ConfigKit\Service\SetupSourceResolver;
use ConfigKit\Service\SetupSourceService;
use ConfigKit\Service\SetupSourceState;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.3b half B — owner-action contract: copy / link / detach /
 * reset_override / write_override.
 */
final class SetupSourceServiceTest extends TestCase {

	private const ROMA = 7001;
	private const VIKA = 7002;

	/** @var array<int,array<int,array<string,mixed>>> */
	private array $section_store = [];
	/** @var array<int,array<string,mixed>> */
	private array $source_store = [];

	private SectionListState $sections;
	private SetupSourceState $state;
	private StubPresetRepository $presets;
	private SetupSourceResolver $resolver;
	private SetupSourceService $service;

	protected function setUp(): void {
		$this->section_store = [];
		$this->source_store  = [];
		$this->sections = new SectionListState(
			fn ( int $pid ): array => $this->section_store[ $pid ] ?? [],
			function ( int $pid, array $rows ): void { $this->section_store[ $pid ] = $rows; },
		);
		$this->state = new SetupSourceState(
			fn ( int $pid ): array => $this->source_store[ $pid ] ?? [],
			function ( int $pid, array $row ): void { $this->source_store[ $pid ] = $row; },
		);
		$this->presets  = new StubPresetRepository();
		$this->resolver = new SetupSourceResolver( $this->state, $this->sections, $this->presets, new OverrideApplier() );
		$this->service  = new SetupSourceService( $this->state, $this->sections, $this->resolver );
	}

	public function test_copy_from_product_clones_sections_into_target_local_list(): void {
		$this->seed_vika_with_two_sections();
		$result = $this->service->copy_from_product( self::ROMA, self::VIKA );
		$this->assertTrue( $result['ok'] );
		$rows = $this->sections->list( self::ROMA );
		$this->assertCount( 2, $rows );
	}

	public function test_copy_from_product_preserves_library_key_references(): void {
		$this->seed_vika_with_two_sections();
		$this->service->copy_from_product( self::ROMA, self::VIKA );
		$rows = $this->sections->list( self::ROMA );
		$lib_keys = array_filter( array_column( $rows, 'library_key' ) );
		$this->assertContains( 'lib_vika_fabric', $lib_keys );
		$this->assertContains( 'lib_vika_motor', $lib_keys );
	}

	public function test_copy_from_product_makes_target_independent_of_source(): void {
		$this->seed_vika_with_two_sections();
		$this->service->copy_from_product( self::ROMA, self::VIKA );

		// Wipe VIKA's section list — ROMA must NOT lose its copy.
		foreach ( $this->section_store[ self::VIKA ] as $row ) {
			$this->sections->remove( self::VIKA, (string) $row['id'] );
		}
		$rows = $this->sections->list( self::ROMA );
		$this->assertCount( 2, $rows );
		$this->assertSame( SetupSourceState::SOURCE_BLANK, $this->state->get( self::ROMA )['setup_source'] );
	}

	public function test_copy_from_product_rejects_self_copy(): void {
		$result = $this->service->copy_from_product( self::ROMA, self::ROMA );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'itself', $result['message'] );
	}

	public function test_copy_from_product_lookup_choice_reuse_swaps_lookup_table_key(): void {
		$this->section_store[ self::VIKA ] = [
			[
				'id' => 'sec_size_pricing_a', 'type' => SectionTypeRegistry::TYPE_SIZE_PRICING,
				'label' => 'Size pricing', 'position' => 0,
				'lookup_table_key' => 'shared_table_v1',
				'visibility' => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
			],
		];
		$result = $this->service->copy_from_product(
			self::ROMA, self::VIKA, SetupSourceService::LOOKUP_REUSE, 'roma_table_v2'
		);
		$this->assertTrue( $result['ok'] );
		$rows = $this->sections->list( self::ROMA );
		$this->assertSame( 'roma_table_v2', $rows[0]['lookup_table_key'] );
	}

	public function test_link_to_setup_sets_state_and_clears_local_sections(): void {
		$this->seed_vika_with_two_sections();
		$this->section_store[ self::ROMA ] = [
			[
				'id' => 'sec_old_garbage', 'type' => SectionTypeRegistry::TYPE_OPTION_GROUP,
				'label' => 'Old', 'position' => 0,
				'visibility' => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
			],
		];
		$result = $this->service->link_to_setup( self::ROMA, self::VIKA );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( SetupSourceState::SOURCE_LINK, $this->state->get( self::ROMA )['setup_source'] );
		$this->assertSame( self::VIKA, $this->state->get( self::ROMA )['source_product_id'] );
		$this->assertSame( [], $this->sections->list( self::ROMA ) );
	}

	public function test_link_to_setup_rejects_self_link(): void {
		$result = $this->service->link_to_setup( self::ROMA, self::ROMA );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'itself', $result['message'] );
	}

	public function test_detach_from_preset_materializes_resolved_view_into_local_sections(): void {
		$preset_id = $this->seed_preset();
		$this->state->patch( self::ROMA, [
			'setup_source' => SetupSourceState::SOURCE_PRESET,
			'preset_id'    => $preset_id,
		] );
		$result = $this->service->detach_from_preset( self::ROMA );
		$this->assertTrue( $result['ok'] );
		$rows = $this->sections->list( self::ROMA );
		$this->assertCount( 2, $rows );
		$this->assertSame( SetupSourceState::SOURCE_BLANK, $this->state->get( self::ROMA )['setup_source'] );
		$this->assertSame( [], $this->state->get( self::ROMA )['overrides'] );
	}

	public function test_detach_from_preset_rejects_when_already_blank(): void {
		$result = $this->service->detach_from_preset( self::ROMA );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'already independent', $result['message'] );
	}

	public function test_reset_override_removes_path_when_present(): void {
		$this->state->patch( self::ROMA, [
			'overrides' => [ 'price_overrides.motor.0.somfy_io.price' => 4500 ],
		] );
		$result = $this->service->reset_override( self::ROMA, 'price_overrides.motor.0.somfy_io.price' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( [], $this->state->get( self::ROMA )['overrides'] );
	}

	public function test_reset_override_returns_false_for_unknown_path(): void {
		$result = $this->service->reset_override( self::ROMA, 'nope.does.not.exist' );
		$this->assertFalse( $result['ok'] );
	}

	public function test_write_override_validates_bucket_prefix(): void {
		$result = $this->service->write_override( self::ROMA, 'made_up_bucket.motor.0.somfy_io', 4500 );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Unknown override bucket', $result['message'] );
	}

	public function test_write_override_stores_path_in_overrides(): void {
		$result = $this->service->write_override( self::ROMA, 'price_overrides.motor.0.somfy_io.price', 4500 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 4500, $this->state->get( self::ROMA )['overrides']['price_overrides.motor.0.somfy_io.price'] );
	}

	public function test_write_override_top_level_lookup_table_key_allowed(): void {
		$result = $this->service->write_override( self::ROMA, 'lookup_table_key', 'roma_table_v2' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'roma_table_v2', $this->state->get( self::ROMA )['overrides']['lookup_table_key'] );
	}

	private function seed_vika_with_two_sections(): void {
		$this->section_store[ self::VIKA ] = [
			[
				'id' => 'sec_option_group_a', 'type' => SectionTypeRegistry::TYPE_OPTION_GROUP,
				'label' => 'Fabric', 'position' => 0,
				'library_key' => 'lib_vika_fabric', 'module_key' => 'textiles',
				'visibility' => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
			],
			[
				'id' => 'sec_motor_a', 'type' => SectionTypeRegistry::TYPE_MOTOR,
				'label' => 'Motor', 'position' => 1,
				'library_key' => 'lib_vika_motor', 'module_key' => 'motors',
				'visibility' => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
			],
		];
	}

	private function seed_preset(): int {
		return $this->presets->create( [
			'preset_key' => 'markise_standard',
			'name'       => 'Markise standard',
			'sections'   => [
				[
					'type' => SectionTypeRegistry::TYPE_OPTION_GROUP,
					'type_position' => 0,
					'label' => 'Fabric',
					'library_key' => 'shared_fabric',
					'module_key' => 'textiles',
					'visibility' => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
				],
				[
					'type' => SectionTypeRegistry::TYPE_MOTOR,
					'type_position' => 0,
					'label' => 'Motor',
					'library_key' => 'shared_motor',
					'module_key' => 'motors',
					'visibility' => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
				],
			],
		] );
	}
}
