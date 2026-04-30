<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Adapters\ProductSearchProvider;
use ConfigKit\Rest\AbstractController;

/**
 * Phase 4.2b.2 — read-only Woo product picker endpoint.
 *
 * Used by:
 *   - Library item form (item_type = bundle component picker)
 *   - Library item form (woo_product_id field for `price_source = 'woo'`)
 *   - Product binding override editor (item picker is library-side, but
 *     this endpoint also serves the "main product" lookup)
 *
 * Spec: UI_LABELS_MAPPING.md §4. The owner-facing UI never displays raw
 * enum values: this endpoint just feeds raw Woo product data; the JS
 * picker handles labelling.
 *
 * Capability: `configkit_manage_libraries` — picker is invoked from
 * library item editing screens. Product-binding screens are gated by
 * `configkit_manage_products`, but those owners also hold libraries
 * cap in the default cap matrix (see Capabilities\Registrar).
 *
 * Cache: 60-second transient keyed by query+page+limit. The picker
 * issues a request per keystroke (debounced 300 ms in JS), so cache
 * smooths repeated identical requests.
 */
final class WooProductsController extends AbstractController {

	private const CAP            = 'configkit_manage_libraries';
	private const CACHE_TTL_SECS = 60;
	private const CACHE_PREFIX   = 'configkit_woo_search_';

	public function __construct( private ProductSearchProvider $provider ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/woo-products',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'search' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'q'        => [ 'type' => 'string', 'default' => '' ],
						'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/woo-products/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);
	}

	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$query    = trim( (string) $request->get_param( 'q' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 50, $per_page ) : 20;

		$cache_key = self::CACHE_PREFIX . md5( $query . '|' . $page . '|' . $per_page );
		if ( function_exists( 'get_transient' ) ) {
			$cached = \get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $this->ok( $cached );
			}
		}

		$result = $this->provider->search( $query, $per_page, $page );

		if ( function_exists( 'set_transient' ) ) {
			\set_transient( $cache_key, $result, self::CACHE_TTL_SECS );
		}
		return $this->ok( $result );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['id'];
		$row = $this->provider->find( $id );
		if ( $row === null ) {
			return $this->error( 'not_found', 'Woo product not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $row ] );
	}
}
