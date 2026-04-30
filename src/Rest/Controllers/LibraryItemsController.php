<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Engines\PricingEngine;
use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\LibraryItemService;

final class LibraryItemsController extends AbstractController {

	private const CAP = 'configkit_manage_libraries';

	public function __construct(
		private LibraryItemService $service,
		private ?PricingEngine $pricing_engine = null,
	) {}

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
			'/library-items',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'search_global' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'q'         => [ 'type' => 'string', 'default' => '' ],
						'page'      => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page'  => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
						'is_active' => [ 'type' => 'boolean' ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/library-items/preview-price',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'preview_price' ],
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

	public function search_global( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [];
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( $q !== '' ) $filters['q'] = $q;
		if ( $request->get_param( 'is_active' ) !== null ) {
			$filters['is_active'] = (bool) $request->get_param( 'is_active' );
		}
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		return $this->ok( $this->service->search_global( $filters, $page === 0 ? 1 : $page, $per_page === 0 ? 50 : $per_page ) );
	}

	/**
	 * Phase 4.2b.2 — preview the resolved price for an unsaved library
	 * item from the admin form. Stateless: nothing is persisted, the
	 * payload is a free-form library_item shape and the response is
	 * the resolved total + (when applicable) per-component bundle
	 * breakdown.
	 *
	 * Spec: UI_LABELS_MAPPING.md §9.1 (resolved-price preview),
	 * §9.2 (package breakdown). The PricingEngine is stateless and
	 * pure-PHP; this endpoint just adapts the form payload.
	 */
	public function preview_price( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->pricing_engine === null ) {
			return $this->error( 'preview_unavailable', 'Pricing preview engine is not wired in this environment.', [], 500 );
		}
		$payload = $this->payload( $request );
		$item    = is_array( $payload['library_item'] ?? null ) ? $payload['library_item'] : $payload;

		$item_type    = (string) ( $item['item_type'] ?? 'simple_option' );
		$price_source = (string) ( $item['price_source'] ?? 'configkit' );

		$resolved = $this->pricing_engine->resolveLibraryItemPrice( $item );

		$response = [
			'resolved_price' => $resolved,
			'price_source'   => $price_source,
			'item_type'      => $item_type,
		];

		if ( $item_type === 'bundle' ) {
			$response['breakdown'] = $this->pricing_engine->resolveBundleBreakdown( $item );
		}

		return $this->ok( $response );
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
