<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\LookupCellService;
use ConfigKit\Service\LookupTableService;
use PHPUnit\Framework\TestCase;

final class LookupCellServiceTest extends TestCase {

	private StubLookupTableRepository $tables;
	private StubLookupCellRepository $cells;
	private LookupCellService $service;
	private int $table_id;
	private int $pg_table_id;

	protected function setUp(): void {
		$this->tables  = new StubLookupTableRepository();
		$this->cells   = new StubLookupCellRepository();
		$this->service = new LookupCellService( $this->cells, $this->tables );

		// Seed a 2D table and a 3D (price-group) table via LookupTableService
		// so the canonical record shape is used.
		$tableSvc = new LookupTableService( $this->tables, $this->cells );
		$created  = $tableSvc->create( [
			'lookup_table_key'     => 'markise_2d_v1',
			'name'                 => 'Markise 2D',
			'unit'                 => 'mm',
			'match_mode'           => 'round_up',
			'supports_price_group' => false,
			'is_active'            => true,
		] );
		$this->table_id = $created['id'];

		$pg = $tableSvc->create( [
			'lookup_table_key'     => 'markise_3d_v1',
			'name'                 => 'Markise 3D',
			'unit'                 => 'mm',
			'match_mode'           => 'exact',
			'supports_price_group' => true,
			'is_active'            => true,
		] );
		$this->pg_table_id = $pg['id'];
	}

	public function test_create_cell_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900.0,
		] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 4000, $result['record']['width'] );
		$this->assertSame( 8900.0, $result['record']['price'] );
		$this->assertSame( '', $result['record']['price_group_key'] );
	}

	public function test_create_cell_in_unknown_table_returns_table_not_found(): void {
		$result = $this->service->create( 9999, [
			'width' => 4000, 'height' => 3000, 'price' => 1000,
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'table_not_found', $result['errors'][0]['code'] );
	}

	public function test_create_cell_with_zero_width_is_rejected(): void {
		$result = $this->service->create( $this->table_id, [
			'width' => 0, 'height' => 3000, 'price' => 1000,
		] );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_dimension', $codes );
	}

	public function test_create_cell_with_negative_price_is_rejected(): void {
		$result = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => -10,
		] );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'negative_price', $codes );
	}

	public function test_create_cell_without_price_returns_required(): void {
		$result = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000,
		] );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_cell_with_price_group_in_2d_table_is_rejected(): void {
		$result = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900, 'price_group_key' => 'II',
		] );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'unsupported_price_group', $codes );
	}

	public function test_create_duplicate_cell_is_rejected(): void {
		$this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900,
		] );
		$result = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 9000,
		] );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_3d_cell_with_price_group_succeeds(): void {
		$result = $this->service->create( $this->pg_table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 9900, 'price_group_key' => 'II',
		] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'II', $result['record']['price_group_key'] );
	}

	public function test_3d_cells_with_different_price_groups_are_distinct(): void {
		$first = $this->service->create( $this->pg_table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900, 'price_group_key' => 'I',
		] );
		$second = $this->service->create( $this->pg_table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 9900, 'price_group_key' => 'II',
		] );
		$this->assertTrue( $first['ok'] );
		$this->assertTrue( $second['ok'] );
	}

	public function test_update_cell_succeeds(): void {
		$created = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900,
		] );
		$result = $this->service->update( $this->table_id, $created['id'], [
			'width' => 4000, 'height' => 3000, 'price' => 9100,
		] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 9100.0, $result['record']['price'] );
	}

	public function test_update_cell_in_wrong_table_returns_not_found(): void {
		$created = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900,
		] );
		$result = $this->service->update( $this->pg_table_id, $created['id'], [
			'width' => 4000, 'height' => 3000, 'price' => 9100,
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}

	public function test_delete_cell_removes_from_storage(): void {
		$created = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900,
		] );
		$result = $this->service->delete( $this->table_id, $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertNull( $this->cells->find_by_id( $created['id'] ) );
	}

	public function test_list_for_unknown_table_returns_null(): void {
		$result = $this->service->list_for_table( 9999 );
		$this->assertNull( $result );
	}

	public function test_list_for_table_returns_only_its_cells(): void {
		$this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900,
		] );
		$this->service->create( $this->pg_table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 9900, 'price_group_key' => 'II',
		] );

		$listing = $this->service->list_for_table( $this->table_id );
		$this->assertSame( 1, $listing['total'] );
		$this->assertSame( 'markise_2d_v1', $listing['items'][0]['lookup_table_key'] );
	}

	public function test_bulk_upsert_creates_and_updates(): void {
		$first = $this->service->create( $this->table_id, [
			'width' => 4000, 'height' => 3000, 'price' => 8900,
		] );

		$result = $this->service->bulk_upsert( $this->table_id, [
			[ 'width' => 4000, 'height' => 3000, 'price' => 9100 ], // matches existing → update
			[ 'width' => 5000, 'height' => 3000, 'price' => 9700 ], // new → create
		] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['summary']['created'] );
		$this->assertSame( 1, $result['summary']['updated'] );
		$this->assertSame( 9100.0, $this->cells->find_by_id( $first['id'] )['price'] );
	}

	public function test_bulk_upsert_unknown_table_returns_table_not_found(): void {
		$result = $this->service->bulk_upsert( 9999, [
			[ 'width' => 4000, 'height' => 3000, 'price' => 8900 ],
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'table_not_found', $result['errors'][0]['code'] );
	}
}
