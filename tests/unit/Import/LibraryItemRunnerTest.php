<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Import\LibraryItemParser;
use ConfigKit\Import\LibraryItemRunner;
use ConfigKit\Import\LibraryItemValidator;
use ConfigKit\Repository\ImportBatchRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Tests\Unit\Adapters\StubWooSkuResolver;
use ConfigKit\Tests\Unit\Service\StubLibraryItemRepository;
use ConfigKit\Tests\Unit\Service\StubLibraryRepository;
use ConfigKit\Tests\Unit\Service\StubModuleRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 dalis 3 — end-to-end coverage for the library-items runner.
 * Builds a real .xlsx file in /tmp so the parser → validator → runner
 * loop runs through PhpSpreadsheet, then asserts the on-disk repo
 * state after commit. Idempotency is verified by re-parsing +
 * re-committing the same file.
 */
final class LibraryItemRunnerTest extends TestCase {

	private StubImportBatchRepository $batches;
	private StubImportRowRepository $rows;
	private StubLibraryItemRepository $items;
	private StubLibraryRepository $libraries;
	private StubModuleRepository $modules;
	private StubWooSkuResolver $woo;
	private LibraryItemRunner $runner;
	private string $tmp_file;

	protected function setUp(): void {
		$this->batches   = new StubImportBatchRepository();
		$this->rows      = new StubImportRowRepository();
		$this->items     = new StubLibraryItemRepository();
		$this->libraries = new StubLibraryRepository();
		$this->modules   = new StubModuleRepository();
		$this->woo       = new StubWooSkuResolver();

		$this->modules->records[1] = $this->module( 'mod_textiles', [
			'supports_sku' => true, 'supports_price' => true,
			'supports_brand' => true, 'supports_collection' => true,
			'supports_color_family' => true, 'supports_filters' => true,
		] );
		$this->libraries->records[1] = [
			'id' => 1, 'library_key' => 'lib_textiles', 'module_key' => 'mod_textiles', 'is_active' => true,
		];

		$this->runner = new LibraryItemRunner(
			new \wpdb(),
			$this->batches,
			$this->rows,
			$this->items,
			$this->libraries,
			new LibraryItemParser(),
			new LibraryItemValidator(
				$this->libraries,
				$this->modules,
				$this->items,
				$this->woo,
			),
		);

		$this->tmp_file = $this->build_file( [
			[ 'library_key', 'item_key', 'label', 'sku', 'price', 'brand', 'color_family', 'filter_tags' ],
			[ 'lib_textiles', 'red_001', 'Red',    'TX-RED', 1290, 'Dickson', 'red',    'blackout' ],
			[ 'lib_textiles', 'blue_001', 'Blue',  'TX-BLU', 1390, 'Dickson', 'blue',   'light, blackout' ],
		] );
	}

	protected function tearDown(): void {
		if ( $this->tmp_file !== '' && file_exists( $this->tmp_file ) ) {
			@unlink( $this->tmp_file );
		}
	}

