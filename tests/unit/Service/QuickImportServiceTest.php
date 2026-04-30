<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Import\LibraryItemParser;
use ConfigKit\Import\LibraryItemRunner;
use ConfigKit\Import\LibraryItemValidator;
use ConfigKit\Import\Parser as LookupParser;
use ConfigKit\Import\Runner as LookupRunner;
use ConfigKit\Import\Validator as LookupValidator;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Service\LibraryService;
use ConfigKit\Service\LookupTableService;
use ConfigKit\Service\QuickImportService;
use ConfigKit\Tests\Unit\Adapters\StubWooSkuResolver;
use ConfigKit\Tests\Unit\Import\StubImportBatchRepository;
use ConfigKit\Tests\Unit\Import\StubImportRowRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 dalis 4 BUG 5 — Quick import (Excel-first) end-to-end.
 *
 * Owner uploads an .xlsx and the service detects format + proposes
 * a target name, then create_and_import() makes the entity AND runs
 * the importer through to applied state in one call.
 */
final class QuickImportServiceTest extends TestCase {

	private QuickImportService $service;
	private StubLookupTableRepository $lookup_tables;
	private StubLibraryRepository $libraries;
	private StubModuleRepository $modules;
	private StubLibraryItemRepository $items;
	private StubLookupCellRepository $cells;
	/** @var list<string> */
	private array $tmp_files = [];

