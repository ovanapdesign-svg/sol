<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\FieldOptionService;
use ConfigKit\Service\FieldService;
use ConfigKit\Service\StepService;
use ConfigKit\Service\TemplateService;
use PHPUnit\Framework\TestCase;

final class FieldOptionServiceTest extends TestCase {

	private StubFieldOptionRepository $optRepo;
	private StubFieldRepository $fields;
	private FieldOptionService $service;
	private int $manual_field_id;
	private int $library_field_id;

	protected function setUp(): void {
		$this->optRepo   = new StubFieldOptionRepository();
		$this->fields    = new StubFieldRepository();
		$this->service   = new FieldOptionService( $this->optRepo, $this->fields );

		$tmplRepo = new StubTemplateRepository();
		$stepRepo = new StubStepRepository();

		$tmplSvc = new TemplateService( $tmplRepo );
		$tmpl    = $tmplSvc->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise',
			'status'       => 'draft',
		] );

		$stepSvc = new StepService( $stepRepo, $tmplRepo );
		$step    = $stepSvc->create( $tmpl['id'], [ 'step_key' => 'maal', 'label' => 'Mål' ] );

		$fieldSvc = new FieldService( $this->fields, $stepRepo, $tmplRepo );
		$manual   = $fieldSvc->create( $tmpl['id'], $step['id'], [
			'field_key'    => 'control_type',
			'label'        => 'Betjening',
			'field_kind'   => 'input',
			'input_type'   => 'radio',
			'display_type' => 'plain',
			'value_source' => 'manual_options',
			'behavior'     => 'normal_option',
			'source_config' => [ 'type' => 'manual_options' ],
		] );
		$this->manual_field_id = $manual['id'];

		$library = $fieldSvc->create( $tmpl['id'], $step['id'], [
			'field_key'    => 'fabric_color',
			'label'        => 'Velg dukfarge',
			'field_kind'   => 'input',
			'input_type'   => 'radio',
			'display_type' => 'cards',
			'value_source' => 'library',
			'behavior'     => 'normal_option',
			'source_config' => [ 'type' => 'library', 'libraries' => [ 'textiles_dickson' ] ],
		] );
		$this->library_field_id = $library['id'];
	}

	private function valid_option( array $overrides = [] ): array {
		return array_replace(
			[
				'option_key' => 'manual',
				'label'      => 'Manuell',
				'price'      => 0,
				'is_active'  => true,
			],
			$overrides
		);
	}

	public function test_create_succeeds_for_manual_options_field(): void {
		$result = $this->service->create( $this->manual_field_id, $this->valid_option() );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 'manual', $result['record']['option_key'] );
	}

	public function test_create_rejected_for_non_manual_field(): void {
		$result = $this->service->create( $this->library_field_id, $this->valid_option() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'wrong_value_source', $result['errors'][0]['code'] );
	}

	public function test_create_unknown_field_returns_field_not_found(): void {
		$result = $this->service->create( 9999, $this->valid_option() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'field_not_found', $result['errors'][0]['code'] );
	}

	public function test_create_missing_option_key_returns_required(): void {
		$result = $this->service->create( $this->manual_field_id, $this->valid_option( [ 'option_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_two_char_option_key_is_rejected(): void {
		$result = $this->service->create( $this->manual_field_id, $this->valid_option( [ 'option_key' => 'ab' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_duplicate_option_key_within_field_is_rejected(): void {
		$this->service->create( $this->manual_field_id, $this->valid_option() );
		$result = $this->service->create( $this->manual_field_id, $this->valid_option() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_negative_price_is_rejected(): void {
		$result = $this->service->create( $this->manual_field_id, $this->valid_option( [ 'price' => -10 ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'negative_price', $codes );
	}

	public function test_create_assigns_max_sort_order_plus_one(): void {
		$this->service->create( $this->manual_field_id, $this->valid_option( [ 'option_key' => 'manual', 'sort_order' => 5 ] ) );
		$input = $this->valid_option( [ 'option_key' => 'motorized' ] );
		unset( $input['sort_order'] );
		$result = $this->service->create( $this->manual_field_id, $input );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 6, $result['record']['sort_order'] );
	}

	public function test_update_with_correct_hash_succeeds(): void {
		$created = $this->service->create( $this->manual_field_id, $this->valid_option() );
		$result  = $this->service->update(
			$this->manual_field_id,
			$created['id'],
			$this->valid_option( [ 'label' => 'Manuell krank' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Manuell krank', $result['record']['label'] );
	}

	public function test_update_with_stale_hash_returns_conflict(): void {
		$created = $this->service->create( $this->manual_field_id, $this->valid_option() );
		$result  = $this->service->update(
			$this->manual_field_id,
			$created['id'],
			$this->valid_option( [ 'label' => 'X' ] ),
			'stale-hash'
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_option_key_is_immutable(): void {
		$created = $this->service->create( $this->manual_field_id, $this->valid_option() );
		$result  = $this->service->update(
			$this->manual_field_id,
			$created['id'],
			$this->valid_option( [ 'option_key' => 'something_else', 'label' => 'Manuell' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'manual', $result['record']['option_key'] );
	}

	public function test_soft_delete_marks_inactive(): void {
		$created = $this->service->create( $this->manual_field_id, $this->valid_option() );
		$result  = $this->service->soft_delete( $this->manual_field_id, $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->optRepo->find_by_id( $created['id'] )['is_active'] );
	}
}
