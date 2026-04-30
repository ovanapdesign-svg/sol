<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Import\Parser;
use ConfigKit\Import\Runner;
use ConfigKit\Import\Validator;
use ConfigKit\Repository\ImportBatchRepository;
use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Tests\Unit\Service\StubLookupCellRepository;
use ConfigKit\Tests\Unit\Service\StubLookupTableRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase {

	private StubImportBatchRepository $batches;
	private StubImportRowRepository $rows;
	private StubLookupCellRepository $cells;
	private StubLookupTableRepository $tables;
	private Runner $runner;
	private string $tmp_file;

	protected function setUp(): void {
		$this->batches = new StubImportBatchRepository();
		$this->rows    = new StubImportRowRepository();
		$this->cells   = new StubLookupCellRepository();
		$this->tables  = new StubLookupTableRepository();

		$this->tables->records[1] = [
			'id'                   => 1,
			'lookup_table_key'     => 'markise_v1',
			'name'                 => 'Markise',
			'is_active'            => true,
			'supports_price_group' => true,
		];

		$this->runner = new Runner(
			new \wpdb(),
			$this->batches,
			$this->rows,
			$this->cells,
			$this->tables,
			new Parser(),
			new Validator( $this->tables, $this->cells )
		);

		// Build a small grid file once for the suite.
		$this->tmp_file = $this->build_grid_file();
	}

	protected function tearDown(): void {
		if ( $this->tmp_file !== '' && file_exists( $this->tmp_file ) ) {
			@unlink( $this->tmp_file );
		}
	}

	private function build_grid_file(): string {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->setTitle( 'A' );
		$sheet->fromArray( [
			[ null, 1000, 1500 ],
			[ 1000, 4990, 5490 ],
			[ 1500, 5990, 6490 ],
		] );
		$file   = tempnam( sys_get_temp_dir(), 'configkit-test-' );
		$writer = IOFactory::createWriter( $book, 'Xlsx' );
		$writer->save( $file );
		return $file;
	}

	private function create_batch( string $mode = Runner::MODE_INSERT_UPDATE ): array {
		return $this->runner->create( [
			'import_type'             => 'lookup_cells',
			'filename'                => 'grid.xlsx',
			'file_path'               => $this->tmp_file,
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => $mode,
		] );
	}

	public function test_create_then_parse_transitions_to_validated(): void {
		$created = $this->create_batch();
		$result  = $this->runner->parse( $created['batch_id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'A', $result['format'] );
		$this->assertSame( ImportBatchRepository::STATE_VALIDATED, $result['batch']['status'] );
		$this->assertSame( 4, $result['counts']['total'] );
		$this->assertSame( 4, $result['counts']['green'] );
		$this->assertSame( 4, $result['counts']['insert'] );
	}

	public function test_commit_inserts_new_cells_then_marks_applied(): void {
		$created = $this->create_batch();
		$this->runner->parse( $created['batch_id'] );
		$result = $this->runner->commit( $created['batch_id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( ImportBatchRepository::STATE_APPLIED, $result['batch']['status'] );
		$this->assertSame( 4, $result['summary']['inserted'] );
		$this->assertSame( 0, $result['summary']['updated'] );
		$this->assertCount( 4, $this->cells->records );
	}

	public function test_idempotent_re_import_updates_existing_cells(): void {
		// First import — inserts 4.
		$first = $this->create_batch();
		$this->runner->parse( $first['batch_id'] );
		$this->runner->commit( $first['batch_id'] );
		$this->assertCount( 4, $this->cells->records );

		// Same file, fresh batch — every row already exists → 4 updates.
		$second = $this->create_batch();
		$this->runner->parse( $second['batch_id'] );
		$result = $this->runner->commit( $second['batch_id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['summary']['inserted'] );
		$this->assertSame( 4, $result['summary']['updated'] );
		$this->assertCount( 4, $this->cells->records, 'cell count must not grow on re-import' );
	}

	public function test_replace_all_mode_wipes_unrelated_cells_first(): void {
		// Pre-seed an unrelated cell that the file does NOT redeclare.
		$this->cells->create( [
			'lookup_table_key' => 'markise_v1',
			'width'            => 2000,
			'height'           => 2000,
			'price_group_key'  => 'a',
			'price'            => 7990.0,
		] );
		$created = $this->create_batch( Runner::MODE_REPLACE_ALL );
		$this->runner->parse( $created['batch_id'] );
		$this->runner->commit( $created['batch_id'] );

		// Original 2000x2000 cell must be gone; only the 4 file rows
		// remain.
		$this->assertCount( 4, $this->cells->records );
		$found = $this->cells->find_by_coordinates( 'markise_v1', 2000, 2000, 'a' );
		$this->assertNull( $found );
	}

	public function test_cancel_marks_batch_cancelled(): void {
		$created = $this->create_batch();
		$result  = $this->runner->cancel( $created['batch_id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( ImportBatchRepository::STATE_CANCELLED, $result['batch']['status'] );
	}

	public function test_commit_refuses_when_not_validated(): void {
		$created = $this->create_batch();
		// Skip parse — batch is in 'received' state.
		$result = $this->runner->commit( $created['batch_id'] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'validated', $result['error'] ?? '' );
	}

	public function test_red_rows_do_not_insert_cells(): void {
		// Build a file with one bad row (negative price).
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'lookup_table_key', 'width_mm', 'height_mm', 'price_group_key', 'price' ],
			[ 'markise_v1', 1000, 1000, 'a', 4990 ],
			[ 'markise_v1', 1500, 1000, 'a', -100 ],
		] );
		$file = tempnam( sys_get_temp_dir(), 'configkit-test-' );
		IOFactory::createWriter( $book, 'Xlsx' )->save( $file );

		$created = $this->runner->create( [
			'import_type'             => 'lookup_cells',
			'filename'                => 'mixed.xlsx',
			'file_path'               => $file,
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => Runner::MODE_INSERT_UPDATE,
		] );
		$this->runner->parse( $created['batch_id'] );
		$result = $this->runner->commit( $created['batch_id'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['summary']['inserted'] );
		$this->assertSame( 1, $result['summary']['skipped'] );
		@unlink( $file );
	}

	public function test_parse_failure_marks_batch_failed(): void {
		$created = $this->runner->create( [
			'import_type'             => 'lookup_cells',
			'filename'                => 'missing.xlsx',
			'file_path'               => sys_get_temp_dir() . '/configkit-no-such-file-' . uniqid(),
			'target_lookup_table_key' => 'markise_v1',
			'mode'                    => Runner::MODE_INSERT_UPDATE,
		] );
		$result = $this->runner->parse( $created['batch_id'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( ImportBatchRepository::STATE_FAILED, $result['batch']['status'] );
	}
}
