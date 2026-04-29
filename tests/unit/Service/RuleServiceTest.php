<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\FieldOptionService;
use ConfigKit\Service\FieldService;
use ConfigKit\Service\RuleService;
use ConfigKit\Service\StepService;
use ConfigKit\Service\TemplateService;
use PHPUnit\Framework\TestCase;

final class RuleServiceTest extends TestCase {

	private StubRuleRepository $rules;
	private StubTemplateRepository $templates;
	private StubFieldRepository $fields;
	private StubStepRepository $steps;
	private StubFieldOptionRepository $options;
	private RuleService $service;

	private int $template_id;
	private int $other_template_id;

	protected function setUp(): void {
		$this->rules     = new StubRuleRepository();
		$this->templates = new StubTemplateRepository();
		$this->fields    = new StubFieldRepository();
		$this->steps     = new StubStepRepository();
		$this->options   = new StubFieldOptionRepository();

		$this->service = new RuleService(
			$this->rules,
			$this->templates,
			$this->fields,
			$this->steps,
			$this->options
		);

		// Seed a template with a step + a couple of fields + manual options
		// so cross-reference checks have something to look at.
		$tmplSvc = new TemplateService( $this->templates );
		$tmpl    = $tmplSvc->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise motorisert',
			'status'       => 'draft',
		] );
		$this->template_id = $tmpl['id'];

		$other = $tmplSvc->create( [
			'template_key' => 'markise_manuell',
			'name'         => 'Markise manuell',
			'status'       => 'draft',
		] );
		$this->other_template_id = $other['id'];

		$stepSvc = new StepService( $this->steps, $this->templates );
		$step    = $stepSvc->create( $tmpl['id'], [
			'step_key' => 'maal',
			'label'    => 'Mål',
		] );
		$stepSvc->create( $tmpl['id'], [
			'step_key' => 'motor_and_control',
			'label'    => 'Betjening',
		] );

		$fieldSvc = new FieldService( $this->fields, $this->steps, $this->templates );
		$fieldSvc->create( $tmpl['id'], $step['id'], [
			'field_key'    => 'control_type',
			'label'        => 'Betjening',
			'field_kind'   => 'input',
			'input_type'   => 'radio',
			'display_type' => 'plain',
			'value_source' => 'manual_options',
			'behavior'     => 'normal_option',
			'source_config' => [ 'type' => 'manual_options' ],
		] );
		$fieldSvc->create( $tmpl['id'], $step['id'], [
			'field_key'    => 'width_mm',
			'label'        => 'Bredde',
			'field_kind'   => 'lookup',
			'input_type'   => 'number',
			'display_type' => 'plain',
			'value_source' => 'lookup_table',
			'behavior'     => 'lookup_dimension',
			'source_config' => [
				'type'             => 'lookup_table',
				'lookup_table_key' => 'markise_2d_v1',
				'dimension'        => 'width',
			],
		] );

		$control_field = $fieldSvc->get( $tmpl['id'], 1 );
		$optSvc        = new FieldOptionService( $this->options, $this->fields );
		$optSvc->create( $control_field['id'], [ 'option_key' => 'manual',    'label' => 'Manuell' ] );
		$optSvc->create( $control_field['id'], [ 'option_key' => 'motorized', 'label' => 'Motorisert' ] );
	}

	private function valid_rule( array $overrides = [] ): array {
		return array_replace(
			[
				'rule_key' => 'show_motor_step',
				'name'     => 'Show motor step when motorized',
				'priority' => 100,
				'spec'     => [
					'when' => [ 'field' => 'control_type', 'op' => 'equals', 'value' => 'motorized' ],
					'then' => [
						[ 'action' => 'show_step', 'step' => 'motor_and_control' ],
					],
				],
			],
			$overrides
		);
	}

	public function test_create_with_valid_spec_succeeds(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule() );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 'show_motor_step', $result['record']['rule_key'] );
	}

	public function test_create_unknown_template_returns_template_not_found(): void {
		$result = $this->service->create( 9999, $this->valid_rule() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'template_not_found', $result['errors'][0]['code'] );
	}

	public function test_create_with_unknown_field_in_when_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'field' => 'no_such_field', 'op' => 'equals', 'value' => 'x' ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'motor_and_control' ] ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unknown_field', $codes );
	}

	public function test_create_with_unknown_step_in_action_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'field' => 'control_type', 'op' => 'equals', 'value' => 'motorized' ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'no_such_step' ] ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unknown_step', $codes );
	}

	public function test_create_with_unknown_operator_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'field' => 'control_type', 'op' => 'starts_with', 'value' => 'm' ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'motor_and_control' ] ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unknown_operator', $codes );
	}

	public function test_create_with_unknown_action_type_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'field' => 'control_type', 'op' => 'equals', 'value' => 'motorized' ],
				'then' => [ [ 'action' => 'do_a_barrel_roll' ] ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unknown_action', $codes );
	}

	public function test_between_operator_requires_two_value_array(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'field' => 'width_mm', 'op' => 'between', 'value' => 5000 ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'motor_and_control' ] ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_in_operator_requires_array_value(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'field' => 'control_type', 'op' => 'in', 'value' => 'motorized' ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'motor_and_control' ] ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_logical_all_with_nested_atoms_succeeds(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'rule_key' => 'oversize',
			'spec'     => [
				'when' => [
					'all' => [
						[ 'field' => 'control_type', 'op' => 'equals', 'value' => 'motorized' ],
						[ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 5000 ],
					],
				],
				'then' => [
					[ 'action' => 'add_surcharge', 'label' => 'Storformat', 'amount' => 1500 ],
				],
			],
		] ) );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
	}

	public function test_disable_option_with_unknown_option_key_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'rule_key' => 'block_manual_at_size',
			'spec'     => [
				'when' => [ 'field' => 'width_mm', 'op' => 'greater_than', 'value' => 5000 ],
				'then' => [
					[ 'action' => 'disable_option', 'field' => 'control_type', 'option' => 'unicycle' ],
				],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unknown_option', $codes );
	}

	public function test_add_surcharge_requires_exactly_one_of_amount_or_percent(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'rule_key' => 'surcharge_both',
			'spec'     => [
				'when' => [ 'always' => true ],
				'then' => [
					[ 'action' => 'add_surcharge', 'label' => 'X', 'amount' => 100, 'percent_of_base' => 5 ],
				],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_show_warning_invalid_level_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'rule_key' => 'warn',
			'spec'     => [
				'when' => [ 'always' => true ],
				'then' => [
					[ 'action' => 'show_warning', 'message' => 'Hi', 'level' => 'whatever' ],
				],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_create_duplicate_rule_key_in_template_is_rejected(): void {
		$this->service->create( $this->template_id, $this->valid_rule() );
		$result = $this->service->create( $this->template_id, $this->valid_rule() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_same_rule_key_in_different_templates_is_allowed(): void {
		$first = $this->service->create( $this->template_id, $this->valid_rule() );
		$this->assertTrue( $first['ok'] );
		// Other template also lets us reuse the rule_key, even though the
		// step it references doesn't exist there. Cross-ref errors are
		// reported, but uniqueness is scoped per-template.
		$result = $this->service->create( $this->other_template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'always' => true ],
				'then' => [ [ 'action' => 'show_warning', 'message' => 'Hi' ] ],
			],
		] ) );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
	}

	public function test_create_two_char_rule_key_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [ 'rule_key' => 'ab' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_missing_when_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'then' => [ [ 'action' => 'show_step', 'step' => 'motor_and_control' ] ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'missing', $codes );
	}

	public function test_create_missing_then_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->valid_rule( [
			'spec' => [
				'when' => [ 'always' => true ],
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'missing', $codes );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->template_id, $this->valid_rule() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_rule( [ 'name' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 'Renamed', $result['record']['name'] );
	}

	public function test_update_with_stale_version_hash_returns_conflict(): void {
		$created = $this->service->create( $this->template_id, $this->valid_rule() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_rule( [ 'name' => 'X-X' ] ),
			'stale-hash'
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_rule_key_is_immutable(): void {
		$created = $this->service->create( $this->template_id, $this->valid_rule() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_rule( [ 'rule_key' => 'something_else', 'name' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'show_motor_step', $result['record']['rule_key'] );
	}

	public function test_soft_delete_marks_inactive(): void {
		$created = $this->service->create( $this->template_id, $this->valid_rule() );
		$result  = $this->service->soft_delete( $this->template_id, $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->rules->find_by_id( $created['id'] )['is_active'] );
	}

	public function test_reorder_updates_priority_and_sort(): void {
		$a = $this->service->create( $this->template_id, $this->valid_rule( [ 'rule_key' => 'rule_a', 'priority' => 100 ] ) );
		$b = $this->service->create( $this->template_id, $this->valid_rule( [ 'rule_key' => 'rule_b', 'priority' => 200 ] ) );

		$result = $this->service->reorder( $this->template_id, [
			[ 'rule_id' => $a['id'], 'priority' => 50, 'sort_order' => 1 ],
			[ 'rule_id' => $b['id'], 'priority' => 150, 'sort_order' => 2 ],
		] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['summary']['updated'] );
		$this->assertSame( 50,  $this->rules->find_by_id( $a['id'] )['priority'] );
		$this->assertSame( 150, $this->rules->find_by_id( $b['id'] )['priority'] );
	}
}
