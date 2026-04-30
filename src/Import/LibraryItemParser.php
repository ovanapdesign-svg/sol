<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Phase 4 dalis 3 — Excel parser for the library-items long format
 * (IMPORT_WIZARD_SPEC §6). Reads the first sheet's header row and
 * normalises every supported column into a typed shape that the
 * validator and runner can consume without re-parsing strings.
 *
 * Required headers: library_key, item_key, label.
 * Every other column is optional; its presence is decided per row by
 * the file (not by the module's capability flags — capability
 * mismatches are flagged later as warnings, not parse errors).
 *
 * Each parsed row carries:
 *   - row_number   : 1-indexed counter for import_rows
 *   - source_sheet : sheet title (debug)
 *   - source_cell  : cell reference like "A2"
 *   - raw          : original cell values keyed by header name
 *   - normalized   : typed shape for the validator
 */
final class LibraryItemParser {

	/** Headers stored at the top of `normalized` (not in attributes). */
	private const TOP_LEVEL_FIELDS = [
		'library_key', 'item_key', 'label', 'short_label', 'description',
		'sku', 'price', 'sale_price', 'price_group_key',
		'woo_product_id', 'woo_product_sku',
		'price_source', 'item_type', 'bundle_components_json',
		'is_active', 'sort_order',
	];

	/** Headers that map into the attributes_json object. */
	private const ATTRIBUTE_FIELDS = [
		'brand', 'collection', 'color_family', 'image_url', 'main_image_url',
		'filter_tags', 'compatibility_tags',
	];

	/**
	 * @return array{
	 *   format: string,
	 *   rows: list<array<string,mixed>>,
	 *   notes: list<string>,
	 *   sheet_titles: list<string>,
	 *   columns: list<string>
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
	 * @return array{format:string, rows:list<array<string,mixed>>, notes:list<string>, sheet_titles:list<string>, columns:list<string>}
	 */
	public function parse_spreadsheet( Spreadsheet $book ): array {
		$detector = new FormatDetector();
		$format   = $detector->detect( $book );

		$titles = [];
		foreach ( $book->getAllSheets() as $sheet ) $titles[] = $sheet->getTitle();

		if ( $format !== FormatDetector::FORMAT_C ) {
			return [
				'format'       => $format,
				'rows'         => [],
				'notes'        => [ 'Library-items import expects Format C (header row with library_key, item_key, label).' ],
				'sheet_titles' => $titles,
				'columns'      => [],
			];
		}

		$sheet  = $book->getSheet( 0 );
		$header = $this->read_header_row( $sheet );
		$columns = array_values( $header );

		$rows    = [];
		$rowNum  = 0;
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

			$rows[] = [
				'row_number'   => $rowNum,
				'source_sheet' => $sheet->getTitle(),
				'source_cell'  => 'A' . $r,
				'raw'          => $raw,
				'normalized'   => $this->normalize( $raw ),
			];
		}

		return [
			'format'       => $format,
			'rows'         => $rows,
			'notes'        => [],
			'sheet_titles' => $titles,
			'columns'      => $columns,
		];
	}

	/**
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

	/**
	 * Convert raw cell values into a typed shape. Unknown columns are
	 * preserved in `unknown_columns` so the validator can warn the
	 * owner that a header doesn't match anything ConfigKit understands.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function normalize( array $raw ): array {
		$out = [
			'library_key'             => $this->to_string_or_null( $raw['library_key']     ?? null ),
			'item_key'                => $this->to_string_or_null( $raw['item_key']        ?? null ),
			'label'                   => $this->to_string_or_null( $raw['label']           ?? null ),
			'short_label'             => $this->to_string_or_null( $raw['short_label']     ?? null ),
			'description'             => $this->to_string_or_null( $raw['description']     ?? null ),
			'sku'                     => $this->to_string_or_null( $raw['sku']             ?? null ),
			'price'                   => $this->to_float( $raw['price']             ?? null ),
			'sale_price'              => $this->to_float( $raw['sale_price']        ?? null ),
			'price_group_key'         => $this->to_string_or_null( $raw['price_group_key'] ?? null ) ?? '',
			'woo_product_id'          => $this->to_int( $raw['woo_product_id']      ?? null ),
			'woo_product_sku'         => $this->to_string_or_null( $raw['woo_product_sku'] ?? null ),
			'price_source'            => $this->to_string_or_null( $raw['price_source']    ?? null ),
			'item_type'               => $this->to_string_or_null( $raw['item_type']       ?? null ),
			'bundle_components_json'  => $this->to_string_or_null( $raw['bundle_components_json'] ?? null ),
			'is_active'               => array_key_exists( 'is_active', $raw ) ? $this->to_bool( $raw['is_active'] ) : true,
			'sort_order'              => $this->to_int( $raw['sort_order']          ?? null ) ?? 0,
			// Attribute group — all module-capability-driven optional fields.
			'attributes'              => $this->extract_attributes( $raw ),
			// Bookkeeping — owner-supplied headers we don't know about.
			'unknown_columns'         => $this->extract_unknown_columns( $raw ),
		];
		return $out;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extract_attributes( array $raw ): array {
		$out = [];
		foreach ( self::ATTRIBUTE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $raw ) ) continue;
			$value = $raw[ $field ];
			if ( $value === null || $value === '' ) continue;
			if ( $field === 'filter_tags' || $field === 'compatibility_tags' ) {
				$out[ $field ] = $this->split_csv( (string) $value );
				continue;
			}
			$out[ $field ] = (string) $value;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return list<string>
	 */
	private function extract_unknown_columns( array $raw ): array {
		$known = array_merge( self::TOP_LEVEL_FIELDS, self::ATTRIBUTE_FIELDS );
		$out   = [];
		foreach ( array_keys( $raw ) as $header ) {
			if ( ! in_array( $header, $known, true ) ) $out[] = (string) $header;
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function split_csv( string $value ): array {
		$parts = array_map( 'trim', explode( ',', $value ) );
		$parts = array_values( array_filter( $parts, static fn( string $p ): bool => $p !== '' ) );
		return $parts;
	}

	private function to_string_or_null( mixed $value ): ?string {
		if ( $value === null || $value === '' ) return null;
		return (string) $value;
	}

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
