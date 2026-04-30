<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Admin\ProductTypeRecipes;
use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\AutoManagedRegistry;
use ConfigKit\Service\ProductBuilderService;
use ConfigKit\Service\SetupSourceResolver;
use ConfigKit\Service\SetupSourceService;

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

	public function __construct(
		private ProductBuilderService $service,
		private ?AutoManagedRegistry $registry = null,
		private ?SetupSourceService $setup_source = null,
		private ?SetupSourceResolver $resolver = null,
	) {}

	public function register_routes(): void {
		\register_rest_route( self::NAMESPACE, '/product-builder/recipes', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_recipes' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/auto-managed', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_auto_managed' ],
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

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/snapshot', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_snapshot' ],
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

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/checklist', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_checklist' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/enable', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enable' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		// Phase 4.3b half B — setup-source actions.
		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/setup-source', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read_setup_source' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/copy-from-product', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'copy_from_product' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/link-to-setup', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'link_to_setup' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/detach-from-preset', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'detach_from_preset' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/reset-override', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_override' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/product-builder/(?P<product_id>\d+)/write-override', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'write_override' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );
	}

	public function read_setup_source( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->resolver === null ) {
			return $this->error( 'half_b_not_wired', 'Setup-source resolver is not wired.', [], 500 );
		}
		$product_id = (int) $request['product_id'];
		$resolved   = $this->resolver->resolve( $product_id );
		// Surface the parts the UI needs without dumping the full
		// section list — the existing /configurator/{id}/sections
		// endpoint already returns those.
		return $this->ok( [
			'setup_source'      => $resolved['setup_source'],
			'preset_id'         => $resolved['preset_id'],
			'source_product_id' => $resolved['source_product_id'],
			'preset'            => $resolved['preset'] !== null ? [
				'id'           => $resolved['preset']['id'],
				'preset_key'   => $resolved['preset']['preset_key'],
				'name'         => $resolved['preset']['name'],
				'product_type' => $resolved['preset']['product_type'],
			] : null,
			'overrides'         => $resolved['overrides'],
			'global_overrides'  => $resolved['global_overrides'],
			'orphan_paths'      => $resolved['orphan_paths'],
		] );
	}

	public function copy_from_product( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->setup_source === null ) {
			return $this->error( 'half_b_not_wired', 'Setup-source service is not wired.', [], 500 );
		}
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$source_id  = (int) ( $body['source_product_id'] ?? 0 );
		if ( $source_id <= 0 ) {
			return $this->error( 'copy_failed', 'source_product_id is required.', [], 400 );
		}
		$choice    = (string) ( $body['lookup_table_choice'] ?? SetupSourceService::LOOKUP_INHERIT );
		$reuse_key = isset( $body['lookup_table_key'] ) ? (string) $body['lookup_table_key'] : null;
		$result    = $this->setup_source->copy_from_product( $product_id, $source_id, $choice, $reuse_key );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'copy_failed', (string) ( $result['message'] ?? 'Copy failed.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	public function link_to_setup( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->setup_source === null ) {
			return $this->error( 'half_b_not_wired', 'Setup-source service is not wired.', [], 500 );
		}
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$source_id  = (int) ( $body['source_product_id'] ?? 0 );
		if ( $source_id <= 0 ) {
			return $this->error( 'link_failed', 'source_product_id is required.', [], 400 );
		}
		$result = $this->setup_source->link_to_setup( $product_id, $source_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'link_failed', (string) ( $result['message'] ?? 'Link failed.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	public function detach_from_preset( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->setup_source === null ) {
			return $this->error( 'half_b_not_wired', 'Setup-source service is not wired.', [], 500 );
		}
		$product_id = (int) $request['product_id'];
		$result     = $this->setup_source->detach_from_preset( $product_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'detach_failed', (string) ( $result['message'] ?? 'Detach failed.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	public function reset_override( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->setup_source === null ) {
			return $this->error( 'half_b_not_wired', 'Setup-source service is not wired.', [], 500 );
		}
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$path       = (string) ( $body['override_path'] ?? '' );
		$result     = $this->setup_source->reset_override( $product_id, $path );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'reset_failed', (string) ( $result['message'] ?? 'Reset failed.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	public function write_override( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->setup_source === null ) {
			return $this->error( 'half_b_not_wired', 'Setup-source service is not wired.', [], 500 );
		}
		$product_id = (int) $request['product_id'];
		$body       = $this->payload( $request );
		$path       = (string) ( $body['path'] ?? '' );
		$value      = $body['value'] ?? null;
		$result     = $this->setup_source->write_override( $product_id, $path, $value );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'write_failed', (string) ( $result['message'] ?? 'Write failed.' ), [], 400 );
		}
		return $this->ok( $result );
	}

	public function list_recipes( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( [ 'recipes' => ProductTypeRecipes::all() ] );
	}

	public function list_auto_managed( \WP_REST_Request $request ): \WP_REST_Response {
		$registry = $this->registry ?? new AutoManagedRegistry();
		return $this->ok( $registry->snapshot() );
	}

	public function read_state( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( [
			'product_id' => $product_id,
			'state'      => $this->service->get_state( $product_id ),
		] );
	}

	public function read_snapshot( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( array_merge(
			[ 'product_id' => $product_id ],
			$this->service->get_full_snapshot( $product_id )
		) );
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

	public function read_checklist( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request['product_id'];
		return $this->ok( $this->service->can_enable_configurator( $product_id ) );
	}

	public function enable( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$result     = $this->service->enable_configurator( $product_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error(
				'product_builder_not_ready',
				(string) ( $result['message'] ?? 'Configurator could not be enabled.' ),
				[ 'checklist' => $result['checklist'] ?? [] ],
				400
			);
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
