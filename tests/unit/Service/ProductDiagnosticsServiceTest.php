<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\FieldOptionService;
use ConfigKit\Service\FieldService;
use ConfigKit\Service\LibraryItemService;
use ConfigKit\Service\LibraryService;
use ConfigKit\Service\LookupCellService;
use ConfigKit\Service\LookupTableService;
use ConfigKit\Service\ModuleService;
use ConfigKit\Service\ProductDiagnosticsService;
use ConfigKit\Service\StepService;
use ConfigKit\Service\TemplateService;
use ConfigKit\Service\TemplateVersionService;
use ConfigKit\Service\TemplateValidator;
use PHPUnit\Framework\TestCase;

final class ProductDiagnosticsServiceTest extends TestCase {

	private const PRODUCT_ID = 7777;

	private StubProductBindingRepository $bindings;
	private StubTemplateRepository $templates;
	private StubStepRepository $steps;
	private StubFieldRepository $fields;
	private StubFieldOptionRepository $options;
	private StubLookupTableRepository $lookup_tables;
	private StubLookupCellRepository $lookup_cells;
	private StubLibraryRepository $libraries;
	private StubLibraryItemRepository $library_items;
	private StubRuleRepository $rules;
	private StubModuleRepository $modules;
	private ProductDiagnosticsService $diagnostics;
	private int $template_id;

	protected function setUp(): void {
		$this->bindings      = new StubProductBindingRepository();
		$this->templates     = new StubTemplateRepository();
		$this->steps         = new StubStepRepository();
		$this->fields        = new StubFieldRepository();
		$this->options       = new StubFieldOptionRepository();
		$this->lookup_tables = new StubLookupTableRepository();
		$this->lookup_cells  = new StubLookupCellRepository();
		$this->libraries     = new StubLibraryRepository();
		$this->library_items = new StubLibraryItemRepository();
		$this->rules         = new StubRuleRepository();
		$this->modules       = new StubModuleRepository();
		$this->bindings->register_product( self::PRODUCT_ID );

		$this->diagnostics = new ProductDiagnosticsService(
			$this->bindings,
			$this->templates,
			$this->steps,
			$this->fields,
			$this->options,
			$this->lookup_tables,
			$this->lookup_cells,
			$this->libraries,
			$this->library_items,
			$this->rules
		);
	}

	private function build_clean_template(): void {
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

		$validator = new TemplateValidator(
			$this->templates,
			$this->steps,
			$this->fields,
			$this->options,
			$this->rules
		);
		$versions  = new StubTemplateVersionRepository();
		$versionSvc = new TemplateVersionService(
			$versions,
			$this->templates,
			$this->steps,
			$this->fields,
			$this->options,
			$this->rules,
			$validator
		);
		$res = $versionSvc->publish( $this->template_id );
		$this->assertTrue( $res['ok'], json_encode( $res['errors'] ?? [] ) );
	}

	private function build_lookup_table(): void {
		$ltSvc = new LookupTableService( $this->lookup_tables, $this->lookup_cells );
		$res   = $ltSvc->create( [
			'lookup_table_key'     => 'markise_2d_v1',
			'name'                 => 'Markise 2D v1',
			'dimension_keys'       => [ 'width', 'height' ],
			'fallback_policy'      => 'reject',
			'supports_price_group' => true,
		] );
		$this->assertTrue( $res['ok'], json_encode( $res['errors'] ?? [] ) );
		$cellSvc = new LookupCellService( $this->lookup_cells, $this->lookup_tables );
		$cell    = $cellSvc->create( $res['id'], [
			'width'           => 1000,
			'height'          => 1000,
			'price_group_key' => 'A',
			'price'           => 1500.0,
		] );
		$this->assertTrue( $cell['ok'], json_encode( $cell['errors'] ?? [] ) );
	}

	private function build_library(): void {
		$modSvc = new ModuleService( $this->modules );
		$mod    = $modSvc->create( [ 'module_key' => 'textiles', 'name' => 'Textiles' ] );
		$libSvc = new LibraryService( $this->libraries, $this->modules );
		$libSvc->create( [
			'library_key' => 'textiles_dickson',
			'module_key'  => 'textiles',
			'name'        => 'Dickson Orchestra',
		] );
	}

	public function test_run_returns_null_for_unknown_product(): void {
		$this->assertNull( $this->diagnostics->run( 99999 ) );
	}

