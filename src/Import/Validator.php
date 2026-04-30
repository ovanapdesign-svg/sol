<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;

/**
 * Per-row + cross-row validation for parsed rows. Annotates each
 * parsed row with severity / message / action and returns the
 * decorated list ready for ImportRowRepository::bulk_create.
 *
 * Severity rules per IMPORT_WIZARD_SPEC §8:
 *   green  — row is valid, will insert or update on commit
 *   yellow — non-blocking warning (e.g. duplicate within file: last wins)
 *   red    — blocking error: row will be skipped on commit
 *
 * Action mapping:
 *   insert — new (target_table, width, height, price_group_key) tuple
 *   update — exists in target table
 *   skip   — red severity OR a duplicate that was overridden by a later row
 */
final class Validator {

	public const MAX_DIMENSION_MM = 100000;

	public function __construct(
		private LookupTableRepository $tables,
		private LookupCellRepository $cells,
	) {}

	/**
	 * @param list<array<string,mixed>> $parsed_rows  output of Parser::parse_file
	 * @param array{
	 *   target_lookup_table_key: string,
	 *   mode: 'insert_update'|'replace_all',
	 * } $context
	 *
	 * @return list<array<string,mixed>>  Each row gains action / severity / message / errors
	 */
	public function validate( array $parsed_rows, array $context ): array {
		$target_key = (string) $context['target_lookup_table_key'];
		$target     = $this->tables->find_by_key( $target_key );

		// Map of (width|height|price_group_key) → row_number that "won" so far.
		// Later occurrences are demoted to yellow + skip.
		$winners = [];
		foreach ( $parsed_rows as $i => $row ) {
			$norm = $row['normalized'] ?? [];
			$key  = (string) ( $norm['width'] ?? '' ) . '|' . (string) ( $norm['height'] ?? '' ) . '|' . (string) ( $norm['price_group_key'] ?? '' );
			$winners[ $key ] = $i;
		}

		$out = [];
		foreach ( $parsed_rows as $i => $row ) {
			$row     = $this->validate_row( $row, $target_key, $target );
			$norm    = $row['normalized'] ?? [];
			$key     = (string) ( $norm['width'] ?? '' ) . '|' . (string) ( $norm['height'] ?? '' ) . '|' . (string) ( $norm['price_group_key'] ?? '' );
			if ( $row['severity'] !== ImportRowRepository::SEVERITY_RED && ( $winners[ $key ] ?? -1 ) !== $i ) {
				// An earlier or later row also targets this tuple. The
				// later row wins per spec §8.2; demote losers.
				$row['severity'] = ImportRowRepository::SEVERITY_YELLOW;
				$row['action']   = ImportRowRepository::ACTION_SKIP;
				$row['message']  = trim( ( $row['message'] ?? '' ) . ' Duplicate within file — superseded by row ' . ( $winners[ $key ] + 1 ) . '.' );
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed>|null $target  the target lookup table record, or null
	 * @return array<string,mixed>
	 */
	private function validate_row( array $row, string $target_key, ?array $target ): array {
		$errors  = [];
		$warnings = [];
		$norm    = is_array( $row['normalized'] ?? null ) ? $row['normalized'] : [];

		// Always set the lookup_table_key from context — Format A never has
		// it, and Format B may but the UI's pre-select wins.
		$norm['lookup_table_key'] = $target_key;

		// Width.
		if ( $norm['width'] === null || ! is_int( $norm['width'] ) ) {
			$errors[] = [ 'field' => 'width', 'message' => 'width_mm is missing or not numeric.' ];
		} elseif ( $norm['width'] <= 0 ) {
			$errors[] = [ 'field' => 'width', 'message' => 'width_mm must be greater than 0.' ];
		} elseif ( $norm['width'] > self::MAX_DIMENSION_MM ) {
			$errors[] = [ 'field' => 'width', 'message' => 'width_mm above sane bound (' . self::MAX_DIMENSION_MM . ' mm).' ];
		}

		// Height.
		if ( $norm['height'] === null || ! is_int( $norm['height'] ) ) {
			$errors[] = [ 'field' => 'height', 'message' => 'height_mm is missing or not numeric.' ];
		} elseif ( $norm['height'] <= 0 ) {
			$errors[] = [ 'field' => 'height', 'message' => 'height_mm must be greater than 0.' ];
		} elseif ( $norm['height'] > self::MAX_DIMENSION_MM ) {
			$errors[] = [ 'field' => 'height', 'message' => 'height_mm above sane bound.' ];
		}

		// Price.
		if ( $norm['price'] === null || ! is_float( $norm['price'] ) ) {
			$errors[] = [ 'field' => 'price', 'message' => 'price is missing or not numeric.' ];
		} elseif ( $norm['price'] < 0 ) {
			$errors[] = [ 'field' => 'price', 'message' => 'price must be ≥ 0.' ];
		}

		// Price group key — snake_case if non-empty.
		$pg = (string) ( $norm['price_group_key'] ?? '' );
		if ( $pg !== '' && ! preg_match( '/^[a-z][a-z0-9_]*$/', $pg ) ) {
			$errors[] = [ 'field' => 'price_group_key', 'message' => 'price_group_key must be snake_case (lowercase, no spaces).' ];
		}

		// Cross-target sanity: target table must exist.
		if ( $target === null ) {
			$errors[] = [ 'field' => 'target', 'message' => 'Target lookup table "' . $target_key . '" does not exist.' ];
		} elseif ( $pg !== '' && empty( $target['supports_price_group'] ) ) {
			$warnings[] = [
				'field'   => 'price_group_key',
				'message' => 'Target table does not enable price groups — price_group_key will be cleared on commit.',
			];
			// We still apply the value, but the table won't accept a non-empty
			// group. Force clear.
			$norm['price_group_key'] = '';
		}

		$severity = count( $errors ) > 0
			? ImportRowRepository::SEVERITY_RED
			: ( count( $warnings ) > 0
				? ImportRowRepository::SEVERITY_YELLOW
				: ImportRowRepository::SEVERITY_GREEN );

		// Determine action when the row is otherwise valid.
		$action = ImportRowRepository::ACTION_SKIP;
		if ( $severity !== ImportRowRepository::SEVERITY_RED && $target !== null ) {
			$existing = $this->cells->find_by_coordinates(
				$target_key,
				(int) $norm['width'],
				(int) $norm['height'],
				(string) $norm['price_group_key']
			);
			$action = $existing !== null
				? ImportRowRepository::ACTION_UPDATE
				: ImportRowRepository::ACTION_INSERT;
		}

		$message_parts = [];
		foreach ( $errors as $e )   $message_parts[] = $e['message'];
		foreach ( $warnings as $w ) $message_parts[] = $w['message'];

		$row['normalized'] = $norm;
		$row['severity']   = $severity;
		$row['action']     = $action;
		$row['message']    = implode( ' ', $message_parts );
		$row['errors']     = $errors;
		$row['warnings']   = $warnings;
		$row['object_type'] = 'lookup_cell';
		$row['object_key']  = sprintf( '%s:%s:%s:%s',
			$target_key,
			(string) ( $norm['width'] ?? '' ),
			(string) ( $norm['height'] ?? '' ),
			(string) ( $norm['price_group_key'] ?? '' )
		);
		return $row;
	}
}
