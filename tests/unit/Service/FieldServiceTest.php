<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\FieldService;
use ConfigKit\Service\StepService;
use ConfigKit\Service\TemplateService;
use PHPUnit\Framework\TestCase;

final class FieldServiceTest extends TestCase {

	private StubFieldRepository $fields;
	private StubStepRepository $steps;
	private StubTemplateRepository $templates;
	private FieldService $service;
	private int $template_id;
	private int $step_id;

	protected function setUp(): void {
		$this->fields    = new StubFieldRepository();
		$this->steps     = new StubStepRepository();
		$this->templates = new StubTemplateRepository();
		$this->service   = new FieldService( $this->fields, $this->steps, $this->templates );

		$tmplSvc = new TemplateService( $this->templates );
		$created = $tmplSvc->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise motorisert',
			'status'       => 'draft',
		] );
		$this->template_id = $created['id'];

		$stepSvc = new StepService( $this->steps, $this->templates );
		$step    = $stepSvc->create( $this->template_id, [
			'step_key'    => 'maal',
			'label'       => 'Mål',
			'is_required' => true,
		] );
		$this->step_id = $step['id'];
	}

	private function valid_input( array $overrides = [] ): array {
		return array_replace(
			[
				'field_key'    => 'fabric_color',
				'label'        => 'Velg dukfarge',
				'field_kind'   => 'input',
				'input_type'   => 'radio',
				'display_type' => 'cards',
				'value_source' => 'library',
				'behavior'     => 'normal_option',
				'source_config' => [
					'type'      => 'library',
					'libraries' => [ 'textiles_dickson' ],
				],
				'pricing_mode' => 'fixed',
				'pricing_value' => 0,
				'is_required'  => true,
			],
			$overrides
		);
	}

	public function test_create_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input() );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 'fabric_color', $result['record']['field_key'] );
		$this->assertSame( 'maal', $result['record']['step_key'] );
		$this->assertSame( 'library', $result['record']['value_source'] );
	}

	public function test_create_in_unknown_template_returns_not_found(): void {
		$result = $this->service->create( 9999, $this->step_id, $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_create_in_unknown_step_returns_not_found(): void {
		$result = $this->service->create( $this->template_id, 9999, $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_create_invalid_field_kind_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [ 'field_kind' => 'banana' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_create_display_field_with_input_type_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [
			'field_key'    => 'heading_one',
			'field_kind'   => 'display',
			'input_type'   => 'radio',
			'display_type' => 'heading',
			'value_source' => 'manual_options',
			'behavior'     => 'presentation_only',
			'source_config' => [ 'type' => 'manual_options' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_combination', $codes );
	}

	public function test_create_display_field_with_normal_option_behavior_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [
			'field_key'    => 'heading_two',
			'field_kind'   => 'display',
			'input_type'   => null,
			'display_type' => 'heading',
			'value_source' => 'manual_options',
			'behavior'     => 'normal_option',
			'source_config' => [ 'type' => 'manual_options' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_combination', $codes );
	}

	public function test_create_display_field_with_valid_combination_succeeds(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, [
			'field_key'    => 'section_heading',
			'label'        => 'Skriv inn mål',
			'field_kind'   => 'display',
			'input_type'   => null,
			'display_type' => 'heading',
			'value_source' => 'manual_options',
			'behavior'     => 'presentation_only',
			'source_config' => [ 'type' => 'manual_options' ],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
	}

	public function test_create_lookup_field_must_use_lookup_dimension_behavior(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [
			'field_key'    => 'width_mm',
			'field_kind'   => 'lookup',
			'input_type'   => 'number',
			'display_type' => 'plain',
			'value_source' => 'lookup_table',
			'behavior'     => 'normal_option', // wrong
			'source_config' => [
				'type'             => 'lookup_table',
				'lookup_table_key' => 'markise_2d_v1',
				'dimension'        => 'width',
			],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_combination', $codes );
	}

	public function test_create_lookup_field_with_lookup_dimension_succeeds(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, [
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
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
	}

	public function test_create_addon_field_with_woo_category_succeeds(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, [
			'field_key'    => 'sensor_addon',
			'label'        => 'Værsensor',
			'field_kind'   => 'addon',
			'input_type'   => 'checkbox',
			'display_type' => 'cards',
			'value_source' => 'woo_category',
			'behavior'     => 'product_addon',
			'source_config' => [
				'type'          => 'woo_category',
				'category_slug' => 'sensorer',
			],
		] );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
	}

	public function test_create_woo_source_with_input_kind_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [
			'field_key'    => 'wrong_source',
			'value_source' => 'woo_category',
			'source_config' => [ 'type' => 'woo_category', 'category_slug' => 'sensorer' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_combination', $codes );
	}

	public function test_create_library_source_without_libraries_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [
			'source_config' => [ 'type' => 'library' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'library_required', $codes );
	}

	public function test_create_lookup_source_without_dimension_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, [
			'field_key'    => 'width_mm',
			'label'        => 'Bredde',
			'field_kind'   => 'lookup',
			'input_type'   => 'number',
			'display_type' => 'plain',
			'value_source' => 'lookup_table',
			'behavior'     => 'lookup_dimension',
			'source_config' => [ 'type' => 'lookup_table', 'lookup_table_key' => 'markise_2d_v1' ],
		] );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'dimension_required', $codes );
	}

	public function test_create_duplicate_field_key_in_template_is_rejected(): void {
		$this->service->create( $this->template_id, $this->step_id, $this->valid_input() );
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_two_char_field_key_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [ 'field_key' => 'ab' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_invalid_pricing_mode_is_rejected(): void {
		$result = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [ 'pricing_mode' => 'banana' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_create_assigns_max_sort_order_plus_one(): void {
		$first  = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [ 'sort_order' => 7 ] ) );
		$input2 = $this->valid_input( [ 'field_key' => 'profile_color' ] );
		unset( $input2['sort_order'] );
		$second = $this->service->create( $this->template_id, $this->step_id, $input2 );

		$this->assertTrue( $second['ok'] );
		$this->assertSame( 8, $second['record']['sort_order'] );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->template_id, $this->step_id, $this->valid_input() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_input( [ 'label' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 'Renamed', $result['record']['label'] );
	}

	public function test_update_with_stale_version_hash_returns_conflict(): void {
		$created = $this->service->create( $this->template_id, $this->step_id, $this->valid_input() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_input( [ 'label' => 'X-X' ] ),
			'stale-hash'
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_field_key_is_immutable(): void {
		$created = $this->service->create( $this->template_id, $this->step_id, $this->valid_input() );
		$result  = $this->service->update(
			$this->template_id,
			$created['id'],
			$this->valid_input( [ 'field_key' => 'something_else', 'label' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'fabric_color', $result['record']['field_key'] );
	}

	public function test_delete_removes_field(): void {
		$created = $this->service->create( $this->template_id, $this->step_id, $this->valid_input() );
		$result  = $this->service->delete( $this->template_id, $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertNull( $this->fields->find_by_id( $created['id'] ) );
	}

	public function test_list_in_step_returns_only_step_fields(): void {
		$this->service->create( $this->template_id, $this->step_id, $this->valid_input() );

		$stepSvc = new StepService( $this->steps, $this->templates );
		$step2   = $stepSvc->create( $this->template_id, [ 'step_key' => 'duk', 'label' => 'Duk' ] );
		$this->service->create( $this->template_id, $step2['id'], $this->valid_input( [ 'field_key' => 'cover_color' ] ) );

		$listing = $this->service->list_in_step( $this->template_id, $this->step_id );
		$this->assertSame( 1, $listing['total'] );
		$this->assertSame( 'fabric_color', $listing['items'][0]['field_key'] );
	}

	public function test_reorder_updates_sort_orders(): void {
		$a = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [ 'field_key' => 'a_field', 'sort_order' => 1 ] ) );
		$b = $this->service->create( $this->template_id, $this->step_id, $this->valid_input( [ 'field_key' => 'b_field', 'sort_order' => 2 ] ) );

		$result = $this->service->reorder( $this->template_id, [
			[ 'field_id' => $a['id'], 'sort_order' => 99 ],
			[ 'field_id' => $b['id'], 'sort_order' => 50 ],
		] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['summary']['updated'] );
		$this->assertSame( 99, $this->fields->find_by_id( $a['id'] )['sort_order'] );
		$this->assertSame( 50, $this->fields->find_by_id( $b['id'] )['sort_order'] );
	}
}
