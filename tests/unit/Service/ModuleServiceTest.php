<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\ModuleService;
use PHPUnit\Framework\TestCase;

final class ModuleServiceTest extends TestCase {

	private StubModuleRepository $repo;
	private ModuleService $service;

	protected function setUp(): void {
		$this->repo    = new StubModuleRepository();
		$this->service = new ModuleService( $this->repo );
	}

	private function valid_input( array $overrides = [] ): array {
		return array_replace(
			[
				'module_key'          => 'textiles',
				'name'                => 'Textiles',
				'description'         => 'Fabric collections.',
				'allowed_field_kinds' => [ 'input' ],
				'attribute_schema'    => [
					'fabric_code' => 'string',
					'blackout'    => 'boolean',
				],
				'is_active'           => true,
				'supports_sku'        => true,
				'supports_image'      => true,
			],
			$overrides
		);
	}

	public function test_create_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->valid_input() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['id'] );
		$this->assertSame( 'textiles', $result['record']['module_key'] );
		$this->assertTrue( $result['record']['supports_sku'] );
	}

	public function test_create_missing_module_key_returns_required_error(): void {
		$result = $this->service->create( $this->valid_input( [ 'module_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'module_key', $result['errors'][0]['field'] );
		$this->assertSame( 'required', $result['errors'][0]['code'] );
	}

	public function test_create_invalid_module_key_format_returns_format_error(): void {
		$result = $this->service->create( $this->valid_input( [ 'module_key' => 'NotSnake' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_format', $codes );
	}

	public function test_create_module_key_starting_with_digit_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'module_key' => '1textiles' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_format', $codes );
	}

	public function test_create_duplicate_module_key_returns_duplicate_error(): void {
		$this->service->create( $this->valid_input() );
		$result = $this->service->create( $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_missing_name_returns_required_error(): void {
		$result = $this->service->create( $this->valid_input( [ 'name' => '   ' ] ) );
		$this->assertFalse( $result['ok'] );
		$fields = array_column( $result['errors'], 'field' );
		$this->assertContains( 'name', $fields );
	}

	public function test_create_invalid_field_kind_returns_invalid_value_error(): void {
		$result = $this->service->create( $this->valid_input( [
			'allowed_field_kinds' => [ 'input', 'nonsense' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_create_attribute_schema_invalid_type_value_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [
			'attribute_schema' => [ 'fabric_code' => 'decimal' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_attribute_type', $codes );
	}

	public function test_create_attribute_schema_invalid_key_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [
			'attribute_schema' => [ 'BadKey' => 'string' ],
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_key', $codes );
	}

	public function test_create_attribute_schema_invalid_json_string_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [
			'attribute_schema' => '{ not json',
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_json', $codes );
	}

	public function test_create_accepts_attribute_schema_as_json_string(): void {
		$result = $this->service->create( $this->valid_input( [
			'attribute_schema' => '{"fabric_code":"string","blackout":"boolean"}',
		] ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'string', $result['record']['attribute_schema']['fabric_code'] );
	}

	public function test_create_default_capability_flags_default_to_false(): void {
		$input = $this->valid_input();
		// Don't pass supports_brand explicitly.
		unset( $input['supports_brand'] );
		$result = $this->service->create( $input );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['record']['supports_brand'] );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->valid_input() );
		$id      = $created['id'];
		$hash    = $created['record']['version_hash'];

		$result = $this->service->update( $id, $this->valid_input( [
			'name'           => 'Textiles renamed',
			'supports_brand' => true,
		] ), $hash );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Textiles renamed', $result['record']['name'] );
		$this->assertTrue( $result['record']['supports_brand'] );
		$this->assertNotSame( $hash, $result['record']['version_hash'] );
	}

	public function test_update_with_stale_version_hash_returns_conflict(): void {
		$created = $this->service->create( $this->valid_input() );
		$id      = $created['id'];

		$result = $this->service->update( $id, $this->valid_input( [
			'name' => 'Textiles renamed',
		] ), 'stale-hash-value' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_changing_module_key_to_existing_returns_duplicate(): void {
		$first  = $this->service->create( $this->valid_input( [ 'module_key' => 'textiles' ] ) );
		$second = $this->service->create( $this->valid_input( [ 'module_key' => 'motors' ] ) );

		$result = $this->service->update(
			$second['id'],
			$this->valid_input( [ 'module_key' => 'textiles', 'name' => 'Motors' ] ),
			$second['record']['version_hash']
		);

		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_update_keeping_same_module_key_does_not_trip_uniqueness(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'name' => 'Updated' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
	}

	public function test_update_unknown_id_returns_not_found(): void {
		$result = $this->service->update( 9999, $this->valid_input(), 'whatever' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_soft_delete_marks_inactive(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->soft_delete( $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->repo->find_by_id( $created['id'] )['is_active'] );
	}

	public function test_soft_delete_unknown_id_returns_not_found(): void {
		$result = $this->service->soft_delete( 9999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_get_returns_record_when_present(): void {
		$created = $this->service->create( $this->valid_input() );
		$record  = $this->service->get( $created['id'] );
		$this->assertNotNull( $record );
		$this->assertSame( 'textiles', $record['module_key'] );
	}

	public function test_get_returns_null_when_absent(): void {
		$this->assertNull( $this->service->get( 9999 ) );
	}
}
