<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\FamilyService;
use PHPUnit\Framework\TestCase;

final class FamilyServiceTest extends TestCase {

	private StubFamilyRepository $repo;
	private FamilyService $service;

	protected function setUp(): void {
		$this->repo    = new StubFamilyRepository();
		$this->service = new FamilyService( $this->repo );
	}

	private function valid_input( array $overrides = [] ): array {
		return array_replace(
			[
				'family_key'  => 'markiser',
				'name'        => 'Markiser',
				'description' => 'Awning product family.',
				'is_active'   => true,
			],
			$overrides
		);
	}

	public function test_create_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->valid_input() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'markiser', $result['record']['family_key'] );
		$this->assertSame( 'Markiser', $result['record']['name'] );
	}

	public function test_create_missing_family_key_returns_required(): void {
		$result = $this->service->create( $this->valid_input( [ 'family_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_two_char_family_key_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'family_key' => 'ab' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_reserved_family_key_is_rejected(): void {
		foreach ( [ 'admin', 'config', 'configkit' ] as $reserved ) {
			$result = $this->service->create( $this->valid_input( [ 'family_key' => $reserved ] ) );
			$this->assertFalse( $result['ok'], 'Expected reserved keyword to be rejected: ' . $reserved );
			$codes = array_column( $result['errors'], 'code' );
			$this->assertContains( 'reserved', $codes );
		}
	}

	public function test_create_uppercase_family_key_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'family_key' => 'NotSnake' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_chars', $codes );
	}

	public function test_create_duplicate_family_key_returns_duplicate(): void {
		$this->service->create( $this->valid_input() );
		$result = $this->service->create( $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_with_short_name_returns_too_short(): void {
		$result = $this->service->create( $this->valid_input( [ 'name' => 'A' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_with_empty_name_returns_required(): void {
		$result = $this->service->create( $this->valid_input( [ 'name' => '   ' ] ) );
		$this->assertFalse( $result['ok'] );
		$fields = array_column( $result['errors'], 'field' );
		$this->assertContains( 'name', $fields );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_with_201_char_name_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'name' => str_repeat( 'a', 201 ) ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_long', $codes );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->valid_input() );
		$id      = $created['id'];
		$hash    = $created['record']['version_hash'];

		$result = $this->service->update(
			$id,
			$this->valid_input( [ 'name' => 'Markiser (renamed)' ] ),
			$hash
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Markiser (renamed)', $result['record']['name'] );
	}

	public function test_update_with_stale_version_hash_returns_conflict(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'name' => 'X' ] ),
			'stale-hash'
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'conflict', $result['errors'][0]['code'] );
	}

	public function test_update_family_key_is_immutable(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'family_key' => 'screens', 'name' => 'Screens' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'markiser', $result['record']['family_key'] );
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

	public function test_soft_delete_unknown_returns_not_found(): void {
		$result = $this->service->soft_delete( 9999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}
}
