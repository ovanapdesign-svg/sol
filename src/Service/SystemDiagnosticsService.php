<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LogRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Repository\ProductBindingRepository;
use ConfigKit\Repository\RuleRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;

/**
 * System-wide diagnostics scanner. Surfaces broken state across
 * products, templates, libraries, lookup tables, modules, and rules
 * per ADMIN_SITEMAP.md §2.7 + OWNER_UX_FLOW.md §8.
 *
 * Phase 3 scope: critical issues only (Flow F line 207). Two warnings
 * land here too — empty steps + modules with no allowed field kinds
 * — because they are cheap to detect and cited in the Phase 3 brief.
 * Full diagnostic catalogue (info/warning expansions) is Phase 4.
 *
 * The service is read-only against the entity repositories. The only
 * write surface is acknowledgement logging, delegated to LogRepository.
 */
final class SystemDiagnosticsService {

	public const SEVERITY_CRITICAL = 'critical';
	public const SEVERITY_WARNING  = 'warning';

	public const TYPE_PRODUCT      = 'product';
	public const TYPE_TEMPLATE     = 'template';
	public const TYPE_LIBRARY_ITEM = 'library_item';
	public const TYPE_LOOKUP_TABLE = 'lookup_table';
	public const TYPE_MODULE       = 'module';
	public const TYPE_RULE         = 'rule';

	public function __construct(
		private ProductBindingRepository $bindings,
		private TemplateRepository $templates,
		private StepRepository $steps,
		private FieldRepository $fields,
		private FieldOptionRepository $options,
		private LookupTableRepository $lookup_tables,
		private LookupCellRepository $lookup_cells,
		private LibraryRepository $libraries,
		private LibraryItemRepository $library_items,
		private ModuleRepository $modules,
		private RuleRepository $rules,
		private LogRepository $log,
	) {}

