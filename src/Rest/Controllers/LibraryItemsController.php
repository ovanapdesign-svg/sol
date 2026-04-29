<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\LibraryItemService;

final class LibraryItemsController extends AbstractController {

	private const CAP = 'configkit_manage_libraries';

	public function __construct( private LibraryItemService $service ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/libraries/(?P<id>\d+)/items',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'id'       => [ 'type' => 'integer', 'required' => true ],
						'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page' => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ],
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
			'/libraries/(?P<id>\d+)/items/(?P<item_id>\d+)',
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
		$library_id = (int) $request['id'];
		$page       = (int) $request->get_param( 'page' );
		$per_page   = (int) $request->get_param( 'per_page' );
		$result     = $this->service->list_for_library( $library_id, $page === 0 ? 1 : $page, $per_page === 0 ? 100 : $per_page );
		if ( $result === null ) {
			return $this->error( 'not_found', 'Library not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = $this->service->get( (int) $request['id'], (int) $request['item_id'] );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Library item not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->create( (int) $request['id'], $this->payload( $request ) );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'library_not_found' ) {
				return $this->error( 'not_found', 'Library not found.', [], 404 );
			}
			return $this->error(
				'validation_failed',
				'Library item could not be created.',
				[ 'errors' => $result['errors'] ?? [] ],
				400
			);
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ], 201 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload  = $this->payload( $request );
		$expected = isset( $payload['version_hash'] ) ? (string) $payload['version_hash'] : '';
		if ( $expected === '' ) {
			return $this->error( 'missing_version_hash', 'version_hash is required for updates.', [], 400 );
		}
		$result = $this->service->update( (int) $request['id'], (int) $request['item_id'], $payload, $expected );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'conflict' ) {
				return $this->error( 'conflict', 'Stale version_hash.', [ 'errors' => $result['errors'] ], 409 );
			}
			if ( in_array( $first, [ 'not_found', 'library_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Library item could not be updated.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ] );
	}

	public function soft_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->soft_delete( (int) $request['id'], (int) $request['item_id'] );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( in_array( $first, [ 'not_found', 'library_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'delete_failed', 'Library item could not be deleted.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'deleted' => true, 'id' => (int) $request['item_id'] ] );
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
