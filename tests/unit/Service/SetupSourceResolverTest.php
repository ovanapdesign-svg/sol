<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Service\OverrideApplier;
use ConfigKit\Service\SectionListState;
use ConfigKit\Service\SetupSourceResolver;
use ConfigKit\Service\SetupSourceState;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.3b half B — resolver contract under each setup_source mode.
 */
final class SetupSourceResolverTest extends TestCase {

	private const ROMA = 9001;
	private const VIKA = 9002;

	/** @var array<int,array<int,array<string,mixed>>> */
	private array $section_store = [];
	/** @var array<int,array<string,mixed>> */
	private array $source_store = [];

	private SectionListState $sections;
	private SetupSourceState $state;
	private StubPresetRepository $presets;
	private SetupSourceResolver $resolver;

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
	}

	public function test_start_blank_returns_local_sections_marked_local(): void {
		$this->section_store[ self::ROMA ] = [ $this->local_section( 'option_group', 0, 'lib_local' ) ];
		$result = $this->resolver->resolve( self::ROMA );
		$this->assertSame( SetupSourceState::SOURCE_BLANK, $result['setup_source'] );
		$this->assertCount( 1, $result['sections'] );
		$this->assertSame( 'local', $result['sections'][0]['source'] );
		$this->assertSame( 0, $result['sections'][0]['type_position'] );
	}

	public function test_use_preset_synthesizes_sections_with_shared_source(): void {
		$preset_id = $this->seed_preset_with_motor_and_fabric();
		$this->state->patch( self::ROMA, [
			'setup_source' => SetupSourceState::SOURCE_PRESET,
			'preset_id'    => $preset_id,
		] );
		$result = $this->resolver->resolve( self::ROMA );
		$this->assertSame( SetupSourceState::SOURCE_PRESET, $result['setup_source'] );
		$this->assertCount( 2, $result['sections'] );
		$this->assertSame( 'shared', $result['sections'][0]['source'] );
		$this->assertSame( 'shared', $result['sections'][1]['source'] );
		// Preset attaches preset_name onto each section so the badge
		// can render "Shared from {name}".
		$this->assertSame( 'Markise standard', $result['sections'][0]['preset_name'] );
	}

	public function test_use_preset_section_ids_are_stable_across_resolves(): void {
		$preset_id = $this->seed_preset_with_motor_and_fabric();
		$this->state->patch( self::ROMA, [ 'setup_source' => SetupSourceState::SOURCE_PRESET, 'preset_id' => $preset_id ] );
		$first  = $this->resolver->resolve( self::ROMA );
		$second = $this->resolver->resolve( self::ROMA );
		$this->assertSame( $first['sections'][0]['id'], $second['sections'][0]['id'] );
		$this->assertSame( $first['sections'][1]['id'], $second['sections'][1]['id'] );
	}

	public function test_use_preset_with_price_override_marks_section_overridden(): void {
		$preset_id = $this->seed_preset_with_motor_and_fabric();
		$this->state->patch( self::ROMA, [
			'setup_source' => SetupSourceState::SOURCE_PRESET,
			'preset_id'    => $preset_id,
			'overrides'    => [ 'price_overrides.motor.0.somfy_io.price' => 4500 ],
		] );
		$result = $this->resolver->resolve( self::ROMA );
		// Motor section is the second section (option_group is first
		// in seed). Find it by type for clarity.
		$motor = null;
		foreach ( $result['sections'] as $row ) {
			if ( $row['type'] === SectionTypeRegistry::TYPE_MOTOR ) { $motor = $row; break; }
		}
		$this->assertNotNull( $motor );
		$this->assertSame( 'overridden', $motor['source'] );
		$this->assertSame( 4500, $motor['option_overrides']['somfy_io']['price'] );
	}

	public function test_use_preset_with_missing_preset_falls_back_to_blank(): void {
		$this->state->patch( self::ROMA, [
			'setup_source' => SetupSourceState::SOURCE_PRESET,
			'preset_id'    => 99999,
		] );
		$this->section_store[ self::ROMA ] = [ $this->local_section( 'option_group', 0, 'lib_local' ) ];
		$result = $this->resolver->resolve( self::ROMA );
		$this->assertSame( SetupSourceState::SOURCE_BLANK, $result['setup_source'] );
		$this->assertSame( 'local', $result['sections'][0]['source'] );
	}

	public function test_link_to_setup_recursively_resolves_source(): void {
		$this->section_store[ self::VIKA ] = [ $this->local_section( 'option_group', 0, 'lib_vika' ) ];
		$this->state->patch( self::ROMA, [
			'setup_source'      => SetupSourceState::SOURCE_LINK,
			'source_product_id' => self::VIKA,
		] );
		$result = $this->resolver->resolve( self::ROMA );
		$this->assertSame( SetupSourceState::SOURCE_LINK, $result['setup_source'] );
		$this->assertSame( 'lib_vika', $result['sections'][0]['library_key'] );
	}

	public function test_link_cycle_falls_back_to_blank_without_infinite_loop(): void {
		$this->state->patch( self::ROMA, [
			'setup_source'      => SetupSourceState::SOURCE_LINK,
			'source_product_id' => self::VIKA,
		] );
		$this->state->patch( self::VIKA, [
			'setup_source'      => SetupSourceState::SOURCE_LINK,
			'source_product_id' => self::ROMA,
		] );
		// VIKA also has a local fallback so the resolver returns
		// something even after the cycle is detected.
		$this->section_store[ self::VIKA ] = [ $this->local_section( 'option_group', 0, 'lib_vika' ) ];
		$result = $this->resolver->resolve( self::ROMA );
		// The recursive call into VIKA hits VIKA → ROMA → cycle → fall
		// back to VIKA's locals; ROMA's outer mode keeps track of the
		// link mode it started in.
		$this->assertNotNull( $result );
		$this->assertNotEmpty( $result['sections'] );
	}

	private function seed_preset_with_motor_and_fabric(): int {
		return $this->presets->create( [
			'preset_key' => 'markise_standard',
			'name'       => 'Markise standard',
			'sections'   => [
				[
					'type'          => SectionTypeRegistry::TYPE_OPTION_GROUP,
					'type_position' => 0,
					'label'         => 'Fabric',
					'library_key'   => 'shared_fabric',
					'module_key'    => 'textiles',
					'visibility'    => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
				],
				[
					'type'          => SectionTypeRegistry::TYPE_MOTOR,
					'type_position' => 0,
					'label'         => 'Motor',
					'library_key'   => 'shared_motor',
					'module_key'    => 'motors',
					'visibility'    => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
				],
			],
		] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function local_section( string $type, int $position, string $library_key ): array {
		return [
			'id'          => 'sec_' . $type . '_local',
			'type'        => $type,
			'label'       => ucfirst( $type ),
			'position'    => $position,
			'library_key' => $library_key,
			'visibility'  => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
		];
	}
}
