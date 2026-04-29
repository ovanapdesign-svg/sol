<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\RuleRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Validation\KeyValidator;

/**
 * Rule CRUD + spec_json validation against RULE_ENGINE_CONTRACT.md.
 *
 * Cross-reference checks (field_keys / step_keys / option_keys) run
 * against the entities currently in the template; lookup_table_keys
 * are not cross-checked here (would require LookupTableRepository
 * coupling — defer to a write-time validator pass when needed).
 */
final class RuleService {

	public const OPERATORS = [
		'equals', 'not_equals',
		'greater_than', 'less_than',
		'between',
		'contains',
		'is_selected', 'is_empty',
		'in', 'not_in',
	];

	public const ACTIONS = [
		'show_field', 'hide_field',
		'show_step', 'hide_step',
		'require_field',
		'disable_option',
		'filter_source',
		'set_default',
		'reset_value',
		'switch_lookup_table',
		'add_surcharge',
		'show_warning',
		'block_add_to_cart',
	];

	private const NAME_MIN = 2;
	private const NAME_MAX = 255;

	public function __construct(
		private RuleRepository $rules,
		private TemplateRepository $templates,
		private FieldRepository $fields,
		private StepRepository $steps,
		private FieldOptionRepository $options,
	) {}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}|null
	 */
	public function list_for_template( int $template_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		return $this->rules->list_in_template( (string) $template['template_key'] );
	}

	public function get( int $template_id, int $rule_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		$rule = $this->rules->find_by_id( $rule_id );
		if ( $rule === null || $rule['template_key'] !== $template['template_key'] ) {
			return null;
		}
		return $rule;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,mixed>>}
	 */
	public function create( int $template_id, array $input ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Template not found.' ] ] ];
		}
		$errors = $this->validate( $input, null, $template );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized                 = $this->sanitize( $input );
		$sanitized['template_key'] = (string) $template['template_key'];
		$id = $this->rules->create( $sanitized );
		return [ 'ok' => true, 'id' => $id, 'record' => $this->rules->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,mixed>>}
	 */
	public function update( int $template_id, int $rule_id, array $input, string $expected_version_hash ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Template not found.' ] ] ];
		}
		$existing = $this->rules->find_by_id( $rule_id );
		if ( $existing === null || $existing['template_key'] !== $template['template_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Rule not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Rule was edited elsewhere. Reload and try again.' ] ] ];
		}
		$errors = $this->validate( $input, $existing, $template );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized                 = $this->sanitize( $input );
		$sanitized['template_key'] = (string) $existing['template_key'];
		// rule_key is immutable.
		$sanitized['rule_key'] = (string) $existing['rule_key'];
		$this->rules->update( $rule_id, $sanitized );
		return [ 'ok' => true, 'record' => $this->rules->find_by_id( $rule_id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,mixed>>}
	 */
	public function soft_delete( int $template_id, int $rule_id ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Template not found.' ] ] ];
		}
		$existing = $this->rules->find_by_id( $rule_id );
		if ( $existing === null || $existing['template_key'] !== $template['template_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Rule not found.' ] ] ];
		}
		$this->rules->soft_delete( $rule_id );
		return [ 'ok' => true ];
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @return array{ok:bool, summary:array<string,int>, errors:list<array<string,mixed>>}
	 */
	public function reorder( int $template_id, array $items ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [
				'ok'      => false,
				'summary' => [ 'updated' => 0, 'skipped' => 0 ],
				'errors'  => [ [ 'code' => 'template_not_found', 'message' => 'Template not found.' ] ],
			];
		}
		$updated = 0;
		$skipped = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['rule_id'] ) ) {
				$skipped++;
				continue;
			}
			$rule = $this->rules->find_by_id( (int) $item['rule_id'] );
			if ( $rule === null || $rule['template_key'] !== $template['template_key'] ) {
				$skipped++;
				continue;
			}
			$priority   = isset( $item['priority'] )   ? (int) $item['priority']   : (int) $rule['priority'];
			$sort_order = isset( $item['sort_order'] ) ? (int) $item['sort_order'] : (int) $rule['sort_order'];
			$this->rules->set_priority_and_sort( (int) $item['rule_id'], $priority, $sort_order );
			$updated++;
		}
		return [
			'ok'      => true,
			'summary' => [ 'updated' => $updated, 'skipped' => $skipped ],
			'errors'  => [],
		];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @param array<string,mixed>      $template
	 * @return list<array{field?:string, code:string, message:string, path?:string}>
	 */
	public function validate( array $input, ?array $existing, array $template ): array {
		$errors = [];

		// rule_key (immutable on update).
		if ( $existing === null ) {
			$rule_key   = isset( $input['rule_key'] ) ? (string) $input['rule_key'] : '';
			$key_errors = KeyValidator::validate( 'rule_key', $rule_key );
			if ( count( $key_errors ) > 0 ) {
				$errors = array_merge( $errors, $key_errors );
			} elseif ( $this->rules->key_exists_in_template( (string) $template['template_key'], $rule_key ) ) {
				$errors[] = [
					'field'   => 'rule_key',
					'code'    => 'duplicate',
					'message' => 'A rule with this key already exists in this template.',
				];
			}
		}

		$name        = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		$name_length = strlen( $name );
		if ( $name === '' ) {
			$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => 'name is required.' ];
		} elseif ( $name_length < self::NAME_MIN ) {
			$errors[] = [ 'field' => 'name', 'code' => 'too_short', 'message' => sprintf( 'name must be at least %d characters.', self::NAME_MIN ) ];
		} elseif ( $name_length > self::NAME_MAX ) {
			$errors[] = [ 'field' => 'name', 'code' => 'too_long', 'message' => sprintf( 'name must be at most %d characters.', self::NAME_MAX ) ];
		}

		if ( array_key_exists( 'priority', $input ) && $input['priority'] !== '' && $input['priority'] !== null ) {
			if ( ! is_numeric( $input['priority'] ) ) {
				$errors[] = [ 'field' => 'priority', 'code' => 'invalid_type', 'message' => 'priority must be numeric.' ];
			}
		}

		// spec validation
		$spec = $input['spec'] ?? null;
		if ( $spec === null || ! is_array( $spec ) ) {
			$errors[] = [ 'field' => 'spec', 'code' => 'invalid_type', 'message' => 'spec must be an object with `when` and `then` keys.' ];
		} else {
			$errors = array_merge( $errors, $this->validate_spec( $spec, (string) $template['template_key'] ) );
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $spec
	 * @return list<array{field?:string, code:string, message:string, path?:string}>
	 */
	private function validate_spec( array $spec, string $template_key ): array {
		$errors = [];

		// Top-level shape: {when, then}
		if ( ! array_key_exists( 'when', $spec ) ) {
			$errors[] = [ 'field' => 'spec', 'path' => 'when', 'code' => 'missing', 'message' => 'spec.when is required.' ];
		}
		if ( ! array_key_exists( 'then', $spec ) || ! is_array( $spec['then'] ) ) {
			$errors[] = [ 'field' => 'spec', 'path' => 'then', 'code' => 'missing', 'message' => 'spec.then must be an array of actions.' ];
		}

		// Index template entities for cross-reference checks.
		$index = $this->index_template_entities( $template_key );

		if ( isset( $spec['when'] ) ) {
			$errors = array_merge( $errors, $this->validate_condition( $spec['when'], 'when', $index ) );
		}
		if ( isset( $spec['then'] ) && is_array( $spec['then'] ) ) {
			foreach ( $spec['then'] as $i => $action ) {
				$errors = array_merge( $errors, $this->validate_action( $action, sprintf( 'then[%d]', $i ), $index ) );
			}
		}

		return $errors;
	}

	/**
	 * @return array{fields:list<string>, steps:list<string>, options_by_field:array<string,list<string>>}
	 */
	private function index_template_entities( string $template_key ): array {
		$field_keys = [];
		$options_by_field = [];

		// Walk all steps to find every field belonging to this template.
		$step_keys = [];
		$steps_listing = $this->steps->list_in_template( $template_key );
		foreach ( $steps_listing['items'] as $step ) {
			$step_keys[] = (string) $step['step_key'];
			$fields_listing = $this->fields->list_in_step( $template_key, (string) $step['step_key'] );
			foreach ( $fields_listing['items'] as $field ) {
				$field_keys[] = (string) $field['field_key'];
				$options_listing = $this->options->list_for_field( $template_key, (string) $field['field_key'] );
				$options_by_field[ (string) $field['field_key'] ] = array_map(
					static fn( array $o ): string => (string) $o['option_key'],
					$options_listing['items']
				);
			}
		}

		return [
			'fields'           => array_values( array_unique( $field_keys ) ),
			'steps'            => array_values( array_unique( $step_keys ) ),
			'options_by_field' => $options_by_field,
		];
	}

	/**
	 * @param array{fields:list<string>, steps:list<string>, options_by_field:array<string,list<string>>} $index
	 * @return list<array{field?:string, code:string, message:string, path?:string}>
	 */
	private function validate_condition( mixed $condition, string $path, array $index ): array {
		if ( ! is_array( $condition ) ) {
			return [ [ 'field' => 'spec', 'path' => $path, 'code' => 'invalid_type', 'message' => 'condition must be an object.' ] ];
		}
		// always
		if ( array_key_exists( 'always', $condition ) ) {
			if ( ! is_bool( $condition['always'] ) ) {
				return [ [ 'field' => 'spec', 'path' => $path . '.always', 'code' => 'invalid_type', 'message' => 'always must be boolean.' ] ];
			}
			return [];
		}
		// all / any
		foreach ( [ 'all', 'any' ] as $kw ) {
			if ( array_key_exists( $kw, $condition ) ) {
				if ( ! is_array( $condition[ $kw ] ) || count( $condition[ $kw ] ) === 0 ) {
					return [ [ 'field' => 'spec', 'path' => $path . '.' . $kw, 'code' => 'invalid_type', 'message' => $kw . ' must be a non-empty array.' ] ];
				}
				$errors = [];
				foreach ( $condition[ $kw ] as $i => $sub ) {
					$errors = array_merge( $errors, $this->validate_condition( $sub, $path . '.' . $kw . '[' . $i . ']', $index ) );
				}
				return $errors;
			}
		}
		// not
		if ( array_key_exists( 'not', $condition ) ) {
			return $this->validate_condition( $condition['not'], $path . '.not', $index );
		}
		// atomic { field, op, value }
		if ( ! isset( $condition['field'], $condition['op'] ) ) {
			return [ [ 'field' => 'spec', 'path' => $path, 'code' => 'invalid_condition', 'message' => 'condition must be { field, op, value } or { all|any|not|always }.' ] ];
		}
		$errors = [];
		$field_key = (string) $condition['field'];
		if ( ! in_array( $field_key, $index['fields'], true ) ) {
			$errors[] = [ 'field' => 'spec', 'path' => $path . '.field', 'code' => 'unknown_field', 'message' => sprintf( 'Unknown field_key: %s', $field_key ) ];
		}
		$op = (string) $condition['op'];
		if ( ! in_array( $op, self::OPERATORS, true ) ) {
			$errors[] = [ 'field' => 'spec', 'path' => $path . '.op', 'code' => 'unknown_operator', 'message' => sprintf( 'Unknown operator: %s', $op ) ];
		}
		// `between` requires a [min, max] tuple.
		if ( $op === 'between' ) {
			$value = $condition['value'] ?? null;
			if ( ! is_array( $value ) || count( $value ) !== 2 ) {
				$errors[] = [ 'field' => 'spec', 'path' => $path . '.value', 'code' => 'invalid_value', 'message' => 'between requires [min, max].' ];
			}
		}
		// `in` / `not_in` require an array.
		if ( in_array( $op, [ 'in', 'not_in' ], true ) ) {
			$value = $condition['value'] ?? null;
			if ( ! is_array( $value ) ) {
				$errors[] = [ 'field' => 'spec', 'path' => $path . '.value', 'code' => 'invalid_value', 'message' => $op . ' requires an array value.' ];
			}
		}
		return $errors;
	}

	/**
	 * @param array{fields:list<string>, steps:list<string>, options_by_field:array<string,list<string>>} $index
	 * @return list<array{field?:string, code:string, message:string, path?:string}>
	 */
	private function validate_action( mixed $action, string $path, array $index ): array {
		if ( ! is_array( $action ) || ! isset( $action['action'] ) ) {
			return [ [ 'field' => 'spec', 'path' => $path, 'code' => 'invalid_action', 'message' => 'action must be { action, ... }.' ] ];
		}
		$type = (string) $action['action'];
		if ( ! in_array( $type, self::ACTIONS, true ) ) {
			return [ [ 'field' => 'spec', 'path' => $path . '.action', 'code' => 'unknown_action', 'message' => sprintf( 'Unknown action: %s', $type ) ] ];
		}
		$errors = [];

		switch ( $type ) {
			case 'show_field':
			case 'hide_field':
			case 'require_field':
			case 'reset_value':
				$f = (string) ( $action['field'] ?? '' );
				if ( $f === '' || ! in_array( $f, $index['fields'], true ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.field', 'code' => 'unknown_field', 'message' => sprintf( 'Unknown field_key: %s', $f ) ];
				}
				break;
			case 'show_step':
			case 'hide_step':
				$s = (string) ( $action['step'] ?? '' );
				if ( $s === '' || ! in_array( $s, $index['steps'], true ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.step', 'code' => 'unknown_step', 'message' => sprintf( 'Unknown step_key: %s', $s ) ];
				}
				break;
			case 'disable_option':
				$f = (string) ( $action['field'] ?? '' );
				if ( ! in_array( $f, $index['fields'], true ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.field', 'code' => 'unknown_field', 'message' => sprintf( 'Unknown field_key: %s', $f ) ];
					break;
				}
				$opt = (string) ( $action['option'] ?? '' );
				$valid_options = $index['options_by_field'][ $f ] ?? [];
				if ( $opt === '' ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.option', 'code' => 'missing', 'message' => 'disable_option requires `option`.' ];
				} elseif ( count( $valid_options ) > 0 && ! in_array( $opt, $valid_options, true ) ) {
					// Only flag if the field has manual options registered;
					// library / woo sources are validated at render time.
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.option', 'code' => 'unknown_option', 'message' => sprintf( 'Unknown option_key on field %s: %s', $f, $opt ) ];
				}
				break;
			case 'filter_source':
				$f = (string) ( $action['field'] ?? '' );
				if ( ! in_array( $f, $index['fields'], true ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.field', 'code' => 'unknown_field', 'message' => sprintf( 'Unknown field_key: %s', $f ) ];
				}
				if ( ! isset( $action['filter'] ) || ! is_array( $action['filter'] ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.filter', 'code' => 'missing', 'message' => 'filter_source requires a filter object.' ];
				}
				break;
			case 'set_default':
				$f = (string) ( $action['field'] ?? '' );
				if ( ! in_array( $f, $index['fields'], true ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.field', 'code' => 'unknown_field', 'message' => sprintf( 'Unknown field_key: %s', $f ) ];
				}
				if ( ! array_key_exists( 'value', $action ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.value', 'code' => 'missing', 'message' => 'set_default requires `value`.' ];
				}
				break;
			case 'switch_lookup_table':
				if ( empty( $action['lookup_table_key'] ) || ! is_string( $action['lookup_table_key'] ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.lookup_table_key', 'code' => 'missing', 'message' => 'switch_lookup_table requires lookup_table_key.' ];
				}
				break;
			case 'add_surcharge':
				$has_amount  = array_key_exists( 'amount', $action );
				$has_percent = array_key_exists( 'percent_of_base', $action );
				if ( $has_amount === $has_percent ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path, 'code' => 'invalid_value', 'message' => 'add_surcharge needs exactly one of amount or percent_of_base.' ];
				}
				if ( empty( $action['label'] ) || ! is_string( $action['label'] ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.label', 'code' => 'missing', 'message' => 'add_surcharge requires label.' ];
				}
				break;
			case 'show_warning':
				if ( empty( $action['message'] ) || ! is_string( $action['message'] ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.message', 'code' => 'missing', 'message' => 'show_warning requires message.' ];
				}
				if ( isset( $action['level'] ) && ! in_array( (string) $action['level'], [ 'info', 'warning', 'error' ], true ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.level', 'code' => 'invalid_value', 'message' => 'level must be info | warning | error.' ];
				}
				break;
			case 'block_add_to_cart':
				if ( ! isset( $action['message'] ) || ! is_string( $action['message'] ) ) {
					$errors[] = [ 'field' => 'spec', 'path' => $path . '.message', 'code' => 'missing', 'message' => 'block_add_to_cart requires message.' ];
				}
				break;
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		return [
			'rule_key'   => (string) ( $input['rule_key'] ?? '' ),
			'name'       => trim( (string) ( $input['name'] ?? '' ) ),
			'spec'       => is_array( $input['spec'] ?? null ) ? $input['spec'] : [],
			'priority'   => isset( $input['priority'] ) && $input['priority'] !== '' ? (int) $input['priority'] : 100,
			'is_active'  => array_key_exists( 'is_active', $input ) ? (bool) $input['is_active'] : true,
			'sort_order' => isset( $input['sort_order'] ) && $input['sort_order'] !== '' ? (int) $input['sort_order'] : 0,
		];
	}
}
