<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\BulkPasteParser;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.4 — bulk paste textarea parser. Owners drop tab-separated
 * rows from Excel and expect the columns to land in the right
 * places. Parser supports tab, multi-space, and comma delimiters
 * because owners sometimes paste from formatted email or shared docs.
 */
final class BulkPasteParserTest extends TestCase {

	private BulkPasteParser $parser;

	protected function setUp(): void {
		$this->parser = new BulkPasteParser();
	}

	public function test_tab_separated_excel_paste(): void {
		$columns = [ 'sku', 'label', 'brand', 'collection' ];
		$result  = $this->parser->parse(
			"U171\tBeige Sand\tDickson\tOrchestra\nU172\tGrey Stone\tDickson\tOrchestra",
			$columns
		);
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( 'U171',     $result['rows'][0]['sku'] );
		$this->assertSame( 'Beige Sand', $result['rows'][0]['label'] );
		$this->assertSame( 'Orchestra', $result['rows'][1]['collection'] );
		$this->assertSame( [], $result['errors'] );
	}

	public function test_comma_separated_fallback(): void {
		$result = $this->parser->parse( "U171,Beige", [ 'sku', 'label' ] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'U171', $result['rows'][0]['sku'] );
	}

	public function test_blank_lines_skipped(): void {
		$result = $this->parser->parse( "U171\tBeige\n\n\nU172\tGrey", [ 'sku', 'label' ] );
		$this->assertCount( 2, $result['rows'] );
	}

	public function test_too_few_values_records_per_row_error(): void {
		$result = $this->parser->parse(
			"U171\tBeige\nU172",
			[ 'sku', 'label', 'brand' ]
		);
		$this->assertCount( 1, $result['rows'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Row 2', $result['errors'][0] );
	}

	public function test_extra_columns_dropped(): void {
		$result = $this->parser->parse( "U171\tBeige\tDickson\textra1\textra2", [ 'sku', 'label', 'brand' ] );
		$this->assertSame( 'U171',    $result['rows'][0]['sku'] );
		$this->assertSame( 'Dickson', $result['rows'][0]['brand'] );
		$this->assertArrayNotHasKey( 'extra1', $result['rows'][0] );
	}
}
