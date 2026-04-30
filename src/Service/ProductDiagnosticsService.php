<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\ProductBindingRepository;
use ConfigKit\Repository\RuleRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;

/**
 * The 11 product-binding readiness checks per
 * PRODUCT_BINDING_SPEC.md §10.1, plus the status derivation per §10.3.
 */
final class ProductDiagnosticsService {

	/**
	 * Owner-readable title per check id (rendered as the label on
	 * the check row, instead of the technical id).
	 */
	private const TITLES = [
		'template_selected'           => 'Template selected',
		'template_version_published'  => 'Template has a published version',
		'lookup_table_selected'       => 'Lookup table selected',
		'lookup_table_has_cells'      => 'Lookup table has price cells',
		'allowed_libraries_exist'     => 'Allowed libraries exist',
		'excluded_items_format'       => 'Excluded items format is correct',
		'defaults_valid'              => 'Default values are valid',
		'price_resolvable'            => 'Default price resolves',
		'rules_targets_valid'         => 'Template rules target valid entities',
		'locked_values_valid'         => 'Locked field values are valid',
		'frontend_mode_selected'      => 'Frontend mode set',
	];

	/**
	 * Owner-friendly suggested next action when a check fails.
	 */
	private const SUGGESTED_FIXES = [
		'template_selected'           => "Pick a template from the 'Base setup' section above.",
		'template_version_published'  => 'Open the template and click Publish.',
		'lookup_table_selected'       => "Pick a lookup table from the 'Base setup' section above.",
		'lookup_table_has_cells'      => 'Open the lookup table and add cells (Excel import lands in Phase 4).',
		'allowed_libraries_exist'     => "Check the 'Allowed sources' section — one or more libraries are missing or inactive.",
		'excluded_items_format'       => "Excluded items must use library_key:item_key format. Edit the 'Allowed sources' section.",
		'defaults_valid'              => "Open 'Product defaults' and re-pick — some entries reference deleted fields or items.",
		'price_resolvable'            => "Set width/height defaults in 'Product defaults' so the engine can resolve a price.",
		'rules_targets_valid'         => 'Open the template and fix rules that reference deleted fields or steps.',
		'locked_values_valid'         => "Update 'Visibility & locking' — a locked field references a deleted option.",
		'frontend_mode_selected'      => "Pick stepper or accordion in the 'Base setup' section above.",
	];

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
		private RuleRepository $rules,
	) {}

	/**
	 * @return array{status:string, checks:list<array<string,mixed>>}|null
	 */
	public function run( int $product_id ): ?array {
		$binding = $this->bindings->find( $product_id );
		if ( $binding === null ) {
			return null;
		}

		$checks = [];

		// 1. Template selected.
		$template = null;
		if ( ! empty( $binding['template_key'] ) ) {
			$template = $this->templates->find_by_key( (string) $binding['template_key'] );
			$checks[] = $this->check( 'template_selected', $template !== null, 'critical',
				$template !== null ? 'Template is set.' : 'No template selected.',
				$template !== null ? null : '#section-base-setup'
			);
		} else {
			$checks[] = $this->check( 'template_selected', false, 'critical', 'No template selected.', '#section-base-setup' );
		}

		// 2. Published template version exists.
		$template_published = $template !== null && (string) ( $template['status'] ?? '' ) === 'published';
		$checks[] = $this->check(
			'template_version_published',
			$template_published,
			'critical',
			$template_published
				? 'Template has a published version.'
				: 'Selected template has no published version.',
			$template_published ? null : '#section-base-setup'
		);

		// 3. Lookup table selected.
		$lookup_table = null;
		$lt_set = ! empty( $binding['lookup_table_key'] );
		if ( $lt_set ) {
			$lookup_table = $this->lookup_tables->find_by_key( (string) $binding['lookup_table_key'] );
		}
		$checks[] = $this->check(
			'lookup_table_selected',
			$lt_set && $lookup_table !== null,
			'critical',
			$lt_set
				? ( $lookup_table !== null ? 'Lookup table is set.' : 'Lookup table reference is missing.' )
				: 'No lookup table selected.',
			( $lt_set && $lookup_table !== null ) ? null : '#section-base-setup'
		);

		// 4. Lookup table has at least 1 cell.
		$cells_ok = false;
		if ( $lookup_table !== null ) {
			$cells_listing = $this->lookup_cells->list_in_table( (string) $lookup_table['lookup_table_key'], [], 1, 1 );
			$cells_ok      = $cells_listing['total'] > 0;
		}
		$checks[] = $this->check(
			'lookup_table_has_cells',
			$cells_ok,
			'critical',
			$cells_ok ? 'Lookup table has cells.' : 'Lookup table is empty.',
			$cells_ok ? null : '#section-base-setup'
		);

		// Build template field index (re-used by checks 5-10).
		$known_fields           = [];
		$known_options_by_field = [];
		$field_value_sources    = [];
		$field_records          = [];
		if ( $template !== null ) {
			$template_key = (string) $template['template_key'];
			foreach ( $this->steps->list_in_template( $template_key )['items'] as $step ) {
				foreach ( $this->fields->list_in_step( $template_key, (string) $step['step_key'] )['items'] as $field ) {
					$fk                       = (string) $field['field_key'];
					$known_fields[]           = $fk;
					$field_value_sources[ $fk ] = (string) $field['value_source'];
					$field_records[ $fk ]      = $field;
					$known_options_by_field[ $fk ] = array_map(
						static fn( array $o ): string => (string) $o['option_key'],
						$this->options->list_for_field( $template_key, $fk )['items']
					);
				}
			}
		}

		// 5. All allowed libraries exist and are active.
		$allowed_sources = is_array( $binding['allowed_sources'] ?? null ) ? $binding['allowed_sources'] : [];
		$bad_libraries   = [];
		foreach ( $allowed_sources as $field_key => $cfg ) {
			$libs = is_array( $cfg ) && isset( $cfg['allowed_libraries'] ) && is_array( $cfg['allowed_libraries'] )
				? $cfg['allowed_libraries']
				: [];
			foreach ( $libs as $library_key ) {
				if ( ! is_string( $library_key ) ) continue;
				$lib = $this->libraries->find_by_key( $library_key );
				if ( $lib === null || empty( $lib['is_active'] ) ) {
					$bad_libraries[] = $library_key;
				}
			}
		}
		$checks[] = $this->check(
			'allowed_libraries_exist',
			count( $bad_libraries ) === 0,
			'critical',
			count( $bad_libraries ) === 0
				? 'All allowed libraries exist and are active.'
				: 'Missing or inactive libraries: ' . implode( ', ', array_unique( $bad_libraries ) ),
			count( $bad_libraries ) === 0 ? null : '#section-allowed-sources'
		);

		// 6. Excluded library items still resolve. Phase 3 minimum: just
		//    confirm the format is valid (libraryKey:itemKey). Existence
		//    can be verified once owner enables an items index.
		$bad_excluded = [];
		foreach ( $allowed_sources as $field_key => $cfg ) {
			$excluded = is_array( $cfg ) && isset( $cfg['excluded_items'] ) && is_array( $cfg['excluded_items'] )
				? $cfg['excluded_items']
				: [];
			foreach ( $excluded as $entry ) {
				if ( ! is_string( $entry ) || strpos( $entry, ':' ) === false ) {
					$bad_excluded[] = (string) $entry;
				}
			}
		}
		$checks[] = $this->check(
			'excluded_items_format',
			count( $bad_excluded ) === 0,
			'warning',
			count( $bad_excluded ) === 0
				? 'Excluded items use library_key:item_key format.'
				: 'Malformed excluded items: ' . implode( ', ', $bad_excluded ),
			count( $bad_excluded ) === 0 ? null : '#section-allowed-sources'
		);

		// 7. Defaults reference valid field/option/item keys.
		$defaults    = is_array( $binding['defaults'] ?? null ) ? $binding['defaults'] : [];
		$bad_default_keys = [];
		foreach ( $defaults as $field_key => $value ) {
			if ( ! is_string( $field_key ) || ! in_array( $field_key, $known_fields, true ) ) {
				$bad_default_keys[] = (string) $field_key;
				continue;
			}
			// For manual_options + library, ensure the value is at least a string/array.
			$source = $field_value_sources[ $field_key ] ?? '';
			if ( $source === 'manual_options' ) {
				$valid_options = $known_options_by_field[ $field_key ] ?? [];
				if ( is_string( $value ) && count( $valid_options ) > 0 && ! in_array( $value, $valid_options, true ) ) {
					$bad_default_keys[] = $field_key . '=' . $value;
				}
			}
		}
		$checks[] = $this->check(
			'defaults_valid',
			count( $bad_default_keys ) === 0,
			'critical',
			count( $bad_default_keys ) === 0
				? 'Defaults reference known fields and options.'
				: 'Bad default references: ' . implode( ', ', $bad_default_keys ),
			count( $bad_default_keys ) === 0 ? null : '#section-defaults'
		);

		// 8. Default configuration resolves to a price.
		// Phase 3 minimum: if there's a default width and height + an
		// active lookup table with cells, accept; otherwise warn.
		$can_resolve_price = $cells_ok && (
			isset( $defaults['width_mm'] ) || isset( $defaults['height_mm'] ) || count( $defaults ) === 0
		);
		$checks[] = $this->check(
			'price_resolvable',
			$can_resolve_price,
			'warning',
			$can_resolve_price
				? 'Default configuration is expected to resolve a price.'
				: 'Default configuration may not resolve a price (no width/height defaults yet).',
			$can_resolve_price ? null : '#section-defaults'
		);

		// 9. All template rules have valid targets.
		$rule_target_errors = 0;
		if ( $template !== null ) {
			foreach ( $this->rules->list_in_template( (string) $template['template_key'] )['items'] as $rule ) {
				$spec = is_array( $rule['spec'] ?? null ) ? $rule['spec'] : [];
				if ( $this->rule_has_orphan_target( $spec, $known_fields, array_map( static fn( $s ) => (string) $s, array_keys( $known_options_by_field ) ) ) ) {
					$rule_target_errors++;
				}
			}
		}
		$checks[] = $this->check(
			'rules_targets_valid',
			$rule_target_errors === 0,
			'critical',
			$rule_target_errors === 0
				? 'Template rules reference live entities.'
				: $rule_target_errors . ' rule(s) reference deleted fields or steps.',
			$rule_target_errors === 0 ? null : '#section-base-setup'
		);

		// 10. No locked field has invalid value.
		$field_overrides    = is_array( $binding['field_overrides'] ?? null ) ? $binding['field_overrides'] : [];
		$bad_locks          = [];
		foreach ( $field_overrides as $fk => $cfg ) {
			if ( ! is_array( $cfg ) ) continue;
			if ( ! array_key_exists( 'lock', $cfg ) ) continue;
			if ( ! is_string( $fk ) || ! in_array( $fk, $known_fields, true ) ) {
				$bad_locks[] = (string) $fk;
				continue;
			}
			$source = $field_value_sources[ $fk ] ?? '';
			if ( $source === 'manual_options' ) {
				$valid_options = $known_options_by_field[ $fk ] ?? [];
				$value         = $cfg['lock'];
				if ( is_string( $value ) && count( $valid_options ) > 0 && ! in_array( $value, $valid_options, true ) ) {
					$bad_locks[] = $fk;
				}
			}
		}
		$checks[] = $this->check(
			'locked_values_valid',
			count( $bad_locks ) === 0,
			'critical',
			count( $bad_locks ) === 0
				? 'Locked field values reference live options.'
				: 'Locked fields with bad values: ' . implode( ', ', $bad_locks ),
			count( $bad_locks ) === 0 ? null : '#section-visibility'
		);

		// 11. Frontend mode selected.
		$frontend_mode = (string) ( $binding['frontend_mode'] ?? '' );
		$frontend_ok   = in_array( $frontend_mode, ProductBindingService::FRONTEND_MODES, true );
		$checks[] = $this->check(
			'frontend_mode_selected',
			$frontend_ok,
			'critical',
			$frontend_ok ? 'Frontend mode is set: ' . $frontend_mode . '.' : 'Frontend mode missing.',
			$frontend_ok ? null : '#section-base-setup'
		);

		return [
			'status' => $this->derive_status( $binding, $checks ),
			'checks' => $checks,
		];
	}

	public function compute_status( int $product_id ): string {
		$result = $this->run( $product_id );
		return $result === null ? 'disabled' : $result['status'];
	}

	/**
	 * @param array<string,mixed>      $binding
	 * @param list<array<string,mixed>> $checks
	 */
	private function derive_status( array $binding, array $checks ): string {
		if ( empty( $binding['enabled'] ) ) {
			return 'disabled';
		}
		// Most-specific failure wins, in priority order.
		$ordered = [
			'template_selected'         => 'missing_template',
			'template_version_published' => 'missing_template',
			'lookup_table_selected'      => 'missing_lookup_table',
			'lookup_table_has_cells'     => 'missing_lookup_table',
			'defaults_valid'             => 'invalid_defaults',
			'locked_values_valid'        => 'invalid_defaults',
			'rules_targets_valid'        => 'invalid_defaults',
			'price_resolvable'           => 'pricing_unresolved',
			'frontend_mode_selected'     => 'missing_template',
		];
		foreach ( $ordered as $check_id => $status ) {
			$check = $this->find_check( $checks, $check_id );
			if ( $check !== null && ! $check['passed'] && $check['severity'] === 'critical' ) {
				return $status;
			}
		}
		return 'ready';
	}

	/**
	 * @param list<array<string,mixed>> $checks
	 */
	private function find_check( array $checks, string $id ): ?array {
		foreach ( $checks as $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed>     $spec
	 * @param list<string>            $known_fields
	 * @param list<string>            $known_field_keys_with_options
	 */
	private function rule_has_orphan_target( array $spec, array $known_fields, array $known_field_keys_with_options ): bool {
		// Walk WHEN.
		$has_orphan = false;
		$walk = function ( $cond ) use ( &$walk, &$has_orphan, $known_fields ): void {
			if ( ! is_array( $cond ) ) return;
			if ( isset( $cond['all'] ) && is_array( $cond['all'] ) ) {
				foreach ( $cond['all'] as $sub ) $walk( $sub );
				return;
			}
			if ( isset( $cond['any'] ) && is_array( $cond['any'] ) ) {
				foreach ( $cond['any'] as $sub ) $walk( $sub );
				return;
			}
			if ( isset( $cond['not'] ) ) {
				$walk( $cond['not'] );
				return;
			}
			if ( array_key_exists( 'always', $cond ) ) return;
			if ( isset( $cond['field'] ) && ! in_array( (string) $cond['field'], $known_fields, true ) ) {
				$has_orphan = true;
			}
		};
		$walk( $spec['when'] ?? null );
		if ( $has_orphan ) return true;

		foreach ( ( $spec['then'] ?? [] ) as $action ) {
			if ( ! is_array( $action ) ) continue;
			$type = (string) ( $action['action'] ?? '' );
			if ( in_array( $type, [ 'show_field', 'hide_field', 'require_field', 'reset_value', 'set_default', 'filter_source', 'disable_option' ], true ) ) {
				$f = (string) ( $action['field'] ?? '' );
				if ( $f !== '' && ! in_array( $f, $known_fields, true ) ) {
					return true;
				}
			}
			// step lookups handled by the validator pre-publish; checks
			// 5-10 here focus on field-level orphans.
		}
		// $known_field_keys_with_options reserved for option-level
		// orphan checks in a future iteration.
		unset( $known_field_keys_with_options );
		return false;
	}

	/**
	 * @return array{id:string, title:string, passed:bool, severity:string, message:string, suggested_fix:?string, fix_url:?string, fix_link:?string}
	 */
	private function check( string $id, bool $passed, string $severity, string $message, ?string $fix_url ): array {
		$title = self::TITLES[ $id ] ?? $id;
		$suggested = $passed ? null : ( self::SUGGESTED_FIXES[ $id ] ?? null );
		return [
			'id'             => $id,
			'title'          => $title,
			'passed'         => $passed,
			'severity'       => $severity,
			'message'        => $message,
			'suggested_fix'  => $suggested,
			'fix_url'        => $fix_url,
			// `fix_link` is an alias for fix_url so JS code can prefer
			// either name without surprises.
			'fix_link'       => $fix_url,
		];
	}
}
