<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\TemplateService;
use PHPUnit\Framework\TestCase;

final class TemplateServiceTest extends TestCase {

	private StubTemplateRepository $repo;
	private TemplateService $service;

	protected function setUp(): void {
		$this->repo    = new StubTemplateRepository();
		$this->service = new TemplateService( $this->repo );
	}

	private function valid_input( array $overrides = [] ): array {
		return array_replace(
			[
				'template_key' => 'markise_motorisert',
				'name'         => 'Markise motorisert',
				'family_key'   => 'markiser',
				'description'  => 'Motorised awning template.',
				'status'       => 'draft',
			],
			$overrides
		);
	}

	public function test_create_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->valid_input() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'markise_motorisert', $result['record']['template_key'] );
		$this->assertSame( 'draft', $result['record']['status'] );
		$this->assertSame( 'markiser', $result['record']['family_key'] );
	}

	public function test_create_default_status_is_draft(): void {
		$input = $this->valid_input();
		unset( $input['status'] );
		$result = $this->service->create( $input );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'draft', $result['record']['status'] );
	}

	public function test_create_missing_template_key_returns_required(): void {
		$result = $this->service->create( $this->valid_input( [ 'template_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_two_char_template_key_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'template_key' => 'ab' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_reserved_template_key_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'template_key' => 'admin' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'reserved', $codes );
	}

	public function test_create_template_key_with_dot_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'template_key' => 'foo.bar' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_chars', $codes );
	}

	public function test_create_duplicate_template_key_returns_duplicate(): void {
		$this->service->create( $this->valid_input() );
		$result = $this->service->create( $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_invalid_status_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'status' => 'live' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_create_short_name_returns_too_short(): void {
		$result = $this->service->create( $this->valid_input( [ 'name' => 'A' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_short', $codes );
	}

	public function test_create_empty_name_returns_required(): void {
		$result = $this->service->create( $this->valid_input( [ 'name' => '   ' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_201_char_name_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'name' => str_repeat( 'a', 201 ) ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'too_long', $codes );
	}

	public function test_create_with_empty_family_key_succeeds(): void {
		$result = $this->service->create( $this->valid_input( [ 'family_key' => '' ] ) );
		$this->assertTrue( $result['ok'] );
		$this->assertNull( $result['record']['family_key'] );
	}

	public function test_create_with_invalid_family_key_format_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'family_key' => 'AB' ] ) );
		$this->assertFalse( $result['ok'] );
		$fields = array_column( $result['errors'], 'field' );
		$this->assertContains( 'family_key', $fields );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->valid_input() );
		$id      = $created['id'];
		$hash    = $created['record']['version_hash'];

		$result = $this->service->update(
			$id,
			$this->valid_input( [ 'name' => 'Renamed', 'status' => 'published' ] ),
			$hash
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Renamed', $result['record']['name'] );
		$this->assertSame( 'published', $result['record']['status'] );
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

	public function test_update_template_key_is_immutable(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'template_key' => 'something_else', 'name' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		// Update succeeds but template_key stays the same.
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'markise_motorisert', $result['record']['template_key'] );
	}

	public function test_update_unknown_id_returns_not_found(): void {
		$result = $this->service->update( 9999, $this->valid_input(), 'whatever' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_soft_delete_marks_archived(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->soft_delete( $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'archived', $this->repo->find_by_id( $created['id'] )['status'] );
	}

	public function test_soft_delete_unknown_returns_not_found(): void {
		$result = $this->service->soft_delete( 9999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}
}
