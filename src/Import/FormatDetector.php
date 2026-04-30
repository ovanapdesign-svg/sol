<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Decides whether an uploaded XLSX file is Format A (grid) or
 * Format B (long) per IMPORT_WIZARD_SPEC §5.2.
 *
 * Detection rules (case-insensitive):
 *   • If the first sheet's header row contains all of
 *     `lookup_table_key`, `width_mm`, `height_mm`, `price` → Format B.
 *   • If A1 contains "Price group:" or A1 is empty / "width \ height"
 *     and B1 / A2 are numeric → Format A.
 *   • Otherwise → unknown (UI asks the owner to pick).
 */
final class FormatDetector {

	public const FORMAT_A       = 'A';
	public const FORMAT_B       = 'B';
	public const FORMAT_UNKNOWN = 'unknown';

	public const REQUIRED_LONG_HEADERS = [ 'lookup_table_key', 'width_mm', 'height_mm', 'price' ];

	public function detect( Spreadsheet $book ): string {
		$first_sheet = $book->getSheet( 0 );
		$headers     = [];
		$col         = 'A';
		while ( $col !== 'AA' ) { // bound the scan to ~26 columns
			$value = $first_sheet->getCell( $col . '1' )->getValue();
			if ( $value === null || $value === '' ) {
				if ( $col === 'A' ) {
					$col = $this->next_col( $col );
					continue;
				}
				break;
			}
			$headers[] = strtolower( trim( (string) $value ) );
			$col = $this->next_col( $col );
		}

		if ( $this->headers_match_long_format( $headers ) ) {
			return self::FORMAT_B;
		}

		// Format A heuristic: if A2 is numeric (height label) and B1
		// is numeric (width label), or A1 starts with "Price group:".
		$a1 = (string) ( $first_sheet->getCell( 'A1' )->getValue() ?? '' );
		if ( stripos( trim( $a1 ), 'price group:' ) === 0 ) {
			return self::FORMAT_A;
		}
		$a2 = $first_sheet->getCell( 'A2' )->getValue();
		$b1 = $first_sheet->getCell( 'B1' )->getValue();
		if ( is_numeric( $a2 ) && is_numeric( $b1 ) ) {
			return self::FORMAT_A;
		}
		return self::FORMAT_UNKNOWN;
	}

	/**
	 * @param list<string> $headers
	 */
	private function headers_match_long_format( array $headers ): bool {
		foreach ( self::REQUIRED_LONG_HEADERS as $required ) {
			if ( ! in_array( $required, $headers, true ) ) {
				return false;
			}
		}
		return true;
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
