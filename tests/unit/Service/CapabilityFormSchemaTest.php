<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Service\CapabilityFormSchema;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.2c — central form-schema generator. Same logic for every
 * module: capabilities + attribute schema in, library/item form
 * shape + import column whitelist out. Owner can build any custom
 * module and the entire admin reacts without code changes.
 */
final class CapabilityFormSchemaTest extends TestCase {

	private CapabilityFormSchema $schema;

	protected function setUp(): void {
		$this->schema = new CapabilityFormSchema();
	}

	private function module( array $caps = [], array $attrs = [], string $key = 'm', string $name = 'M' ): array {
		$module = [ 'module_key' => $key, 'name' => $name ];
		foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
			$module[ $flag ] = ! empty( $caps[ $flag ] );
		}
		$module['attribute_schema'] = $attrs;
		return $module;
	}

	public function test_universal_fields_present_for_every_module(): void {
		$result = $this->schema->for_library_items( $this->module() );
		$keys = array_column( $result['universal_fields'], 'key' );
		$this->assertContains( 'label', $keys );
		$this->assertContains( 'description', $keys );
		$this->assertContains( 'is_active', $keys );
		$this->assertContains( 'sort_order', $keys );
	}

	public function test_capability_fields_only_for_enabled_caps(): void {
		$result = $this->schema->for_library_items( $this->module( [
			'supports_sku' => true, 'supports_image' => true,
		] ) );
		$keys = array_column( $result['capability_fields'], 'key' );
		$this->assertContains( 'sku', $keys );
		$this->assertContains( 'image_url', $keys );
		$this->assertNotContains( 'price', $keys );
		$this->assertNotContains( 'main_image_url', $keys );
		$this->assertNotContains( 'color_family', $keys );
	}

	public function test_inherited_fields_render_when_module_supports_brand_or_collection(): void {
		$result = $this->schema->for_library_items( $this->module( [
			'supports_brand' => true, 'supports_collection' => true,
		] ) );
		$keys = array_column( $result['inherited_fields'], 'key' );
		$this->assertContains( 'brand', $keys );
		$this->assertContains( 'collection', $keys );
	}

	public function test_sale_price_field_requires_supports_price(): void {
		// supports_sale_price alone shouldn't render a sale_price field —
		// it depends on supports_price being on too.
		$without_price = $this->schema->for_library_items( $this->module( [
			'supports_sale_price' => true,
		] ) );
		$this->assertNotContains( 'sale_price', array_column( $without_price['capability_fields'], 'key' ) );

		$with_price = $this->schema->for_library_items( $this->module( [
			'supports_price' => true, 'supports_sale_price' => true,
		] ) );
		$keys = array_column( $with_price['capability_fields'], 'key' );
		$this->assertContains( 'price',      $keys );
		$this->assertContains( 'sale_price', $keys );
	}

	public function test_attribute_schema_rich_shape_passes_through(): void {
		$result = $this->schema->for_library_items( $this->module( [], [
			'fabric_code'   => [ 'label' => 'Fabric code',   'type' => 'text',    'required' => true ],
			'transparency'  => [ 'label' => 'Transparency',  'type' => 'enum',    'options' => [ 'high', 'medium', 'low' ] ],
			'blackout'      => [ 'label' => 'Blackout',      'type' => 'boolean' ],
			'gsm'           => [ 'label' => 'GSM',           'type' => 'number' ],
		] ) );
		$by_key = [];
		foreach ( $result['attribute_fields'] as $a ) $by_key[ $a['key'] ] = $a;

		$this->assertSame( 'text',    $by_key['fabric_code']['type'] );
		$this->assertTrue(            $by_key['fabric_code']['required'] );
		$this->assertSame( 'enum',    $by_key['transparency']['type'] );
		$this->assertSame( [ 'high', 'medium', 'low' ], $by_key['transparency']['options'] );
		$this->assertSame( 'boolean', $by_key['blackout']['type'] );
		$this->assertSame( 'number',  $by_key['gsm']['type'] );
	}

	public function test_legacy_attribute_schema_strings_normalise_to_canonical_types(): void {
		// Backwards compatible: pre-Phase-4.2c modules stored the schema
		// as { key: type-string }. The service must still parse them.
		$result = $this->schema->for_library_items( $this->module( [], [
			'fabric_code' => 'string',
			'blackout'    => 'boolean',
			'gsm'         => 'integer',
		] ) );
		$by_key = [];
		foreach ( $result['attribute_fields'] as $a ) $by_key[ $a['key'] ] = $a;
		$this->assertSame( 'text',    $by_key['fabric_code']['type'] );
		$this->assertSame( 'boolean', $by_key['blackout']['type'] );
		$this->assertSame( 'number',  $by_key['gsm']['type'] );
	}

	public function test_enum_without_options_falls_back_to_text(): void {
		$result = $this->schema->for_library_items( $this->module( [], [
			'transparency' => [ 'type' => 'enum' ],
		] ) );
		$this->assertSame( 'text', $result['attribute_fields'][0]['type'] );
		$this->assertArrayNotHasKey( 'options', $result['attribute_fields'][0] );
	}

	public function test_pricing_supported_flag_reflects_caps(): void {
		$without = $this->schema->for_library_items( $this->module() );
		$this->assertFalse( $without['pricing_supported'] );
		$with = $this->schema->for_library_items( $this->module( [ 'supports_price' => true ] ) );
		$this->assertTrue( $with['pricing_supported'] );
	}

	public function test_for_library_returns_brand_and_collection_when_supported(): void {
		$result = $this->schema->for_library( $this->module( [ 'supports_brand' => true ] ) );
		$keys = array_column( $result['library_fields'], 'key' );
		$this->assertContains( 'brand', $keys );
		$this->assertNotContains( 'collection', $keys );
	}

	public function test_import_columns_lists_universal_capability_and_attribute_aliases(): void {
		$cols = $this->schema->import_columns( $this->module( [
			'supports_sku' => true, 'supports_price_group' => true, 'supports_woo_product_link' => true,
		], [
			'fabric_code' => [ 'label' => 'Fabric code', 'type' => 'text' ],
		] ) );
		$by_key = [];
		foreach ( $cols as $c ) $by_key[ $c['key'] ] = $c;

		$this->assertArrayHasKey( 'item_key', $by_key );
		$this->assertArrayHasKey( 'label',    $by_key );
		$this->assertSame( 'universal', $by_key['item_key']['group'] );

		$this->assertArrayHasKey( 'sku', $by_key );
		$this->assertSame( 'capability', $by_key['sku']['group'] );

		// price_group_key MUST also accept the friendly alias 'price_group'.
		$this->assertContains( 'price_group', $by_key['price_group_key']['aliases'] );

		// woo_product_id MUST also accept 'woo_product_sku'.
		$this->assertContains( 'woo_product_sku', $by_key['woo_product_id']['aliases'] );

		// Attribute column accepts both bare and attr.* alias.
		$this->assertArrayHasKey( 'fabric_code', $by_key );
		$this->assertContains( 'fabric_code',     $by_key['fabric_code']['aliases'] );
		$this->assertContains( 'attr.fabric_code', $by_key['fabric_code']['aliases'] );
		$this->assertSame( 'attribute', $by_key['fabric_code']['group'] );
	}

	public function test_custom_module_with_arbitrary_caps_generates_correctly(): void {
		// A module created from scratch (no preset) with an unusual mix:
		// colour family + main image only, and one attribute. The form
		// must reflect exactly that — no hidden hardcoding.
		$result = $this->schema->for_library_items( $this->module( [
			'supports_color_family' => true,
			'supports_main_image'   => true,
		], [
			'pantone_code' => [ 'label' => 'Pantone code', 'type' => 'text' ],
		], 'mod_custom', 'My Custom Stuff' ) );

		$cap_keys = array_column( $result['capability_fields'], 'key' );
		$this->assertSame( [ 'main_image_url', 'color_family' ], $cap_keys );
		$this->assertCount( 1, $result['attribute_fields'] );
		$this->assertSame( 'pantone_code', $result['attribute_fields'][0]['key'] );
		$this->assertSame( 'My Custom Stuff', $result['module_name'] );
	}
}
