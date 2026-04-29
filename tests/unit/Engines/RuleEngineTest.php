<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Engines;

use ConfigKit\Engines\RuleEngine;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase {

	private RuleEngine $engine;

	protected function setUp(): void {
		$this->engine = new RuleEngine();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_input( array $overrides = [] ): array {
		return array_replace_recursive(
			[
				'template_key' => 'tpl_test',
				'template_version_id' => 1,
				'rules' => [],
				'selections' => [],
				'field_metadata' => [
					'control_type' => [
						'is_required' => true,
						'default_visible' => true,
						'default_value' => null,
						'step_key' => 'step_control',
					],
					'width_mm' => [
						'is_required' => true,
						'default_visible' => true,
						'default_value' => null,
						'step_key' => 'step_size',
					],
					'height_mm' => [
						'is_required' => true,
						'default_visible' => true,
						'default_value' => null,
						'step_key' => 'step_size',
					],
					'fabric_color' => [
						'is_required' => false,
						'default_visible' => true,
						'default_value' => null,
						'step_key' => 'step_fabric',
					],
					'sensor_addon' => [
						'is_required' => false,
						'default_visible' => true,
						'default_value' => null,
						'step_key' => 'step_addons',
					],
					'control_system' => [
						'is_required' => false,
						'default_visible' => true,
						'default_value' => null,
						'step_key' => 'step_control',
					],
				],
				'step_metadata' => [
					'step_control' => [ 'default_visible' => true ],
					'step_size' => [ 'default_visible' => true ],
					'step_fabric' => [ 'default_visible' => true ],
					'step_addons' => [ 'default_visible' => true ],
					'motor_and_control' => [ 'default_visible' => true ],
				],
			],
			$overrides
		);
	}

	private function rule( string $key, array $when, array $then, int $priority = 100, int $sort_order = 0 ): array {
		return [
			'rule_key' => $key,
			'priority' => $priority,
			'sort_order' => $sort_order,
			'is_active' => true,
			'spec' => [ 'when' => $when, 'then' => $then ],
		];
	}

	// ---- Operator tests ----

	public function test_equals_operator(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_type' => 'motorized' ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'control_type', 'op' => 'equals', 'value' => 'motorized' ],
					[ [ 'action' => 'show_step', 'step' => 'motor_and_control' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
	}

	public function test_not_equals_operator(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_type' => 'manual' ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'control_type', 'op' => 'not_equals', 'value' => 'motorized' ],
					[ [ 'action' => 'show_warning', 'message' => 'manual', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
		$this->assertCount( 1, $out['warnings'] );
	}

	public function test_greater_than_and_less_than(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 6000 ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 5000 ],
					[ [ 'action' => 'show_warning', 'message' => 'wide', 'level' => 'warning' ] ]
				),
				$this->rule(
					'r2',
					[ 'field' => 'width_mm', 'op' => 'less_than', 'value' => 7000 ],
					[ [ 'action' => 'show_warning', 'message' => 'not_too_wide', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
		$this->assertTrue( $out['rule_results'][1]['matched'] );
	}

	public function test_between_operator(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 4000 ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'width_mm', 'op' => 'between', 'value' => [ 3000, 5000 ] ],
					[ [ 'action' => 'show_warning', 'message' => 'in_range', 'level' => 'info' ] ]
				),
				$this->rule(
					'r2',
					[ 'field' => 'width_mm', 'op' => 'between', 'value' => [ 5000, 7000 ] ],
					[ [ 'action' => 'show_warning', 'message' => 'oversize', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
		$this->assertFalse( $out['rule_results'][1]['matched'] );
	}

	public function test_in_and_not_in_operators(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_system' => 'io' ],
			'rules' => [
				$this->rule(
					'r_in',
					[ 'field' => 'control_system', 'op' => 'in', 'value' => [ 'io', 'rts' ] ],
					[ [ 'action' => 'filter_source', 'field' => 'sensor_addon', 'filter' => [ 'tag' => 'io' ] ] ]
				),
				$this->rule(
					'r_not_in',
					[ 'field' => 'control_system', 'op' => 'not_in', 'value' => [ 'manual_only' ] ],
					[ [ 'action' => 'show_warning', 'message' => 'compatible', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
		$this->assertTrue( $out['rule_results'][1]['matched'] );
		$this->assertSame( [ 'tag' => 'io' ], $out['fields']['sensor_addon']['options_filter'] );
	}

	public function test_contains_operator_for_array(): void {
		$input = $this->base_input( [
			'selections' => [ 'sensor_addon' => [ 'SOM-IO-WIND-300' ] ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'sensor_addon', 'op' => 'contains', 'value' => 'SOM-IO-WIND-300' ],
					[ [ 'action' => 'show_warning', 'message' => 'has_wind', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
	}

	public function test_is_selected_and_is_empty_operators(): void {
		$input = $this->base_input( [
			'selections' => [ 'fabric_color' => 'textiles_dickson:u171', 'sensor_addon' => [] ],
			'rules' => [
				$this->rule(
					'r_selected',
					[ 'field' => 'fabric_color', 'op' => 'is_selected' ],
					[ [ 'action' => 'show_warning', 'message' => 'fabric_chosen', 'level' => 'info' ] ]
				),
				$this->rule(
					'r_empty',
					[ 'field' => 'sensor_addon', 'op' => 'is_empty' ],
					[ [ 'action' => 'show_warning', 'message' => 'no_sensor', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
		$this->assertTrue( $out['rule_results'][1]['matched'] );
	}

	public function test_logical_all_any_not(): void {
		$input = $this->base_input( [
			'selections' => [
				'control_type' => 'motorized',
				'control_system' => 'io',
				'width_mm' => 5500,
			],
			'rules' => [
				$this->rule(
					'r_all_any',
					[
						'all' => [
							[ 'field' => 'control_type', 'op' => 'equals', 'value' => 'motorized' ],
							[
								'any' => [
									[ 'field' => 'control_system', 'op' => 'equals', 'value' => 'io' ],
									[ 'field' => 'control_system', 'op' => 'equals', 'value' => 'rts' ],
								],
							],
							[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 4000 ],
						],
					],
					[ [ 'action' => 'add_surcharge', 'label' => 'matched', 'amount' => 100 ] ]
				),
				$this->rule(
					'r_not',
					[ 'not' => [ 'field' => 'control_type', 'op' => 'equals', 'value' => 'manual' ] ],
					[ [ 'action' => 'show_warning', 'message' => 'not_manual', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
		$this->assertTrue( $out['rule_results'][1]['matched'] );
	}

	public function test_always_true_and_false(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r_always',
					[ 'always' => true ],
					[ [ 'action' => 'show_warning', 'message' => 'always', 'level' => 'info' ] ]
				),
				$this->rule(
					'r_never',
					[ 'always' => false ],
					[ [ 'action' => 'show_warning', 'message' => 'never', 'level' => 'info' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['rule_results'][0]['matched'] );
		$this->assertFalse( $out['rule_results'][1]['matched'] );
	}

	// ---- Action tests ----

	public function test_show_field_and_hide_field(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_type' => 'manual' ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'control_type', 'op' => 'equals', 'value' => 'manual' ],
					[ [ 'action' => 'hide_field', 'field' => 'control_system' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertFalse( $out['fields']['control_system']['visible'] );
	}

	public function test_show_step_and_hide_step_cascades_to_fields(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_type' => 'manual' ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'control_type', 'op' => 'equals', 'value' => 'manual' ],
					[ [ 'action' => 'hide_step', 'step' => 'step_control' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertFalse( $out['steps']['step_control']['visible'] );
		$this->assertFalse( $out['fields']['control_type']['visible'] );
		$this->assertFalse( $out['fields']['control_system']['visible'] );
	}

	public function test_require_field_marks_field_required(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r1',
					[ 'always' => true ],
					[ [ 'action' => 'require_field', 'field' => 'fabric_color' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['fields']['fabric_color']['required'] );
	}

	public function test_disable_option_lists_disabled_keys(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 6000 ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 5000 ],
					[ [ 'action' => 'disable_option', 'field' => 'control_type', 'option' => 'manual' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertSame( [ 'manual' ], $out['fields']['control_type']['disabled_options'] );
	}

	public function test_set_default_applies_only_when_field_empty(): void {
		$input = $this->base_input( [
			'selections' => [ 'fabric_color' => 'textiles_dickson:u171' ],
			'rules' => [
				$this->rule(
					'r_default',
					[ 'always' => true ],
					[ [ 'action' => 'set_default', 'field' => 'fabric_color', 'value' => 'textiles_sandatex:u300' ] ]
				),
				$this->rule(
					'r_default_for_unset',
					[ 'always' => true ],
					[ [ 'action' => 'set_default', 'field' => 'sensor_addon', 'value' => [ 'SOM-IO-WIND-300' ] ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );

		$this->assertSame( 'textiles_dickson:u171', $out['fields']['fabric_color']['value'] );
		$this->assertSame( [ 'SOM-IO-WIND-300' ], $out['fields']['sensor_addon']['value'] );
	}

	public function test_reset_value_nulls_field(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 4000, 'fabric_color' => 'textiles_dickson:u171' ],
			'rules' => [
				$this->rule(
					'r_reset',
					[ 'field' => 'width_mm', 'op' => 'is_selected' ],
					[ [ 'action' => 'reset_value', 'field' => 'fabric_color' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertNull( $out['fields']['fabric_color']['value'] );
	}

	public function test_switch_lookup_table(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 6000 ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 5000 ],
					[ [ 'action' => 'switch_lookup_table', 'lookup_table_key' => 'markise_xl_v1' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertSame( 'markise_xl_v1', $out['lookup_table_key'] );
	}

	public function test_add_surcharge_amount_and_percent(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 6000, 'height_mm' => 4000 ],
			'rules' => [
				$this->rule(
					'r_amt',
					[ 'all' => [
						[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 5000 ],
						[ 'field' => 'height_mm', 'op' => 'greater_than', 'value' => 3500 ],
					] ],
					[ [ 'action' => 'add_surcharge', 'label' => 'Storformat', 'amount' => 1500 ] ]
				),
				$this->rule(
					'r_pct',
					[ 'always' => true ],
					[ [ 'action' => 'add_surcharge', 'label' => 'pct', 'percent_of_base' => 5 ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertCount( 2, $out['surcharges'] );
		$this->assertSame( 1500.0, $out['surcharges'][0]['amount'] );
		$this->assertSame( 5.0, $out['surcharges'][1]['percent_of_base'] );
	}

	public function test_block_add_to_cart(): void {
		$input = $this->base_input( [
			'selections' => [ 'width_mm' => 9999 ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 7000 ],
					[ [ 'action' => 'block_add_to_cart', 'message' => 'Kontakt oss' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertTrue( $out['blocked'] );
		$this->assertSame( 'Kontakt oss', $out['block_reason'] );
	}

	public function test_show_warning_levels(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r1',
					[ 'always' => true ],
					[
						[ 'action' => 'show_warning', 'message' => 'info!', 'level' => 'info' ],
						[ 'action' => 'show_warning', 'message' => 'warn!', 'level' => 'warning' ],
					]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertCount( 2, $out['warnings'] );
		$this->assertSame( 'info', $out['warnings'][0]['level'] );
		$this->assertSame( 'warning', $out['warnings'][1]['level'] );
	}

	public function test_filter_source_combines_with_AND_semantics(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r_first',
					[ 'always' => true ],
					[ [ 'action' => 'filter_source', 'field' => 'sensor_addon', 'filter' => [ 'tags_any' => [ 'io' ] ] ] ]
				),
				$this->rule(
					'r_second',
					[ 'always' => true ],
					[ [ 'action' => 'filter_source', 'field' => 'sensor_addon', 'filter' => [ 'tags_any' => [ 'rts' ] ] ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertEqualsCanonicalizing(
			[ 'io', 'rts' ],
			$out['fields']['sensor_addon']['options_filter']['tags_any']
		);
	}

	// ---- Single-pass and reset cascade ----

	public function test_single_pass_reset_cascade_when_field_hidden(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_type' => 'manual', 'control_system' => 'io' ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'control_type', 'op' => 'equals', 'value' => 'manual' ],
					[ [ 'action' => 'hide_field', 'field' => 'control_system' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertFalse( $out['fields']['control_system']['visible'] );
		$this->assertNull(
			$out['fields']['control_system']['value'],
			'Hidden field must be reset to null per §6.4.'
		);
	}

	public function test_disabled_option_currently_selected_is_reset(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_type' => 'manual', 'width_mm' => 6000 ],
			'rules' => [
				$this->rule(
					'r1',
					[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 5000 ],
					[ [ 'action' => 'disable_option', 'field' => 'control_type', 'option' => 'manual' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertNull( $out['fields']['control_type']['value'] );
	}

	// ---- Missing target ----

	public function test_missing_field_target_logs_marker_and_skips(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r1',
					[ 'always' => true ],
					[ [ 'action' => 'hide_field', 'field' => 'does_not_exist' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertSame( [], $out['rule_results'][0]['actions_applied'] );
		$this->assertCount( 1, $out['log'] );
		$this->assertSame( 'rule.target_missing', $out['log'][0]['marker'] );
		$this->assertSame( 'field:does_not_exist', $out['log'][0]['detail'] );
	}

	public function test_missing_step_target_logs_marker(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r1',
					[ 'always' => true ],
					[ [ 'action' => 'show_step', 'step' => 'no_such_step' ] ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertCount( 1, $out['log'] );
		$this->assertSame( 'step:no_such_step', $out['log'][0]['detail'] );
	}

	// ---- Conflict (same priority, higher sort_order wins) ----

	public function test_same_priority_higher_sort_order_wins(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r_show',
					[ 'always' => true ],
					[ [ 'action' => 'show_field', 'field' => 'fabric_color' ] ],
					100,
					0
				),
				$this->rule(
					'r_hide',
					[ 'always' => true ],
					[ [ 'action' => 'hide_field', 'field' => 'fabric_color' ] ],
					100,
					10
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertFalse(
			$out['fields']['fabric_color']['visible'],
			'Higher sort_order rule (hide) should overwrite lower sort_order (show).'
		);
	}

	public function test_lower_priority_runs_first(): void {
		$input = $this->base_input( [
			'rules' => [
				$this->rule(
					'r_runs_second',
					[ 'always' => true ],
					[ [ 'action' => 'show_field', 'field' => 'fabric_color' ] ],
					200
				),
				$this->rule(
					'r_runs_first',
					[ 'always' => true ],
					[ [ 'action' => 'hide_field', 'field' => 'fabric_color' ] ],
					100
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		// runs_first hides, then runs_second shows; runs_second is later -> visible = true
		$this->assertTrue( $out['fields']['fabric_color']['visible'] );
	}

	public function test_inactive_rule_is_skipped(): void {
		$input = $this->base_input( [
			'selections' => [ 'control_type' => 'motorized' ],
			'rules' => [
				array_merge(
					$this->rule(
						'r_inactive',
						[ 'field' => 'control_type', 'op' => 'equals', 'value' => 'motorized' ],
						[ [ 'action' => 'show_warning', 'message' => 'should_not_appear', 'level' => 'info' ] ]
					),
					[ 'is_active' => false ]
				),
			],
		] );
		$out = $this->engine->evaluate( $input );
		$this->assertFalse( $out['rule_results'][0]['matched'] );
		$this->assertCount( 0, $out['warnings'] );
	}
}
