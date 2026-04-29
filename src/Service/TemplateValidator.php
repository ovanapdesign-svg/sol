<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\RuleRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;

/**
 * Pre-publish full-template validation per TEMPLATE_BUILDER_UX.md §9.3.
 *
 * Errors block publish; warnings don't. The result has the same shape
 * for both so the UI can render them consistently.
 */
final class TemplateValidator {

	public function __construct(
		private TemplateRepository $templates,
		private StepRepository $steps,
		private FieldRepository $fields,
		private FieldOptionRepository $options,
		private RuleRepository $rules,
	) {}

	/**
	 * @return array{
	 *   valid: bool,
	 *   errors: list<array<string,string>>,
	 *   warnings: list<array<string,string>>
	 * }|null
	 */
	public function validate( int $template_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}

		$template_key = (string) $template['template_key'];
		$errors       = [];
		$warnings     = [];

		$steps_listing = $this->steps->list_in_template( $template_key );
		$steps         = $steps_listing['items'];

		if ( count( $steps ) === 0 ) {
			$errors[] = $this->issue( 'error', 'template', $template_key, 'A template needs at least one step.' );
		}

		// Index for cross-references.
		$known_steps  = array_map( static fn( array $s ): string => (string) $s['step_key'], $steps );
		$known_fields = [];
		$known_options_by_field = [];
		$fields_with_required = [];
		$fields_by_key        = [];

		foreach ( $steps as $step ) {
			$step_key       = (string) $step['step_key'];
			$fields_listing = $this->fields->list_in_step( $template_key, $step_key );
			$fields_in_step = $fields_listing['items'];

			if ( count( $fields_in_step ) === 0 ) {
				$warnings[] = $this->issue( 'warning', 'step', $step_key, sprintf( 'Step "%s" has no fields.', $step['label'] ?? $step_key ) );
			}

			foreach ( $fields_in_step as $field ) {
				$field_key            = (string) $field['field_key'];
				$known_fields[]       = $field_key;
				$fields_by_key[ $field_key ] = $field;
				if ( ! empty( $field['is_required'] ) ) {
					$fields_with_required[] = $field_key;
				}

				// Source config sanity per value_source.
				$source_config = is_array( $field['source_config'] ?? null ) ? $field['source_config'] : [];
				switch ( (string) $field['value_source'] ) {
					case 'manual_options':
						$opts_listing = $this->options->list_for_field( $template_key, $field_key );
						$active_opts  = array_filter( $opts_listing['items'], static fn( array $o ): bool => ! empty( $o['is_active'] ) );
						$known_options_by_field[ $field_key ] = array_map(
							static fn( array $o ): string => (string) $o['option_key'],
							$active_opts
						);
						if ( count( $active_opts ) === 0 ) {
							$errors[] = $this->issue(
								'error',
								'field',
								$field_key,
								sprintf( 'Field "%s" uses manual_options but has no active options.', $field['label'] ?? $field_key )
							);
						}
						break;
					case 'library':
						if ( empty( $source_config['libraries'] ) || ! is_array( $source_config['libraries'] ) ) {
							$errors[] = $this->issue(
								'error',
								'field',
								$field_key,
								sprintf( 'Field "%s" uses library source but has no libraries selected.', $field['label'] ?? $field_key )
							);
						}
						break;
					case 'woo_category':
						if ( empty( $source_config['category_slug'] ) ) {
							$errors[] = $this->issue(
								'error',
								'field',
								$field_key,
								sprintf( 'Field "%s" uses woo_category but has no category_slug.', $field['label'] ?? $field_key )
							);
						}
						break;
					case 'woo_products':
						if ( empty( $source_config['product_skus'] ) || ! is_array( $source_config['product_skus'] ) ) {
							$errors[] = $this->issue(
								'error',
								'field',
								$field_key,
								sprintf( 'Field "%s" uses woo_products but has no product SKUs.', $field['label'] ?? $field_key )
							);
						}
						break;
					case 'lookup_table':
						if ( empty( $source_config['lookup_table_key'] ) ) {
							$errors[] = $this->issue(
								'error',
								'field',
								$field_key,
								sprintf( 'Field "%s" uses lookup_table but has no lookup_table_key.', $field['label'] ?? $field_key )
							);
						}
						if ( empty( $source_config['dimension'] ) ) {
							$errors[] = $this->issue(
								'error',
								'field',
								$field_key,
								sprintf( 'Field "%s" uses lookup_table but has no dimension.', $field['label'] ?? $field_key )
							);
						}
						break;
				}
			}
		}

		// Required fields with no source = unable to ever satisfy.
		foreach ( $fields_with_required as $fk ) {
			$f = $fields_by_key[ $fk ];
			if ( (string) $f['value_source'] === '' ) {
				$errors[] = $this->issue(
					'error',
					'field',
					$fk,
					sprintf( 'Required field "%s" has no value_source.', $f['label'] ?? $fk )
				);
			}
		}

