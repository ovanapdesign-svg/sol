<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.3b half B — pure transformer that lays a flat-path
 * overrides map onto a list of resolved sections (the output of
 * SetupSourceResolver's "base" step). No DB, no wpdb, no WordPress
 * calls — safe to unit-test directly and safe to call from any
 * service.
 *
 * Path scheme (locked by owner in Half A direction):
 *   - lookup_table_key                                  → string
 *   - price_overrides.{type}.{pos}.{item_key}.price     → number
 *   - hidden_options.{type}.{pos}                       → list<string>
 *   - default_values.{type}.{pos}.{field}               → mixed
 *   - min_dimensions.{type}.{pos}.{field}               → mixed
 *   - max_dimensions.{type}.{pos}.{field}               → mixed
 *
 * Sections are walked in their existing (type, type_position) order.
 * For each section we scan overrides whose dotted prefix matches
 * `{type}.{position}.*` and stamp:
 *   section.overridden_paths   list<string>  the matching paths
 *   section.option_overrides   array<itemKey,{price?,is_hidden?}>
 *   section.section_overrides  array<string,mixed>  (defaults / dims)
 *
 * The applier does NOT read library items or lookup cells — those
 * stay shared and are read at the option / range list endpoints.
 * It just records what an override touches; the caller (UI or save
 * path) decides whether to display the override or write through it.
 *
 * Orphan overrides (paths whose section is missing — section deleted
 * from preset, or owner edited preset and dropped a section) are
 * collected separately so the caller can warn the owner; they remain
 * in storage so re-adding the section recovers the override.
 */
final class OverrideApplier {

	/**
	 * @param list<array<string,mixed>>  $sections  base sections from SetupSourceResolver
	 * @param array<string,mixed>        $overrides flat-path map (see class docblock)
	 *
	 * @return array{
	 *   sections:list<array<string,mixed>>,
	 *   orphan_paths:list<string>,
	 *   global:array<string,mixed>
	 * }
	 */
	public function apply( array $sections, array $overrides ): array {
		// Index sections by (type, type_position) so we can route
		// per-section override paths quickly.
		$by_addr = [];
		foreach ( $sections as $i => $section ) {
			$type = (string) ( $section['type'] ?? '' );
			$pos  = isset( $section['type_position'] ) ? (int) $section['type_position'] : 0;
			$by_addr[ $type . '.' . $pos ] = $i;
			// Make sure every section gets the override metadata fields
			// so the UI can rely on them existing.
			$sections[ $i ]['overridden_paths']   = [];
			$sections[ $i ]['option_overrides']   = [];
			$sections[ $i ]['section_overrides'] = [];
		}

		$global = [];
		$orphan = [];

		foreach ( $overrides as $path => $value ) {
			if ( ! is_string( $path ) || $path === '' ) continue;

			// Top-level (global) keys.
			if ( strpos( $path, '.' ) === false ) {
				$global[ $path ] = $value;
				continue;
			}

			$parts  = explode( '.', $path );
			$bucket = $parts[0];
			$type   = $parts[1] ?? '';
			$pos    = isset( $parts[2] ) ? (int) $parts[2] : 0;
			$addr   = $type . '.' . $pos;

			if ( ! isset( $by_addr[ $addr ] ) ) {
				$orphan[] = $path;
				continue;
			}
			$idx = $by_addr[ $addr ];
			$sections[ $idx ]['overridden_paths'][] = $path;

			switch ( $bucket ) {
				case 'price_overrides':
					$item_key = $parts[3] ?? '';
					$field    = $parts[4] ?? 'price';
					if ( $item_key === '' ) { $orphan[] = $path; break; }
					if ( ! isset( $sections[ $idx ]['option_overrides'][ $item_key ] ) ) {
						$sections[ $idx ]['option_overrides'][ $item_key ] = [];
					}
					$sections[ $idx ]['option_overrides'][ $item_key ][ $field ] = $value;
					break;

				case 'hidden_options':
					// Whole-list shape: value is a list of item_keys
					// to hide. Every key gets its own option_overrides
					// entry so the UI can render a per-row indicator.
					if ( is_array( $value ) ) {
						foreach ( $value as $item_key ) {
							if ( ! is_string( $item_key ) || $item_key === '' ) continue;
							if ( ! isset( $sections[ $idx ]['option_overrides'][ $item_key ] ) ) {
								$sections[ $idx ]['option_overrides'][ $item_key ] = [];
							}
							$sections[ $idx ]['option_overrides'][ $item_key ]['is_hidden'] = true;
						}
					}
					break;

				case 'default_values':
				case 'min_dimensions':
				case 'max_dimensions':
					$field = $parts[3] ?? '';
					if ( $field === '' ) { $orphan[] = $path; break; }
					if ( ! isset( $sections[ $idx ]['section_overrides'][ $bucket ] ) ) {
						$sections[ $idx ]['section_overrides'][ $bucket ] = [];
					}
					$sections[ $idx ]['section_overrides'][ $bucket ][ $field ] = $value;
					break;

				default:
					// Unknown bucket — leave the override stamped on
					// overridden_paths so diagnostics surfaces it but
					// don't try to interpret the value.
					break;
			}
		}

		return [
			'sections'     => array_values( $sections ),
			'orphan_paths' => $orphan,
			'global'       => $global,
		];
	}

	/**
	 * Convenience for the UI: any path that matches `*.{type}.{pos}.*`
	 * means the section is overridden. Returns the count so the
	 * caller can decide between Shared / Overridden / Local labels.
	 *
	 * @param array<string,mixed> $overrides
	 */
	public function count_overrides_for( array $overrides, string $type, int $position ): int {
		$prefix = '.' . $type . '.' . $position . '.';
		$total  = 0;
		foreach ( array_keys( $overrides ) as $path ) {
			if ( ! is_string( $path ) ) continue;
			if ( strpos( '.' . $path . '.', $prefix ) !== false ) $total++;
		}
		return $total;
	}
}
