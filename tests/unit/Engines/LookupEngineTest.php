<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Engines;

use ConfigKit\Engines\LookupEngine;
use PHPUnit\Framework\TestCase;

final class LookupEngineTest extends TestCase {

	private LookupEngine $engine;

	protected function setUp(): void {
		$this->engine = new LookupEngine();
	}

	private function cell( int $w, int $h, float $price, string $pg = '' ): array {
		return [
			'width'           => $w,
			'height'          => $h,
			'price_group_key' => $pg,
			'price'           => $price,
		];
	}

	public function test_exact_match_2d(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 4000,
			'height'               => 3000,
			'price_group_key'      => '',
			'supports_price_group' => false,
			'match_mode'           => 'exact',
			'cells'                => [
				$this->cell( 3000, 3000, 7800.0 ),
				$this->cell( 4000, 3000, 8900.0 ),
				$this->cell( 5000, 3000, 9700.0 ),
			],
		] );

		$this->assertTrue( $out['matched'] );
		$this->assertSame( 8900.0, $out['price'] );
		$this->assertSame( 'exact', $out['match_strategy'] );
		$this->assertSame( 4000, $out['cell']['width'] );
	}

	public function test_exact_no_match_returns_no_cell(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 4001,
			'height'               => 3000,
			'price_group_key'      => '',
			'supports_price_group' => false,
			'match_mode'           => 'exact',
			'cells'                => [ $this->cell( 4000, 3000, 8900.0 ) ],
		] );

		$this->assertFalse( $out['matched'] );
		$this->assertSame( 'no_cell', $out['reason'] );
	}

	public function test_round_up_picks_smallest_cell_at_or_above_request(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 3500,
			'height'               => 2800,
			'price_group_key'      => '',
			'supports_price_group' => false,
			'match_mode'           => 'round_up',
			'cells'                => [
				$this->cell( 3000, 3000, 7800.0 ),
				$this->cell( 4000, 3000, 8900.0 ),
				$this->cell( 5000, 3000, 9700.0 ),
				$this->cell( 4000, 4000, 9100.0 ),
			],
		] );

		$this->assertTrue( $out['matched'] );
		$this->assertSame( 'round_up', $out['match_strategy'] );
		$this->assertSame( 4000, $out['cell']['width'] );
		$this->assertSame( 3000, $out['cell']['height'] );
		$this->assertSame( 8900.0, $out['price'] );
	}

	public function test_round_up_exceeds_max_dimensions(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 9000,
			'height'               => 5000,
			'price_group_key'      => '',
			'supports_price_group' => false,
			'match_mode'           => 'round_up',
			'cells'                => [
				$this->cell( 5000, 3000, 9700.0 ),
			],
		] );

		$this->assertFalse( $out['matched'] );
		$this->assertSame( 'exceeds_max_dimensions', $out['reason'] );
	}

	public function test_nearest_returns_closest_cell(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 4100,
			'height'               => 3050,
			'price_group_key'      => '',
			'supports_price_group' => false,
			'match_mode'           => 'nearest',
			'cells'                => [
				$this->cell( 3000, 3000, 7800.0 ),
				$this->cell( 4000, 3000, 8900.0 ),
				$this->cell( 5000, 3000, 9700.0 ),
			],
		] );

		$this->assertTrue( $out['matched'] );
		$this->assertSame( 4000, $out['cell']['width'] );
		$this->assertSame( 'nearest', $out['match_strategy'] );
	}

	public function test_3d_match_with_price_group(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_3d_v1',
			'width'                => 4000,
			'height'               => 3000,
			'price_group_key'      => 'II',
			'supports_price_group' => true,
			'match_mode'           => 'exact',
			'cells'                => [
				$this->cell( 4000, 3000, 8900.0, 'I' ),
				$this->cell( 4000, 3000, 9900.0, 'II' ),
				$this->cell( 4000, 3000, 10900.0, 'III' ),
			],
		] );

		$this->assertTrue( $out['matched'] );
		$this->assertSame( 'II', $out['cell']['price_group_key'] );
		$this->assertSame( 9900.0, $out['price'] );
	}

	public function test_3d_request_falls_back_to_2d_when_table_does_not_support_groups(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 4000,
			'height'               => 3000,
			'price_group_key'      => 'II',          // request carries group …
			'supports_price_group' => false,         // … but table is 2D, so ignored
			'match_mode'           => 'exact',
			'cells'                => [
				$this->cell( 4000, 3000, 8900.0, '' ),
			],
		] );

		$this->assertTrue( $out['matched'] );
		$this->assertSame( '', $out['cell']['price_group_key'] );
		$this->assertSame( '', $out['requested']['price_group_key'] );
	}

	public function test_no_cells_returns_no_cell(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 4000,
			'height'               => 3000,
			'price_group_key'      => '',
			'supports_price_group' => false,
			'match_mode'           => 'round_up',
			'cells'                => [],
		] );

		$this->assertFalse( $out['matched'] );
		$this->assertSame( 'no_cell', $out['reason'] );
	}

	public function test_3d_with_no_cell_for_requested_group_returns_no_cell(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_3d_v1',
			'width'                => 4000,
			'height'               => 3000,
			'price_group_key'      => 'IV',
			'supports_price_group' => true,
			'match_mode'           => 'exact',
			'cells'                => [
				$this->cell( 4000, 3000, 8900.0, 'I' ),
				$this->cell( 4000, 3000, 9900.0, 'II' ),
			],
		] );

		$this->assertFalse( $out['matched'] );
		$this->assertSame( 'no_cell', $out['reason'] );
	}

	public function test_round_up_picks_smallest_when_two_cells_satisfy(): void {
		$out = $this->engine->match( [
			'lookup_table_key'     => 'markise_2d_v1',
			'width'                => 3500,
			'height'               => 2900,
			'price_group_key'      => '',
			'supports_price_group' => false,
			'match_mode'           => 'round_up',
			'cells'                => [
				$this->cell( 4000, 3000, 8900.0 ),
				$this->cell( 5000, 3000, 9700.0 ),
				$this->cell( 4000, 4000, 9100.0 ),
			],
		] );

		// Smallest by width: 4000x3000 (8900). Both 4000s are valid; 3000 wins on height tie-break.
		$this->assertSame( 4000, $out['cell']['width'] );
		$this->assertSame( 3000, $out['cell']['height'] );
	}
}
