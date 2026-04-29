<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Validation\KeyValidator;

final class FieldOptionService {

	private const LABEL_MIN = 1;
	private const LABEL_MAX = 200;

	public function __construct(
		private FieldOptionRepository $options,
		private FieldRepository $fields,
	) {}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}|null
	 */
	public function list_for_field( int $field_id ): ?array {
		$field = $this->fields->find_by_id( $field_id );
		if ( $field === null ) {
			return null;
		}
		return $this->options->list_for_field( (string) $field['template_key'], (string) $field['field_key'] );
	}

	public function get( int $field_id, int $option_id ): ?array {
		$field = $this->fields->find_by_id( $field_id );
		if ( $field === null ) {
			return null;
		}
		$option = $this->options->find_by_id( $option_id );
		if ( $option === null
			|| $option['template_key'] !== $field['template_key']
			|| $option['field_key'] !== $field['field_key']
		) {
			return null;
		}
		return $option;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function create( int $field_id, array $input ): array {
		$field = $this->fields->find_by_id( $field_id );
		if ( $field === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'field_not_found', 'message' => 'Parent field not found.' ] ] ];
		}
		if ( (string) $field['value_source'] !== 'manual_options' ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'wrong_value_source', 'message' => 'Manual options can only be added to fields with value_source=manual_options.' ] ] ];
		}
		$errors = $this->validate( $input, null, $field );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized                 = $this->sanitize( $input );
		$sanitized['template_key'] = (string) $field['template_key'];
		$sanitized['field_key']    = (string) $field['field_key'];
		if ( ! array_key_exists( 'sort_order', $input ) || $input['sort_order'] === '' || $input['sort_order'] === null ) {
			$sanitized['sort_order'] = $this->options->max_sort_order( (string) $field['template_key'], (string) $field['field_key'] ) + 1;
		}

		$id = $this->options->create( $sanitized );
		return [ 'ok' => true, 'id' => $id, 'record' => $this->options->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $field_id, int $option_id, array $input, string $expected_version_hash ): array {
		$field = $this->fields->find_by_id( $field_id );
		if ( $field === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'field_not_found', 'message' => 'Parent field not found.' ] ] ];
		}
		$existing = $this->options->find_by_id( $option_id );
		if (
			$existing === null
			|| $existing['template_key'] !== $field['template_key']
			|| $existing['field_key'] !== $field['field_key']
		) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Field option not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Field option was edited elsewhere. Reload and try again.' ] ] ];
		}
		$errors = $this->validate( $input, $existing, $field );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized                 = $this->sanitize( $input );
		$sanitized['template_key'] = (string) $existing['template_key'];
		$sanitized['field_key']    = (string) $existing['field_key'];
		// option_key is immutable.
		$sanitized['option_key'] = (string) $existing['option_key'];
		if ( ! array_key_exists( 'sort_order', $input ) || $input['sort_order'] === '' || $input['sort_order'] === null ) {
			$sanitized['sort_order'] = (int) $existing['sort_order'];
		}
		$this->options->update( $option_id, $sanitized );
		return [ 'ok' => true, 'record' => $this->options->find_by_id( $option_id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function soft_delete( int $field_id, int $option_id ): array {
		$field = $this->fields->find_by_id( $field_id );
		if ( $field === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'field_not_found', 'message' => 'Parent field not found.' ] ] ];
		}
		$existing = $this->options->find_by_id( $option_id );
		if (
			$existing === null
			|| $existing['template_key'] !== $field['template_key']
			|| $existing['field_key'] !== $field['field_key']
		) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Field option not found.' ] ] ];
		}
		$this->options->soft_delete( $option_id );
		return [ 'ok' => true ];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @param array<string,mixed>      $field
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing, array $field ): array {
		$errors = [];

		if ( $existing === null ) {
			$option_key = isset( $input['option_key'] ) ? (string) $input['option_key'] : '';
			$key_errors = KeyValidator::validate( 'option_key', $option_key );
			if ( count( $key_errors ) > 0 ) {
				$errors = array_merge( $errors, $key_errors );
			} elseif ( $this->options->key_exists_in_field(
				(string) $field['template_key'],
				(string) $field['field_key'],
				$option_key
			) ) {
				$errors[] = [
					'field'   => 'option_key',
					'code'    => 'duplicate',
					'message' => 'An option with this key already exists in this field.',
				];
			}
		}

		$label        = isset( $input['label'] ) ? trim( (string) $input['label'] ) : '';
		$label_length = strlen( $label );
		if ( $label === '' ) {
			$errors[] = [ 'field' => 'label', 'code' => 'required', 'message' => 'label is required.' ];
		} elseif ( $label_length > self::LABEL_MAX ) {
			$errors[] = [ 'field' => 'label', 'code' => 'too_long', 'message' => sprintf( 'label must be at most %d characters.', self::LABEL_MAX ) ];
		}

		foreach ( [ 'price', 'sale_price' ] as $numeric ) {
			if ( ! array_key_exists( $numeric, $input ) || $input[ $numeric ] === '' || $input[ $numeric ] === null ) {
				continue;
			}
			if ( ! is_numeric( $input[ $numeric ] ) ) {
				$errors[] = [ 'field' => $numeric, 'code' => 'invalid_type', 'message' => sprintf( '%s must be numeric.', $numeric ) ];
			} elseif ( (float) $input[ $numeric ] < 0 ) {
				$errors[] = [ 'field' => $numeric, 'code' => 'negative_price', 'message' => sprintf( '%s must be ≥ 0.', $numeric ) ];
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		return [
			'option_key'  => (string) ( $input['option_key'] ?? '' ),
			'label'       => trim( (string) ( $input['label'] ?? '' ) ),
			'price'       => isset( $input['price'] ) && $input['price'] !== '' && $input['price'] !== null ? (float) $input['price'] : null,
			'sale_price'  => isset( $input['sale_price'] ) && $input['sale_price'] !== '' && $input['sale_price'] !== null ? (float) $input['sale_price'] : null,
			'image_url'   => isset( $input['image_url'] ) && $input['image_url'] !== '' ? (string) $input['image_url'] : null,
			'description' => isset( $input['description'] ) && $input['description'] !== '' ? (string) $input['description'] : null,
			'is_active'   => array_key_exists( 'is_active', $input ) ? (bool) $input['is_active'] : true,
			'sort_order'  => isset( $input['sort_order'] ) && $input['sort_order'] !== '' ? (int) $input['sort_order'] : 0,
		];
	}
}
