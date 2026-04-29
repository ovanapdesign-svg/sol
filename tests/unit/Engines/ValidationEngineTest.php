<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Engines;

use ConfigKit\Engines\ValidationEngine;
use PHPUnit\Framework\TestCase;

final class ValidationEngineTest extends TestCase {

	private ValidationEngine $engine;

	protected function setUp(): void {
		$this->engine = new ValidationEngine();
	}

	private function template_with( array $fields ): array {
		return [ 'fields' => $fields ];
	}

	public function test_required_field_present_is_valid(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'control_type' => [
					'is_required' => true,
					'input_type'  => 'radio',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'control_type' => [ 'visible' => true, 'required' => false ] ],
			],
			'selections' => [ 'control_type' => 'motorized' ],
		] );

		$this->assertTrue( $out['valid'] );
		$this->assertSame( [], $out['errors'] );
		$this->assertSame( 'motorized', $out['coerced_selections']['control_type'] );
	}

	public function test_required_field_empty_produces_error(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'control_type' => [
					'is_required' => true,
					'input_type'  => 'radio',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'control_type' => [ 'visible' => true, 'required' => false ] ],
			],
			'selections' => [],
		] );

		$this->assertFalse( $out['valid'] );
		$this->assertCount( 1, $out['errors'] );
		$this->assertSame( 'control_type', $out['errors'][0]['field_key'] );
		$this->assertSame( 'required', $out['errors'][0]['code'] );
	}

	public function test_required_field_hidden_by_rule_is_not_required(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'control_type' => [
					'is_required' => true,
					'input_type'  => 'radio',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'control_type' => [ 'visible' => false, 'required' => false ] ],
			],
			'selections' => [],
		] );

		$this->assertTrue( $out['valid'] );
		$this->assertSame( [], $out['errors'] );
	}

	public function test_rule_required_overrides_template_optional(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'fabric_color' => [
					'is_required' => false,
					'input_type'  => 'radio',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'fabric_color' => [ 'visible' => true, 'required' => true ] ],
			],
			'selections' => [],
		] );

		$this->assertFalse( $out['valid'] );
		$this->assertSame( 'required', $out['errors'][0]['code'] );
	}

	public function test_number_string_is_coerced_to_int(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'width_mm' => [
					'is_required' => true,
					'input_type'  => 'number',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'width_mm' => [ 'visible' => true, 'required' => false ] ],
			],
			'selections' => [ 'width_mm' => '4000' ],
		] );

		$this->assertTrue( $out['valid'] );
		$this->assertSame( 4000, $out['coerced_selections']['width_mm'] );
	}

	public function test_number_decimal_string_is_coerced_to_float(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'width_mm' => [ 'input_type' => 'number' ],
			] ),
			'rule_engine_output' => [],
			'selections' => [ 'width_mm' => '4000.5' ],
		] );

		$this->assertSame( 4000.5, $out['coerced_selections']['width_mm'] );
	}

	public function test_non_numeric_for_number_field_is_invalid_type(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'width_mm' => [
					'is_required' => false,
					'input_type'  => 'number',
				],
			] ),
			'rule_engine_output' => [],
			'selections' => [ 'width_mm' => 'abc' ],
		] );

		// 'abc' coerces to null (rejected as numeric); since not required → still valid
		$this->assertTrue( $out['valid'] );
		$this->assertArrayNotHasKey( 'width_mm', $out['coerced_selections'] );
	}

	public function test_checkbox_scalar_is_coerced_to_array(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'sensor_addon' => [ 'input_type' => 'checkbox' ],
			] ),
			'rule_engine_output' => [],
			'selections' => [ 'sensor_addon' => 'SOM-IO-WIND-300' ],
		] );

		$this->assertSame( [ 'SOM-IO-WIND-300' ], $out['coerced_selections']['sensor_addon'] );
	}

	public function test_checkbox_array_passes_through(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'sensor_addon' => [ 'input_type' => 'checkbox' ],
			] ),
			'rule_engine_output' => [],
			'selections' => [ 'sensor_addon' => [ 'A', 'B', 'C' ] ],
		] );

		$this->assertSame( [ 'A', 'B', 'C' ], $out['coerced_selections']['sensor_addon'] );
	}

	public function test_array_for_radio_field_is_dropped_as_invalid(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'control_type' => [
					'is_required' => true,
					'input_type'  => 'radio',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'control_type' => [ 'visible' => true ] ],
			],
			'selections' => [ 'control_type' => [ 'a', 'b' ] ],
		] );

		// Coerced to null → required field empty → error
		$this->assertFalse( $out['valid'] );
		$this->assertSame( 'required', $out['errors'][0]['code'] );
	}

	public function test_rule_blocked_propagates_as_error(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [] ),
			'rule_engine_output' => [
				'fields'       => [],
				'blocked'      => true,
				'block_reason' => 'Combination unavailable.',
			],
			'selections' => [],
		] );

		$this->assertFalse( $out['valid'] );
		$this->assertSame( 'blocked', $out['errors'][0]['code'] );
		$this->assertSame( 'Combination unavailable.', $out['errors'][0]['message'] );
		$this->assertNull( $out['errors'][0]['field_key'] );
	}

	public function test_empty_string_is_treated_as_empty_for_required(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'fabric_color' => [
					'is_required' => true,
					'input_type'  => 'radio',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'fabric_color' => [ 'visible' => true ] ],
			],
			'selections' => [ 'fabric_color' => '' ],
		] );

		$this->assertFalse( $out['valid'] );
		$this->assertSame( 'required', $out['errors'][0]['code'] );
	}

	public function test_empty_array_is_treated_as_empty_for_required_checkbox(): void {
		$out = $this->engine->validate( [
			'template' => $this->template_with( [
				'sensor_addon' => [
					'is_required' => true,
					'input_type'  => 'checkbox',
				],
			] ),
			'rule_engine_output' => [
				'fields' => [ 'sensor_addon' => [ 'visible' => true ] ],
			],
			'selections' => [ 'sensor_addon' => [] ],
		] );

		$this->assertFalse( $out['valid'] );
		$this->assertSame( 'required', $out['errors'][0]['code'] );
	}
}
