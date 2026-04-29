<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\ModuleRepository;

final class LibraryService {

	private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	public function __construct(
		private LibraryRepository $repo,
		private ModuleRepository $modules,
	) {}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list( array $filters = [], int $page = 1, int $per_page = 100 ): array {
		return $this->repo->list( $filters, $page, $per_page );
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
		return [ 'ok' => true, 'id' => $id, 'record' => $this->repo->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $id, array $input, string $expected_version_hash ): array {
		$existing = $this->repo->find_by_id( $id );
		if ( $existing === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Library not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Library was edited elsewhere. Reload and try again.' ] ] ];
		}
		$errors = $this->validate( $input, $existing );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input );
		// Preserve module_key on update — module cannot be moved across libraries.
		$sanitized['module_key'] = $existing['module_key'];
		$this->repo->update( $id, $sanitized );
		return [ 'ok' => true, 'record' => $this->repo->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function soft_delete( int $id ): array {
		$existing = $this->repo->find_by_id( $id );
		if ( $existing === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Library not found.' ] ] ];
		}
		$this->repo->soft_delete( $id );
		return [ 'ok' => true ];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing ): array {
		$errors = [];

		$key = isset( $input['library_key'] ) ? (string) $input['library_key'] : '';
		if ( $key === '' ) {
			$errors[] = [ 'field' => 'library_key', 'code' => 'required', 'message' => 'library_key is required.' ];
		} elseif ( ! preg_match( self::KEY_PATTERN, $key ) ) {
			$errors[] = [
				'field'   => 'library_key',
				'code'    => 'invalid_format',
				'message' => 'library_key must be lowercase ascii starting with a letter, max 64 chars.',
			];
		} else {
			$exclude_id = isset( $existing['id'] ) ? (int) $existing['id'] : null;
			if ( $this->repo->key_exists( $key, $exclude_id ) ) {
				$errors[] = [
					'field'   => 'library_key',
					'code'    => 'duplicate',
					'message' => 'A library with this key already exists.',
				];
			}
		}

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		if ( $name === '' ) {
			$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => 'name is required.' ];
		} elseif ( strlen( $name ) > 255 ) {
			$errors[] = [ 'field' => 'name', 'code' => 'too_long', 'message' => 'name must be 255 characters or fewer.' ];
		}

		// module_key — required on create, immutable on update (we just verify it exists).
		$module_key = $existing !== null
			? (string) ( $existing['module_key'] ?? '' )
			: (string) ( $input['module_key'] ?? '' );

		if ( $module_key === '' ) {
			$errors[] = [ 'field' => 'module_key', 'code' => 'required', 'message' => 'module_key is required.' ];
		} else {
			$module = $this->modules->find_by_key( $module_key );
			if ( $module === null ) {
				$errors[] = [
					'field'   => 'module_key',
					'code'    => 'unknown_module',
					'message' => 'No module with this key exists.',
				];
			} elseif ( ! ( $module['is_active'] ?? false ) ) {
				$errors[] = [
					'field'   => 'module_key',
					'code'    => 'inactive_module',
					'message' => 'The referenced module is not active.',
				];
			} else {
				if ( ! empty( $input['brand'] ) && ! ( $module['supports_brand'] ?? false ) ) {
					$errors[] = [
						'field'   => 'brand',
						'code'    => 'unsupported_capability',
						'message' => sprintf( 'Module %s does not support brand.', $module_key ),
					];
				}
				if ( ! empty( $input['collection'] ) && ! ( $module['supports_collection'] ?? false ) ) {
					$errors[] = [
						'field'   => 'collection',
						'code'    => 'unsupported_capability',
						'message' => sprintf( 'Module %s does not support collection.', $module_key ),
					];
				}
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
			'library_key' => (string) ( $input['library_key'] ?? '' ),
			'module_key'  => (string) ( $input['module_key'] ?? '' ),
			'name'        => trim( (string) ( $input['name'] ?? '' ) ),
			'description' => isset( $input['description'] ) && $input['description'] !== '' ? (string) $input['description'] : null,
			'brand'       => isset( $input['brand'] ) && $input['brand'] !== '' ? (string) $input['brand'] : null,
			'collection'  => isset( $input['collection'] ) && $input['collection'] !== '' ? (string) $input['collection'] : null,
			'is_active'   => array_key_exists( 'is_active', $input ) ? (bool) $input['is_active'] : true,
			'sort_order'  => (int) ( $input['sort_order'] ?? 0 ),
		];
	}
}
