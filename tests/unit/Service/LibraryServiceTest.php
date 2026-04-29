<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\LibraryService;
use ConfigKit\Service\ModuleService;
use PHPUnit\Framework\TestCase;

final class LibraryServiceTest extends TestCase {

	private StubLibraryRepository $libRepo;
	private StubModuleRepository $moduleRepo;
	private LibraryService $service;

	protected function setUp(): void {
		$this->libRepo    = new StubLibraryRepository();
		$this->moduleRepo = new StubModuleRepository();
		$this->service    = new LibraryService( $this->libRepo, $this->moduleRepo );

		// Seed an active module via ModuleService so its sanitization
		// produces the canonical record shape.
		$modSvc = new ModuleService( $this->moduleRepo );
		$modSvc->create( [
			'module_key'           => 'textiles',
			'name'                 => 'Textiles',
			'allowed_field_kinds'  => [ 'input' ],
			'attribute_schema'     => [],
			'is_active'            => true,
			'supports_brand'       => true,
			'supports_collection'  => true,
		] );
	}

	private function valid_input( array $overrides = [] ): array {
		return array_replace(
			[
				'library_key' => 'textiles_dickson',
				'module_key'  => 'textiles',
				'name'        => 'Dickson Orchestra',
				'description' => 'Premium fabric collection.',
				'brand'       => 'Dickson',
				'collection'  => 'Orchestra',
				'is_active'   => true,
			],
			$overrides
		);
	}

	public function test_create_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->valid_input() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'textiles_dickson', $result['record']['library_key'] );
		$this->assertSame( 'textiles', $result['record']['module_key'] );
	}

	public function test_create_missing_library_key_returns_required(): void {
		$result = $this->service->create( $this->valid_input( [ 'library_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'required', $result['errors'][0]['code'] );
	}

	public function test_create_invalid_library_key_format_returns_format(): void {
		$result = $this->service->create( $this->valid_input( [ 'library_key' => 'NotSnake' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_chars', $codes );
	}

	public function test_create_two_char_library_key_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'library_key' => 'tx' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_reserved_library_key_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'library_key' => 'admin' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'reserved', $codes );
	}

	public function test_create_duplicate_library_key_returns_duplicate(): void {
		$this->service->create( $this->valid_input() );
		$result = $this->service->create( $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_unknown_module_returns_error(): void {
		$result = $this->service->create( $this->valid_input( [ 'module_key' => 'no_such_module' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unknown_module', $codes );
	}

	public function test_create_inactive_module_is_rejected(): void {
		// Soft-delete the module
		$mod = $this->moduleRepo->find_by_key( 'textiles' );
		$this->moduleRepo->soft_delete( $mod['id'] );

		$result = $this->service->create( $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'inactive_module', $codes );
	}

	public function test_create_brand_against_unsupporting_module_is_rejected(): void {
		// Create a second module that does NOT support brand.
		$modSvc = new ModuleService( $this->moduleRepo );
		$modSvc->create( [
			'module_key'          => 'sides',
			'name'                => 'Sides',
			'allowed_field_kinds' => [ 'input' ],
			'attribute_schema'    => [],
			'is_active'           => true,
		] );

		$result = $this->service->create( [
			'library_key' => 'sides_default',
			'module_key'  => 'sides',
			'name'        => 'Sides',
			'brand'       => 'WhoeverCorp',
			'is_active'   => true,
		] );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unsupported_capability', $codes );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->valid_input() );
		$id      = $created['id'];
		$hash    = $created['record']['version_hash'];

		$result = $this->service->update( $id, $this->valid_input( [
			'name' => 'Dickson Orchestra (renamed)',
		] ), $hash );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Dickson Orchestra (renamed)', $result['record']['name'] );
	}

	public function test_update_with_stale_version_hash_returns_conflict(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update( $created['id'], $this->valid_input( [
			'name' => 'X',
		] ), 'stale-hash' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_module_key_is_immutable(): void {
		// Create a second module that the test will try to switch to.
		$modSvc = new ModuleService( $this->moduleRepo );
		$modSvc->create( [
			'module_key'          => 'motors',
			'name'                => 'Motors',
			'allowed_field_kinds' => [ 'input' ],
			'attribute_schema'    => [],
			'is_active'           => true,
		] );

		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'module_key' => 'motors' ] ),
			$created['record']['version_hash']
		);

		// Update should succeed but module_key should be preserved.
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'textiles', $result['record']['module_key'] );
	}

	public function test_soft_delete_sets_inactive(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->soft_delete( $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->libRepo->find_by_id( $created['id'] )['is_active'] );
	}

	public function test_soft_delete_unknown_id_returns_not_found(): void {
		$result = $this->service->soft_delete( 9999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}
}
