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

	/**
	 * @return array<string,mixed>
	 */
	private function payload( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = $request->get_body_params();
		return is_array( $body ) ? $body : [];
	}
}
