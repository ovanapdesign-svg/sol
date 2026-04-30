<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Engines\LookupEngine;
use ConfigKit\Engines\PricingEngine;
use ConfigKit\Service\TestDefaultPriceService;
use ConfigKit\Tests\Unit\Engines\StubPriceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.2b.2 — focused snapshot computation behind the
 * "Test default configuration price" admin panel
 * (UI_LABELS_MAPPING.md §9.3).
 *
 * The service walks binding.defaults, resolves library-sourced
 * fields against their allowed libraries, and applies per-product
 * overrides via the pricing engine. Lookup-table base price + rule
 * surcharges + VAT are deferred to Phase 4.2b.3.
 */
final class TestDefaultPriceServiceTest extends TestCase {

	private const PRODUCT_ID = 8080;

	private function service( StubPriceProvider $provider, callable $configure ): TestDefaultPriceService {
		$bindings = new StubProductBindingRepository();
		$fields   = new StubFieldRepository();
		$steps    = new StubStepRepository();
		$items    = new StubLibraryItemRepository();
		$bindings->register_product( self::PRODUCT_ID );
		$configure( $bindings, $fields, $steps, $items );
		return new TestDefaultPriceService(
			$bindings,
			$fields,
			$steps,
			$items,
			new PricingEngine( new LookupEngine(), $provider )
		);
	}

	public function test_returns_404_payload_for_unknown_product(): void {
		$svc = $this->service( new StubPriceProvider(), function ( $bindings ) {
			// Don't register any product.
			$bindings->known_products = [];
		} );
		$result = $svc->compute( 9999 );
		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['not_found'] );
	}

	public function test_returns_warning_when_no_template(): void {
		$svc = $this->service( new StubPriceProvider(), function ( $bindings ) {
			$bindings->save( self::PRODUCT_ID, [ 'enabled' => true ] );
		} );
		$result = $svc->compute( self::PRODUCT_ID );
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_resolves_library_item_default_to_configkit_price(): void {
		$svc = $this->service( new StubPriceProvider(), function ( $bindings, $fields, $steps, $items ) {
			$steps->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'label' => 'Step',
				'helper_text' => null, 'sort_order' => 0, 'is_active' => true,
			] );
			$fields->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'field_key' => 'fabric_color',
				'label' => 'Fabric color', 'helper_text' => null,
				'field_kind' => 'option', 'input_type' => 'select', 'display_type' => 'list',
				'value_source' => 'library', 'source_config' => [ 'libraries' => [ 'lib_textiles' ] ],
				'is_required' => true, 'sort_order' => 0, 'is_active' => true,
			] );
			$items->create( [
				'library_key' => 'lib_textiles', 'item_key' => 'red',
				'label' => 'Red fabric', 'price' => 1290.0, 'price_source' => 'configkit',
				'item_type' => 'simple_option', 'is_active' => true,
			] );
			$bindings->save( self::PRODUCT_ID, [
				'enabled'      => true,
				'template_key' => 'tpl_a',
				'defaults'     => [ 'fabric_color' => 'red' ],
			] );
		} );

		$result = $svc->compute( self::PRODUCT_ID );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1290.0, $result['subtotal'] );
		$this->assertCount( 1, $result['lines'] );
		$this->assertSame( 'red', $result['lines'][0]['item_key'] );
		$this->assertSame( 'configkit', $result['lines'][0]['price_source'] );
	}

	public function test_per_item_override_replaces_resolved_price(): void {
		$svc = $this->service( new StubPriceProvider(), function ( $bindings, $fields, $steps, $items ) {
			$steps->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'label' => 'Step',
				'helper_text' => null, 'sort_order' => 0, 'is_active' => true,
			] );
			$fields->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'field_key' => 'fabric_color',
				'label' => 'Fabric color', 'helper_text' => null,
				'field_kind' => 'option', 'input_type' => 'select', 'display_type' => 'list',
				'value_source' => 'library', 'source_config' => [ 'libraries' => [ 'lib_textiles' ] ],
				'is_required' => true, 'sort_order' => 0, 'is_active' => true,
			] );
			$items->create( [
				'library_key' => 'lib_textiles', 'item_key' => 'red',
				'label' => 'Red fabric', 'price' => 1290.0, 'price_source' => 'configkit',
				'item_type' => 'simple_option', 'is_active' => true,
			] );
			$bindings->save( self::PRODUCT_ID, [
				'enabled'              => true,
				'template_key'         => 'tpl_a',
				'defaults'             => [ 'fabric_color' => 'red' ],
				'item_price_overrides' => [
					'lib_textiles:red' => [ 'price' => 999.0, 'price_source' => 'product_override', 'reason' => 'Promo' ],
				],
			] );
		} );

		$result = $svc->compute( self::PRODUCT_ID );
		$this->assertSame( 999.0, $result['subtotal'] );
		$this->assertSame( 'product_override', $result['lines'][0]['price_source'] );
		$this->assertSame( 'Promo', $result['lines'][0]['note'] );
	}

	public function test_warns_when_default_item_not_found(): void {
		$svc = $this->service( new StubPriceProvider(), function ( $bindings, $fields, $steps ) {
			$steps->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'label' => 'Step',
				'helper_text' => null, 'sort_order' => 0, 'is_active' => true,
			] );
			$fields->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'field_key' => 'fabric_color',
				'label' => 'Fabric color', 'helper_text' => null,
				'field_kind' => 'option', 'input_type' => 'select', 'display_type' => 'list',
				'value_source' => 'library', 'source_config' => [ 'libraries' => [ 'lib_textiles' ] ],
				'is_required' => true, 'sort_order' => 0, 'is_active' => true,
			] );
			$bindings->save( self::PRODUCT_ID, [
				'enabled'      => true,
				'template_key' => 'tpl_a',
				'defaults'     => [ 'fabric_color' => 'ghost' ],
			] );
		} );

		$result = $svc->compute( self::PRODUCT_ID );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0.0, $result['subtotal'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_ignores_non_library_defaults(): void {
		$svc = $this->service( new StubPriceProvider(), function ( $bindings, $fields, $steps ) {
			$steps->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'label' => 'Step',
				'helper_text' => null, 'sort_order' => 0, 'is_active' => true,
			] );
			$fields->create( [
				'template_key' => 'tpl_a', 'step_key' => 's1', 'field_key' => 'control_type',
				'label' => 'Control type', 'helper_text' => null,
				'field_kind' => 'option', 'input_type' => 'select', 'display_type' => 'list',
				'value_source' => 'manual_options', 'source_config' => [],
				'is_required' => true, 'sort_order' => 0, 'is_active' => true,
			] );
			$bindings->save( self::PRODUCT_ID, [
				'enabled'      => true,
				'template_key' => 'tpl_a',
				'defaults'     => [ 'control_type' => 'manual' ],
			] );
		} );

		$result = $svc->compute( self::PRODUCT_ID );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0.0, $result['subtotal'] );
		$this->assertCount( 0, $result['lines'] );
	}
}
