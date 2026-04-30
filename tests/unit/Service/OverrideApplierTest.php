<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\OverrideApplier;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.3b half B — OverrideApplier is a pure transformer over
 * the resolver's base sections + a flat-path overrides map.
 */
final class OverrideApplierTest extends TestCase {

	private OverrideApplier $applier;

	protected function setUp(): void {
		$this->applier = new OverrideApplier();
	}

	public function test_no_overrides_returns_empty_metadata_per_section(): void {
		$result = $this->applier->apply( [ $this->section( 'motor', 0 ) ], [] );
		$this->assertSame( [], $result['orphan_paths'] );
		$this->assertSame( [], $result['global'] );
		$this->assertSame( [], $result['sections'][0]['overridden_paths'] );
		$this->assertSame( [], $result['sections'][0]['option_overrides'] );
		$this->assertSame( [], $result['sections'][0]['section_overrides'] );
	}

	public function test_price_override_routes_into_option_overrides_bucket(): void {
		$result = $this->applier->apply(
			[ $this->section( 'motor', 0 ) ],
			[ 'price_overrides.motor.0.somfy_io.price' => 4500 ]
		);
		$this->assertSame( [ 4500 ], [ $result['sections'][0]['option_overrides']['somfy_io']['price'] ] );
		$this->assertContains( 'price_overrides.motor.0.somfy_io.price', $result['sections'][0]['overridden_paths'] );
	}

	public function test_hidden_options_marks_each_listed_item_hidden(): void {
		$result = $this->applier->apply(
			[ $this->section( 'fabric', 0 ) ],
			[ 'hidden_options.fabric.0' => [ 'u171', 'u172' ] ]
		);
		$this->assertTrue( $result['sections'][0]['option_overrides']['u171']['is_hidden'] );
		$this->assertTrue( $result['sections'][0]['option_overrides']['u172']['is_hidden'] );
	}

	public function test_default_values_and_dimensions_land_in_section_overrides(): void {
		$result = $this->applier->apply(
			[ $this->section( 'size_pricing', 0 ) ],
			[
				'default_values.size_pricing.0.width' => 2000,
				'min_dimensions.size_pricing.0.min_width' => 1000,
				'max_dimensions.size_pricing.0.max_width' => 6000,
			]
		);
		$so = $result['sections'][0]['section_overrides'];
		$this->assertSame( 2000, $so['default_values']['width'] );
		$this->assertSame( 1000, $so['min_dimensions']['min_width'] );
		$this->assertSame( 6000, $so['max_dimensions']['max_width'] );
	}

	public function test_global_lookup_table_key_lands_in_global_bucket(): void {
		$result = $this->applier->apply(
			[ $this->section( 'size_pricing', 0 ) ],
			[ 'lookup_table_key' => 'roma_table_v2' ]
		);
		$this->assertSame( [ 'lookup_table_key' => 'roma_table_v2' ], $result['global'] );
	}

	public function test_orphan_path_for_unknown_section_collected(): void {
		$result = $this->applier->apply(
			[ $this->section( 'motor', 0 ) ],
			[ 'price_overrides.motor.5.ghost.price' => 999 ]
		);
		$this->assertContains( 'price_overrides.motor.5.ghost.price', $result['orphan_paths'] );
	}

	public function test_count_overrides_for_returns_only_matching_address(): void {
		$overrides = [
			'price_overrides.motor.0.somfy_io.price' => 4500,
			'price_overrides.motor.0.somfy_x.price'  => 5500,
			'hidden_options.motor.0'                  => [ 'somfy_y' ],
			'price_overrides.fabric.0.u171.price'    => 800,
		];
		$this->assertSame( 3, $this->applier->count_overrides_for( $overrides, 'motor', 0 ) );
		$this->assertSame( 1, $this->applier->count_overrides_for( $overrides, 'fabric', 0 ) );
		$this->assertSame( 0, $this->applier->count_overrides_for( $overrides, 'controls', 0 ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function section( string $type, int $position ): array {
		return [
			'id'            => 'sec_' . $type . '_xxxx',
			'type'          => $type,
			'type_position' => $position,
			'label'         => ucfirst( $type ),
		];
	}
}
