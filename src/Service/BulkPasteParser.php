<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.4 — owner-facing bulk paste parser.
 *
 * Owners drop tab-separated rows from Excel into a textarea on the
 * Configurator Builder modal. This helper splits the text and maps
 * positional values into a flat row shape per the section type's
 * `bulk_paste_columns` hint.
 *
 * Format rules:
 *   - One owner row per line; blank lines are skipped.
 *   - Columns separated by tab, two-or-more spaces, or comma — the
 *     tabs-from-Excel path is the primary case; the others are
 *     fallbacks for owners pasting from formatted email or shared
 *     docs.
 *   - Quoted fields are NOT supported — Excel paste always emits
 *     tabs without quoting.
 *   - Per-row errors are returned with 1-based row numbers so the
 *     editor can highlight the offending line.
 */
final class BulkPasteParser {

	/**
	 * @param list<string> $columns  positional column names; values
	 *                               beyond the column list are
	 *                               dropped (owner pasted extra
	 *                               cells we don't care about).
	 *
	 * @return array{rows:list<array<string,string>>,errors:list<string>}
	 */
	public function parse( string $text, array $columns ): array {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $text ) ) ?: [];
		$rows  = [];
		$errors = [];
		foreach ( $lines as $i => $raw ) {
			$line = trim( $raw );
			if ( $line === '' ) continue;
			$parts = preg_split( '/\t|\s{2,}|,/', $line ) ?: [];
			$parts = array_map( static fn ( $p ): string => trim( (string) $p ), $parts );
			if ( count( $parts ) < 2 ) {
				// Owners pasting partial rows almost always mean
				// "this row is broken" rather than "trailing
				// columns are blank" — flag it.
				$errors[] = sprintf(
					'Row %d: expected at least 2 values (got %d).',
					$i + 1,
					count( $parts )
				);
				continue;
			}
			$row = [];
			foreach ( $columns as $ci => $col ) {
				// Trailing optional columns are filled with empty
				// strings so owners can omit them.
				$row[ $col ] = isset( $parts[ $ci ] ) ? $parts[ $ci ] : '';
			}
			$rows[] = $row;
		}
		return [ 'rows' => $rows, 'errors' => $errors ];
	}
}
