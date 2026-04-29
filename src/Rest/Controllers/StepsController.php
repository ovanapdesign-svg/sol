<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\StepService;

final class StepsController extends AbstractController {

	private const CAP = 'configkit_manage_templates';

	public function __construct( private StepService $service ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)/steps',
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
			'/templates/(?P<id>\d+)/steps/reorder',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'reorder' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)/steps/(?P<step_id>\d+)',
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
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->list_for_template( (int) $request['id'] );
		if ( $result === null ) {
			return $this->error( 'not_found', 'Template not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = $this->service->get( (int) $request['id'], (int) $request['step_id'] );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Step not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->create( (int) $request['id'], $this->payload( $request ) );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'template_not_found' ) {
				return $this->error( 'not_found', 'Template not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Step could not be created.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ], 201 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload  = $this->payload( $request );
		$expected = isset( $payload['version_hash'] ) ? (string) $payload['version_hash'] : '';
		if ( $expected === '' ) {
			return $this->error( 'missing_version_hash', 'version_hash is required for updates.', [], 400 );
		}
		$result = $this->service->update( (int) $request['id'], (int) $request['step_id'], $payload, $expected );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'conflict' ) {
				return $this->error( 'conflict', 'Stale version_hash.', [ 'errors' => $result['errors'] ], 409 );
			}
			if ( in_array( $first, [ 'not_found', 'template_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Step could not be updated.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ] );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->delete( (int) $request['id'], (int) $request['step_id'] );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( in_array( $first, [ 'not_found', 'template_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'delete_failed', 'Step could not be deleted.', [], 400 );
		}
		return $this->ok( [ 'deleted' => true, 'id' => (int) $request['step_id'] ] );
	}

	public function reorder( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->payload( $request );
		$items   = $payload['items'] ?? [];
		if ( ! is_array( $items ) ) {
			return $this->error( 'invalid_payload', 'Body must include an "items" array.', [], 400 );
		}
		$result = $this->service->reorder( (int) $request['id'], array_values( $items ) );
		$first  = $result['errors'][0]['code'] ?? '';
		if ( $first === 'template_not_found' ) {
			return $this->error( 'not_found', 'Template not found.', [], 404 );
		}
		return $this->ok( $result );
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
