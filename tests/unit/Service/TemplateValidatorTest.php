<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\FieldOptionService;
use ConfigKit\Service\FieldService;
use ConfigKit\Service\RuleService;
use ConfigKit\Service\StepService;
use ConfigKit\Service\TemplateService;
use ConfigKit\Service\TemplateValidator;
use PHPUnit\Framework\TestCase;

final class TemplateValidatorTest extends TestCase {

	private StubTemplateRepository $templates;
	private StubStepRepository $steps;
	private StubFieldRepository $fields;
	private StubFieldOptionRepository $options;
	private StubRuleRepository $rules;
	private TemplateValidator $validator;
	private int $template_id;

	protected function setUp(): void {
		$this->templates = new StubTemplateRepository();
		$this->steps     = new StubStepRepository();
		$this->fields    = new StubFieldRepository();
		$this->options   = new StubFieldOptionRepository();
		$this->rules     = new StubRuleRepository();

		$this->validator = new TemplateValidator(
			$this->templates,
			$this->steps,
			$this->fields,
			$this->options,
			$this->rules
		);

		$tmplSvc = new TemplateService( $this->templates );
		$tmpl    = $tmplSvc->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise motorisert',
			'status'       => 'draft',
		] );
		$this->template_id = $tmpl['id'];
	}

	private function add_step( string $key, string $label = 'Step' ): array {
		$svc = new StepService( $this->steps, $this->templates );
		return $svc->create( $this->template_id, [ 'step_key' => $key, 'label' => $label ] );
	}

	private function add_field( int $step_id, array $overrides = [] ): array {
		$svc = new FieldService( $this->fields, $this->steps, $this->templates );
		return $svc->create( $this->template_id, $step_id, array_replace(
			[
				'field_key'    => 'control_type',
				'label'        => 'Betjening',
				'field_kind'   => 'input',
				'input_type'   => 'radio',
				'display_type' => 'plain',
				'value_source' => 'manual_options',
				'behavior'     => 'normal_option',
				'source_config' => [ 'type' => 'manual_options' ],
			],
			$overrides
		) );
	}

	public function test_empty_template_reports_no_steps_error(): void {
		$result = $this->validator->validate( $this->template_id );
		$this->assertNotNull( $result );
		$this->assertFalse( $result['valid'] );
		$messages = array_column( $result['errors'], 'message' );
		$this->assertContains( 'A template needs at least one step.', $messages );
	}

	public function test_unknown_template_returns_null(): void {
		$this->assertNull( $this->validator->validate( 9999 ) );
	}

	public function test_step_without_fields_emits_warning(): void {
		$this->add_step( 'maal' );
		$result = $this->validator->validate( $this->template_id );
		$this->assertNotNull( $result );
		// no fields → warning, but no errors → valid stays true.
		$this->assertTrue( $result['valid'] );
		$this->assertSame( 1, count( $result['warnings'] ) );
		$this->assertSame( 'warning', $result['warnings'][0]['severity'] );
	}

	public function test_manual_options_field_without_options_is_error(): void {
		$step = $this->add_step( 'maal' );
		$this->add_field( $step['id'] );

		$result = $this->validator->validate( $this->template_id );
		$this->assertFalse( $result['valid'] );
		$messages = array_column( $result['errors'], 'message' );
		$found = false;
		foreach ( $messages as $m ) {
			if ( str_contains( $m, 'manual_options but has no active options' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected manual_options-without-options error.' );
	}

	public function test_library_field_without_libraries_is_error(): void {
		$step = $this->add_step( 'duk' );
		$this->add_field( $step['id'], [
			'field_key'    => 'fabric_color',
			'value_source' => 'library',
			'display_type' => 'cards',
			'source_config' => [ 'type' => 'library', 'libraries' => [ 'textiles_dickson' ] ],
		] );
		// Now mutate the persisted record to drop libraries — simulate
		// the library being deleted out from under the rule.
		foreach ( $this->fields->records as $id => $rec ) {
			if ( $rec['field_key'] === 'fabric_color' ) {
				$this->fields->records[ $id ]['source_config'] = [ 'type' => 'library', 'libraries' => [] ];
			}
		}

		$result = $this->validator->validate( $this->template_id );
		$this->assertFalse( $result['valid'] );
		$messages = array_column( $result['errors'], 'message' );
		$found = false;
		foreach ( $messages as $m ) {
			if ( str_contains( $m, 'library source but has no libraries selected' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_lookup_field_without_lookup_table_key_is_error(): void {
		$step = $this->add_step( 'maal' );
		$this->add_field( $step['id'], [
			'field_key'    => 'width_mm',
			'field_kind'   => 'lookup',
			'input_type'   => 'number',
			'value_source' => 'lookup_table',
			'behavior'     => 'lookup_dimension',
			'source_config' => [
				'type'             => 'lookup_table',
				'lookup_table_key' => 'markise_2d_v1',
				'dimension'        => 'width',
			],
		] );
		// Strip the lookup_table_key after creation.
		foreach ( $this->fields->records as $id => $rec ) {
			if ( $rec['field_key'] === 'width_mm' ) {
				$this->fields->records[ $id ]['source_config'] = [ 'type' => 'lookup_table', 'dimension' => 'width' ];
			}
		}

		$result = $this->validator->validate( $this->template_id );
		$this->assertFalse( $result['valid'] );
		$messages = array_column( $result['errors'], 'message' );
		$found = false;
		foreach ( $messages as $m ) {
			if ( str_contains( $m, 'lookup_table_key' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_rule_referencing_missing_field_is_error(): void {
		$step  = $this->add_step( 'maal' );
		$this->add_field( $step['id'] );
		$optSvc = new FieldOptionService( $this->options, $this->fields );
		$optSvc->create( 1, [ 'option_key' => 'manual', 'label' => 'Manual' ] );

		// Persist an orphaned rule directly — RuleService would reject
		// these references upfront, but the validator's job is to catch
		// refs that BECAME orphaned later (e.g. someone deleted the
		// referenced field/step after the rule was saved).
		$this->rules->create( [
			'template_key' => 'markise_motorisert',
			'rule_key'     => 'orphaned',
			'name'         => 'Orphaned',
			'priority'     => 100,
			'spec'         => [
				'when' => [ 'field' => 'no_such_field', 'op' => 'equals', 'value' => 'x' ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'no_such_step' ] ],
			],
		] );

		$result = $this->validator->validate( $this->template_id );
		$this->assertFalse( $result['valid'] );
		$messages = array_column( $result['errors'], 'message' );
		$has_missing_field = false;
		$has_missing_step  = false;
		foreach ( $messages as $m ) {
			if ( str_contains( $m, 'missing field_key' ) ) $has_missing_field = true;
			if ( str_contains( $m, 'missing step_key' ) )  $has_missing_step  = true;
		}
		$this->assertTrue( $has_missing_field, 'Expected missing field_key error.' );
		$this->assertTrue( $has_missing_step,  'Expected missing step_key error.' );
	}

	public function test_clean_template_validates_successfully(): void {
		$step = $this->add_step( 'maal' );
		$this->add_field( $step['id'] );
		$optSvc = new FieldOptionService( $this->options, $this->fields );
		$optSvc->create( 1, [ 'option_key' => 'manual',    'label' => 'Manuell' ] );
		$optSvc->create( 1, [ 'option_key' => 'motorized', 'label' => 'Motorisert' ] );

		$result = $this->validator->validate( $this->template_id );
		$this->assertTrue( $result['valid'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( [], $result['errors'] );
	}
}
