<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Validation\KeyValidator;

final class TemplateService {

	public const VALID_STATUSES = [ 'draft', 'published', 'archived' ];
	private const NAME_MIN     = 2;
	private const NAME_MAX     = 200;

	public function __construct( private TemplateRepository $repo ) {}

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
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Template not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Template was edited elsewhere. Reload and try again.' ] ] ];
		}
		$errors = $this->validate( $input, $existing );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input );
		// template_key is immutable once created.
		$sanitized['template_key'] = (string) $existing['template_key'];
		$this->repo->update( $id, $sanitized );
		return [ 'ok' => true, 'record' => $this->repo->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function soft_delete( int $id ): array {
		$existing = $this->repo->find_by_id( $id );
		if ( $existing === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Template not found.' ] ] ];
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

		// template_key is immutable on update; only validate format/uniqueness on create.
		if ( $existing === null ) {
			$key        = isset( $input['template_key'] ) ? (string) $input['template_key'] : '';
			$key_errors = KeyValidator::validate( 'template_key', $key );
			if ( count( $key_errors ) > 0 ) {
				$errors = array_merge( $errors, $key_errors );
			} elseif ( $this->repo->key_exists( $key ) ) {
				$errors[] = [
					'field'   => 'template_key',
					'code'    => 'duplicate',
					'message' => 'A template with this key already exists.',
				];
			}
		}

		$name        = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		$name_length = strlen( $name );
		if ( $name === '' ) {
			$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => 'name is required.' ];
		} elseif ( $name_length < self::NAME_MIN ) {
			$errors[] = [
				'field'   => 'name',
				'code'    => 'too_short',
				'message' => sprintf( 'name must be at least %d characters.', self::NAME_MIN ),
			];
		} elseif ( $name_length > self::NAME_MAX ) {
			$errors[] = [
				'field'   => 'name',
				'code'    => 'too_long',
				'message' => sprintf( 'name must be at most %d characters.', self::NAME_MAX ),
			];
		}

		if ( array_key_exists( 'status', $input ) ) {
			$status = (string) $input['status'];
			if ( $status !== '' && ! in_array( $status, self::VALID_STATUSES, true ) ) {
				$errors[] = [
					'field'   => 'status',
					'code'    => 'invalid_value',
					'message' => 'status must be one of: draft, published, archived.',
				];
			}
		}

		// family_key is optional — but if provided, must pass the key format rules.
		if ( array_key_exists( 'family_key', $input ) ) {
			$family_key = (string) $input['family_key'];
			if ( $family_key !== '' ) {
				$fk_errors = KeyValidator::validate( 'family_key', $family_key );
				$errors    = array_merge( $errors, $fk_errors );
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		$status = (string) ( $input['status'] ?? 'draft' );
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			$status = 'draft';
		}
		return [
			'template_key' => (string) ( $input['template_key'] ?? '' ),
			'name'         => trim( (string) ( $input['name'] ?? '' ) ),
			'family_key'   => isset( $input['family_key'] ) && $input['family_key'] !== '' ? (string) $input['family_key'] : null,
			'description'  => isset( $input['description'] ) && $input['description'] !== '' ? (string) $input['description'] : null,
			'status'       => $status,
		];
	}
}
