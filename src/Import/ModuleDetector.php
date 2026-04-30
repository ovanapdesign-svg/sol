<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use ConfigKit\Service\CapabilityFormSchema;

/**
 * Phase 4.2c — score Excel header rows against the active modules to
 * decide which one the file likely belongs to.
 *
 * The match ratio is `(headers we recognise) / (headers excluding the
 * universal ones library_key/item_key/label)`. The "universal"
 * headers are excluded from the denominator because they appear on
 * every Format C file regardless of module — counting them would
 * inflate every score equally and make the picker useless.
 *
 * `pick_best( modules, headers )` returns the highest-scoring module
 * that crosses the configured threshold (default 60 %), or null when
 * nothing matches confidently — in which case the wizard falls back
 * to a manual pick.
 *
 * Pure-PHP. No WP / no DB.
 */
final class ModuleDetector {

	public const DEFAULT_THRESHOLD = 0.6;

	/** Headers that don't help differentiate modules. */
	private const UNIVERSAL_HEADERS = [
		'library_key', 'item_key', 'label', 'short_label', 'description',
		'is_active', 'active', 'sort_order',
	];

	public function __construct(
		private CapabilityFormSchema $schema,
		private float $threshold = self::DEFAULT_THRESHOLD,
	) {}

	/**
	 * Score a single module against a header row.
	 *
	 * @param array<string,mixed> $module
	 * @param list<string>        $headers
	 *
	 * @return array{
	 *   matched:int,
	 *   total:int,
	 *   ratio:float,
	 *   matches:list<string>,
	 *   misses:list<string>
	 * }
	 */
	public function score( array $module, array $headers ): array {
		$normalised = $this->normalise_headers( $headers );
		$denominator = array_values( array_diff( $normalised, self::UNIVERSAL_HEADERS ) );

		$accepted = $this->accepted_keys_for_module( $module );

		$matches = [];
		$misses  = [];
		foreach ( $denominator as $h ) {
			if ( isset( $accepted[ $h ] ) ) {
				$matches[] = $h;
			} else {
				$misses[] = $h;
			}
		}
		$total = count( $denominator );
		$ratio = $total === 0 ? 0.0 : count( $matches ) / $total;

		return [
			'matched' => count( $matches ),
			'total'   => $total,
			'ratio'   => $ratio,
			'matches' => $matches,
			'misses'  => $misses,
		];
	}

	/**
	 * Pick the highest-scoring module above the threshold.
	 *
	 * @param list<array<string,mixed>> $modules
	 * @param list<string>              $headers
	 *
	 * @return array{
	 *   module:array<string,mixed>|null,
	 *   ratio:float,
	 *   matched:int,
	 *   total:int,
	 *   threshold:float,
	 *   ranked:list<array<string,mixed>>
	 * }
	 */
	public function pick_best( array $modules, array $headers ): array {
		$ranked = [];
		foreach ( $modules as $module ) {
			$score = $this->score( $module, $headers );
			$ranked[] = [
				'module_key' => (string) ( $module['module_key'] ?? '' ),
				'name'       => (string) ( $module['name'] ?? '' ),
				'matched'    => $score['matched'],
				'total'      => $score['total'],
				'ratio'      => $score['ratio'],
				'_module'    => $module,
			];
		}
		// Sort by ratio desc, then by matched desc for tie-breaking.
		usort( $ranked, static function ( $a, $b ) {
			if ( $a['ratio'] === $b['ratio'] ) return $b['matched'] <=> $a['matched'];
			return $b['ratio'] <=> $a['ratio'];
		} );

		$best = $ranked[0] ?? null;
		$pick = ( $best !== null && $best['ratio'] >= $this->threshold && $best['matched'] > 0 )
			? $best['_module']
			: null;

		// Strip the internal _module key from the public ranked list
		// so callers don't accidentally serialise the full record.
		$public_ranked = array_map( static function ( $row ) {
			unset( $row['_module'] );
			return $row;
		}, $ranked );

		return [
			'module'    => $pick,
			'ratio'     => $best['ratio'] ?? 0.0,
			'matched'   => $best['matched'] ?? 0,
			'total'     => $best['total']   ?? 0,
			'threshold' => $this->threshold,
			'ranked'    => $public_ranked,
		];
	}

	/**
	 * Build the lookup of accepted header → group for a given module.
	 *
	 * @param array<string,mixed> $module
	 * @return array<string,string>
	 */
	private function accepted_keys_for_module( array $module ): array {
		$accepted = [];
		foreach ( $this->schema->import_columns( $module ) as $col ) {
			foreach ( $col['aliases'] as $alias ) {
				$accepted[ $alias ] = $col['group'];
				// `attr.fabric_code` is also accepted as a bare alias
				// under that key — match it both ways.
				if ( str_starts_with( $alias, 'attr.' ) ) {
					$accepted[ substr( $alias, 5 ) ] = $col['group'];
				}
			}
		}
		// Universal headers are also accepted (they just don't inflate
		// the denominator).
		foreach ( self::UNIVERSAL_HEADERS as $h ) {
			$accepted[ $h ] = 'universal';
		}
		return $accepted;
	}

	/**
	 * @param list<string> $headers
	 * @return list<string>
	 */
	private function normalise_headers( array $headers ): array {
		$out = [];
		foreach ( $headers as $h ) {
			if ( ! is_string( $h ) ) continue;
			$lower = strtolower( trim( $h ) );
			if ( $lower === '' ) continue;
			// `attr.x` always normalises to bare `x` for matching.
			if ( str_starts_with( $lower, 'attr.' ) ) $lower = substr( $lower, 5 );
			$out[] = $lower;
		}
		return $out;
	}
}
