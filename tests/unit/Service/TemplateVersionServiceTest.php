<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\FieldOptionService;
use ConfigKit\Service\FieldService;
use ConfigKit\Service\StepService;
use ConfigKit\Service\TemplateService;
use ConfigKit\Service\TemplateValidator;
use ConfigKit\Service\TemplateVersionService;
use PHPUnit\Framework\TestCase;

final class TemplateVersionServiceTest extends TestCase {

	private StubTemplateVersionRepository $versions;
	private StubTemplateRepository $templates;
	private StubStepRepository $steps;
	private StubFieldRepository $fields;
	private StubFieldOptionRepository $options;
	private StubRuleRepository $rules;
	private TemplateVersionService $service;
	private int $template_id;

	protected function setUp(): void {
		$this->versions  = new StubTemplateVersionRepository();
		$this->templates = new StubTemplateRepository();
		$this->steps     = new StubStepRepository();
		$this->fields    = new StubFieldRepository();
		$this->options   = new StubFieldOptionRepository();
		$this->rules     = new StubRuleRepository();

		$validator = new TemplateValidator(
			$this->templates,
			$this->steps,
			$this->fields,
			$this->options,
			$this->rules
		);

		$this->service = new TemplateVersionService(
			$this->versions,
			$this->templates,
			$this->steps,
			$this->fields,
			$this->options,
			$this->rules,
			$validator
		);

		// Clean template ready to publish.
		$tmplSvc = new TemplateService( $this->templates );
		$tmpl    = $tmplSvc->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise motorisert',
			'status'       => 'draft',
		] );
		$this->template_id = $tmpl['id'];

		$stepSvc = new StepService( $this->steps, $this->templates );
		$step    = $stepSvc->create( $this->template_id, [ 'step_key' => 'maal', 'label' => 'Mål' ] );

		$fieldSvc = new FieldService( $this->fields, $this->steps, $this->templates );
		$fieldSvc->create( $this->template_id, $step['id'], [
			'field_key'    => 'control_type',
			'label'        => 'Betjening',
			'field_kind'   => 'input',
			'input_type'   => 'radio',
			'display_type' => 'plain',
			'value_source' => 'manual_options',
			'behavior'     => 'normal_option',
			'source_config' => [ 'type' => 'manual_options' ],
		] );

		$optSvc = new FieldOptionService( $this->options, $this->fields );
		$optSvc->create( 1, [ 'option_key' => 'manual',    'label' => 'Manuell' ] );
		$optSvc->create( 1, [ 'option_key' => 'motorized', 'label' => 'Motorisert' ] );
	}

	public function test_publish_creates_version_one(): void {
		$result = $this->service->publish( $this->template_id );
		$this->assertTrue( $result['ok'], 'errors=' . json_encode( $result['errors'] ?? [] ) );
		$this->assertSame( 1, $result['record']['version_number'] );
		$this->assertSame( 'published', $result['record']['status'] );
	}

	public function test_publish_fails_when_validation_errors(): void {
		// Strip the manual options to make validation fail.
		$this->options->records = [];

		$result = $this->service->publish( $this->template_id );
		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'validation', $result );
		$this->assertFalse( $result['validation']['valid'] );
	}

	public function test_publish_unknown_template_returns_not_found(): void {
		$result = $this->service->publish( 9999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_re_publish_creates_v2_not_overwrite(): void {
		$this->service->publish( $this->template_id );
		$result = $this->service->publish( $this->template_id );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['record']['version_number'] );
		$listing = $this->service->list_for_template( $this->template_id );
		$this->assertSame( 2, $listing['total'] );
	}

	public function test_publish_updates_template_published_version_id(): void {
		$result = $this->service->publish( $this->template_id );
		$this->assertTrue( $result['ok'] );
		$tmpl = $this->templates->find_by_id( $this->template_id );
		$this->assertSame( 'published', $tmpl['status'] );
		$this->assertSame( $result['record']['id'], $tmpl['published_version_id'] );
	}

	public function test_snapshot_includes_all_related_data(): void {
		$snapshot = $this->service->build_snapshot( $this->template_id );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 'markise_motorisert', $snapshot['template']['template_key'] );
		$this->assertCount( 1, $snapshot['steps'] );
		$this->assertCount( 1, $snapshot['fields'] );
		$this->assertCount( 2, $snapshot['field_options'] );
		$this->assertArrayHasKey( 'snapshot_metadata', $snapshot );
		$this->assertSame( TemplateVersionService::ENGINE_VERSION, $snapshot['snapshot_metadata']['engine_version'] );
	}

	public function test_get_version_returns_immutable_record(): void {
		$result  = $this->service->publish( $this->template_id );
		$version = $this->service->get_version( $this->template_id, $result['record']['id'] );
		$this->assertNotNull( $version );
		$this->assertSame( 1, $version['version_number'] );
		$this->assertSame( 'published', $version['status'] );
	}

	public function test_get_version_in_wrong_template_returns_null(): void {
		$result = $this->service->publish( $this->template_id );

		// Create a second template — its version space is independent.
		$tmplSvc = new TemplateService( $this->templates );
		$other   = $tmplSvc->create( [
			'template_key' => 'markise_manuell',
			'name'         => 'Markise manuell',
			'status'       => 'draft',
		] );

		$fetched = $this->service->get_version( $other['id'], $result['record']['id'] );
		$this->assertNull( $fetched );
	}

	public function test_list_for_unknown_template_returns_null(): void {
		$this->assertNull( $this->service->list_for_template( 9999 ) );
	}
}