	public function test_disabled_product_reports_disabled(): void {
		$this->bindings->save( self::PRODUCT_ID, [ 'enabled' => false ] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$this->assertSame( 'disabled', $result['status'] );
	}

	public function test_enabled_without_template_reports_missing_template(): void {
		$this->bindings->save( self::PRODUCT_ID, [ 'enabled' => true ] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$this->assertSame( 'missing_template', $result['status'] );
		$check = $this->find_check( $result['checks'], 'template_selected' );
		$this->assertFalse( $check['passed'] );
		// Phase 3.6: owner-readable title + suggested fix.
		$this->assertSame( 'Template selected', $check['title'] );
		$this->assertNotNull( $check['suggested_fix'] );
		$this->assertStringContainsString( 'template', strtolower( $check['suggested_fix'] ) );
		$this->assertArrayHasKey( 'fix_link', $check, 'fix_link alias must exist' );
	}

	public function test_passed_check_has_title_but_no_suggested_fix(): void {
		// Build a fully clean binding so most checks pass.
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'defaults'         => [ 'control_type' => 'manual' ],
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$check  = $this->find_check( $result['checks'], 'template_selected' );
		$this->assertTrue( $check['passed'] );
		$this->assertSame( 'Template selected', $check['title'] );
		$this->assertNull( $check['suggested_fix'], 'passed checks have no suggested fix' );
	}

	public function test_unpublished_template_reports_missing_template(): void {
		$tmplSvc = new TemplateService( $this->templates );
		$tmplSvc->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise motorisert',
			'status'       => 'draft',
		] );
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'      => true,
			'template_key' => 'markise_motorisert',
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$pub_check = $this->find_check( $result['checks'], 'template_version_published' );
		$this->assertFalse( $pub_check['passed'] );
		$this->assertSame( 'missing_template', $result['status'] );
	}

	public function test_missing_lookup_table_reports_missing_lookup_table(): void {
		$this->build_clean_template();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'      => true,
			'template_key' => 'markise_motorisert',
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$this->assertSame( 'missing_lookup_table', $result['status'] );
	}

	public function test_lookup_table_without_cells_reports_missing_lookup_table(): void {
		$this->build_clean_template();
		$ltSvc = new LookupTableService( $this->lookup_tables, $this->lookup_cells );
		$ltSvc->create( [
			'lookup_table_key' => 'empty_table',
			'name'             => 'Empty',
			'dimension_keys'   => [ 'width', 'height' ],
		] );
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'empty_table',
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$cells_check = $this->find_check( $result['checks'], 'lookup_table_has_cells' );
		$this->assertFalse( $cells_check['passed'] );
		$this->assertSame( 'missing_lookup_table', $result['status'] );
	}

	public function test_invalid_default_field_key_reports_invalid_defaults(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'defaults'         => [ 'unknown_field' => 'whatever' ],
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$check = $this->find_check( $result['checks'], 'defaults_valid' );
		$this->assertFalse( $check['passed'] );
		$this->assertSame( 'invalid_defaults', $result['status'] );
	}

	public function test_default_with_unknown_option_value_reports_invalid_defaults(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'defaults'         => [ 'control_type' => 'no_such_option' ],
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$check = $this->find_check( $result['checks'], 'defaults_valid' );
		$this->assertFalse( $check['passed'] );
	}

	public function test_unknown_library_in_allowed_sources_reports_failure(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'allowed_sources'  => [
				'fabric_color' => [ 'allowed_libraries' => [ 'no_such_library' ] ],
			],
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$check = $this->find_check( $result['checks'], 'allowed_libraries_exist' );
		$this->assertFalse( $check['passed'] );
	}

	public function test_malformed_excluded_items_emits_warning(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'allowed_sources'  => [
				'fabric_color' => [ 'excluded_items' => [ 'no-colon-here' ] ],
			],
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$check = $this->find_check( $result['checks'], 'excluded_items_format' );
		$this->assertFalse( $check['passed'] );
		$this->assertSame( 'warning', $check['severity'] );
	}

	public function test_locked_value_against_unknown_option_reports_failure(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'field_overrides'  => [
				'control_type' => [ 'lock' => 'no_such_option' ],
			],
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$check = $this->find_check( $result['checks'], 'locked_values_valid' );
		$this->assertFalse( $check['passed'] );
		$this->assertSame( 'invalid_defaults', $result['status'] );
	}

	public function test_invalid_frontend_mode_reports_failure(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
		] );
		// Forge an invalid frontend_mode after save to simulate a meta
		// value that bypassed the service (e.g. external migration).
		$this->bindings->records[ self::PRODUCT_ID ]['frontend_mode'] = 'mars-mode';

		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$check = $this->find_check( $result['checks'], 'frontend_mode_selected' );
		$this->assertFalse( $check['passed'] );
	}

	public function test_clean_binding_reports_ready(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->build_library();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'defaults'         => [ 'control_type' => 'manual' ],
			'allowed_sources'  => [
				'fabric_color' => [ 'allowed_libraries' => [ 'textiles_dickson' ] ],
			],
		] );
		$result = $this->diagnostics->run( self::PRODUCT_ID );
		$this->assertSame( 'ready', $result['status'], 'checks=' . json_encode( $result['checks'] ) );
		foreach ( $result['checks'] as $c ) {
			if ( $c['severity'] === 'critical' ) {
				$this->assertTrue( $c['passed'], 'critical check failed: ' . $c['id'] );
			}
		}
	}

	public function test_compute_status_returns_ready_for_clean_binding(): void {
		$this->build_clean_template();
		$this->build_lookup_table();
		$this->bindings->save( self::PRODUCT_ID, [
			'enabled'          => true,
			'template_key'     => 'markise_motorisert',
			'lookup_table_key' => 'markise_2d_v1',
			'frontend_mode'    => 'stepper',
			'defaults'         => [ 'control_type' => 'manual' ],
		] );
		$this->assertSame( 'ready', $this->diagnostics->compute_status( self::PRODUCT_ID ) );
	}

	private function find_check( array $checks, string $id ): array {
		foreach ( $checks as $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) return $c;
		}
		$this->fail( 'Check not found: ' . $id );
	}
}