		// Rule cross-references.
		$rules_listing = $this->rules->list_in_template( $template_key );
		foreach ( $rules_listing['items'] as $rule ) {
			$rule_key = (string) $rule['rule_key'];
			if ( ! is_array( $rule['spec'] ?? null ) ) {
				$errors[] = $this->issue( 'error', 'rule', $rule_key, sprintf( 'Rule "%s" has invalid spec_json.', $rule['name'] ?? $rule_key ) );
				continue;
			}
			$ref_errors = $this->collect_rule_reference_errors(
				$rule['spec'],
				$known_fields,
				$known_steps,
				$known_options_by_field,
				$rule_key,
				(string) ( $rule['name'] ?? $rule_key )
			);
			$errors = array_merge( $errors, $ref_errors );
		}

		return [
			'valid'    => count( $errors ) === 0,
			'errors'   => array_values( $errors ),
			'warnings' => array_values( $warnings ),
		];
	}

	/**
	 * @param array<string,mixed>           $spec
	 * @param list<string>                  $known_fields
	 * @param list<string>                  $known_steps
	 * @param array<string,list<string>>    $options_by_field
	 * @return list<array<string,string>>
	 */
	private function collect_rule_reference_errors(
		array $spec,
		array $known_fields,
		array $known_steps,
		array $options_by_field,
		string $rule_key,
		string $rule_name
	): array {
		$errors = [];
		$collect_condition = function ( $condition ) use ( &$collect_condition, &$errors, $known_fields, $rule_key, $rule_name ): void {
			if ( ! is_array( $condition ) ) {
				return;
			}
			if ( isset( $condition['all'] ) && is_array( $condition['all'] ) ) {
				foreach ( $condition['all'] as $sub ) {
					$collect_condition( $sub );
				}
				return;
			}
			if ( isset( $condition['any'] ) && is_array( $condition['any'] ) ) {
				foreach ( $condition['any'] as $sub ) {
					$collect_condition( $sub );
				}
				return;
			}
			if ( isset( $condition['not'] ) ) {
				$collect_condition( $condition['not'] );
				return;
			}
			if ( array_key_exists( 'always', $condition ) ) {
				return;
			}
			if ( isset( $condition['field'] ) ) {
				$f = (string) $condition['field'];
				if ( ! in_array( $f, $known_fields, true ) ) {
					$errors[] = $this->issue(
						'error',
						'rule',
						$rule_key,
						sprintf( 'Rule "%s" condition references missing field_key: %s', $rule_name, $f )
					);
				}
			}
		};
		$collect_condition( $spec['when'] ?? null );

		if ( isset( $spec['then'] ) && is_array( $spec['then'] ) ) {
			foreach ( $spec['then'] as $action ) {
				if ( ! is_array( $action ) || ! isset( $action['action'] ) ) {
					continue;
				}
				$type = (string) $action['action'];
				if ( in_array( $type, [ 'show_field', 'hide_field', 'require_field', 'reset_value', 'set_default', 'filter_source' ], true ) ) {
					$f = (string) ( $action['field'] ?? '' );
					if ( $f !== '' && ! in_array( $f, $known_fields, true ) ) {
						$errors[] = $this->issue(
							'error',
							'rule',
							$rule_key,
							sprintf( 'Rule "%s" action %s references missing field_key: %s', $rule_name, $type, $f )
						);
					}
				} elseif ( in_array( $type, [ 'show_step', 'hide_step' ], true ) ) {
					$s = (string) ( $action['step'] ?? '' );
					if ( $s !== '' && ! in_array( $s, $known_steps, true ) ) {
						$errors[] = $this->issue(
							'error',
							'rule',
							$rule_key,
							sprintf( 'Rule "%s" action %s references missing step_key: %s', $rule_name, $type, $s )
						);
					}
				} elseif ( $type === 'disable_option' ) {
					$f = (string) ( $action['field'] ?? '' );
					$o = (string) ( $action['option'] ?? '' );
					if ( $f !== '' && ! in_array( $f, $known_fields, true ) ) {
						$errors[] = $this->issue(
							'error',
							'rule',
							$rule_key,
							sprintf( 'Rule "%s" disable_option references missing field_key: %s', $rule_name, $f )
						);
						continue;
					}
					$valid_options = $options_by_field[ $f ] ?? [];
					if ( $o !== '' && count( $valid_options ) > 0 && ! in_array( $o, $valid_options, true ) ) {
						$errors[] = $this->issue(
							'error',
							'rule',
							$rule_key,
							sprintf( 'Rule "%s" disable_option references missing option_key on field %s: %s', $rule_name, $f, $o )
						);
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * @return array{severity:string, object_type:string, object_key:string, message:string}
	 */
	private function issue( string $severity, string $object_type, string $object_key, string $message ): array {
		return [
			'severity'    => $severity,
			'object_type' => $object_type,
			'object_key'  => $object_key,
			'message'     => $message,
		];
	}
}
