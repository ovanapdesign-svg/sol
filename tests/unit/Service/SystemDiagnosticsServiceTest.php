<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\SystemDiagnosticsService;
use PHPUnit\Framework\TestCase;

final class SystemDiagnosticsServiceTest extends TestCase {

	private StubProductBindingRepository $bindings;
	private StubTemplateRepository $templates;
	private StubStepRepository $steps;
	private StubFieldRepository $fields;
	private StubFieldOptionRepository $options;
	private StubLookupTableRepository $lookup_tables;
	private StubLookupCellRepository $lookup_cells;
	private StubLibraryRepository $libraries;
	private StubLibraryItemRepository $library_items;
	private StubModuleRepository $modules;
	private StubRuleRepository $rules;
	private StubLogRepository $log;
	private SystemDiagnosticsService $service;

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
		$this->modules       = new StubModuleRepository();
		$this->rules         = new StubRuleRepository();
		$this->log           = new StubLogRepository();

		$this->service = new SystemDiagnosticsService(
			$this->bindings,
			$this->templates,
			$this->steps,
			$this->fields,
			$this->options,
			$this->lookup_tables,
			$this->lookup_cells,
			$this->libraries,
			$this->library_items,
			$this->modules,
			$this->rules,
			$this->log
		);
	}

	public function test_clean_state_returns_no_issues(): void {
		$result = $this->service->run();
		$this->assertSame( [], $result['issues'] );
		$this->assertSame( 0, $result['counts']['critical'] );
	}

	public function test_product_with_enabled_no_template_reports_critical(): void {
		$this->bindings->save( 1001, [ 'enabled' => true, 'template_key' => null ] );
		$result = $this->service->run();
		$ids = $this->collect_issue_ids( $result['issues'] );
		$this->assertContains( 'products_missing_template', $ids );
		$issue = $this->find_issue( $result['issues'], 'products_missing_template', 1001 );
		$this->assertSame( 'critical', $issue['severity'] );
		$this->assertSame( 'product', $issue['object_type'] );
		// Phase 3.6: title + suggested_fix + fix_link surfaced.
		$this->assertSame( 'Product without template', $issue['title'] );
		$this->assertNotNull( $issue['suggested_fix'] );
		$this->assertArrayHasKey( 'fix_link', $issue );
		$this->assertSame( $issue['fix_url'], $issue['fix_link'] );
	}

	public function test_disabled_product_skipped(): void {
		$this->bindings->save( 1002, [ 'enabled' => false, 'template_key' => null ] );
		$result = $this->service->run();
		$this->assertSame( 0, $result['counts']['critical'] );
	}

	public function test_product_with_template_no_lookup_reports_critical(): void {
		$this->bindings->save( 1003, [
			'enabled'      => true,
			'template_key' => 'markise_motorisert',
			'lookup_table_key' => null,
		] );
		$result = $this->service->run();
		$this->assertContains( 'products_missing_lookup_table', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_template_without_published_version_reports_critical(): void {
		$this->templates->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise',
			'status'       => 'draft',
		] );
		$result = $this->service->run();
		$this->assertContains( 'templates_no_published_version', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_archived_template_skipped(): void {
		$this->templates->create( [
			'template_key' => 'old_template',
			'name'         => 'Old',
			'status'       => 'archived',
		] );
		$result = $this->service->run();
		$this->assertNotContains( 'templates_no_published_version', $this->collect_issue_ids( $result['issues'] ) );
		$this->assertNotContains( 'templates_no_steps', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_template_no_steps_reports_warning(): void {
		$this->templates->create( [
			'template_key' => 'empty_tmpl',
			'name'         => 'Empty Template',
			'status'       => 'draft',
		] );
		$result = $this->service->run();
		$ids = $this->collect_issue_ids( $result['issues'] );
		$this->assertContains( 'templates_no_steps', $ids );
		$issue = $this->find_issue( $result['issues'], 'templates_no_steps', 'empty_tmpl' );
		$this->assertSame( 'warning', $issue['severity'] );
	}

	public function test_lookup_table_empty_reports_critical(): void {
		$this->lookup_tables->create( [
			'lookup_table_key' => 'markise_2d_v1',
			'name'             => 'Markise',
			'is_active'        => true,
		] );
		$result = $this->service->run();
		$ids = $this->collect_issue_ids( $result['issues'] );
		$this->assertContains( 'lookup_tables_empty', $ids );
		// Phase 4.1: fix_link points to the table's cells editor, not
		// the generic Lookup Tables list.
		$issue = $this->find_issue( $result['issues'], 'lookup_tables_empty', 'markise_2d_v1' );
		$this->assertStringContainsString( 'configkit-lookup-tables', (string) $issue['fix_url'] );
		$this->assertStringContainsString( '#cells', (string) $issue['fix_url'] );
	}

	public function test_unpublished_template_fix_link_points_to_publish(): void {
		$tmpl_svc = new \ConfigKit\Service\TemplateService( $this->templates );
		$tmpl_svc->create( [
			'template_key' => 'unfinished',
			'name'         => 'Unfinished',
			'status'       => 'draft',
		] );
		$result = $this->service->run();
		$issue = $this->find_issue( $result['issues'], 'templates_no_published_version', 'unfinished' );
		$this->assertStringContainsString( 'configkit-templates', (string) $issue['fix_url'] );
		$this->assertStringContainsString( 'action=edit', (string) $issue['fix_url'] );
		$this->assertStringContainsString( '#publish', (string) $issue['fix_url'] );
	}

	public function test_inactive_lookup_table_skipped(): void {
		$this->lookup_tables->create( [
			'lookup_table_key' => 'old_table',
			'name'             => 'Old',
			'is_active'        => false,
		] );
		$result = $this->service->run();
		$this->assertNotContains( 'lookup_tables_empty', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_lookup_table_with_cells_passes(): void {
		$this->lookup_tables->create( [
			'lookup_table_key' => 'markise_2d_v1',
			'name'             => 'Markise',
			'is_active'        => true,
		] );
		$this->lookup_cells->create( [
			'lookup_table_key' => 'markise_2d_v1',
			'width'            => 1000,
			'height'           => 1000,
			'price_group_key'  => 'A',
			'price'            => 1500.0,
		] );
		$result = $this->service->run();
		$this->assertNotContains( 'lookup_tables_empty', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_orphan_library_items_in_inactive_library(): void {
		$this->libraries->create( [
			'library_key' => 'textiles_old',
			'module_key'  => 'textiles',
			'name'        => 'Old Textiles',
			'is_active'   => false,
		] );
		$this->library_items->create( [
			'library_key' => 'textiles_old',
			'item_key'    => 'fabric_a',
			'label'       => 'Fabric A',
		] );
		$result = $this->service->run();
		$ids = $this->collect_issue_ids( $result['issues'] );
		$this->assertContains( 'library_items_orphaned', $ids );
	}

	public function test_inactive_library_with_no_items_does_not_report(): void {
		$this->libraries->create( [
			'library_key' => 'empty_lib',
			'module_key'  => 'textiles',
			'name'        => 'Empty',
			'is_active'   => false,
		] );
		$result = $this->service->run();
		$this->assertNotContains( 'library_items_orphaned', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_module_no_field_kinds_reports_warning(): void {
		$this->modules->create( [
			'module_key'          => 'awnings',
			'name'                => 'Awnings',
			'allowed_field_kinds' => [],
			'is_active'           => true,
		] );
		$result = $this->service->run();
		$ids = $this->collect_issue_ids( $result['issues'] );
		$this->assertContains( 'modules_no_field_kinds', $ids );
		$this->assertSame( 1, $result['counts']['warning'] );
	}

	public function test_inactive_module_skipped(): void {
		$this->modules->create( [
			'module_key'          => 'unused',
			'name'                => 'Unused',
			'allowed_field_kinds' => [],
			'is_active'           => false,
		] );
		$result = $this->service->run();
		$this->assertNotContains( 'modules_no_field_kinds', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_rule_with_missing_field_target_reports_critical(): void {
		$this->seed_template_with_one_field();
		// Create an orphaned rule directly in the repo, bypassing RuleService.
		$this->rules->create( [
			'template_key' => 'markise_motorisert',
			'rule_key'     => 'orphan',
			'name'         => 'Orphaned',
			'priority'     => 100,
			'spec'         => [
				'when' => [ 'field' => 'no_such_field', 'op' => 'equals', 'value' => 'x' ],
				'then' => [ [ 'action' => 'show_field', 'field' => 'still_no_such_field' ] ],
			],
		] );
		$result = $this->service->run();
		$this->assertContains( 'rules_broken_targets', $this->collect_issue_ids( $result['issues'] ) );
		$issue = $this->find_issue( $result['issues'], 'rules_broken_targets', 'orphan' );
		$this->assertStringContainsString( 'no_such_field', $issue['message'] );
	}

	public function test_rule_with_missing_step_target_reports_critical(): void {
		$this->seed_template_with_one_field();
		$this->rules->create( [
			'template_key' => 'markise_motorisert',
			'rule_key'     => 'step_orphan',
			'name'         => 'Step Orphan',
			'priority'     => 100,
			'spec'         => [
				'when' => [ 'always' => true ],
				'then' => [ [ 'action' => 'hide_step', 'step' => 'no_such_step' ] ],
			],
		] );
		$result = $this->service->run();
		$ids = $this->collect_issue_ids( $result['issues'] );
		$this->assertContains( 'rules_broken_targets', $ids );
		$issue = $this->find_issue( $result['issues'], 'rules_broken_targets', 'step_orphan' );
		$this->assertStringContainsString( 'no_such_step', $issue['message'] );
	}

	public function test_rule_with_unknown_option_reports_critical(): void {
		$this->seed_template_with_one_field();
		$this->options->create( [
			'template_key' => 'markise_motorisert',
			'field_key'    => 'control_type',
			'option_key'   => 'manual',
			'label'        => 'Manual',
		] );
		$this->rules->create( [
			'template_key' => 'markise_motorisert',
			'rule_key'     => 'bad_option',
			'name'         => 'Bad Option',
			'priority'     => 100,
			'spec'         => [
				'when' => [ 'field' => 'control_type', 'op' => 'equals', 'value' => 'no_such_option' ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'maal' ] ],
			],
		] );
		$result = $this->service->run();
		$this->assertContains( 'rules_broken_targets', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_clean_rule_passes(): void {
		$this->seed_template_with_one_field();
		$this->rules->create( [
			'template_key' => 'markise_motorisert',
			'rule_key'     => 'good',
			'name'         => 'Good',
			'priority'     => 100,
			'spec'         => [
				'when' => [ 'always' => true ],
				'then' => [ [ 'action' => 'show_step', 'step' => 'maal' ] ],
			],
		] );
		$result = $this->service->run();
		$this->assertNotContains( 'rules_broken_targets', $this->collect_issue_ids( $result['issues'] ) );
	}

	public function test_acknowledged_issue_hidden_by_default(): void {
		$this->bindings->save( 1004, [ 'enabled' => true, 'template_key' => null ] );
		$this->service->acknowledge( 'products_missing_template', 'product', 1004 );
		$default = $this->service->run();
		$this->assertSame( 0, count( $default['issues'] ) );
		$this->assertSame( 1, $default['counts']['acknowledged'] );

		$with_ack = $this->service->run( true );
		$this->assertSame( 1, count( $with_ack['issues'] ) );
		$this->assertTrue( $with_ack['issues'][0]['acknowledged'] );
	}

	public function test_acknowledge_writes_log_entry(): void {
		$this->service->acknowledge( 'foo_issue', 'product', 7777, 'fixing tomorrow' );
		$this->assertCount( 1, $this->log->records );
		$row = $this->log->records[0];
		$this->assertSame( 'diagnostic_acknowledged', $row['event_type'] );
		$ctx = json_decode( (string) $row['context_json'], true );
		$this->assertSame( 'foo_issue', $ctx['issue_id'] );
		$this->assertSame( 'fixing tomorrow', $ctx['note'] );
	}

	private function seed_template_with_one_field(): void {
		$this->templates->create( [
			'template_key' => 'markise_motorisert',
			'name'         => 'Markise',
			'status'       => 'draft',
		] );
		$this->steps->create( [
			'template_key' => 'markise_motorisert',
			'step_key'     => 'maal',
			'label'        => 'Mål',
		] );
		$this->fields->create( [
			'template_key' => 'markise_motorisert',
			'step_key'     => 'maal',
			'field_key'    => 'control_type',
			'label'        => 'Betjening',
			'field_kind'   => 'input',
			'input_type'   => 'radio',
			'display_type' => 'plain',
			'value_source' => 'manual_options',
			'behavior'     => 'normal_option',
			'source_config' => [ 'type' => 'manual_options' ],
		] );
	}

	/**
	 * @param list<array<string,mixed>> $issues
	 * @return list<string>
	 */
	private function collect_issue_ids( array $issues ): array {
		return array_map( static fn( $i ) => (string) $i['id'], $issues );
	}

	/**
	 * @param list<array<string,mixed>> $issues
	 */
	private function find_issue( array $issues, string $id, int|string $object_id ): array {
		foreach ( $issues as $i ) {
			if ( $i['id'] === $id && (string) $i['object_id'] === (string) $object_id ) {
				return $i;
			}
		}
		$this->fail( "Issue $id for object $object_id not found." );
	}
}
