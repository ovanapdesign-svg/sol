<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Rest\Controllers;

use ConfigKit\Rest\Controllers\WooProductsController;
use ConfigKit\Tests\Unit\Adapters\StubProductSearchProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Phase 4.2b.2 — REST controller for the Woo product picker. Verifies:
 *
 *  - search delegates to the provider with sane defaults
 *  - per_page is bounded to [1, 50]
 *  - the 60-second transient cache short-circuits a duplicate call
 *  - `find()` returns 404 when the id is unknown
 *  - the picker never leaks technical-key labels (the controller
 *    just passes data through; UI labelling lives in JS)
 */
final class WooProductsControllerTest extends TestCase {

	protected function setUp(): void {
		// Reset the transient store between tests so cache assertions
		// are isolated.
		$GLOBALS['__configkit_transients'] = [];
	}

	private function controller( StubProductSearchProvider $provider ): WooProductsController {
		return new WooProductsController( $provider );
	}

	private function provider(): StubProductSearchProvider {
		return new StubProductSearchProvider( [
			123 => [
				'id'            => 123,
				'name'          => 'Telis 4 RTS Pure',
				'sku'           => 'TLS4RTS',
				'price'         => 1490.0,
				'thumbnail_url' => null,
				'status'        => 'publish',
			],
			456 => [
				'id'            => 456,
				'name'          => 'Somfy IO Premium motor',
				'sku'           => 'IO-PREM',
				'price'         => 4500.0,
				'thumbnail_url' => 'https://example/img.jpg',
				'status'        => 'publish',
			],
			789 => [
				'id'            => 789,
				'name'          => 'Soliris sensor',
				'sku'           => 'SOL-100',
				'price'         => null,
				'thumbnail_url' => null,
				'status'        => 'draft',
			],
		] );
	}

	public function test_search_returns_all_products_when_query_empty(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'q', '' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 20 );

		$res = $ctrl->search( $req );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$data = $res->get_data();
		$this->assertSame( 3, $data['total'] );
		$this->assertCount( 3, $data['items'] );
	}

	public function test_search_filters_by_name_substring(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'q', 'somfy' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 20 );

		$data = $ctrl->search( $req )->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 456, $data['items'][0]['id'] );
	}

	public function test_search_filters_by_sku(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'q', 'TLS4RTS' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 20 );

		$data = $ctrl->search( $req )->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'TLS4RTS', $data['items'][0]['sku'] );
	}

	public function test_search_paginates(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'q', '' );
		$req->set_param( 'page', 2 );
		$req->set_param( 'per_page', 2 );

		$data = $ctrl->search( $req )->get_data();
		$this->assertSame( 3, $data['total'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 2, $data['page'] );
		$this->assertSame( 2, $data['per_page'] );
	}

	public function test_search_caps_per_page_at_50(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'q', '' );
		$req->set_param( 'per_page', 9999 );

		$data = $ctrl->search( $req )->get_data();
		$this->assertSame( 50, $data['per_page'] );
	}

	public function test_search_uses_cache_on_duplicate_request(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'q', 'somfy' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 20 );

		$ctrl->search( $req );
		$this->assertSame( 1, $provider->search_calls );

		$ctrl->search( $req );
		$this->assertSame( 1, $provider->search_calls, 'cache should short-circuit second call' );
	}

	public function test_search_misses_cache_when_query_changes(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'q', 'somfy' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 20 );
		$ctrl->search( $req );

		$req2 = new WP_REST_Request();
		$req2->set_param( 'q', 'soliris' );
		$req2->set_param( 'page', 1 );
		$req2->set_param( 'per_page', 20 );
		$ctrl->search( $req2 );

		$this->assertSame( 2, $provider->search_calls );
	}

	public function test_read_returns_record_when_found(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'id', 456 );

		$res = $ctrl->read( $req );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$data = $res->get_data();
		$this->assertSame( 456, $data['record']['id'] );
		$this->assertSame( 'Somfy IO Premium motor', $data['record']['name'] );
	}

	public function test_read_returns_404_when_missing(): void {
		$provider = $this->provider();
		$ctrl     = $this->controller( $provider );

		$req = new WP_REST_Request();
		$req->set_param( 'id', 9999 );

		$res = $ctrl->read( $req );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'not_found', $res->get_error_code() );
	}
}
