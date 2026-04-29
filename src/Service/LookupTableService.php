<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;

final class LookupTableService {

	private const KEY_PATTERN  = '/^[a-z][a-z0-9_]{0,63}$/';
	private const VALID_UNITS  = [ 'mm', 'cm', 'm' ];
	private const VALID_MODES  = [ 'exact', 'round_up', 'nearest' ];

	public function __construct(
		private LookupTableRepository $repo,
		private LookupCellRepository $cells,
	) {}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list( array $filters = [], int $page = 1, int $per_page = 100 ): array {
		return $this->repo->list( $filters, $page, $per_page );
	}

	public function get( int $id ): ?array {
		return $this->repo->find_by_id( $id );
	}

	public function get_with_stats( int $id ): ?array {
		$record = $this->repo->find_by_id( $id );
		if ( $record === null ) {
			return null;
		}
		$record['stats'] = $this->cells->stats( (string) $record['lookup_table_key'] );
		return $record;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function create( array $input ): array {
		$errors = $this->validate( $input, null );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input );
		$id        = $this->repo->create( $sanitized );
		return [ 'ok' => true, 'id' => $id, 'record' => $this->repo->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $id, array $input, string $expected_version_hash ): array {
		$existing = $this->repo->find_by_id( $id );
		if ( $existing === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Lookup table not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Lookup table was edited elsewhere. Reload and try again.' ] ] ];
		}
		$errors = $this->validate( $input, $existing );

		// Specific cross-state check: turning off supports_price_group while
		// cells still have non-empty price_group_key would orphan that data.
		if (
			array_key_exists( 'supports_price_group', $input )
			&& empty( $input['supports_price_group'] )
			&& $this->cells->has_cells_with_price_group( (string) $existing['lookup_table_key'] )
		) {
			$errors[] = [
				'field'   => 'supports_price_group',
				'code'    => 'cells_have_price_groups',
				'message' => 'Cannot disable price groups while existing cells use a non-empty price_group_key.',
			];
		}

		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}

		$sanitized = $this->sanitize( $input );
		// lookup_table_key is immutable once created.
		$sanitized['lookup_table_key'] = (string) $existing['lookup_table_key'];

		$this->repo->update( $id, $sanitized );
		return [ 'ok' => true, 'record' => $this->repo->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function soft_delete( int $id ): array {
		$existing = $this->repo->find_by_id( $id );
		if ( $existing === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Lookup table not found.' ] ] ];
		}
		$this->repo->soft_delete( $id );
		return [ 'ok' => true ];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing ): array {
		$errors = [];

		if ( $existing === null ) {
			$key = isset( $input['lookup_table_key'] ) ? (string) $input['lookup_table_key'] : '';
			if ( $key === '' ) {
				$errors[] = [ 'field' => 'lookup_table_key', 'code' => 'required', 'message' => 'lookup_table_key is required.' ];
			} elseif ( ! preg_match( self::KEY_PATTERN, $key ) ) {
				$errors[] = [
					'field'   => 'lookup_table_key',
					'code'    => 'invalid_format',
					'message' => 'lookup_table_key must be lowercase ascii starting with a letter, max 64 chars.',
				];
			} elseif ( $this->repo->key_exists( $key ) ) {
				$errors[] = [
					'field'   => 'lookup_table_key',
					'code'    => 'duplicate',
					'message' => 'A lookup table with this key already exists.',
				];
			}
		}

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		if ( $name === '' ) {
			$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => 'name is required.' ];
		} elseif ( strlen( $name ) > 255 ) {
			$errors[] = [ 'field' => 'name', 'code' => 'too_long', 'message' => 'name must be 255 characters or fewer.' ];
		}

		if ( array_key_exists( 'unit', $input ) ) {
			$unit = (string) $input['unit'];
			if ( $unit !== '' && ! in_array( $unit, self::VALID_UNITS, true ) ) {
				$errors[] = [
					'field'   => 'unit',
					'code'    => 'invalid_value',
					'message' => 'unit must be one of: mm, cm, m.',
				];
			}
		}

		if ( array_key_exists( 'match_mode', $input ) ) {
			$mode = (string) $input['match_mode'];
			if ( $mode !== '' && ! in_array( $mode, self::VALID_MODES, true ) ) {
				$errors[] = [
					'field'   => 'match_mode',
					'code'    => 'invalid_value',
					'message' => 'match_mode must be one of: exact, round_up, nearest.',
				];
			}
		}

		foreach ( [ 'width_min', 'width_max', 'height_min', 'height_max' ] as $dim ) {
			if ( ! array_key_exists( $dim, $input ) || $input[ $dim ] === null || $input[ $dim ] === '' ) {
				continue;
			}
			if ( ! is_numeric( $input[ $dim ] ) || (int) $input[ $dim ] < 0 ) {
				$errors[] = [
					'field'   => $dim,
					'code'    => 'invalid_dimension',
					'message' => sprintf( '%s must be a non-negative integer.', $dim ),
				];
			}
		}

		if (
			isset( $input['width_min'], $input['width_max'] )
			&& is_numeric( $input['width_min'] ) && is_numeric( $input['width_max'] )
			&& (int) $input['width_min'] > (int) $input['width_max']
		) {
			$errors[] = [ 'field' => 'width_max', 'code' => 'out_of_range', 'message' => 'width_max must be greater than or equal to width_min.' ];
		}
		if (
			isset( $input['height_min'], $input['height_max'] )
			&& is_numeric( $input['height_min'] ) && is_numeric( $input['height_max'] )
			&& (int) $input['height_min'] > (int) $input['height_max']
		) {
			$errors[] = [ 'field' => 'height_max', 'code' => 'out_of_range', 'message' => 'height_max must be greater than or equal to height_min.' ];
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		return [
			'lookup_table_key'     => (string) ( $input['lookup_table_key'] ?? '' ),
			'name'                 => trim( (string) ( $input['name'] ?? '' ) ),
			'family_key'           => isset( $input['family_key'] ) && $input['family_key'] !== '' ? (string) $input['family_key'] : null,
			'unit'                 => isset( $input['unit'] ) && in_array( (string) $input['unit'], self::VALID_UNITS, true )
				? (string) $input['unit']
				: 'mm',
			'supports_price_group' => ! empty( $input['supports_price_group'] ),
			'width_min'            => isset( $input['width_min'] ) && $input['width_min'] !== '' && $input['width_min'] !== null ? (int) $input['width_min'] : null,
			'width_max'            => isset( $input['width_max'] ) && $input['width_max'] !== '' && $input['width_max'] !== null ? (int) $input['width_max'] : null,
			'height_min'           => isset( $input['height_min'] ) && $input['height_min'] !== '' && $input['height_min'] !== null ? (int) $input['height_min'] : null,
			'height_max'           => isset( $input['height_max'] ) && $input['height_max'] !== '' && $input['height_max'] !== null ? (int) $input['height_max'] : null,
			'match_mode'           => isset( $input['match_mode'] ) && in_array( (string) $input['match_mode'], self::VALID_MODES, true )
				? (string) $input['match_mode']
				: 'round_up',
			'is_active'            => array_key_exists( 'is_active', $input ) ? (bool) $input['is_active'] : true,
		];
	}
}
