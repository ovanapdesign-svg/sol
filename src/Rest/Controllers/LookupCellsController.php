<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\LookupCellService;

final class LookupCellsController extends AbstractController {

	private const CAP = 'configkit_manage_lookup_tables';

	public function __construct( private LookupCellService $service ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/lookup-tables/(?P<id>\d+)/cells',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'id'              => [ 'type' => 'integer', 'required' => true ],
						'page'            => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page'        => [ 'type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 1000 ],
						'price_group_key' => [ 'type' => 'string' ],
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
			'/lookup-tables/(?P<id>\d+)/cells/bulk',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'bulk_upsert' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/lookup-tables/(?P<id>\d+)/cells/(?P<cell_id>\d+)',
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
		$id      = (int) $request['id'];
		$filters = [];
		if ( $request->get_param( 'price_group_key' ) !== null ) {
			$filters['price_group_key'] = (string) $request->get_param( 'price_group_key' );
		}
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $this->service->list_for_table( $id, $filters, $page === 0 ? 1 : $page, $per_page === 0 ? 200 : $per_page );
		if ( $result === null ) {
			return $this->error( 'not_found', 'Lookup table not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = $this->service->get( (int) $request['id'], (int) $request['cell_id'] );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Lookup cell not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->create( (int) $request['id'], $this->payload( $request ) );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'table_not_found' ) {
				return $this->error( 'not_found', 'Lookup table not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Cell could not be created.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ], 201 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->update( (int) $request['id'], (int) $request['cell_id'], $this->payload( $request ) );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( in_array( $first, [ 'not_found', 'table_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Cell could not be updated.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ] );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->delete( (int) $request['id'], (int) $request['cell_id'] );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( in_array( $first, [ 'not_found', 'table_not_found' ], true ) ) {
				return $this->error( 'not_found', $result['errors'][0]['message'] ?? 'Not found.', [], 404 );
			}
			return $this->error( 'delete_failed', 'Cell could not be deleted.', [], 400 );
		}
		return $this->ok( [ 'deleted' => true, 'id' => (int) $request['cell_id'] ] );
	}

	public function bulk_upsert( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->payload( $request );
		$cells   = $payload['cells'] ?? [];
		if ( ! is_array( $cells ) ) {
			return $this->error( 'invalid_payload', 'Body must include a "cells" array.', [], 400 );
		}
		$result = $this->service->bulk_upsert( (int) $request['id'], array_values( $cells ) );
		$first  = $result['errors'][0]['code'] ?? '';
		if ( $first === 'table_not_found' ) {
			return $this->error( 'not_found', 'Lookup table not found.', [], 404 );
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
