<?php
declare(strict_types=1);

namespace ConfigKit\Engines;

/**
 * Rule Engine — pure-PHP, no WP dependencies.
 *
 * Evaluates a list of rule specs against a configuration state and returns
 * the effects to apply. See RULE_ENGINE_CONTRACT.md (DRAFT v1).
 */
final class RuleEngine {

	/** @var list<array{marker:string,rule_key:string,detail:string}> */
	private array $log = [];

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function evaluate( array $input ): array {
		$this->log = [];

		/** @var array<string,array<string,mixed>> $field_metadata */
		$field_metadata = $input['field_metadata'] ?? [];
		/** @var array<string,array<string,mixed>> $step_metadata */
		$step_metadata = $input['step_metadata'] ?? [];
		/** @var array<string,mixed> $selections */
		$selections = $input['selections'] ?? [];
		/** @var list<array<string,mixed>> $rules */
		$rules = $this->sort_rules( $input['rules'] ?? [] );

		$state            = $this->init_state( $field_metadata, $step_metadata, $selections );
		$rule_results     = [];
		$pending_defaults = []; // queued by set_default actions

		foreach ( $rules as $rule ) {
			$rule_key  = (string) ( $rule['rule_key'] ?? '' );
			$is_active = (bool) ( $rule['is_active'] ?? true );
			$record    = [ 'rule_key' => $rule_key, 'matched' => false, 'actions_applied' => [] ];

			if ( ! $is_active ) {
				$rule_results[] = $record;
				continue;
			}

			$when    = $rule['spec']['when'] ?? null;
			$matched = $this->evaluate_condition( $when, $state );
			$record['matched'] = $matched;

			if ( $matched ) {
				$actions = $rule['spec']['then'] ?? [];
				if ( is_array( $actions ) ) {
					foreach ( $actions as $action ) {
						if ( ! is_array( $action ) ) {
							continue;
						}
						if ( $this->apply_action( $action, $state, $rule_key, $pending_defaults ) ) {
							$record['actions_applied'][] = (string) ( $action['action'] ?? '' );
						}
					}
				}
			}

			$rule_results[] = $record;
		}

		$this->apply_reset_cascade( $state );

		foreach ( $pending_defaults as $field_key => $default_value ) {
			if ( ! isset( $state['fields'][ $field_key ] ) ) {
				continue;
			}
			$current = $state['fields'][ $field_key ]['value'] ?? null;
			if ( $this->is_empty_value( $current ) ) {
				$state['fields'][ $field_key ]['value'] = $default_value;
			}
		}

		return $this->finalize( $state, $rule_results );
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return list<array<string,mixed>>
	 */
	private function sort_rules( array $rules ): array {
		usort(
			$rules,
			static function ( array $a, array $b ): int {
				$pa = (int) ( $a['priority'] ?? 0 );
				$pb = (int) ( $b['priority'] ?? 0 );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				return ( (int) ( $a['sort_order'] ?? 0 ) ) <=> ( (int) ( $b['sort_order'] ?? 0 ) );
			}
		);
		return $rules;
	}

	/**
	 * @param array<string,array<string,mixed>> $field_metadata
	 * @param array<string,array<string,mixed>> $step_metadata
	 * @param array<string,mixed>               $selections
	 * @return array<string,mixed>
	 */
	private function init_state( array $field_metadata, array $step_metadata, array $selections ): array {
		$fields = [];
		foreach ( $field_metadata as $field_key => $meta ) {
			$fields[ $field_key ] = [
				'visible'          => (bool) ( $meta['default_visible'] ?? true ),
				'required'         => (bool) ( $meta['is_required'] ?? false ),
				'options_filter'   => null,
				'disabled_options' => [],
				'default'          => $meta['default_value'] ?? null,
				'value'            => $selections[ $field_key ] ?? ( $meta['default_value'] ?? null ),
				'_step_key'        => $meta['step_key'] ?? null,
			];
		}

		$steps = [];
		foreach ( $step_metadata as $step_key => $meta ) {
			$steps[ $step_key ] = [
				'visible' => (bool) ( $meta['default_visible'] ?? true ),
			];
		}

		return [
			'fields'           => $fields,
			'steps'            => $steps,
			'lookup_table_key' => null,
			'surcharges'       => [],
			'warnings'         => [],
			'blocked'          => false,
			'block_reason'     => null,
		];
	}

	/**
	 * @param mixed                $condition
	 * @param array<string,mixed>  $state
	 */
	private function evaluate_condition( mixed $condition, array $state ): bool {
		if ( ! is_array( $condition ) ) {
			return false;
		}

		if ( array_key_exists( 'always', $condition ) ) {
			return (bool) $condition['always'];
		}

		if ( isset( $condition['all'] ) && is_array( $condition['all'] ) ) {
			foreach ( $condition['all'] as $sub ) {
				if ( ! $this->evaluate_condition( $sub, $state ) ) {
					return false;
				}
			}
			return true;
		}

		if ( isset( $condition['any'] ) && is_array( $condition['any'] ) ) {
			foreach ( $condition['any'] as $sub ) {
				if ( $this->evaluate_condition( $sub, $state ) ) {
					return true;
				}
			}
			return false;
		}

		if ( isset( $condition['not'] ) ) {
			return ! $this->evaluate_condition( $condition['not'], $state );
		}

		if ( isset( $condition['field'], $condition['op'] ) ) {
			$field       = (string) $condition['field'];
			$op          = (string) $condition['op'];
			$value       = $condition['value'] ?? null;
			$field_value = $state['fields'][ $field ]['value'] ?? null;
			return $this->evaluate_atom( $op, $field_value, $value );
		}

		return false;
	}

	private function evaluate_atom( string $op, mixed $field_value, mixed $value ): bool {
		switch ( $op ) {
			case 'equals':
				if ( is_numeric( $field_value ) && is_numeric( $value ) ) {
					return (float) $field_value === (float) $value;
				}
				return $field_value === $value;
			case 'not_equals':
				return ! $this->evaluate_atom( 'equals', $field_value, $value );
			case 'greater_than':
				return is_numeric( $field_value ) && is_numeric( $value )
					&& (float) $field_value > (float) $value;
			case 'less_than':
				return is_numeric( $field_value ) && is_numeric( $value )
					&& (float) $field_value < (float) $value;
			case 'between':
				if ( ! is_array( $value ) || count( $value ) !== 2 ) {
					return false;
				}
				if ( ! is_numeric( $field_value ) ) {
					return false;
				}
				$min = $value[0] ?? null;
				$max = $value[1] ?? null;
				return is_numeric( $min ) && is_numeric( $max )
					&& (float) $field_value >= (float) $min
					&& (float) $field_value <= (float) $max;
			case 'contains':
				if ( is_array( $field_value ) ) {
					return in_array( $value, $field_value, false );
				}
				if ( is_string( $field_value ) && is_string( $value ) ) {
					return $value !== '' && str_contains( $field_value, $value );
				}
				return false;
			case 'is_selected':
				return ! $this->is_empty_value( $field_value );
			case 'is_empty':
				return $this->is_empty_value( $field_value );
			case 'in':
				return is_array( $value ) && in_array( $field_value, $value, false );
			case 'not_in':
				return is_array( $value ) && ! in_array( $field_value, $value, false );
			default:
				return false;
		}
	}

	private function is_empty_value( mixed $v ): bool {
		if ( $v === null || $v === '' ) {
			return true;
		}
		if ( is_array( $v ) && count( $v ) === 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $action
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $pending_defaults
	 */
	private function apply_action(
		array $action,
		array &$state,
		string $rule_key,
		array &$pending_defaults
	): bool {
		$type = (string) ( $action['action'] ?? '' );

		switch ( $type ) {
			case 'show_field':
				return $this->mutate_field_visibility( $action, $state, $rule_key, true );

			case 'hide_field':
				return $this->mutate_field_visibility( $action, $state, $rule_key, false );

			case 'show_step':
				return $this->mutate_step_visibility( $action, $state, $rule_key, true );

			case 'hide_step':
				return $this->mutate_step_visibility( $action, $state, $rule_key, false );

			case 'require_field':
				$field = (string) ( $action['field'] ?? '' );
				if ( ! isset( $state['fields'][ $field ] ) ) {
					$this->log_target_missing( $rule_key, "field:{$field}" );
					return false;
				}
				$state['fields'][ $field ]['required'] = true;
				return true;

			case 'disable_option':
				$field  = (string) ( $action['field'] ?? '' );
				$option = $action['option'] ?? '';
				if ( ! isset( $state['fields'][ $field ] ) ) {
					$this->log_target_missing( $rule_key, "field:{$field}" );
					return false;
				}
				if ( ! in_array( $option, $state['fields'][ $field ]['disabled_options'], true ) ) {
					$state['fields'][ $field ]['disabled_options'][] = $option;
				}
				return true;

			case 'filter_source':
				$field  = (string) ( $action['field'] ?? '' );
				$filter = $action['filter'] ?? null;
				if ( ! isset( $state['fields'][ $field ] ) ) {
					$this->log_target_missing( $rule_key, "field:{$field}" );
					return false;
				}
				if ( ! is_array( $filter ) ) {
					return false;
				}
				$existing = $state['fields'][ $field ]['options_filter'] ?? [];
				$state['fields'][ $field ]['options_filter'] = $this->merge_filter( $existing ?? [], $filter );
				return true;

			case 'set_default':
				$field = (string) ( $action['field'] ?? '' );
				if ( ! isset( $state['fields'][ $field ] ) ) {
					$this->log_target_missing( $rule_key, "field:{$field}" );
					return false;
				}
				$pending_defaults[ $field ]               = $action['value'] ?? null;
				$state['fields'][ $field ]['default']     = $action['value'] ?? null;
				return true;

			case 'reset_value':
				$field = (string) ( $action['field'] ?? '' );
				if ( ! isset( $state['fields'][ $field ] ) ) {
					$this->log_target_missing( $rule_key, "field:{$field}" );
					return false;
				}
				$state['fields'][ $field ]['value'] = null;
				return true;

			case 'switch_lookup_table':
				$key = $action['lookup_table_key'] ?? null;
				if ( ! is_string( $key ) || $key === '' ) {
					return false;
				}
				$state['lookup_table_key'] = $key;
				return true;

			case 'add_surcharge':
				$entry = [ 'label' => (string) ( $action['label'] ?? '' ) ];
				if ( array_key_exists( 'amount', $action ) && array_key_exists( 'percent_of_base', $action ) ) {
					return false;
				}
				if ( array_key_exists( 'amount', $action ) ) {
					$entry['amount'] = (float) $action['amount'];
				} elseif ( array_key_exists( 'percent_of_base', $action ) ) {
					$entry['percent_of_base'] = (float) $action['percent_of_base'];
				} else {
					return false;
				}
				$state['surcharges'][] = $entry;
				return true;

			case 'show_warning':
				$state['warnings'][] = [
					'message' => (string) ( $action['message'] ?? '' ),
					'level'   => (string) ( $action['level'] ?? 'info' ),
				];
				return true;

			case 'block_add_to_cart':
				$state['blocked']      = true;
				$state['block_reason'] = isset( $action['message'] ) ? (string) $action['message'] : null;
				return true;

			default:
				return false;
		}
	}

	/**
	 * @param array<string,mixed> $action
	 * @param array<string,mixed> $state
	 */
	private function mutate_field_visibility( array $action, array &$state, string $rule_key, bool $visible ): bool {
		$field = (string) ( $action['field'] ?? '' );
		if ( ! isset( $state['fields'][ $field ] ) ) {
			$this->log_target_missing( $rule_key, "field:{$field}" );
			return false;
		}
		$state['fields'][ $field ]['visible'] = $visible;
		return true;
	}

	/**
	 * @param array<string,mixed> $action
	 * @param array<string,mixed> $state
	 */
	private function mutate_step_visibility( array $action, array &$state, string $rule_key, bool $visible ): bool {
		$step = (string) ( $action['step'] ?? '' );
		if ( ! isset( $state['steps'][ $step ] ) ) {
			$this->log_target_missing( $rule_key, "step:{$step}" );
			return false;
		}
		$state['steps'][ $step ]['visible'] = $visible;

		if ( ! $visible ) {
			foreach ( $state['fields'] as $field_key => $field_state ) {
				if ( ( $field_state['_step_key'] ?? null ) === $step ) {
					$state['fields'][ $field_key ]['visible'] = false;
				}
			}
		}
		return true;
	}

	/**
	 * Merge filter clauses with AND semantics: union of keys, array
	 * lists merged, scalar values become arrays.
	 *
	 * @param array<string,mixed> $existing
	 * @param array<string,mixed> $incoming
	 * @return array<string,mixed>
	 */
	private function merge_filter( array $existing, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if ( ! array_key_exists( $key, $existing ) ) {
				$existing[ $key ] = $value;
				continue;
			}
			$prev = $existing[ $key ];
			if ( is_array( $prev ) && is_array( $value ) ) {
				if ( array_is_list( $prev ) && array_is_list( $value ) ) {
					$existing[ $key ] = array_values( array_unique( array_merge( $prev, $value ), SORT_REGULAR ) );
				} else {
					$existing[ $key ] = array_merge( $prev, $value );
				}
			} else {
				$existing[ $key ] = [ $prev, $value ];
			}
		}
		return $existing;
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function apply_reset_cascade( array &$state ): void {
		foreach ( $state['fields'] as $field_key => $field ) {
			$current = $field['value'];
			$reset   = false;

			if ( $field['visible'] === false ) {
				$reset = true;
			} elseif (
				! $this->is_empty_value( $current )
				&& in_array( $current, $field['disabled_options'], true )
			) {
				$reset = true;
			}

			if ( $reset ) {
				$state['fields'][ $field_key ]['value'] = null;
			}
		}
	}

	private function log_target_missing( string $rule_key, string $detail ): void {
		$this->log[] = [
			'marker'   => 'rule.target_missing',
			'rule_key' => $rule_key,
			'detail'   => $detail,
		];
	}

	/**
	 * @param array<string,mixed>          $state
	 * @param list<array<string,mixed>>    $rule_results
	 * @return array<string,mixed>
	 */
	private function finalize( array $state, array $rule_results ): array {
		$fields_out = [];
		foreach ( $state['fields'] as $key => $f ) {
			$fields_out[ $key ] = [
				'visible'          => $f['visible'],
				'required'         => $f['required'],
				'options_filter'   => $f['options_filter'],
				'disabled_options' => array_values( $f['disabled_options'] ),
				'default'          => $f['default'] ?? null,
				'value'            => $f['value'],
			];
		}

		return [
			'fields'           => $fields_out,
			'steps'            => $state['steps'],
			'lookup_table_key' => $state['lookup_table_key'],
			'surcharges'       => $state['surcharges'],
			'warnings'         => $state['warnings'],
			'blocked'          => $state['blocked'],
			'block_reason'     => $state['block_reason'],
			'rule_results'     => $rule_results,
			'log'              => $this->log,
		];
	}
}
