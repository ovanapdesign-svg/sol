<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Import\Validator;
use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Tests\Unit\Service\StubLookupCellRepository;
use ConfigKit\Tests\Unit\Service\StubLookupTableRepository;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

	private StubLookupTableRepository $tables;
	private StubLookupCellRepository $cells;
	private Validator $validator;

	protected function setUp(): void {
		$this->tables = new StubLookupTableRepository();
		$this->cells  = new StubLookupCellRepository();
		$this->validator = new Validator( $this->tables, $this->cells );

		$this->tables->records[1] = [
			'id'                   => 1,
			'lookup_table_key'     => 'markise_v1',
			'name'                 => 'Markise',
			'is_active'            => true,
			'supports_price_group' => true,
		];
	}

	private function parsed_row( array $norm, int $row_number = 1 ): array {
		return [
			'row_number'   => $row_number,
			'source_sheet' => 'Sheet1',
			'source_cell'  => 'B2',
			'raw'          => $norm,
			'normalized'   => array_merge( [
				'lookup_table_key' => null,
				'width'            => null,
				'height'           => null,
				'price_group_key'  => '',
				'price'            => null,
				'is_active'        => true,
			], $norm ),
		];
	}

	public function test_clean_row_passes_with_action_insert(): void {
		$rows = [ $this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => 4990.0, 'price_group_key' => 'a' ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'green', $out[0]['severity'] );
		$this->assertSame( ImportRowRepository::ACTION_INSERT, $out[0]['action'] );
	}

	public function test_existing_cell_yields_action_update(): void {
		$this->cells->records[1] = [
			'id'               => 1,
			'lookup_table_key' => 'markise_v1',
			'width'            => 1000,
			'height'           => 1000,
			'price_group_key'  => 'a',
			'price'            => 4990.0,
		];
		$rows = [ $this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => 5490.0, 'price_group_key' => 'a' ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( ImportRowRepository::ACTION_UPDATE, $out[0]['action'] );
	}

	public function test_non_numeric_dimension_is_red(): void {
		$rows = [ $this->parsed_row( [ 'width' => null, 'height' => 1000, 'price' => 4990.0 ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'red', $out[0]['severity'] );
		$this->assertStringContainsString( 'width_mm', $out[0]['message'] );
	}

	public function test_negative_price_is_red(): void {
		$rows = [ $this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => -10.0 ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'red', $out[0]['severity'] );
	}

	public function test_oversized_dimension_is_red(): void {
		$rows = [ $this->parsed_row( [ 'width' => 200000, 'height' => 1000, 'price' => 4990.0 ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'red', $out[0]['severity'] );
	}

	public function test_invalid_price_group_key_is_red(): void {
		$rows = [ $this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => 4990.0, 'price_group_key' => 'BIG GROUP' ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'red', $out[0]['severity'] );
	}

	public function test_unknown_target_table_is_red(): void {
		$rows = [ $this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => 4990.0 ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'no_such_table',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'red', $out[0]['severity'] );
		$this->assertStringContainsString( 'no_such_table', $out[0]['message'] );
	}

	public function test_price_group_demoted_when_table_does_not_support(): void {
		$this->tables->records[1]['supports_price_group'] = false;
		$rows = [ $this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => 4990.0, 'price_group_key' => 'a' ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'yellow', $out[0]['severity'] );
		$this->assertSame( '', $out[0]['normalized']['price_group_key'] );
	}

	public function test_duplicate_within_file_demotes_earlier_row(): void {
		$rows = [
			$this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => 4990.0, 'price_group_key' => 'a' ], 1 ),
			$this->parsed_row( [ 'width' => 1000, 'height' => 1000, 'price' => 5490.0, 'price_group_key' => 'a' ], 2 ),
		];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		// Row 2 wins, row 1 is demoted to yellow + skip.
		$this->assertSame( 'yellow', $out[0]['severity'] );
		$this->assertSame( ImportRowRepository::ACTION_SKIP, $out[0]['action'] );
		$this->assertSame( 'green', $out[1]['severity'] );
		$this->assertSame( ImportRowRepository::ACTION_INSERT, $out[1]['action'] );
	}

	public function test_validator_overrides_lookup_table_key_from_context(): void {
		$rows = [ $this->parsed_row( [ 'lookup_table_key' => 'wrong_table', 'width' => 1000, 'height' => 1000, 'price' => 4990.0 ] ) ];
		$out = $this->validator->validate( $rows, [
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => 'insert_update',
		] );
		$this->assertSame( 'markise_v1', $out[0]['normalized']['lookup_table_key'] );
	}
}
