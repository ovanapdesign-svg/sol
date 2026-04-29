<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\FieldOptionService;

final class FieldOptionsController extends AbstractController {

	private const CAP = 'configkit_manage_templates';

	public function __construct( private FieldOptionService $service ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/fields/(?P<field_id>\d+)/options',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/fields/(?P<field_id>\d+)/options/(?P<option_id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'soft_delete' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->list_for_field( (int) $request['field_id'] );
		if ( $result === null ) {
			return $this->error( 'not_found', 'Field not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = $this->service->get( (int) $request['field_id'], (int) $request['option_id'] );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Field option not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->create( (int) $request['field_id'], $this->payload( $request ) );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'field_not_found' ) {
				return $this->error( 'not_found', 'Field not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Option could not be created.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ], 201 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload  = $this->payload( $request );
		$expected = isset( $payload['version_hash'] ) ? (string) $payload['version_hash'] : '';
		if ( $expected === '' ) {
			return $this->error( 'missing_version_hash', 'version_hash is required for updates.', [], 400 );
		}
		$result = $this->service->update( (int) $request['field_id'], (int) $request['option_id'], $payload, $expected );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'conflict' ) {
				return $this->error( 'conflict', 'Stale version_hash.', [ 'errors' => $result['errors'] ], 409 );
			}
			if ( in_array( $first, [ 'not_found', 'field_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Option could not be updated.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ] );
	}

	public function soft_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->soft_delete( (int) $request['field_id'], (int) $request['option_id'] );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( in_array( $first, [ 'not_found', 'field_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'delete_failed', 'Option could not be deleted.', [], 400 );
		}
		return $this->ok( [ 'deleted' => true, 'id' => (int) $request['option_id'] ] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		return is_array( $body ) ? $body : [];
	}
}
