<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Validation\KeyValidator;

/**
 * Validation + orchestration over `ModuleRepository`.
 *
 * Controllers call this. Repository stays I/O. Service has no \wpdb
 * references; it talks only to ModuleRepository.
 */
final class ModuleService {

	private const VALID_FIELD_KINDS = [ 'input', 'display', 'computed', 'addon', 'lookup' ];

	private AttributeSchemaService $schema_service;

	public function __construct( private ModuleRepository $repo, ?AttributeSchemaService $schema_service = null ) {
		$this->schema_service = $schema_service ?? new AttributeSchemaService();
	}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list( int $page = 1, int $per_page = 50 ): array {
		return $this->repo->list( $page, $per_page );
	}

	public function get( int $id ): ?array {
		return $this->repo->find_by_id( $id );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function create( array $input ): array {
		$errors = $this->validate( $input, null );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input );
		$id        = $this->repo->create( $sanitized );
		$record    = $this->repo->find_by_id( $id );
		return [ 'ok' => true, 'id' => $id, 'record' => $record ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $id, array $input, string $expected_version_hash ): array {
		$existing = $this->repo->find_by_id( $id );
		if ( $existing === null ) {
			return [
				'ok'     => false,
				'errors' => [ [ 'code' => 'not_found', 'message' => 'Module not found.' ] ],
			];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [
				'ok'     => false,
				'errors' => [ [ 'code' => 'conflict', 'message' => 'Module was edited elsewhere. Reload and try again.' ] ],
			];
		}
		$errors = $this->validate( $input, $existing );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input );
		$this->repo->update( $id, $sanitized );
		$record = $this->repo->find_by_id( $id );
		return [ 'ok' => true, 'record' => $record ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function soft_delete( int $id ): array {
		$existing = $this->repo->find_by_id( $id );
		if ( $existing === null ) {
			return [
				'ok'     => false,
				'errors' => [ [ 'code' => 'not_found', 'message' => 'Module not found.' ] ],
			];
		}
		$this->repo->soft_delete( $id );
		return [ 'ok' => true ];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing  null on create, the existing record on update
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing ): array {
		$errors = [];

		$key        = isset( $input['module_key'] ) ? (string) $input['module_key'] : '';
		$key_errors = KeyValidator::validate( 'module_key', $key );
		if ( count( $key_errors ) > 0 ) {
			$errors = array_merge( $errors, $key_errors );
		} else {
			$exclude_id = isset( $existing['id'] ) ? (int) $existing['id'] : null;
			if ( $this->repo->key_exists( $key, $exclude_id ) ) {
				$errors[] = [
					'field'   => 'module_key',
					'code'    => 'duplicate',
					'message' => 'A module with this key already exists.',
				];
			}
		}

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		if ( $name === '' ) {
			$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => 'name is required.' ];
		} elseif ( strlen( $name ) > 255 ) {
			$errors[] = [
				'field'   => 'name',
				'code'    => 'too_long',
				'message' => 'name must be 255 characters or fewer.',
			];
		}

		$kinds = $input['allowed_field_kinds'] ?? [];
		if ( ! is_array( $kinds ) ) {
			$errors[] = [
				'field'   => 'allowed_field_kinds',
				'code'    => 'invalid_type',
				'message' => 'allowed_field_kinds must be an array.',
			];
		} else {
			foreach ( $kinds as $k ) {
				if ( ! is_string( $k ) || ! in_array( $k, self::VALID_FIELD_KINDS, true ) ) {
					$errors[] = [
						'field'   => 'allowed_field_kinds',
						'code'    => 'invalid_value',
						'message' => sprintf( 'Unknown field_kind: %s', is_string( $k ) ? $k : '(non-string)' ),
					];
				}
			}
		}

		// Phase 4.2c — schema accepts both the legacy `{key:type-string}`
		// shape and the new rich shape `{key:{label,type,options,
		// required,sort_order}}` via AttributeSchemaService.
		$schema = $this->parse_schema( $input['attribute_schema'] ?? null );
		if ( $schema === null ) {
			$errors[] = [
				'field'   => 'attribute_schema',
				'code'    => 'invalid_json',
				'message' => 'attribute_schema must be a JSON object or null.',
			];
		} else {
			$errors = array_merge( $errors, $this->schema_service->validate( $schema ) );
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		$out = [
			'module_key'          => (string) ( $input['module_key'] ?? '' ),
			'name'                => trim( (string) ( $input['name'] ?? '' ) ),
			'description'         => isset( $input['description'] ) && $input['description'] !== ''
				? (string) $input['description']
				: null,
			'allowed_field_kinds' => array_values( array_filter(
				(array) ( $input['allowed_field_kinds'] ?? [] ),
				static fn( $k ): bool => is_string( $k ) && in_array( $k, self::VALID_FIELD_KINDS, true )
			) ),
			'attribute_schema'    => $this->schema_service->sanitize( $this->parse_schema( $input['attribute_schema'] ?? null ) ?? [] ),
			'is_active'           => array_key_exists( 'is_active', $input ) ? (bool) $input['is_active'] : true,
			'sort_order'          => (int) ( $input['sort_order'] ?? 0 ),
		];

		foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		return $out;
	}

	/**
	 * Returns:
	 *  - array on success (possibly empty)
	 *  - null on parse failure (caller treats as validation error)
	 *
	 * @param mixed $value
	 * @return array<string,string>|null
	 */
	private function parse_schema( mixed $value ): ?array {
		if ( $value === null || $value === '' ) {
			return [];
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && is_string( $v ) ) {
					$out[ $k ] = $v;
				} else {
					return null;
				}
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			$out = [];
			foreach ( $decoded as $k => $v ) {
				if ( is_string( $k ) && is_string( $v ) ) {
					$out[ $k ] = $v;
				} else {
					return null;
				}
			}
			return $out;
		}
		return null;
	}
}
