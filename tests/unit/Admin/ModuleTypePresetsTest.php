<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Admin;

use ConfigKit\Admin\ModuleTypePresets;
use ConfigKit\Repository\ModuleRepository;
use PHPUnit\Framework\TestCase;

final class ModuleTypePresetsTest extends TestCase {

	public function test_all_returns_six_presets(): void {
		$presets = ModuleTypePresets::all();
		$ids = array_map( static fn( $p ): string => (string) $p['id'], $presets );
		$this->assertSame( [ 'textiles', 'colors', 'motors', 'controls', 'accessories', 'custom' ], $ids );
	}

	public function test_textiles_preset_seeds_attribute_schema_for_phase_4_2c(): void {
		// Phase 4.2c — presets ship attribute_schema defaults so the
		// owner doesn't have to type fabric_code / material / etc. by
		// hand. The schema must still be editable post-apply.
		$preset = ModuleTypePresets::find( 'textiles' );
		$this->assertNotNull( $preset );
		$this->assertArrayHasKey( 'attribute_schema', $preset );
		$keys = array_keys( $preset['attribute_schema'] );
		$this->assertContains( 'fabric_code', $keys );
		$this->assertContains( 'transparency', $keys );
	}

	public function test_apply_to_payload_seeds_attribute_schema_when_owner_has_none(): void {
		$payload = [ 'module_key' => 'tx', 'name' => 'TX', 'module_type' => 'textiles' ];
		$out     = ModuleTypePresets::apply_to_payload( $payload );
		$this->assertArrayHasKey( 'attribute_schema', $out );
		$this->assertArrayHasKey( 'fabric_code', $out['attribute_schema'] );
	}

	public function test_apply_to_payload_owner_attribute_schema_wins(): void {
		$payload = [
			'module_key'       => 'tx',
			'name'             => 'TX',
			'module_type'      => 'textiles',
			'attribute_schema' => [ 'only_one' => [ 'label' => 'Only one', 'type' => 'text' ] ],
		];
		$out = ModuleTypePresets::apply_to_payload( $payload );
		$this->assertSame( [ 'only_one' ], array_keys( $out['attribute_schema'] ) );
	}

	public function test_textiles_seeds_eight_capabilities_and_input_kind(): void {
		$preset = ModuleTypePresets::find( 'textiles' );
		$this->assertNotNull( $preset );
		$enabled = array_keys( array_filter( $preset['capabilities'] ) );
		sort( $enabled );
		$this->assertSame(
			[
				'supports_brand',
				'supports_collection',
				'supports_color_family',
				'supports_compatibility',
				'supports_filters',
				'supports_image',
				'supports_price_group',
				'supports_sku',
			],
			$enabled
		);
		$this->assertSame( [ 'input' ], $preset['allowed_field_kinds'] );
	}

	public function test_colors_preset(): void {
		$preset = ModuleTypePresets::find( 'colors' );
		$this->assertSame(
			[ 'supports_color_family', 'supports_image', 'supports_sku' ],
			$this->enabled_caps( $preset )
		);
		$this->assertSame( [ 'input' ], $preset['allowed_field_kinds'] );
	}

	public function test_motors_preset(): void {
		$preset = ModuleTypePresets::find( 'motors' );
		$this->assertSame(
			[ 'supports_compatibility', 'supports_price', 'supports_sale_price', 'supports_sku', 'supports_woo_product_link' ],
			$this->enabled_caps( $preset )
		);
		$this->assertSame( [ 'addon', 'input' ], $preset['allowed_field_kinds'] );
	}

	public function test_accessories_preset(): void {
		$preset = ModuleTypePresets::find( 'accessories' );
		$this->assertSame(
			[ 'supports_image', 'supports_price', 'supports_sale_price', 'supports_sku', 'supports_woo_product_link' ],
			$this->enabled_caps( $preset )
		);
		$this->assertSame( [ 'addon' ], $preset['allowed_field_kinds'] );
	}

	public function test_custom_preset_has_no_seeds(): void {
		$preset = ModuleTypePresets::find( 'custom' );
		$this->assertSame( [], $this->enabled_caps( $preset ) );
		$this->assertSame( [], $preset['allowed_field_kinds'] );
	}

	public function test_unknown_preset_returns_null(): void {
		$this->assertNull( ModuleTypePresets::find( 'no_such_preset' ) );
	}

	public function test_apply_to_payload_seeds_caps_when_module_type_present(): void {
		$payload = [ 'module_key' => 'textiles_dickson', 'name' => 'Dickson', 'module_type' => 'textiles' ];
		$out     = ModuleTypePresets::apply_to_payload( $payload );
		$this->assertArrayNotHasKey( 'module_type', $out, 'module_type should be consumed' );
		$this->assertTrue( $out['supports_brand'] );
		$this->assertTrue( $out['supports_collection'] );
		$this->assertSame( [ 'input' ], $out['allowed_field_kinds'] );
	}

	public function test_apply_to_payload_owner_value_wins_over_preset(): void {
		$payload = [
			'module_key'           => 'tiny',
			'name'                 => 'Tiny',
			'module_type'          => 'textiles',
			'supports_brand'       => false,
			'allowed_field_kinds'  => [ 'lookup' ],
		];
		$out = ModuleTypePresets::apply_to_payload( $payload );
		$this->assertFalse( $out['supports_brand'], 'owner override must beat preset' );
		$this->assertSame( [ 'lookup' ], $out['allowed_field_kinds'] );
	}

	public function test_apply_to_payload_no_module_type_is_passthrough(): void {
		$payload = [ 'module_key' => 'x', 'name' => 'X' ];
		$this->assertSame( $payload, ModuleTypePresets::apply_to_payload( $payload ) );
	}

	public function test_apply_to_payload_unknown_type_is_stripped_only(): void {
		$payload = [ 'module_key' => 'x', 'name' => 'X', 'module_type' => 'no_such' ];
		$out     = ModuleTypePresets::apply_to_payload( $payload );
		$this->assertArrayNotHasKey( 'module_type', $out );
		$this->assertArrayNotHasKey( 'supports_brand', $out, 'unknown type must not seed caps' );
	}

	public function test_apply_to_payload_custom_does_not_pre_enable_caps(): void {
		$payload = [ 'module_key' => 'c', 'name' => 'C', 'module_type' => 'custom' ];
		$out     = ModuleTypePresets::apply_to_payload( $payload );
		// every cap is set to false (preset always sets the full map)
		$this->assertFalse( $out['supports_brand'] );
		$this->assertFalse( $out['supports_price'] );
		$this->assertSame( [], $out['allowed_field_kinds'] );
	}

	public function test_all_presets_set_every_capability_flag_to_a_bool(): void {
		// The capabilities map must enumerate every flag declared by
		// ModuleRepository so the apply path doesn't silently drop any.
		foreach ( ModuleTypePresets::all() as $preset ) {
			foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
				$this->assertArrayHasKey( $flag, $preset['capabilities'], 'preset ' . $preset['id'] . ' missing ' . $flag );
				$this->assertIsBool( $preset['capabilities'][ $flag ] );
			}
		}
	}

	/**
	 * @param array<string,mixed> $preset
	 * @return list<string>
	 */
	private function enabled_caps( array $preset ): array {
		$enabled = array_keys( array_filter( $preset['capabilities'] ) );
		sort( $enabled );
		return array_values( $enabled );
	}
}
