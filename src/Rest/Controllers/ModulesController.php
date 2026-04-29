<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\ModuleService;

/**
 * REST controller for /configkit/v1/modules.
 *
 * Permission_callback uses current_user_can() — REST nonces are checked
 * automatically by WP for cookie-authed admin requests via the
 * X-WP-Nonce header.
 */
final class ModulesController extends AbstractController {

	private const CAP = 'configkit_manage_modules';

	public function __construct( private ModuleService $service ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/modules',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
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
			'/modules/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'soft_delete' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
				],
			]
		);
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $this->service->list( $page === 0 ? 1 : $page, $per_page === 0 ? 50 : $per_page );
		return $this->ok( $result );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request['id'];
		$record = $this->service->get( $id );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Module not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->payload( $request );
		$result  = $this->service->create( $payload );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error(
				'validation_failed',
				'Module could not be created.',
				[ 'errors' => $result['errors'] ?? [] ],
				400
			);
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
			$errors = $result['errors'] ?? [];
			$first  = $errors[0]['code'] ?? '';
			if ( $first === 'conflict' ) {
				return $this->error( 'conflict', 'Stale version_hash.', [ 'errors' => $errors ], 409 );
			}
			if ( $first === 'not_found' ) {
				return $this->error( 'not_found', 'Module not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Module could not be updated.', [ 'errors' => $errors ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ] );
	}

	public function soft_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request['id'];
		$result = $this->service->soft_delete( $id );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'not_found' ) {
				return $this->error( 'not_found', 'Module not found.', [], 404 );
			}
			return $this->error( 'delete_failed', 'Module could not be deleted.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'deleted' => true, 'id' => $id ] );
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