	protected function setUp(): void {
		$this->lookup_tables = new StubLookupTableRepository();
		$this->libraries     = new StubLibraryRepository();
		$this->modules       = new StubModuleRepository();
		$this->items         = new StubLibraryItemRepository();
		$this->cells         = new StubLookupCellRepository();
		$batches             = new StubImportBatchRepository();
		$rows                = new StubImportRowRepository();

		$this->modules->create( [
			'module_key' => 'mod_textiles', 'name' => 'Textiles',
			'allowed_field_kinds' => [], 'attribute_schema' => [],
			'is_active' => true, 'supports_sku' => true, 'supports_price' => true,
		] );

		$lookup_runner = new LookupRunner(
			new \wpdb(),
			$batches,
			$rows,
			$this->cells,
			$this->lookup_tables,
			new LookupParser(),
			new LookupValidator( $this->lookup_tables, $this->cells ),
		);
		$library_runner = new LibraryItemRunner(
			new \wpdb(),
			$batches,
			$rows,
			$this->items,
			$this->libraries,
			new LibraryItemParser(),
			new LibraryItemValidator(
				$this->libraries,
				$this->modules,
				$this->items,
				new StubWooSkuResolver(),
			),
		);

		$this->service = new QuickImportService(
			new LookupTableService( $this->lookup_tables, $this->cells ),
			new LibraryService( $this->libraries, $this->modules ),
			$this->modules,
			$this->lookup_tables,
			$this->libraries,
			$lookup_runner,
			$library_runner,
		);
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $f ) {
			if ( $f !== '' && file_exists( $f ) ) @unlink( $f );
		}
	}

	private function build_file( array $rows ): string {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( $rows );
		$file   = tempnam( sys_get_temp_dir(), 'configkit-quick-' );
		IOFactory::createWriter( $book, 'Xlsx' )->save( $file );
		$this->tmp_files[] = $file;
		return $file;
	}

	private function build_grid_file(): string {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ null, 1000, 1500 ],
			[ 1000, 4990, 5490 ],
			[ 1500, 5990, 6490 ],
		] );
		$file = tempnam( sys_get_temp_dir(), 'configkit-quick-grid-' );
		IOFactory::createWriter( $book, 'Xlsx' )->save( $file );
		$this->tmp_files[] = $file;
		return $file;
	}

	public function test_detect_grid_proposes_lookup_table_named_after_filename(): void {
		$file = $this->build_grid_file();
		$result = $this->service->detect( $file, 'Pergolas for export clients.xlsx' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'lookup_table', $result['target_type'] );
		$this->assertSame( 'Pergolas for export clients', $result['suggested_name'] );
		$this->assertSame( 'pergolas_for_export_clients', $result['suggested_key'] );
	}

	public function test_detect_long_format_c_proposes_library_named_after_library_key(): void {
		$file = $this->build_file( [
			[ 'library_key', 'item_key', 'label', 'sku' ],
			[ 'dickson_orchestra', 'u171', 'Beige',    'TX-U171' ],
			[ 'dickson_orchestra', 'u172', 'Bordeaux', 'TX-U172' ],
		] );
		$result = $this->service->detect( $file, 'Dickson Orchestra.xlsx' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'library', $result['target_type'] );
		$this->assertSame( 'dickson_orchestra', $result['suggested_key'] );
		$this->assertContains( 'mod_textiles', array_column( $result['available_modules'], 'module_key' ) );
	}

	public function test_detect_rejects_multi_library_files(): void {
		$file = $this->build_file( [
			[ 'library_key', 'item_key', 'label' ],
			[ 'lib_a', 'k1', 'A' ],
			[ 'lib_b', 'k2', 'B' ],
		] );
		$result = $this->service->detect( $file, 'mixed.xlsx' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'spans 2 libraries', $result['error'] );
	}

	public function test_detect_rejects_garbage_files(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->setCellValue( 'A1', 'hello' );
		$sheet->setCellValue( 'B1', 'world' );
		$file = tempnam( sys_get_temp_dir(), 'configkit-quick-garbage-' );
		IOFactory::createWriter( $book, 'Xlsx' )->save( $file );
		$this->tmp_files[] = $file;

		$result = $this->service->detect( $file, 'random.xlsx' );
		$this->assertFalse( $result['ok'] );
	}

	public function test_create_and_import_makes_lookup_table_and_commits_grid(): void {
		$file   = $this->build_grid_file();
		$result = $this->service->create_and_import( $file, [
			'target_type'   => 'lookup_table',
			'name'          => 'Pergolas for export clients',
			'technical_key' => 'pergolas_for_export_clients',
			'mode'          => 'insert_update',
		] );
		$this->assertTrue( $result['ok'], 'quick create failed: ' . ( $result['error'] ?? '' ) );
		$this->assertSame( 'lookup_table', $result['target_type'] );
		$this->assertNotNull( $this->lookup_tables->find_by_key( 'pergolas_for_export_clients' ) );
		// 4 grid cells from the 2x2 file.
		$this->assertGreaterThan( 0, count( $this->cells->records ) );
	}

	public function test_create_and_import_makes_library_and_imports_items(): void {
		$file = $this->build_file( [
			[ 'library_key', 'item_key', 'label', 'sku' ],
			[ 'dickson_orchestra', 'u171', 'Beige',    'TX-U171' ],
			[ 'dickson_orchestra', 'u172', 'Bordeaux', 'TX-U172' ],
		] );
		$result = $this->service->create_and_import( $file, [
			'target_type'   => 'library',
			'name'          => 'Dickson Orchestra',
			'technical_key' => 'dickson_orchestra',
			'module_key'    => 'mod_textiles',
			'mode'          => 'insert_update',
		] );
		$this->assertTrue( $result['ok'], 'quick create failed: ' . ( $result['error'] ?? '' ) );
		$this->assertSame( 'library', $result['target_type'] );
		$this->assertNotNull( $this->libraries->find_by_key( 'dickson_orchestra' ) );
		$keys = array_column( $this->items->records, 'item_key' );
		$this->assertContains( 'u171', $keys );
		$this->assertContains( 'u172', $keys );
	}

	public function test_create_library_without_module_returns_error(): void {
		$file = $this->build_file( [
			[ 'library_key', 'item_key', 'label' ],
			[ 'lib_x', 'k', 'L' ],
		] );
		$result = $this->service->create_and_import( $file, [
			'target_type'   => 'library',
			'name'          => 'Library X',
			'technical_key' => 'lib_x',
			'mode'          => 'insert_update',
		] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'module is required', $result['error'] );
	}

	public function test_create_is_idempotent_when_target_already_exists(): void {
		$file = $this->build_grid_file();
		$first = $this->service->create_and_import( $file, [
			'target_type'   => 'lookup_table',
			'name'          => 'Pergolas',
			'technical_key' => 'pergolas',
			'mode'          => 'insert_update',
		] );
		$this->assertTrue( $first['ok'] );

		// Second run with same target_key should not error on duplicate
		// table — it imports into the existing one.
		$second = $this->service->create_and_import( $file, [
			'target_type'   => 'lookup_table',
			'name'          => 'Pergolas',
			'technical_key' => 'pergolas',
			'mode'          => 'insert_update',
		] );
		$this->assertTrue( $second['ok'], 'second run failed: ' . ( $second['error'] ?? '' ) );
	}
}