	/**
	 * Run all checks. Returned issue list is flat; the page groups by
	 * `object_type` to populate the tabs.
	 *
	 * @return array{
	 *   issues: list<array<string,mixed>>,
	 *   counts: array{critical:int,warning:int,total:int,acknowledged:int},
	 *   ran_at: string
	 * }
	 */
	public function run( bool $include_acknowledged = false ): array {
		$ack_index = $this->log->build_acknowledgement_index();

		$issues = [];
		array_push( $issues, ...$this->check_products_missing_template() );
		array_push( $issues, ...$this->check_products_missing_lookup_table() );
		array_push( $issues, ...$this->check_templates_no_published_version() );
		array_push( $issues, ...$this->check_templates_empty_steps() );
		array_push( $issues, ...$this->check_lookup_tables_empty() );
		array_push( $issues, ...$this->check_orphan_library_items() );
		array_push( $issues, ...$this->check_modules_no_field_kinds() );
		array_push( $issues, ...$this->check_rules_broken_targets() );

		$counts = [ 'critical' => 0, 'warning' => 0, 'total' => 0, 'acknowledged' => 0 ];
		$visible = [];
		foreach ( $issues as $issue ) {
			$key = $this->ack_key( $issue );
			$ack = $ack_index[ $key ] ?? null;
			$issue['acknowledged']     = $ack !== null;
			$issue['ack_at']           = $ack === null ? null : $ack['ack_at'];
			$issue['ack_by_user_id']   = $ack === null ? null : $ack['ack_by_user_id'];

			$counts['total']++;
			if ( $issue['severity'] === self::SEVERITY_CRITICAL ) $counts['critical']++;
			else $counts['warning']++;
			if ( $issue['acknowledged'] ) $counts['acknowledged']++;

			if ( $issue['acknowledged'] && ! $include_acknowledged ) continue;
			$visible[] = $issue;
		}

		return [
			'issues' => $visible,
			'counts' => $counts,
			'ran_at' => function_exists( 'current_time' )
				? (string) \current_time( 'mysql', true )
				: gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Persist an acknowledgement. Idempotent: callers can ack the same
	 * issue multiple times; only the most recent timestamp matters in
	 * the index.
	 */
	public function acknowledge( string $issue_id, string $object_type, int|string|null $object_id, string $note = '' ): void {
		$context = [
			'issue_id'    => $issue_id,
			'object_type' => $object_type,
			'object_id'   => $object_id,
		];
		if ( $note !== '' ) {
			$context['note'] = $note;
		}
		$this->log->record(
			'info',
			LogRepository::EVENT_DIAGNOSTIC_ACK,
			'Acknowledged diagnostic issue: ' . $issue_id,
			$context,
			is_int( $object_id ) && $object_type === self::TYPE_PRODUCT ? $object_id : null,
			$object_type === self::TYPE_TEMPLATE && is_string( $object_id ) ? $object_id : null
		);
	}

	// ---- Checks ----

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_products_missing_template(): array {
		$out = [];
		foreach ( $this->iterate_enabled_products() as $row ) {
			if ( $row['template_key'] === null || $row['template_key'] === '' ) {
				$out[] = $this->issue(
					'products_missing_template',
					self::SEVERITY_CRITICAL,
					'Product without template',
					self::TYPE_PRODUCT,
					(int) $row['product_id'],
					(string) $row['name'],
					'ConfigKit is enabled on this product but no template is selected.',
					$this->product_edit_url( (int) $row['product_id'] )
				);
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_products_missing_lookup_table(): array {
		$out = [];
		foreach ( $this->iterate_enabled_products() as $row ) {
			if ( $row['template_key'] === null || $row['template_key'] === '' ) continue;
			if ( $row['lookup_table_key'] === null || $row['lookup_table_key'] === '' ) {
				$out[] = $this->issue(
					'products_missing_lookup_table',
					self::SEVERITY_CRITICAL,
					'Product without lookup table',
					self::TYPE_PRODUCT,
					(int) $row['product_id'],
					(string) $row['name'],
					'A template is set but no lookup table is selected — pricing cannot resolve.',
					$this->product_edit_url( (int) $row['product_id'] )
				);
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_templates_no_published_version(): array {
		$out = [];
		foreach ( $this->iterate_templates() as $tmpl ) {
			if ( (string) ( $tmpl['status'] ?? '' ) === 'archived' ) continue;
			$pub = (int) ( $tmpl['published_version_id'] ?? 0 );
			if ( $pub > 0 ) continue;
			$out[] = $this->issue(
				'templates_no_published_version',
				self::SEVERITY_CRITICAL,
				'Template has no published version',
				self::TYPE_TEMPLATE,
				(string) $tmpl['template_key'],
				(string) $tmpl['name'],
				'No published version exists. Customers bound to this template cannot purchase until it is published.',
				$this->template_edit_url( (int) $tmpl['id'] )
			);
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_templates_empty_steps(): array {
		$out = [];
		foreach ( $this->iterate_templates() as $tmpl ) {
			if ( (string) ( $tmpl['status'] ?? '' ) === 'archived' ) continue;
			$steps = $this->steps->list_in_template( (string) $tmpl['template_key'] )['items'];
			if ( count( $steps ) === 0 ) {
				$out[] = $this->issue(
					'templates_no_steps',
					self::SEVERITY_WARNING,
					'Template has no steps',
					self::TYPE_TEMPLATE,
					(string) $tmpl['template_key'],
					(string) $tmpl['name'],
					'Add at least one step before publishing.',
					$this->template_edit_url( (int) $tmpl['id'] )
				);
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_lookup_tables_empty(): array {
		$out = [];
		foreach ( $this->iterate_lookup_tables() as $table ) {
			if ( ! ( $table['is_active'] ?? true ) ) continue;
			$total = (int) $this->lookup_cells->list_in_table( (string) $table['lookup_table_key'], [], 1, 1 )['total'];
			if ( $total === 0 ) {
				$out[] = $this->issue(
					'lookup_tables_empty',
					self::SEVERITY_CRITICAL,
					'Lookup table has zero cells',
					self::TYPE_LOOKUP_TABLE,
					(string) $table['lookup_table_key'],
					(string) $table['name'],
					'Pricing cannot resolve from an empty table.',
					$this->lookup_table_edit_url( (int) $table['id'] )
				);
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_orphan_library_items(): array {
		$out = [];
		// Collect inactive library keys. Items in inactive libraries
		// are orphans because they cannot appear in the storefront.
		$inactive_keys = [];
		foreach ( $this->iterate_libraries() as $lib ) {
			if ( ! ( $lib['is_active'] ?? true ) ) {
				$inactive_keys[ (string) $lib['library_key'] ] = (string) $lib['name'];
			}
		}
		foreach ( $inactive_keys as $key => $library_name ) {
			$count = $this->library_items->count_in_library( $key );
			if ( $count > 0 ) {
				$out[] = $this->issue(
					'library_items_orphaned',
					self::SEVERITY_CRITICAL,
					'Library items in inactive library',
					self::TYPE_LIBRARY_ITEM,
					$key,
					$library_name,
					sprintf( '%d item(s) live in an inactive library. Reactivate the library or move the items.', $count ),
					$this->library_edit_url( $key )
				);
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_modules_no_field_kinds(): array {
		$out = [];
		foreach ( $this->iterate_modules() as $mod ) {
			if ( ! ( $mod['is_active'] ?? true ) ) continue;
			$kinds = $mod['allowed_field_kinds'] ?? [];
			if ( ! is_array( $kinds ) || count( $kinds ) === 0 ) {
				$out[] = $this->issue(
					'modules_no_field_kinds',
					self::SEVERITY_WARNING,
					'Module declares no allowed field kinds',
					self::TYPE_MODULE,
					(string) $mod['module_key'],
					(string) $mod['name'],
					'A module without allowed field kinds cannot back any field. Add at least one kind or deactivate.',
					$this->module_edit_url( (int) $mod['id'] )
				);
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function check_rules_broken_targets(): array {
		$out = [];
		foreach ( $this->iterate_templates() as $tmpl ) {
			if ( (string) ( $tmpl['status'] ?? '' ) === 'archived' ) continue;
			$template_key = (string) $tmpl['template_key'];

			$known_steps   = [];
			$known_fields  = [];
			$known_options = [];
			foreach ( $this->steps->list_in_template( $template_key )['items'] as $step ) {
				$known_steps[] = (string) $step['step_key'];
				foreach ( $this->fields->list_in_step( $template_key, (string) $step['step_key'] )['items'] as $field ) {
					$fk              = (string) $field['field_key'];
					$known_fields[]  = $fk;
					$known_options[ $fk ] = array_map(
						static fn( array $o ): string => (string) $o['option_key'],
						$this->options->list_for_field( $template_key, $fk )['items']
					);
				}
			}

			foreach ( $this->rules->list_in_template( $template_key )['items'] as $rule ) {
				$broken = $this->rule_target_problems( $rule, $known_fields, $known_steps, $known_options );
				if ( count( $broken ) === 0 ) continue;
				$out[] = $this->issue(
					'rules_broken_targets',
					self::SEVERITY_CRITICAL,
					'Rule references missing target',
					self::TYPE_RULE,
					(string) $rule['rule_key'],
					(string) ( $rule['name'] ?? $rule['rule_key'] ),
					sprintf(
						'Rule "%s" on template "%s" references: %s.',
						(string) $rule['rule_key'],
						$template_key,
						implode( '; ', $broken )
					),
					$this->template_edit_url( (int) $tmpl['id'] ) . '&drawer=rules'
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed>      $rule
	 * @param list<string>             $known_fields
	 * @param list<string>             $known_steps
	 * @param array<string,list<string>> $known_options
	 * @return list<string>
	 */
	private function rule_target_problems( array $rule, array $known_fields, array $known_steps, array $known_options ): array {
		$problems = [];
		$spec     = is_array( $rule['spec'] ?? null ) ? $rule['spec'] : [];

		$walk = function ( $cond ) use ( &$walk, &$problems, $known_fields, $known_options ): void {
			if ( ! is_array( $cond ) ) return;
			if ( isset( $cond['all'] ) && is_array( $cond['all'] ) ) { foreach ( $cond['all'] as $sub ) $walk( $sub ); return; }
			if ( isset( $cond['any'] ) && is_array( $cond['any'] ) ) { foreach ( $cond['any'] as $sub ) $walk( $sub ); return; }
			if ( isset( $cond['not'] ) ) { $walk( $cond['not'] ); return; }
			if ( array_key_exists( 'always', $cond ) ) return;
			if ( isset( $cond['field'] ) ) {
				$fk = (string) $cond['field'];
				if ( ! in_array( $fk, $known_fields, true ) ) {
					$problems[] = 'missing field_key "' . $fk . '"';
					return;
				}
				if ( isset( $cond['value'] ) && is_string( $cond['value'] ) && ( $known_options[ $fk ] ?? [] ) !== [] ) {
					if ( ! in_array( (string) $cond['value'], $known_options[ $fk ], true ) ) {
						$problems[] = 'unknown option "' . $cond['value'] . '" for field "' . $fk . '"';
					}
				}
			}
		};
		$walk( $spec['when'] ?? null );

		$field_actions = [ 'show_field', 'hide_field', 'require_field', 'reset_value', 'set_default', 'filter_source', 'disable_option' ];
		$step_actions  = [ 'show_step', 'hide_step', 'require_step' ];
		foreach ( ( $spec['then'] ?? [] ) as $action ) {
			if ( ! is_array( $action ) ) continue;
			$type = (string) ( $action['action'] ?? '' );
			if ( in_array( $type, $field_actions, true ) ) {
				$fk = (string) ( $action['field'] ?? '' );
				if ( $fk !== '' && ! in_array( $fk, $known_fields, true ) ) {
					$problems[] = 'missing field_key "' . $fk . '"';
				}
				if ( $type === 'disable_option' && isset( $action['option'] ) ) {
					$opt = (string) $action['option'];
					if ( $fk !== '' && ( $known_options[ $fk ] ?? [] ) !== [] && ! in_array( $opt, $known_options[ $fk ], true ) ) {
						$problems[] = 'unknown option_key "' . $opt . '" for field "' . $fk . '"';
					}
				}
			}
			if ( in_array( $type, $step_actions, true ) ) {
				$sk = (string) ( $action['step'] ?? '' );
				if ( $sk !== '' && ! in_array( $sk, $known_steps, true ) ) {
					$problems[] = 'missing step_key "' . $sk . '"';
				}
			}
		}
		return array_values( array_unique( $problems ) );
	}

	// ---- Iterators (paginate behind the repos so the service stays simple) ----

	/**
	 * @return iterable<array<string,mixed>>
	 */
	private function iterate_enabled_products(): iterable {
		$page = 1;
		while ( true ) {
			$batch = $this->bindings->list_overview( [ 'enabled' => true ], $page, 200 );
			foreach ( $batch['items'] as $row ) {
				yield $row;
			}
			$total_pages = (int) ( $batch['total_pages'] ?? 0 );
			if ( $page >= $total_pages || count( $batch['items'] ) === 0 ) break;
			$page++;
		}
	}

	/**
	 * @return iterable<array<string,mixed>>
	 */
	private function iterate_templates(): iterable {
		$page = 1;
		while ( true ) {
			$batch = $this->templates->list( [], $page, 200 );
			foreach ( $batch['items'] as $row ) yield $row;
			if ( $page >= (int) $batch['total_pages'] || count( $batch['items'] ) === 0 ) break;
			$page++;
		}
	}

	/**
	 * @return iterable<array<string,mixed>>
	 */
	private function iterate_lookup_tables(): iterable {
		$page = 1;
		while ( true ) {
			$batch = $this->lookup_tables->list( [], $page, 200 );
			foreach ( $batch['items'] as $row ) yield $row;
			if ( $page >= (int) $batch['total_pages'] || count( $batch['items'] ) === 0 ) break;
			$page++;
		}
	}

	/**
	 * @return iterable<array<string,mixed>>
	 */
	private function iterate_libraries(): iterable {
		$page = 1;
		while ( true ) {
			$batch = $this->libraries->list( [], $page, 200 );
			foreach ( $batch['items'] as $row ) yield $row;
			if ( $page >= (int) $batch['total_pages'] || count( $batch['items'] ) === 0 ) break;
			$page++;
		}
	}

	/**
	 * @return iterable<array<string,mixed>>
	 */
	private function iterate_modules(): iterable {
		$page = 1;
		while ( true ) {
			$batch = $this->modules->list( $page, 200 );
			foreach ( $batch['items'] as $row ) yield $row;
			if ( $page >= (int) $batch['total_pages'] || count( $batch['items'] ) === 0 ) break;
			$page++;
		}
	}

	// ---- Helpers ----

	/**
	 * @return array<string,mixed>
	 */
	private function issue(
		string $id,
		string $severity,
		string $title,
		string $object_type,
		int|string $object_id,
		string $object_name,
		string $message,
		?string $fix_url
	): array {
		return [
			'id'          => $id,
			'severity'    => $severity,
			'title'       => $title,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'object_name' => $object_name,
			'message'     => $message,
			'fix_url'     => $fix_url,
		];
	}

	/**
	 * @param array<string,mixed> $issue
	 */
	private function ack_key( array $issue ): string {
		$oid = $issue['object_id'] ?? '';
		return (string) $issue['id'] . '|' . (string) $issue['object_type'] . '|' . ( is_int( $oid ) ? (string) $oid : (string) $oid );
	}

	private function product_edit_url( int $product_id ): string {
		if ( function_exists( 'admin_url' ) ) {
			return \admin_url( 'post.php?post=' . $product_id . '&action=edit#configkit_product_data' );
		}
		return '/wp-admin/post.php?post=' . $product_id . '&action=edit#configkit_product_data';
	}

	private function template_edit_url( int $template_id ): string {
		return $this->configkit_admin_url( 'configkit-templates', [ 'action' => 'edit', 'id' => $template_id ] );
	}

	private function lookup_table_edit_url( int $id ): string {
		return $this->configkit_admin_url( 'configkit-lookup-tables', [ 'action' => 'edit', 'id' => $id ] );
	}

	private function library_edit_url( string $library_key ): string {
		return $this->configkit_admin_url( 'configkit-libraries', [ 'view' => 'library', 'library_key' => $library_key ] );
	}

	private function module_edit_url( int $module_id ): string {
		return $this->configkit_admin_url( 'configkit-modules', [ 'action' => 'edit', 'id' => $module_id ] );
	}

	/**
	 * @param array<string,int|string> $args
	 */
	private function configkit_admin_url( string $page, array $args = [] ): string {
		$query = [ 'page' => $page ] + $args;
		$qs    = http_build_query( $query );
		if ( function_exists( 'admin_url' ) ) {
			return \admin_url( 'admin.php?' . $qs );
		}
		return '/wp-admin/admin.php?' . $qs;
	}
}
