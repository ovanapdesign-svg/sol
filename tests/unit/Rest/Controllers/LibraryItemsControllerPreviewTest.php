<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Rest\Controllers;

use ConfigKit\Engines\LookupEngine;
use ConfigKit\Engines\PricingEngine;
use ConfigKit\Rest\Controllers\LibraryItemsController;
use ConfigKit\Service\LibraryItemService;
use ConfigKit\Tests\Unit\Engines\StubPriceProvider;
use ConfigKit\Tests\Unit\Service\StubLibraryItemRepository;
use ConfigKit\Tests\Unit\Service\StubLibraryRepository;
use ConfigKit\Tests\Unit\Service\StubModuleRepository;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Phase 4.2b.2 — POST /library-items/preview-price. The controller is
 * a thin adapter: it forwards form payloads to PricingEngine's
 * resolver and (for bundles) the breakdown helper.
 *
 * Spec: UI_LABELS_MAPPING.md §9.1 (resolved-price panel) + §9.2
 * (package breakdown).
 */
final class LibraryItemsControllerPreviewTest extends TestCase {

	private function controller( StubPriceProvider $provider ): LibraryItemsController {
		$service = new LibraryItemService(
			new StubLibraryItemRepository(),
			new StubLibraryRepository(),
			new StubModuleRepository(),
		);
		$engine = new PricingEngine( new LookupEngine(), $provider );
		return new LibraryItemsController( $service, $engine );
	}

	public function test_preview_returns_configkit_price(): void {
		$ctrl = $this->controller( new StubPriceProvider() );

		$req = new WP_REST_Request();
		$req->set_json_params( [
			'library_item' => [
				'item_type'    => 'simple_option',
				'price_source' => 'configkit',
				'price'        => 1290.0,
			],
		] );

		$res = $ctrl->preview_price( $req );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$data = $res->get_data();
		$this->assertSame( 1290.0, $data['resolved_price'] );
		$this->assertSame( 'configkit', $data['price_source'] );
		$this->assertSame( 'simple_option', $data['item_type'] );
		$this->assertArrayNotHasKey( 'breakdown', $data );
	}

	public function test_preview_returns_woo_price_via_provider(): void {
		$ctrl = $this->controller( new StubPriceProvider( prices: [ 555 => 4500.0 ] ) );

		$req = new WP_REST_Request();
		$req->set_json_params( [
			'library_item' => [
				'item_type'      => 'simple_option',
				'price_source'   => 'woo',
				'woo_product_id' => 555,
			],
		] );

		$data = $ctrl->preview_price( $req )->get_data();
		$this->assertSame( 4500.0, $data['resolved_price'] );
	}

	public function test_preview_returns_null_when_unresolvable(): void {
		$ctrl = $this->controller( new StubPriceProvider() );

		$req = new WP_REST_Request();
		$req->set_json_params( [
			'library_item' => [
				'item_type'      => 'simple_option',
				'price_source'   => 'woo',
				'woo_product_id' => 9999, // not in stub
			],
		] );

		$data = $ctrl->preview_price( $req )->get_data();
		$this->assertNull( $data['resolved_price'] );
	}

	public function test_preview_includes_breakdown_for_bundle(): void {
		$ctrl = $this->controller( new StubPriceProvider( prices: [ 200 => 1490.0 ] ) );

		$req = new WP_REST_Request();
		$req->set_json_params( [
			'library_item' => [
				'item_type'    => 'bundle',
				'price_source' => 'bundle_sum',
				'bundle_components' => [
					[ 'component_key' => 'motor', 'woo_product_id' => 200, 'qty' => 1, 'price_source' => 'woo' ],
					[ 'component_key' => 'sensor', 'woo_product_id' => 300, 'qty' => 2, 'price_source' => 'configkit', 'price' => 100.0 ],
				],
			],
		] );

		$data = $ctrl->preview_price( $req )->get_data();
		$this->assertSame( 1490.0 + 200.0, $data['resolved_price'] );
		$this->assertArrayHasKey( 'breakdown', $data );
		$this->assertCount( 2, $data['breakdown']['components'] );
		$this->assertSame( 'bundle_sum', $data['breakdown']['price_source'] );
	}

	public function test_preview_fixed_bundle_uses_fixed_price(): void {
		$ctrl = $this->controller( new StubPriceProvider( prices: [ 200 => 1490.0 ] ) );

		$req = new WP_REST_Request();
		$req->set_json_params( [
			'library_item' => [
				'item_type'          => 'bundle',
				'price_source'       => 'fixed_bundle',
				'bundle_fixed_price' => 8990.0,
				'bundle_components'  => [
					[ 'component_key' => 'motor', 'woo_product_id' => 200, 'qty' => 1, 'price_source' => 'woo' ],
				],
			],
		] );

		$data = $ctrl->preview_price( $req )->get_data();
		$this->assertSame( 8990.0, $data['resolved_price'] );
		$this->assertSame( 8990.0, $data['breakdown']['fixed_bundle_price'] );
	}

	public function test_preview_returns_500_when_engine_missing(): void {
		$service = new LibraryItemService(
			new StubLibraryItemRepository(),
			new StubLibraryRepository(),
			new StubModuleRepository(),
		);
		$ctrl = new LibraryItemsController( $service, null );

		$req = new WP_REST_Request();
		$req->set_json_params( [ 'library_item' => [] ] );

		$res = $ctrl->preview_price( $req );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'preview_unavailable', $res->get_error_code() );
	}
}
