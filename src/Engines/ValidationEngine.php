<?php
declare(strict_types=1);

namespace ConfigKit\Engines;

/**
 * Validation Engine — pure-PHP, no WP dependencies.
 *
 * Checks a selection map against template field metadata and the
 * RuleEngine output. Returns the list of errors plus a coerced selection
 * map ready for cart writes. Used server-side at add-to-cart.
 *
 * Required-field semantics follow FIELD_MODEL.md §11:
 *   effective_required = visible AND (is_required OR rule_required)
 */
final class ValidationEngine {

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function validate( array $input ): array {
		$template_fields    = $input['template']['fields'] ?? [];
		$rule_fields        = $input['rule_engine_output']['fields'] ?? [];
		$rule_blocked       = (bool) ( $input['rule_engine_output']['blocked'] ?? false );
		$rule_block_reason  = $input['rule_engine_output']['block_reason'] ?? null;
		$selections         = $input['selections'] ?? [];

		$errors  = [];
		$coerced = [];

		foreach ( $template_fields as $field_key => $meta ) {
			$raw_value     = array_key_exists( $field_key, $selections ) ? $selections[ $field_key ] : null;
			$coerced_value = $this->coerce_value( $raw_value, (array) $meta );
			if ( $coerced_value !== null ) {
				$coerced[ $field_key ] = $coerced_value;
			}

			$rule_state        = $rule_fields[ $field_key ] ?? [];
			$visible           = (bool) ( $rule_state['visible'] ?? true );
			$rule_required     = (bool) ( $rule_state['required'] ?? false );
			$template_required = (bool) ( $meta['is_required'] ?? false );

			$effective_required = $visible && ( $template_required || $rule_required );

			if ( $effective_required && $this->is_empty( $coerced_value ) ) {
				$errors[] = [
					'field_key' => $field_key,
					'code'      => 'required',
					'message'   => sprintf( 'Field "%s" is required.', $field_key ),
				];
				continue;
			}

			if ( ! $this->is_empty( $coerced_value ) && $this->is_invalid_type( $coerced_value, (array) $meta ) ) {
				$errors[] = [
					'field_key' => $field_key,
					'code'      => 'invalid_type',
					'message'   => sprintf(
						'Field "%s" has an invalid value for input_type=%s.',
						$field_key,
						(string) ( $meta['input_type'] ?? '' )
					),
				];
			}
		}

		// Block from rule engine propagates as a non-field error.
		if ( $rule_blocked ) {
			$errors[] = [
				'field_key' => null,
				'code'      => 'blocked',
				'message'   => is_string( $rule_block_reason ) ? $rule_block_reason : 'Add to cart blocked by rule.',
			];
		}

		return [
			'valid'              => count( $errors ) === 0,
			'errors'             => $errors,
			'coerced_selections' => $coerced,
		];
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function coerce_value( mixed $value, array $meta ): mixed {
		if ( $value === null || $value === '' ) {
			return null;
		}

		$input_type = (string) ( $meta['input_type'] ?? '' );

		switch ( $input_type ) {
			case 'number':
				if ( is_numeric( $value ) ) {
					$f = (float) $value;
					if ( $f === floor( $f ) && abs( $f ) < PHP_INT_MAX ) {
						return (int) $f;
					}
					return $f;
				}
				return null;

			case 'checkbox':
				if ( is_array( $value ) ) {
					return array_values( array_filter(
						$value,
						static fn( $v ): bool => $v !== null && $v !== ''
					) );
				}
				return [ (string) $value ];

			case 'radio':
			case 'dropdown':
			case 'text':
				if ( is_array( $value ) ) {
					return null;
				}
				return (string) $value;

			case 'hidden':
			default:
				return $value;
		}
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function is_invalid_type( mixed $value, array $meta ): bool {
		$input_type = (string) ( $meta['input_type'] ?? '' );
		switch ( $input_type ) {
			case 'number':
				return ! is_int( $value ) && ! is_float( $value );
			case 'checkbox':
				return ! is_array( $value );
			case 'radio':
			case 'dropdown':
			case 'text':
				return ! is_string( $value );
			default:
				return false;
		}
	}

	private function is_empty( mixed $v ): bool {
		if ( $v === null || $v === '' ) {
			return true;
		}
		if ( is_array( $v ) && count( $v ) === 0 ) {
			return true;
		}
		return false;
	}
}
