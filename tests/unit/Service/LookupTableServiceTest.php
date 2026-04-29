<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\LookupTableService;
use PHPUnit\Framework\TestCase;

final class LookupTableServiceTest extends TestCase {

	private StubLookupTableRepository $tables;
	private StubLookupCellRepository $cells;
	private LookupTableService $service;

	protected function setUp(): void {
		$this->tables  = new StubLookupTableRepository();
		$this->cells   = new StubLookupCellRepository();
		$this->service = new LookupTableService( $this->tables, $this->cells );
	}

	private function valid_input( array $overrides = [] ): array {
		return array_replace(
			[
				'lookup_table_key'     => 'markise_2d_v1',
				'name'                 => 'Markise 2D v1',
				'unit'                 => 'mm',
				'match_mode'           => 'round_up',
				'supports_price_group' => false,
				'is_active'            => true,
			],
			$overrides
		);
	}

	public function test_create_with_valid_input_succeeds(): void {
		$result = $this->service->create( $this->valid_input() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'markise_2d_v1', $result['record']['lookup_table_key'] );
		$this->assertSame( 'round_up', $result['record']['match_mode'] );
	}

	public function test_create_missing_key_returns_required(): void {
		$result = $this->service->create( $this->valid_input( [ 'lookup_table_key' => '' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'required', $codes );
	}

	public function test_create_invalid_key_format_returns_format_error(): void {
		$result = $this->service->create( $this->valid_input( [ 'lookup_table_key' => 'NOT_SNAKE' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_chars', $codes );
	}

	public function test_create_duplicate_key_returns_duplicate_error(): void {
		$this->service->create( $this->valid_input() );
		$result = $this->service->create( $this->valid_input() );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'duplicate', $codes );
	}

	public function test_create_invalid_match_mode_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'match_mode' => 'something' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_create_invalid_unit_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [ 'unit' => 'meters' ] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'invalid_value', $codes );
	}

	public function test_create_with_inverted_dimension_range_is_rejected(): void {
		$result = $this->service->create( $this->valid_input( [
			'width_min' => 5000,
			'width_max' => 1000,
		] ) );
		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'out_of_range', $codes );
	}

	public function test_update_with_correct_version_hash_succeeds(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'name' => 'Renamed' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Renamed', $result['record']['name'] );
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

	public function test_update_lookup_table_key_is_immutable(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'lookup_table_key' => 'totally_different', 'name' => 'X' ] ),
			$created['record']['version_hash']
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'markise_2d_v1', $result['record']['lookup_table_key'] );
	}

	public function test_disabling_price_group_with_grouped_cells_is_rejected(): void {
		$created = $this->service->create( $this->valid_input( [ 'supports_price_group' => true ] ) );

		// Add a cell with a non-empty price_group_key
		$this->cells->create( [
			'lookup_table_key' => 'markise_2d_v1',
			'width'            => 4000,
			'height'           => 3000,
			'price_group_key'  => 'II',
			'price'            => 9900.0,
		] );

		$result = $this->service->update(
			$created['id'],
			$this->valid_input( [ 'supports_price_group' => false ] ),
			$created['record']['version_hash']
		);

		$this->assertFalse( $result['ok'] );
		$codes = array_column( $result['errors'], 'code' );
		$this->assertContains( 'cells_have_price_groups', $codes );
	}

	public function test_get_with_stats_includes_cell_stats(): void {
		$created = $this->service->create( $this->valid_input() );
		$this->cells->create( [
			'lookup_table_key' => 'markise_2d_v1',
			'width'            => 3000,
			'height'           => 2000,
			'price_group_key'  => '',
			'price'            => 5000.0,
		] );
		$this->cells->create( [
			'lookup_table_key' => 'markise_2d_v1',
			'width'            => 5000,
			'height'           => 4000,
			'price_group_key'  => '',
			'price'            => 9000.0,
		] );

		$record = $this->service->get_with_stats( $created['id'] );
		$this->assertSame( 2, $record['stats']['cells'] );
		$this->assertSame( 3000, $record['stats']['width_min'] );
		$this->assertSame( 5000, $record['stats']['width_max'] );
	}

	public function test_soft_delete_marks_inactive(): void {
		$created = $this->service->create( $this->valid_input() );
		$result  = $this->service->soft_delete( $created['id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->tables->find_by_id( $created['id'] )['is_active'] );
	}

	public function test_soft_delete_unknown_returns_not_found(): void {
		$result = $this->service->soft_delete( 9999 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['errors'][0]['code'] );
	}
}
