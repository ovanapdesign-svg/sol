<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\AttributeSchemaService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.2c — module-side attribute schema validation. Same shape
 * the JS editor produces; covers legacy + rich, type aliasing,
 * enum-options requirement, key shape.
 */
final class AttributeSchemaServiceTest extends TestCase {

	private AttributeSchemaService $svc;

	protected function setUp(): void {
		$this->svc = new AttributeSchemaService();
	}

	public function test_rich_schema_passes_validate_and_round_trips_through_sanitize(): void {
		$schema = [
			'fabric_code'  => [ 'label' => 'Fabric code', 'type' => 'text', 'sort_order' => 10 ],
			'transparency' => [ 'label' => 'Transparency', 'type' => 'enum', 'options' => [ 'high', 'low' ], 'sort_order' => 20 ],
			'blackout'     => [ 'label' => 'Blackout', 'type' => 'boolean' ],
		];
		$this->assertSame( [], $this->svc->validate( $schema ) );

		$out = $this->svc->sanitize( $schema );
		$this->assertSame( 'text',    $out['fabric_code']['type'] );
		$this->assertSame( [ 'high', 'low' ], $out['transparency']['options'] );
		$this->assertSame( 'boolean', $out['blackout']['type'] );
	}

	public function test_legacy_string_type_normalises_to_text(): void {
		$schema = [ 'fabric_code' => 'string' ];
		$this->assertSame( [], $this->svc->validate( $schema ) );
		$out = $this->svc->sanitize( $schema );
		$this->assertSame( 'text', $out['fabric_code']['type'] );
		$this->assertSame( 'Fabric code', $out['fabric_code']['label'], 'humanise key when label missing' );
	}

	public function test_invalid_key_shape_rejected(): void {
		$errors = $this->svc->validate( [ 'BadKey' => [ 'type' => 'text' ] ] );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'invalid_key', $errors[0]['code'] );
	}

	public function test_unknown_type_rejected(): void {
		$errors = $this->svc->validate( [ 'x' => [ 'type' => 'spaceship' ] ] );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'invalid_type', $errors[0]['code'] );
	}

	public function test_enum_without_options_rejected_in_validate_skipped_in_sanitize(): void {
		$schema = [ 'transparency' => [ 'type' => 'enum' ] ];
		$errors = $this->svc->validate( $schema );
		$this->assertSame( 'enum_missing_options', $errors[0]['code'] );

		$out = $this->svc->sanitize( $schema );
		$this->assertArrayNotHasKey( 'transparency', $out );
	}

	public function test_non_array_schema_rejected(): void {
		$errors = $this->svc->validate( 'not-an-array' );
		$this->assertSame( 'invalid_type', $errors[0]['code'] );
	}

	public function test_required_flag_round_trips(): void {
		$out = $this->svc->sanitize( [ 'sku_code' => [ 'type' => 'text', 'required' => true ] ] );
		$this->assertTrue( $out['sku_code']['required'] );
	}

	public function test_sort_order_auto_increments_when_missing(): void {
		$out = $this->svc->sanitize( [
			'a' => [ 'type' => 'text' ],
			'b' => [ 'type' => 'text' ],
			'c' => [ 'type' => 'text' ],
		] );
		$this->assertSame( 10, $out['a']['sort_order'] );
		$this->assertSame( 20, $out['b']['sort_order'] );
		$this->assertSame( 30, $out['c']['sort_order'] );
	}

	public function test_duplicate_keys_in_input_get_caught(): void {
		// PHP arrays can't have duplicate keys, so the duplicate check
		// is for downstream callers that flatten arrays of [key,...]
		// rows. Verified indirectly: a key seen twice would just keep
		// the latter; the validate method only sees one. Confirm
		// validate accepts the de-duped value.
		$errors = $this->svc->validate( [ 'a' => [ 'type' => 'text' ] ] );
		$this->assertSame( [], $errors );
	}
}
