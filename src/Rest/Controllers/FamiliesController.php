<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\FamilyService;

final class FamiliesController extends AbstractController {

	private const CAP = 'configkit_manage_families';

	public function __construct( private FamilyService $service ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/families',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'page'      => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page'  => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ],
						'is_active' => [ 'type' => 'boolean' ],
					],
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
			'/families/(?P<id>\d+)',
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

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [];
		if ( $request->get_param( 'is_active' ) !== null ) {
			$filters['is_active'] = (bool) $request->get_param( 'is_active' );
		}
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		return $this->ok( $this->service->list( $filters, $page === 0 ? 1 : $page, $per_page === 0 ? 100 : $per_page ) );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = $this->service->get( (int) $request['id'] );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Family not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->create( $this->payload( $request ) );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'validation_failed', 'Family could not be created.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ], 201 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request['id'];
		$payload = $this->payload( $request );
		$expected = isset( $payload['version_hash'] ) ? (string) $payload['version_hash'] : '';
		if ( $expected === '' ) {
			return $this->error( 'missing_version_hash', 'version_hash is required for updates.', [], 400 );
		}
		$result = $this->service->update( $id, $payload, $expected );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'conflict' ) {
				return $this->error( 'conflict', 'Stale version_hash.', [ 'errors' => $result['errors'] ], 409 );
			}
			if ( $first === 'not_found' ) {
				return $this->error( 'not_found', 'Family not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Family could not be updated.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ] );
	}

	public function soft_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->soft_delete( (int) $request['id'] );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'not_found' ) {
				return $this->error( 'not_found', 'Family not found.', [], 404 );
			}
			return $this->error( 'delete_failed', 'Family could not be deleted.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'deleted' => true, 'id' => (int) $request['id'] ] );
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
