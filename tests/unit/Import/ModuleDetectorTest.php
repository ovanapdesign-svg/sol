<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Import\ModuleDetector;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Service\CapabilityFormSchema;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.2c — score Excel header rows against active modules. The
 * detector explains how confident a match is so the wizard can show
 * "Detected: Textiles (87 % of headers match)".
 */
final class ModuleDetectorTest extends TestCase {

	private ModuleDetector $detector;

	protected function setUp(): void {
		$this->detector = new ModuleDetector( new CapabilityFormSchema() );
	}

	private function module( array $caps = [], array $attrs = [], string $key = 'm', string $name = 'M' ): array {
		$module = [ 'module_key' => $key, 'name' => $name, 'is_active' => true ];
		foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
			$module[ $flag ] = ! empty( $caps[ $flag ] );
		}
		$module['attribute_schema'] = $attrs;
		return $module;
	}

	public function test_textiles_headers_score_high_against_textiles_module(): void {
		$textiles = $this->module( [
			'supports_sku' => true, 'supports_image' => true, 'supports_price_group' => true,
			'supports_brand' => true, 'supports_collection' => true, 'supports_color_family' => true,
			'supports_filters' => true, 'supports_compatibility' => true,
		], [
			'fabric_code' => [ 'label' => 'Fabric code', 'type' => 'text' ],
			'material'    => [ 'label' => 'Material',    'type' => 'text' ],
			'transparency' => [ 'label' => 'Transparency', 'type' => 'enum', 'options' => [ 'a', 'b' ] ],
		], 'textiles', 'Textiles' );

		$headers = [ 'item_key', 'label', 'sku', 'image_url', 'price_group', 'color_family', 'fabric_code', 'material', 'transparency' ];
		$score = $this->detector->score( $textiles, $headers );

		$this->assertSame( 7, $score['matched'], 'all 7 non-universal headers should hit textiles' );
		$this->assertSame( 7, $score['total'] );
		$this->assertEqualsWithDelta( 1.0, $score['ratio'], 0.0001 );
	}

	public function test_motors_module_loses_to_textiles_when_file_is_textiles(): void {
		$textiles = $this->module( [ 'supports_sku' => true, 'supports_image' => true, 'supports_brand' => true, 'supports_color_family' => true ], [
			'fabric_code' => [ 'label' => 'Fabric code', 'type' => 'text' ],
		], 'textiles', 'Textiles' );
		$motors = $this->module( [ 'supports_sku' => true, 'supports_price' => true, 'supports_woo_product_link' => true ], [], 'motors', 'Motors' );

		$headers = [ 'item_key', 'label', 'sku', 'image_url', 'color_family', 'fabric_code' ];
		$result = $this->detector->pick_best( [ $textiles, $motors ], $headers );

		$this->assertNotNull( $result['module'] );
		$this->assertSame( 'textiles', $result['module']['module_key'] );
		// Ranked list ordered textiles first.
		$this->assertSame( 'textiles', $result['ranked'][0]['module_key'] );
	}

	public function test_no_module_picked_when_overlap_below_threshold(): void {
		$motors = $this->module( [ 'supports_sku' => true, 'supports_price' => true ], [], 'motors', 'Motors' );

		// File has 4 non-universal headers; motors only matches sku.
		// 1 / 4 = 0.25 < 0.6 threshold → no pick.
		$headers = [ 'item_key', 'label', 'sku', 'fabric_code', 'transparency', 'collection' ];
		$result = $this->detector->pick_best( [ $motors ], $headers );
		$this->assertNull( $result['module'] );
		$this->assertLessThan( 0.6, $result['ratio'] );
	}

	public function test_universal_headers_excluded_from_denominator(): void {
		// File with ONLY universal headers gives total = 0 — no match
		// possible, but no division-by-zero either.
		$module = $this->module( [ 'supports_sku' => true ], [], 'm', 'M' );
		$score  = $this->detector->score( $module, [ 'library_key', 'item_key', 'label', 'description' ] );
		$this->assertSame( 0, $score['total'] );
		$this->assertSame( 0.0, $score['ratio'] );
	}

	public function test_attr_dot_alias_counts_as_a_match(): void {
		$module = $this->module( [], [
			'pantone_code' => [ 'label' => 'Pantone code', 'type' => 'text' ],
		], 'colors', 'Colors' );
		$headers = [ 'item_key', 'label', 'attr.pantone_code' ];
		$score = $this->detector->score( $module, $headers );
		$this->assertSame( 1, $score['matched'] );
		$this->assertSame( [ 'pantone_code' ], $score['matches'] );
	}

	public function test_threshold_is_configurable_for_strict_picks(): void {
		$strict = new ModuleDetector( new CapabilityFormSchema(), 0.95 );
		$module = $this->module( [ 'supports_sku' => true, 'supports_image' => true ], [], 'm', 'M' );
		// Only 2 of 3 non-universal headers match.
		$headers = [ 'item_key', 'label', 'sku', 'image_url', 'something_unknown' ];
		$result = $strict->pick_best( [ $module ], $headers );
		$this->assertNull( $result['module'], 'strict threshold should reject 2/3 match' );
	}
}