	private function module( string $key, array $caps ): array {
		$rec = [ 'id' => 1, 'module_key' => $key, 'name' => $key ];
		foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
			$rec[ $flag ] = ! empty( $caps[ $flag ] );
		}
		return $rec;
	}

	private function build_file( array $rows ): string {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( $rows );
		$file   = tempnam( sys_get_temp_dir(), 'configkit-libitems-' );
		IOFactory::createWriter( $book, 'Xlsx' )->save( $file );
		return $file;
	}

	private function create_batch( string $mode = LibraryItemRunner::MODE_INSERT_UPDATE, ?string $file = null ): array {
		return $this->runner->create( [
			'import_type'        => LibraryItemRunner::TYPE_LIBRARY_ITEMS,
			'filename'           => 'lib.xlsx',
			'file_path'          => $file ?? $this->tmp_file,
			'target_library_key' => 'lib_textiles',
			'mode'               => $mode,
		] );
	}

	public function test_parse_advances_batch_to_validated(): void {
		$created = $this->create_batch();
		$result  = $this->runner->parse( $created['batch_id'] );
		$this->assertTrue( $result['ok'] );
		$batch = $this->batches->find_by_id( $created['batch_id'] );
		$this->assertSame( ImportBatchRepository::STATE_VALIDATED, $batch['status'] );
	}

	public function test_commit_inserts_new_items(): void {
		$created = $this->create_batch();
		$this->runner->parse( $created['batch_id'] );
		$res = $this->runner->commit( $created['batch_id'] );
		$this->assertTrue( $res['ok'], 'commit failed: ' . ( $res['error'] ?? '' ) );
		$this->assertSame( 2, $res['summary']['inserted'] );
		$this->assertSame( 0, $res['summary']['updated'] );

		$keys = array_column( $this->items->records, 'item_key' );
		$this->assertContains( 'red_001', $keys );
		$this->assertContains( 'blue_001', $keys );
	}

	public function test_commit_is_idempotent_on_re_run(): void {
		$first = $this->create_batch();
		$this->runner->parse( $first['batch_id'] );
		$this->runner->commit( $first['batch_id'] );
		$count_after_first = count( $this->items->records );

		// Re-import same file → existing rows update, no inserts.
		$second = $this->create_batch();
		$this->runner->parse( $second['batch_id'] );
		$res = $this->runner->commit( $second['batch_id'] );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 0, $res['summary']['inserted'] );
		$this->assertSame( 2, $res['summary']['updated'] );
		$this->assertSame( $count_after_first, count( $this->items->records ) );
	}

	public function test_commit_updates_existing_items_by_item_key(): void {
		$first = $this->create_batch();
		$this->runner->parse( $first['batch_id'] );
		$this->runner->commit( $first['batch_id'] );

		// New file with the same item_keys but tweaked labels + prices.
		$updated_file = $this->build_file( [
			[ 'library_key', 'item_key', 'label', 'sku', 'price' ],
			[ 'lib_textiles', 'red_001',  'Red v2', 'TX-RED', 1500 ],
			[ 'lib_textiles', 'blue_001', 'Blue v2','TX-BLU', 1600 ],
		] );

		$created = $this->create_batch( LibraryItemRunner::MODE_INSERT_UPDATE, $updated_file );
		$this->runner->parse( $created['batch_id'] );
		$res = $this->runner->commit( $created['batch_id'] );
		@unlink( $updated_file );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 0, $res['summary']['inserted'] );
		$this->assertSame( 2, $res['summary']['updated'] );

		$row = $this->items->find_by_library_and_key( 'lib_textiles', 'red_001' );
		$this->assertNotNull( $row );
		$this->assertSame( 'Red v2', $row['label'] );
		$this->assertSame( 1500.0, $row['price'] );
	}

	public function test_replace_all_soft_deletes_existing_then_inserts(): void {
		// Seed with an item NOT in the new file so we can prove it
		// disappears (soft-deleted) on replace_all.
		$this->items->create( [
			'library_key' => 'lib_textiles', 'item_key' => 'leftover',
			'label' => 'Leftover', 'is_active' => true,
		] );

		$created = $this->create_batch( LibraryItemRunner::MODE_REPLACE_ALL );
		$this->runner->parse( $created['batch_id'] );
		$res = $this->runner->commit( $created['batch_id'] );
		$this->assertTrue( $res['ok'], 'commit failed: ' . ( $res['error'] ?? '' ) );

		// Leftover row stayed (soft-delete keeps the record), but
		// is_active should be false. New rows from the file inserted.
		$leftover = null;
		foreach ( $this->items->records as $rec ) {
			if ( $rec['item_key'] === 'leftover' ) { $leftover = $rec; break; }
		}
		$this->assertNotNull( $leftover );
		$this->assertFalse( (bool) $leftover['is_active'] );

		$active_keys = array_values( array_map(
			static fn( $r ) => $r['item_key'],
			array_filter( $this->items->records, static fn( $r ) => ! empty( $r['is_active'] ) )
		) );
		$this->assertContains( 'red_001', $active_keys );
		$this->assertContains( 'blue_001', $active_keys );
		$this->assertNotContains( 'leftover', $active_keys );
	}

	public function test_unknown_library_target_blocks_commit(): void {
		$created = $this->runner->create( [
			'import_type'        => LibraryItemRunner::TYPE_LIBRARY_ITEMS,
			'filename'           => 'lib.xlsx',
			'file_path'          => $this->tmp_file,
			'target_library_key' => 'ghost',
			'mode'               => LibraryItemRunner::MODE_INSERT_UPDATE,
		] );
		$this->runner->parse( $created['batch_id'] );
		$res = $this->runner->commit( $created['batch_id'] );
		$this->assertFalse( $res['ok'] );
	}

	public function test_woo_sku_resolves_during_parse(): void {
		// Module needs supports_woo_product_link for the resolver path.
		$this->modules->records[1] = $this->module( 'mod_textiles', [
			'supports_sku' => true, 'supports_price' => true,
			'supports_woo_product_link' => true,
		] );
		$this->woo->sku_to_id = [ 'EXT-MOTOR' => 4242 ];

		$file = $this->build_file( [
			[ 'library_key', 'item_key', 'label', 'woo_product_sku' ],
			[ 'lib_textiles', 'mtr_001', 'Motor', 'EXT-MOTOR' ],
		] );
		$created = $this->create_batch( LibraryItemRunner::MODE_INSERT_UPDATE, $file );
		$this->runner->parse( $created['batch_id'] );
		$res = $this->runner->commit( $created['batch_id'] );
		@unlink( $file );

		$this->assertTrue( $res['ok'] );
		$row = $this->items->find_by_library_and_key( 'lib_textiles', 'mtr_001' );
		$this->assertSame( 4242, $row['woo_product_id'] );
	}
}
