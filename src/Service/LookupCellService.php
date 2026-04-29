<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;

final class LookupCellService {

	public function __construct(
		private LookupCellRepository $cells,
		private LookupTableRepository $tables,
	) {}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}|null
	 */
	public function list_for_table( int $table_id, array $filters = [], int $page = 1, int $per_page = 200 ): ?array {
		$table = $this->tables->find_by_id( $table_id );
		if ( $table === null ) {
			return null;
		}
		return $this->cells->list_in_table( (string) $table['lookup_table_key'], $filters, $page, $per_page );
	}

	public function get( int $table_id, int $cell_id ): ?array {
		$table = $this->tables->find_by_id( $table_id );
		if ( $table === null ) {
			return null;
		}
		$cell = $this->cells->find_by_id( $cell_id );
		if ( $cell === null || $cell['lookup_table_key'] !== $table['lookup_table_key'] ) {
			return null;
		}
		return $cell;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function create( int $table_id, array $input ): array {
		$table = $this->tables->find_by_id( $table_id );
		if ( $table === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'table_not_found', 'message' => 'Lookup table not found.' ] ] ];
		}
		$errors = $this->validate( $input, null, $table );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input, $table );
		$id        = $this->cells->create( $sanitized );
		return [ 'ok' => true, 'id' => $id, 'record' => $this->cells->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $table_id, int $cell_id, array $input ): array {
		$table = $this->tables->find_by_id( $table_id );
		if ( $table === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'table_not_found', 'message' => 'Lookup table not found.' ] ] ];
		}
		$existing = $this->cells->find_by_id( $cell_id );
		if ( $existing === null || $existing['lookup_table_key'] !== $table['lookup_table_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Lookup cell not found.' ] ] ];
		}
		$errors = $this->validate( $input, $existing, $table );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input, $table );
		$this->cells->update( $cell_id, $sanitized );
		return [ 'ok' => true, 'record' => $this->cells->find_by_id( $cell_id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function delete( int $table_id, int $cell_id ): array {
		$table = $this->tables->find_by_id( $table_id );
		if ( $table === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'table_not_found', 'message' => 'Lookup table not found.' ] ] ];
		}
		$existing = $this->cells->find_by_id( $cell_id );
		if ( $existing === null || $existing['lookup_table_key'] !== $table['lookup_table_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Lookup cell not found.' ] ] ];
		}
		$this->cells->delete( $cell_id );
		return [ 'ok' => true ];
	}

	/**
	 * Bulk upsert. For each input cell:
	 *  - if id matches an existing cell in this table → update
	 *  - else if (width, height, price_group_key) matches an existing cell → update
	 *  - else → create
	 *
	 * @param list<array<string,mixed>> $cells_input
	 * @return array{ok:bool, summary:array<string,int>, errors:list<array<string,mixed>>}
	 */
	public function bulk_upsert( int $table_id, array $cells_input ): array {
		$table = $this->tables->find_by_id( $table_id );
		if ( $table === null ) {
			return [
				'ok'      => false,
				'summary' => [ 'created' => 0, 'updated' => 0, 'skipped' => 0 ],
				'errors'  => [ [ 'code' => 'table_not_found', 'message' => 'Lookup table not found.' ] ],
			];
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $cells_input as $index => $row ) {
			if ( ! is_array( $row ) ) {
				$skipped++;
				$errors[] = [ 'row' => $index, 'code' => 'invalid_row', 'message' => 'Row must be an object.' ];
				continue;
			}

			// Resolve "is there already a cell at this slot?" before validating,
			// so the duplicate check in validate() doesn't fire on the cell
			// we're about to update.
			$existing = null;
			if ( isset( $row['id'] ) && (int) $row['id'] > 0 ) {
				$candidate = $this->cells->find_by_id( (int) $row['id'] );
				if ( $candidate !== null && $candidate['lookup_table_key'] === $table['lookup_table_key'] ) {
					$existing = $candidate;
				}
			}
			if ( $existing === null ) {
				$probe_w  = isset( $row['width'] ) ? (int) $row['width'] : 0;
				$probe_h  = isset( $row['height'] ) ? (int) $row['height'] : 0;
				$probe_pg = ( $table['supports_price_group'] ?? false ) && isset( $row['price_group_key'] )
					? (string) $row['price_group_key']
					: '';
				if ( $probe_w > 0 && $probe_h > 0 ) {
					$probe_id = $this->find_match(
						(string) $table['lookup_table_key'],
						$probe_w,
						$probe_h,
						$probe_pg
					);
					if ( $probe_id !== null ) {
						$existing = $this->cells->find_by_id( $probe_id );
					}
				}
			}

			$row_errors = $this->validate( $row, $existing, $table );
			if ( count( $row_errors ) > 0 ) {
				$skipped++;
				foreach ( $row_errors as $e ) {
					$errors[] = array_merge( [ 'row' => $index ], $e );
				}
				continue;
			}
			$sanitized = $this->sanitize( $row, $table );

			if ( $existing !== null ) {
				$this->cells->update( (int) $existing['id'], $sanitized );
				$updated++;
			} else {
				$this->cells->create( $sanitized );
				$created++;
			}
		}

		return [
			'ok'      => count( $errors ) === 0,
			'summary' => [ 'created' => $created, 'updated' => $updated, 'skipped' => $skipped ],
			'errors'  => $errors,
		];
	}

	private function find_match( string $table_key, int $width, int $height, string $pg ): ?int {
		// Use exists_in_table just to know presence; to retrieve id we list filtered.
		if ( ! $this->cells->exists_in_table( $table_key, $width, $height, $pg ) ) {
			return null;
		}
		// Walk the (filtered) list once to find the matching id.
		$page = 1;
		do {
			$list = $this->cells->list_in_table( $table_key, [ 'price_group_key' => $pg ], $page, 500 );
			foreach ( $list['items'] as $cell ) {
				if ( (int) $cell['width'] === $width && (int) $cell['height'] === $height ) {
					return (int) $cell['id'];
				}
			}
			$page++;
		} while ( $page <= ( $list['total_pages'] ?? 0 ) );
		return null;
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @param array<string,mixed>      $table
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing, array $table ): array {
		$errors = [];

		$width = isset( $input['width'] ) ? (int) $input['width'] : 0;
		if ( $width <= 0 ) {
			$errors[] = [ 'field' => 'width', 'code' => 'invalid_dimension', 'message' => 'width must be a positive integer.' ];
		}

		$height = isset( $input['height'] ) ? (int) $input['height'] : 0;
		if ( $height <= 0 ) {
			$errors[] = [ 'field' => 'height', 'code' => 'invalid_dimension', 'message' => 'height must be a positive integer.' ];
		}

		if ( ! array_key_exists( 'price', $input ) || $input['price'] === '' || $input['price'] === null ) {
			$errors[] = [ 'field' => 'price', 'code' => 'required', 'message' => 'price is required.' ];
		} elseif ( ! is_numeric( $input['price'] ) ) {
			$errors[] = [ 'field' => 'price', 'code' => 'invalid_type', 'message' => 'price must be numeric.' ];
		} elseif ( (float) $input['price'] < 0 ) {
			$errors[] = [ 'field' => 'price', 'code' => 'negative_price', 'message' => 'price must be greater than or equal to 0.' ];
		}

		$pg = isset( $input['price_group_key'] ) ? (string) $input['price_group_key'] : '';
		if ( $pg !== '' && ! ( $table['supports_price_group'] ?? false ) ) {
			$errors[] = [
				'field'   => 'price_group_key',
				'code'    => 'unsupported_price_group',
				'message' => 'This lookup table does not support price groups.',
			];
		}

		// Uniqueness check
		if ( $width > 0 && $height > 0 ) {
			$exclude_id = isset( $existing['id'] ) ? (int) $existing['id'] : null;
			if ( $this->cells->exists_in_table(
				(string) $table['lookup_table_key'],
				$width,
				$height,
				$pg,
				$exclude_id
			) ) {
				$errors[] = [
					'code'    => 'duplicate',
					'message' => 'A cell with this (width, height, price_group_key) already exists in the table.',
				];
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $table
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input, array $table ): array {
		$pg = ( $table['supports_price_group'] ?? false ) && isset( $input['price_group_key'] )
			? (string) $input['price_group_key']
			: '';
		return [
			'lookup_table_key' => (string) $table['lookup_table_key'],
			'width'            => (int) ( $input['width'] ?? 0 ),
			'height'           => (int) ( $input['height'] ?? 0 ),
			'price_group_key'  => $pg,
			'price'            => isset( $input['price'] ) ? (float) $input['price'] : 0.0,
		];
	}
}
