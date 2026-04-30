<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Import\FormatDetector;
use ConfigKit\Import\LibraryItemParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 dalis 3 — Format C parser coverage. Exercises the documented
 * shape (library_key + item_key + label) plus the optional
 * capability-driven columns the validator would later filter against
 * the module's flags.
 */
final class LibraryItemParserTest extends TestCase {

	public function test_format_c_detected_from_library_keys_in_header(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'library_key', 'item_key', 'label' ],
			[ 'lib_a', 'a1', 'Alpha' ],
		] );
		$detector = new FormatDetector();
		$this->assertSame( FormatDetector::FORMAT_C, $detector->detect( $book ) );
	}

	public function test_parser_returns_typed_rows(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'library_key', 'item_key', 'label', 'sku', 'price', 'brand', 'collection', 'color_family', 'filter_tags' ],
			[ 'dickson_orchestra', 'u171', 'Beige', 'DICK-U171', '', 'Dickson', 'Orchestra', 'beige', 'blackout' ],
			[ 'dickson_orchestra', 'u172', 'Bordeaux', 'DICK-U172', 1290, 'Dickson', 'Orchestra', 'red', 'blackout, light' ],
		] );

		$parser = new LibraryItemParser();
		$result = $parser->parse_spreadsheet( $book );

		$this->assertSame( FormatDetector::FORMAT_C, $result['format'] );
		$this->assertCount( 2, $result['rows'] );

		$row = $result['rows'][0]['normalized'];
		$this->assertSame( 'dickson_orchestra', $row['library_key'] );
		$this->assertSame( 'u171', $row['item_key'] );
		$this->assertSame( 'Beige', $row['label'] );
		$this->assertSame( 'DICK-U171', $row['sku'] );
		$this->assertNull( $row['price'] );
		$this->assertSame( 'Dickson', $row['attributes']['brand'] );
		$this->assertSame( 'Orchestra', $row['attributes']['collection'] );
		$this->assertSame( 'beige', $row['attributes']['color_family'] );
		$this->assertSame( [ 'blackout' ], $row['attributes']['filter_tags'] );

		$row2 = $result['rows'][1]['normalized'];
		$this->assertSame( 1290.0, $row2['price'] );
		$this->assertSame( [ 'blackout', 'light' ], $row2['attributes']['filter_tags'] );
	}

	public function test_parser_reports_unknown_columns_for_validator_warnings(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'library_key', 'item_key', 'label', 'unknown_extra_col' ],
			[ 'lib', 'k', 'L', 'whatever' ],
		] );

		$parser = new LibraryItemParser();
		$result = $parser->parse_spreadsheet( $book );
		$norm   = $result['rows'][0]['normalized'];
		$this->assertContains( 'unknown_extra_col', $norm['unknown_columns'] );
	}

	public function test_parser_skips_empty_rows(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'library_key', 'item_key', 'label' ],
			[ 'lib', 'a', 'A' ],
			[ '', '', '' ],
			[ 'lib', 'b', 'B' ],
		] );

		$parser = new LibraryItemParser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( 1, $result['rows'][0]['row_number'] );
		$this->assertSame( 2, $result['rows'][1]['row_number'] );
	}

	public function test_parser_returns_zero_rows_for_non_format_c(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'lookup_table_key', 'width_mm', 'height_mm', 'price' ],
			[ 'tbl', 1000, 1000, 4990 ],
		] );

		$parser = new LibraryItemParser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( FormatDetector::FORMAT_B, $result['format'] );
		$this->assertCount( 0, $result['rows'] );
		$this->assertNotEmpty( $result['notes'] );
	}

	public function test_parser_records_columns_seen(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'library_key', 'item_key', 'label', 'sku', 'price' ],
			[ 'lib', 'k', 'L', 's', 100 ],
		] );
		$parser = new LibraryItemParser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( [ 'library_key', 'item_key', 'label', 'sku', 'price' ], $result['columns'] );
	}
}
