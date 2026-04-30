<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Parses an uploaded `.xlsx` into a flat list of "parsed rows" that
 * the validator + runner can chew on.
 *
 * Each parsed row carries:
 *   - row_number        : 1-indexed counter used by the import_rows table
 *   - source_sheet      : sheet title the row came from (debug)
 *   - source_cell       : Excel cell reference like "B2" (debug)
 *   - raw               : original cell values (untouched)
 *   - normalized        : { lookup_table_key?, width, height,
 *                          price_group_key, price, is_active }
 *
 * Format A (grid) expansion:
 *   - Multi-sheet → each sheet's title becomes price_group_key.
 *   - Single sheet with "Price group: X" separator rows → block split
 *     and each block carries the labelled price group.
 *   - Single sheet with no separator → empty price_group_key (the
 *     UI's pre-import choice can override).
 *
 * Format B (long) is row-by-row.
 */
final class Parser {

	/**
	 * @return array{
	 *   format: string,
	 *   rows: list<array<string,mixed>>,
	 *   notes: list<string>,
	 *   sheet_titles: list<string>
	 * }
	 */
	public function parse_file( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( 'Import file is not readable: ' . $path );
		}
		try {
			$book = IOFactory::load( $path );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Could not read Excel file: ' . $e->getMessage(), 0, $e );
		}
		return $this->parse_spreadsheet( $book );
	}

	/**
	 * @return array{format:string, rows:list<array<string,mixed>>, notes:list<string>, sheet_titles:list<string>}
	 */
	public function parse_spreadsheet( Spreadsheet $book ): array {
		$detector = new FormatDetector();
		$format   = $detector->detect( $book );

		$titles = [];
		foreach ( $book->getAllSheets() as $sheet ) $titles[] = $sheet->getTitle();

		if ( $format === FormatDetector::FORMAT_B ) {
			return [
				'format'        => $format,
				'rows'          => $this->parse_long( $book ),
				'notes'         => [],
				'sheet_titles'  => $titles,
			];
		}
		if ( $format === FormatDetector::FORMAT_A ) {
			return [
				'format'        => $format,
				'rows'          => $this->parse_grid( $book ),
				'notes'         => [],
				'sheet_titles'  => $titles,
			];
		}
		return [
			'format'        => FormatDetector::FORMAT_UNKNOWN,
			'rows'          => [],
			'notes'         => [ 'Could not auto-detect format. The first sheet does not look like a grid (Format A) or a long table (Format B).' ],
			'sheet_titles'  => $titles,
		];
	}

	// ---- Long format (B) ---------------------------------------------------

	/**
	 * @return list<array<string,mixed>>
	 */
	private function parse_long( Spreadsheet $book ): array {
		$sheet  = $book->getSheet( 0 );
		$header = $this->read_header_row( $sheet );
		if ( count( $header ) === 0 ) {
			return [];
		}
		$rows   = [];
		$rowNum = 0;

		// Walk the sheet starting at row 2.
		$highest = $sheet->getHighestDataRow();
		for ( $r = 2; $r <= $highest; $r++ ) {
			$raw = [];
			$any = false;
			foreach ( $header as $col => $name ) {
				$value = $sheet->getCell( $col . $r )->getValue();
				if ( $value !== null && $value !== '' ) $any = true;
				$raw[ $name ] = $value;
			}
			if ( ! $any ) continue;
			$rowNum++;

			$norm = [
				'lookup_table_key' => isset( $raw['lookup_table_key'] ) ? (string) $raw['lookup_table_key'] : null,
				'width'            => $this->to_int( $raw['width_mm'] ?? null ),
				'height'           => $this->to_int( $raw['height_mm'] ?? null ),
				'price_group_key'  => isset( $raw['price_group_key'] ) ? (string) $raw['price_group_key'] : '',
				'price'            => $this->to_float( $raw['price'] ?? null ),
				'is_active'        => $this->to_bool( $raw['is_active'] ?? true ),
			];

			$rows[] = [
				'row_number'   => $rowNum,
				'source_sheet' => $sheet->getTitle(),
				'source_cell'  => 'A' . $r,
				'raw'          => $raw,
				'normalized'   => $norm,
			];
		}
		return $rows;
	}

	/**
	 * Map column letter → header name (lowercased) for the long format.
	 *
	 * @return array<string,string>
	 */
	private function read_header_row( Worksheet $sheet ): array {
		$out = [];
		$col = 'A';
		while ( $col !== 'AA' ) {
			$value = $sheet->getCell( $col . '1' )->getValue();
			if ( $value === null || $value === '' ) break;
			$out[ $col ] = strtolower( trim( (string) $value ) );
			$col = $this->next_col( $col );
		}
		return $out;
	}

	// ---- Grid format (A) ---------------------------------------------------

	/**
	 * @return list<array<string,mixed>>
	 */
	private function parse_grid( Spreadsheet $book ): array {
		$rows  = [];
		$count = 0;

		$sheets = $book->getAllSheets();
		$multi  = count( $sheets ) > 1;

		foreach ( $sheets as $sheet ) {
			$blocks = $this->extract_blocks( $sheet, $multi );
			foreach ( $blocks as $block ) {
				$cells = $this->expand_block( $sheet, $block );
				foreach ( $cells as $cell ) {
					$count++;
					$cell['row_number'] = $count;
					$rows[] = $cell;
				}
			}
		}
		return $rows;
	}

	/**
	 * Locate one or more grid blocks inside a sheet. Each block is
	 * { price_group_key:string, header_row:int, end_row:int }.
	 * Header row holds widths starting at column B; column A from
	 * header_row + 1 holds heights.
	 *
	 * @return list<array{price_group_key:string,header_row:int,end_row:int}>
	 */
	private function extract_blocks( Worksheet $sheet, bool $multi_sheet ): array {
		$highest = $sheet->getHighestDataRow();
		// Collect all row indices that look like a "Price group:" marker.
		$markers = [];
		for ( $r = 1; $r <= $highest; $r++ ) {
			$a = (string) ( $sheet->getCell( 'A' . $r )->getValue() ?? '' );
			if ( stripos( trim( $a ), 'price group:' ) === 0 ) {
				$markers[] = [
					'row' => $r,
					'key' => $this->normalize_group_key( substr( trim( $a ), strlen( 'Price group:' ) ) ),
				];
			}
		}

		if ( count( $markers ) === 0 ) {
			$key = $multi_sheet ? $this->normalize_group_key( $sheet->getTitle() ) : '';
			// Find the first row that has numeric A2 / B1; that's the header row.
			$header_row = $this->detect_header_row( $sheet );
			if ( $header_row === null ) return [];
			return [ [
				'price_group_key' => $key,
				'header_row'      => $header_row,
				'end_row'         => $highest,
			] ];
		}

		$blocks = [];
		for ( $i = 0; $i < count( $markers ); $i++ ) {
			$marker     = $markers[ $i ];
			$header_row = $marker['row'] + 1;
			$end_row    = isset( $markers[ $i + 1 ] ) ? $markers[ $i + 1 ]['row'] - 1 : $highest;
			$blocks[]   = [
				'price_group_key' => $marker['key'],
				'header_row'      => $header_row,
				'end_row'         => $end_row,
			];
		}
		return $blocks;
	}

	private function detect_header_row( Worksheet $sheet ): ?int {
		$highest = $sheet->getHighestDataRow();
		for ( $r = 1; $r <= min( $highest, 5 ); $r++ ) {
			$a = $sheet->getCell( 'A' . $r )->getValue();
			$b = $sheet->getCell( 'B' . $r )->getValue();
			$a_next = $sheet->getCell( 'A' . ( $r + 1 ) )->getValue();
			// Header row: B is numeric (a width), and A on the row
			// below is numeric (a height). A on the header row
			// itself may be empty or "width \ height".
			if ( is_numeric( $b ) && is_numeric( $a_next ) ) {
				return $r;
			}
			// Tolerate the case where A1 is "width \ height" or empty.
			if ( ( $a === null || $a === '' || stripos( (string) $a, 'width' ) !== false )
				&& is_numeric( $b )
			) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Expand a grid block into per-cell rows.
	 *
	 * @param array{price_group_key:string,header_row:int,end_row:int} $block
	 * @return list<array<string,mixed>>
	 */
	private function expand_block( Worksheet $sheet, array $block ): array {
		// Read width labels along the header row.
		$widths = [];
		$col    = 'B';
		while ( $col !== 'AA' ) {
			$value = $sheet->getCell( $col . $block['header_row'] )->getValue();
			if ( $value === null || $value === '' ) break;
			if ( is_numeric( $value ) ) $widths[ $col ] = (int) round( (float) $value );
			$col = $this->next_col( $col );
		}

		$out = [];
		for ( $r = $block['header_row'] + 1; $r <= $block['end_row']; $r++ ) {
			$h_value = $sheet->getCell( 'A' . $r )->getValue();
			if ( $h_value === null || $h_value === '' ) continue;
			if ( ! is_numeric( $h_value ) ) {
				// Stop when we hit a non-numeric in column A — that's
				// either a separator we missed or a totally different
				// row.
				break;
			}
			$height = (int) round( (float) $h_value );
			foreach ( $widths as $wcol => $width ) {
				$cell  = $wcol . $r;
				$value = $sheet->getCell( $cell )->getValue();
				if ( $value === null || $value === '' ) continue;
				$out[] = [
					'source_sheet' => $sheet->getTitle(),
					'source_cell'  => $cell,
					'raw'          => [
						'width_mm'        => $width,
						'height_mm'       => $height,
						'price_group_key' => $block['price_group_key'],
						'price'           => $value,
					],
					'normalized'   => [
						'lookup_table_key' => null,
						'width'            => $width,
						'height'           => $height,
						'price_group_key'  => $block['price_group_key'],
						'price'            => $this->to_float( $value ),
						'is_active'        => true,
					],
				];
			}
		}
		return $out;
	}

	// ---- Helpers -----------------------------------------------------------

	private function to_int( mixed $value ): ?int {
		if ( $value === null || $value === '' ) return null;
		if ( is_int( $value ) ) return $value;
		if ( is_float( $value ) ) return (int) round( $value );
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) return (int) round( (float) trim( $value ) );
		return null;
	}

	private function to_float( mixed $value ): ?float {
		if ( $value === null || $value === '' ) return null;
		if ( is_int( $value ) || is_float( $value ) ) return (float) $value;
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) return (float) trim( $value );
		return null;
	}

	private function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) return $value;
		if ( $value === 1 || $value === '1' || $value === 'true' || $value === 'TRUE' || $value === 'yes' ) return true;
		if ( $value === 0 || $value === '0' || $value === 'false' || $value === 'FALSE' || $value === 'no' ) return false;
		return true;
	}

	private function normalize_group_key( string $raw ): string {
		$key = strtolower( trim( $raw ) );
		$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$key = preg_replace( '/^_+|_+$/', '', (string) $key );
		return (string) $key;
	}

	private function next_col( string $col ): string {
		$letters = str_split( $col );
		$i = count( $letters ) - 1;
		while ( $i >= 0 ) {
			if ( $letters[ $i ] !== 'Z' ) {
				$letters[ $i ] = chr( ord( $letters[ $i ] ) + 1 );
				return implode( '', $letters );
			}
			$letters[ $i ] = 'A';
			$i--;
		}
		return 'A' . implode( '', $letters );
	}
}
