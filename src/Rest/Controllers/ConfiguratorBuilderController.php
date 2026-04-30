<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\ConfiguratorBuilderService;

/**
 * Phase 4.4 — REST surface for the Yith-style section builder.
 * Lives next to the Phase 4.3 ProductBuilderController so the JS
 * builder can reuse all the existing per-block save endpoints
 * (/pricing, /fabrics, /motors, ...) and just add section CRUD on
 * top.
 *
 * Routes:
 *   GET    /configurator/{productId}/sections
 *   POST   /configurator/{productId}/sections
 *   PUT    /configurator/{productId}/sections/{sectionId}
 *   DELETE /configurator/{productId}/sections/{sectionId}
 *   PUT    /configurator/{productId}/sections/order
 *   GET    /configurator/section-types
 *
 * Capability gate: configkit_manage_products (same as the rest of
 * the product builder surface).
 */
final class ConfiguratorBuilderController extends AbstractController {

	private const CAP = 'configkit_manage_products';

	public function __construct( private ConfiguratorBuilderService $service ) {}

	public function register_routes(): void {
		\register_rest_route( self::NAMESPACE, '/configurator/section-types', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_section_types' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/configurator/(?P<product_id>\d+)/sections', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_sections' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_section' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/configurator/(?P<product_id>\d+)/sections/order', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'reorder_sections' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/configurator/(?P<product_id>\d+)/sections/(?P<section_id>[\w_-]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_section' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_section' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/configurator/(?P<product_id>\d+)/sections/(?P<section_id>[\w_-]+)/ranges', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_ranges' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_ranges' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/configurator/(?P<product_id>\d+)/sections/(?P<section_id>[\w_-]+)/options', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_options' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_options' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/configurator/(?P<product_id>\d+)/diagnostics', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_diagnostics' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );
	}

	public function read_diagnostics( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( $this->service->analyse_product( $product_id ) );
	}

	public function list_section_types( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( [ 'types' => SectionTypeRegistry::all() ] );
	}

	public function list_sections( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( $this->service->list_sections( $product_id ) );
	}

	public function create_section( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$type       = (string) ( $body['type']  ?? '' );
		$label      = isset( $body['label'] ) ? (string) $body['label'] : null;
		$result     = $this->service->create_section( $product_id, $type, $label );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'configurator_failed', (string) ( $result['message'] ?? 'Could not create section.' ), [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( $result, 201 );
	}

	public function update_section( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$section_id = (string) $request['section_id'];
		$body       = $this->payload( $request );
		$result     = $this->service->update_section( $product_id, $section_id, $body );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'configurator_failed', (string) ( $result['message'] ?? 'Could not update section.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	public function delete_section( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$section_id = (string) $request['section_id'];
		$result     = $this->service->delete_section( $product_id, $section_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'configurator_failed', (string) ( $result['message'] ?? 'Could not delete section.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	public function read_ranges( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		$section_id = (string) $request['section_id'];
		return $this->ok( [
			'rows' => $this->service->read_range_rows( $product_id, $section_id ),
		] );
	}

	public function save_ranges( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$section_id = (string) $request['section_id'];
		$body       = $this->payload( $request );
		$rows       = is_array( $body['rows'] ?? null ) ? $body['rows'] : [];
		$result     = $this->service->save_range_rows( $product_id, $section_id, $rows );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'configurator_failed', (string) ( $result['message'] ?? 'Could not save ranges.' ), [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( $result );
	}

	public function read_options( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		$section_id = (string) $request['section_id'];
		return $this->ok( [
			'options' => $this->service->read_section_options( $product_id, $section_id ),
		] );
	}

	public function save_options( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$section_id = (string) $request['section_id'];
		$body       = $this->payload( $request );
		$options    = is_array( $body['options'] ?? null ) ? $body['options'] : [];
		$result     = $this->service->save_section_options( $product_id, $section_id, $options );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'configurator_failed', (string) ( $result['message'] ?? 'Could not save options.' ), [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( $result );
	}

	public function reorder_sections( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$ids        = is_array( $body['order'] ?? null ) ? $body['order'] : [];
		$result     = $this->service->reorder_sections( $product_id, $ids );
		return $this->ok( $result );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = $request->get_body_params();
		return is_array( $body ) ? $body : [];
	}
}
