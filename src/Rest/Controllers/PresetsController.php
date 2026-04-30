<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\PresetService;

/**
 * Phase 4.3b half A — REST surface for the preset entity.
 *
 * Routes:
 *   GET  /presets                                       — list presets
 *   GET  /presets/{preset_id}                           — fetch one
 *   POST /product-builder/{product_id}/save-as-preset   — snapshot
 *   POST /product-builder/{product_id}/apply-preset     — apply
 *
 * Capability gate: configkit_manage_products (matches the rest of
 * the product builder surface). Half B will add copy / link / detach
 * / reset-override under the same gate.
 */
final class PresetsController extends AbstractController {

	private const CAP = 'configkit_manage_products';

	public function __construct( private PresetService $service ) {}

	public function register_routes(): void {
		\register_rest_route( self::NAMESPACE, '/presets', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_presets' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/presets/(?P<preset_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_preset' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/save-as-preset', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_as_preset' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/apply-preset', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'apply_preset' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/presets/(?P<preset_id>\d+)/products-using', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'products_using' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );
	}

	public function products_using( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['preset_id'];
		if ( $this->service->get_preset( $id ) === null ) {
			return $this->error( 'preset_not_found', 'Preset not found.', [], 404 );
		}
		return $this->ok( [ 'products' => $this->service->products_using( $id ) ] );
	}

	public function list_presets( \WP_REST_Request $request ): \WP_REST_Response {
		$page         = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$per_page     = max( 1, min( 200, (int) ( $request->get_param( 'per_page' ) ?? 50 ) ) );
		$product_type = (string) ( $request->get_param( 'product_type' ) ?? '' );
		return $this->ok( $this->service->list_presets(
			$page,
			$per_page,
			$product_type !== '' ? $product_type : null
		) );
	}

	public function get_preset( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request['preset_id'];
		$preset = $this->service->get_preset( $id );
		if ( $preset === null ) {
			return $this->error( 'preset_not_found', 'Preset not found.', [], 404 );
		}
		return $this->ok( [ 'preset' => $preset ] );
	}

	public function save_as_preset( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$created_by = function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		$result     = $this->service->save_as_preset( $product_id, [
			'name'         => (string) ( $body['name'] ?? '' ),
			'description'  => isset( $body['description'] )  ? (string) $body['description']  : '',
			'product_type' => isset( $body['product_type'] ) ? (string) $body['product_type'] : '',
			'created_by'   => $created_by,
		] );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'preset_save_failed', (string) ( $result['message'] ?? 'Could not save preset.' ), [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( $result, 201 );
	}

	public function apply_preset( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$preset_id  = (int) ( $body['preset_id'] ?? 0 );
		if ( $preset_id <= 0 ) {
			return $this->error( 'preset_apply_failed', 'preset_id is required.', [], 400 );
		}
		$result = $this->service->apply_preset( $product_id, $preset_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'preset_apply_failed', (string) ( $result['message'] ?? 'Could not apply preset.' ), [], 400 );
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
