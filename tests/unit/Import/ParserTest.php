<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Import;

use ConfigKit\Import\FormatDetector;
use ConfigKit\Import\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase {

	public function test_detects_format_a_grid_with_numeric_headers(): void {
		$book = $this->build_grid( [
			[ null, 1000, 1500 ],
			[ 1000, 4990, 5490 ],
			[ 1500, 5990, 6490 ],
		] );
		$detector = new FormatDetector();
		$this->assertSame( FormatDetector::FORMAT_A, $detector->detect( $book ) );
	}

	public function test_detects_format_a_with_price_group_marker(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->setCellValue( 'A1', 'Price group: A' );
		$sheet->setCellValue( 'B2', 1000 );
		$sheet->setCellValue( 'A3', 1000 );
		$detector = new FormatDetector();
		$this->assertSame( FormatDetector::FORMAT_A, $detector->detect( $book ) );
	}

	public function test_detects_format_b_long_table(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'lookup_table_key', 'width_mm', 'height_mm', 'price_group_key', 'price' ],
			[ 'markise_v1', 1000, 1000, 'A', 4990 ],
		] );
		$detector = new FormatDetector();
		$this->assertSame( FormatDetector::FORMAT_B, $detector->detect( $book ) );
	}

	public function test_detect_returns_unknown_for_garbage(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->setCellValue( 'A1', 'hello' );
		$sheet->setCellValue( 'B1', 'world' );
		$detector = new FormatDetector();
		$this->assertSame( FormatDetector::FORMAT_UNKNOWN, $detector->detect( $book ) );
	}

	public function test_parses_format_a_single_sheet(): void {
		$book = $this->build_grid( [
			[ null, 1000, 1500 ],
			[ 1000, 4990, 5490 ],
			[ 1500, 5990, 6490 ],
		] );
		$parser = new Parser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( 'A', $result['format'] );
		$this->assertCount( 4, $result['rows'] );
		$first = $result['rows'][0];
		$this->assertSame( 1000, $first['normalized']['width'] );
		$this->assertSame( 1000, $first['normalized']['height'] );
		$this->assertSame( 4990.0, $first['normalized']['price'] );
		$this->assertSame( '', $first['normalized']['price_group_key'] );
	}

	public function test_parses_format_a_multi_sheet_with_price_groups(): void {
		$book = new Spreadsheet();
		// Sheet 1 = price group A
		$book->getActiveSheet()->setTitle( 'A' );
		$book->getActiveSheet()->fromArray( [
			[ null, 1000, 1500 ],
			[ 1000, 4990, 5490 ],
			[ 1500, 5990, 6490 ],
		] );
		// Sheet 2 = price group B
		$sheet_b = $book->createSheet();
		$sheet_b->setTitle( 'B' );
		$sheet_b->fromArray( [
			[ null, 1000, 1500 ],
			[ 1000, 5490, 5990 ],
			[ 1500, 6490, 6990 ],
		] );

		$parser = new Parser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( 'A', $result['format'] );
		$this->assertCount( 8, $result['rows'] );
		$pgs = array_unique( array_map( static fn( $r ) => $r['normalized']['price_group_key'], $result['rows'] ) );
		sort( $pgs );
		$this->assertSame( [ 'a', 'b' ], $pgs );
	}

	public function test_parses_format_a_with_separator_rows(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->setTitle( 'Sheet1' );
		$rows = [
			[ 'Price group: A' ],
			[ null, 1000, 1500 ],
			[ 1000, 4990, 5490 ],
			[ 1500, 5990, 6490 ],
			[ null ],
			[ 'Price group: B' ],
			[ null, 1000, 1500 ],
			[ 1000, 5490, 5990 ],
		];
		$sheet->fromArray( $rows );

		$parser = new Parser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( 'A', $result['format'] );
		$this->assertCount( 6, $result['rows'] );
		$pgs = array_unique( array_map( static fn( $r ) => $r['normalized']['price_group_key'], $result['rows'] ) );
		sort( $pgs );
		$this->assertSame( [ 'a', 'b' ], $pgs );
	}

	public function test_parses_format_b_long_table(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->fromArray( [
			[ 'lookup_table_key', 'width_mm', 'height_mm', 'price_group_key', 'price' ],
			[ 'markise_v1', 1000, 1000, 'A', 4990 ],
			[ 'markise_v1', 1500, 1000, 'A', 5490 ],
			[ 'markise_v1', 1000, 1500, 'B', 5990 ],
		] );
		$parser = new Parser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( 'B', $result['format'] );
		$this->assertCount( 3, $result['rows'] );
		$this->assertSame( 'markise_v1', $result['rows'][0]['normalized']['lookup_table_key'] );
		$this->assertSame( 'A', $result['rows'][0]['normalized']['price_group_key'] );
	}

	public function test_format_b_handles_string_numbers(): void {
		$book = new Spreadsheet();
		$book->getActiveSheet()->fromArray( [
			[ 'lookup_table_key', 'width_mm', 'height_mm', 'price' ],
			[ 'markise_v1', '1000', '1500', '5490.50' ],
		] );
		$parser = new Parser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( 1000, $result['rows'][0]['normalized']['width'] );
		$this->assertSame( 1500, $result['rows'][0]['normalized']['height'] );
		$this->assertSame( 5490.5, $result['rows'][0]['normalized']['price'] );
	}

	public function test_unknown_format_returns_empty_rows_with_note(): void {
		$book = new Spreadsheet();
		$book->getActiveSheet()->fromArray( [ [ 'hello', 'world' ], [ 'foo', 'bar' ] ] );
		$parser = new Parser();
		$result = $parser->parse_spreadsheet( $book );
		$this->assertSame( 'unknown', $result['format'] );
		$this->assertCount( 0, $result['rows'] );
		$this->assertNotEmpty( $result['notes'] );
	}

	/**
	 * @param list<list<mixed>> $rows
	 */
	private function build_grid( array $rows ): Spreadsheet {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		$sheet->setTitle( 'Sheet1' );
		$sheet->fromArray( $rows );
		return $book;
	}
}
