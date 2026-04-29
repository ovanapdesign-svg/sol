<?php
declare(strict_types=1);

namespace ConfigKit\Engines;

/**
 * Lookup Engine — pure-PHP, no WP dependencies.
 *
 * Matches a (width, height, price_group_key) request against a pre-loaded
 * set of cells using one of three match strategies. The engine never
 * queries the database; the caller supplies cells.
 *
 * See PRICING_CONTRACT.md §7 / §8 (DRAFT v1).
 */
final class LookupEngine {

	public const MODE_EXACT    = 'exact';
	public const MODE_ROUND_UP = 'round_up';
	public const MODE_NEAREST  = 'nearest';

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function match( array $input ): array {
		$lookup_table_key = (string) ( $input['lookup_table_key'] ?? '' );
		$width            = (int) ( $input['width'] ?? 0 );
		$height           = (int) ( $input['height'] ?? 0 );
		$supports_pg      = (bool) ( $input['supports_price_group'] ?? false );
		$requested_pg     = (string) ( $input['price_group_key'] ?? '' );
		$effective_pg     = $supports_pg ? $requested_pg : '';
		$match_mode       = (string) ( $input['match_mode'] ?? self::MODE_ROUND_UP );

		/** @var list<array<string,mixed>> $cells */
		$cells = $input['cells'] ?? [];

		$requested = [
			'lookup_table_key' => $lookup_table_key,
			'width'            => $width,
			'height'           => $height,
			'price_group_key'  => $effective_pg,
		];

		if ( count( $cells ) === 0 ) {
			return $this->no_match( 'no_cell', $requested );
		}

		$candidates = array_values( array_filter(
			$cells,
			static fn( array $cell ): bool =>
				(string) ( $cell['price_group_key'] ?? '' ) === $effective_pg
		) );

		if ( count( $candidates ) === 0 ) {
			return $this->no_match( 'no_cell', $requested );
		}

		switch ( $match_mode ) {
			case self::MODE_EXACT:
				return $this->match_exact( $candidates, $width, $height, $requested );
			case self::MODE_NEAREST:
				return $this->match_nearest( $candidates, $width, $height, $requested );
			case self::MODE_ROUND_UP:
			default:
				return $this->match_round_up( $candidates, $width, $height, $requested );
		}
	}

	/**
	 * @param list<array<string,mixed>> $candidates
	 * @param array<string,mixed>       $requested
	 * @return array<string,mixed>
	 */
	private function match_exact( array $candidates, int $width, int $height, array $requested ): array {
		foreach ( $candidates as $cell ) {
			if ( (int) ( $cell['width'] ?? 0 ) === $width && (int) ( $cell['height'] ?? 0 ) === $height ) {
				return $this->matched( $cell, self::MODE_EXACT, $requested );
			}
		}
		return $this->no_match( 'no_cell', $requested );
	}

	/**
	 * @param list<array<string,mixed>> $candidates
	 * @param array<string,mixed>       $requested
	 * @return array<string,mixed>
	 */
	private function match_round_up( array $candidates, int $width, int $height, array $requested ): array {
		$best = null;
		foreach ( $candidates as $cell ) {
			$cw = (int) ( $cell['width'] ?? 0 );
			$ch = (int) ( $cell['height'] ?? 0 );
			if ( $cw < $width || $ch < $height ) {
				continue;
			}
			if ( $best === null ) {
				$best = $cell;
				continue;
			}
			$bw = (int) ( $best['width'] ?? 0 );
			$bh = (int) ( $best['height'] ?? 0 );
			if ( $cw < $bw || ( $cw === $bw && $ch < $bh ) ) {
				$best = $cell;
			}
		}
		if ( $best === null ) {
			return $this->no_match( 'exceeds_max_dimensions', $requested );
		}
		return $this->matched( $best, self::MODE_ROUND_UP, $requested );
	}

	/**
	 * @param list<array<string,mixed>> $candidates
	 * @param array<string,mixed>       $requested
	 * @return array<string,mixed>
	 */
	private function match_nearest( array $candidates, int $width, int $height, array $requested ): array {
		$best          = null;
		$best_distance = PHP_INT_MAX;
		foreach ( $candidates as $cell ) {
			$dw = (int) ( $cell['width'] ?? 0 ) - $width;
			$dh = (int) ( $cell['height'] ?? 0 ) - $height;
			$d  = $dw * $dw + $dh * $dh;
			if ( $d < $best_distance ) {
				$best_distance = $d;
				$best          = $cell;
			}
		}
		if ( $best === null ) {
			return $this->no_match( 'no_cell', $requested );
		}
		return $this->matched( $best, self::MODE_NEAREST, $requested );
	}

	/**
	 * @param array<string,mixed> $cell
	 * @param array<string,mixed> $requested
	 * @return array<string,mixed>
	 */
	private function matched( array $cell, string $strategy, array $requested ): array {
		return [
			'matched'         => true,
			'cell'            => [
				'width'           => (int) ( $cell['width'] ?? 0 ),
				'height'          => (int) ( $cell['height'] ?? 0 ),
				'price_group_key' => (string) ( $cell['price_group_key'] ?? '' ),
				'price'           => (float) ( $cell['price'] ?? 0 ),
			],
			'price'           => (float) ( $cell['price'] ?? 0 ),
			'match_strategy'  => $strategy,
			'requested'       => $requested,
		];
	}

	/**
	 * @param array<string,mixed> $requested
	 * @return array<string,mixed>
	 */
	private function no_match( string $reason, array $requested ): array {
		return [
			'matched'   => false,
			'reason'    => $reason,
			'requested' => $requested,
		];
	}
}
