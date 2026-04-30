<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Admin\ProductTypeRecipes;
use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\ProductBuilderService;

/**
 * Phase 4.3 — Product Builder REST surface (Simple Mode).
 *
 * Owner-facing endpoints under `configkit/v1/product-builder/*`. The
 * Woo product ConfigKit tab posts to these and never has to know
 * which module / library / template the orchestrator is touching.
 *
 * Capability gate: `configkit_manage_products` — the same one that
 * gates the standard product-binding flow. If the owner already had
 * permission to bind a product, they can use Simple Mode.
 */
final class ProductBuilderController extends AbstractController {

	private const CAP = 'configkit_manage_products';

	public function __construct( private ProductBuilderService $service ) {}

	public function register_routes(): void {
		\register_rest_route( self::NAMESPACE, '/product-builder/recipes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_recipes' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/state', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_state' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/product-type', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_product_type' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/pricing', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_pricing' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_pricing' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		// One block-saver endpoint per role. Each follows the same
		// shape: GET returns the saved items, POST replaces them.
		foreach ( [
			'fabrics'        => [ 'read_fabrics',        'save_fabrics' ],
			'profile-colors' => [ 'read_profile_colors', 'save_profile_colors' ],
			'stangs'         => [ 'read_stangs',         'save_stangs' ],
			'motors'         => [ 'read_motors',         'save_motors' ],
			'controls'       => [ 'read_controls',       'save_controls' ],
			'accessories'    => [ 'read_accessories',    'save_accessories' ],
		] as $segment => [ $get_cb, $post_cb ] ) {
			\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/' . $segment, [
				[
					'methods'             => 'GET',
					'callback'            => [ $this, $get_cb ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $post_cb ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			] );
		}

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/operation-mode', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_operation_mode' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );
	}

	public function list_recipes( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( [ 'recipes' => ProductTypeRecipes::all() ] );
	}

	public function read_state( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( [
			'product_id' => $product_id,
			'state'      => $this->service->get_state( $product_id ),
		] );
	}

	public function set_product_type( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id   = (int) $request['product_id'];
		$body         = $this->payload( $request );
		$product_type = (string) ( $body['product_type'] ?? '' );

		$result = $this->service->set_product_type( $product_id, $product_type );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error(
				'product_builder_failed',
				(string) ( $result['message'] ?? 'Could not set product type.' ),
				[ 'errors' => $result['errors'] ?? [] ],
				400
			);
		}
		return $this->ok( $result );
	}

	public function read_pricing( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( [
			'product_id' => $product_id,
			'rows'       => $this->service->read_pricing_rows( $product_id ),
		] );
	}

	public function read_fabrics( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_block( $request, 'read_fabrics' );
	}
	public function save_fabrics( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->save_block( $request, 'fabrics', 'save_fabrics' );
	}
	public function read_profile_colors( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_block( $request, 'read_profile_colors' );
	}
	public function save_profile_colors( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->save_block( $request, 'colors', 'save_profile_colors' );
	}
	public function read_stangs( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_block( $request, 'read_stangs' );
	}
	public function save_stangs( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->save_block( $request, 'stangs', 'save_stangs' );
	}
	public function read_motors( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_block( $request, 'read_motors' );
	}
	public function save_motors( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->save_block( $request, 'motors', 'save_motors' );
	}
	public function read_controls( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_block( $request, 'read_controls' );
	}
	public function save_controls( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->save_block( $request, 'controls', 'save_controls' );
	}
	public function read_accessories( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_block( $request, 'read_accessories' );
	}
	public function save_accessories( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->save_block( $request, 'accessories', 'save_accessories' );
	}

	public function save_operation_mode( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$mode       = (string) ( $body['mode'] ?? '' );
		$result     = $this->service->save_operation_mode( $product_id, $mode );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'product_builder_failed', (string) ( $result['message'] ?? 'Could not save operation mode.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	private function read_block( \WP_REST_Request $request, string $method ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( [
			'product_id' => $product_id,
			'items'      => $this->service->{$method}( $product_id ),
		] );
	}

	private function save_block( \WP_REST_Request $request, string $body_key, string $method ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$items      = is_array( $body[ $body_key ] ?? null ) ? $body[ $body_key ] : [];

		$result = $this->service->{$method}( $product_id, $items );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error(
				'product_builder_failed',
				(string) ( $result['message'] ?? 'Could not save ' . $body_key . '.' ),
				[ 'errors' => $result['errors'] ?? [] ],
				400
			);
		}
		return $this->ok( $result );
	}

	public function save_pricing( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$rows       = is_array( $body['rows'] ?? null ) ? $body['rows'] : [];

		$result = $this->service->save_pricing_rows( $product_id, $rows );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error(
				'product_builder_failed',
				(string) ( $result['message'] ?? 'Could not save pricing rows.' ),
				[ 'errors' => $result['errors'] ?? [] ],
				400
			);
		}
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
