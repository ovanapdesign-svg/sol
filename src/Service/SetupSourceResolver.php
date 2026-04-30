<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\PresetRepository;

/**
 * Phase 4.3b half B — resolves a product's effective section list
 * given its setup_source mode. Sits behind ConfiguratorBuilderService
 * and any reader that needs the post-overrides view of a product.
 *
 * Modes:
 *   start_blank   — sections come straight from SectionListState; no
 *                   inheritance, no overrides applied.
 *   use_preset    — sections are synthesized from the linked preset
 *                   on every read, with deterministic stable ids
 *                   keyed by (productId, type, type_position) so the
 *                   UI's section_id references survive a preset
 *                   change. Overrides from the product's
 *                   overrides_json are layered on top via
 *                   OverrideApplier.
 *   link_to_setup — recursively resolve the linked source product's
 *                   effective state; this product's overrides apply
 *                   on top of that. Cycle protection via a visited
 *                   set; a detected cycle falls back to start_blank
 *                   so the editor still loads.
 *
 * Annotations layered onto each returned section:
 *   source            'shared' | 'overridden' | 'local'
 *   type_position     0-based index within type (kept on output)
 *   overridden_paths  list<string>
 *   option_overrides  array<itemKey,{price?,is_hidden?}>
 *   section_overrides array{default_values?,min_dimensions?,max_dimensions?}
 *
 * The resolver is read-only — it never mutates SectionListState or
 * SetupSourceState. ConfiguratorBuilderService and SetupSourceService
 * own the writes.
 */
final class SetupSourceResolver {

	public function __construct(
		private SetupSourceState $state,
		private SectionListState $sections,
		private PresetRepository $presets,
		private OverrideApplier $applier,
	) {}

	/**
	 * @return array{
	 *   setup_source:string,
	 *   preset_id:int,
	 *   source_product_id:int,
	 *   preset:array<string,mixed>|null,
	 *   sections:list<array<string,mixed>>,
	 *   overrides:array<string,mixed>,
	 *   global_overrides:array<string,mixed>,
	 *   orphan_paths:list<string>
	 * }
	 */
	public function resolve( int $product_id, array $visited = [] ): array {
		$visited[] = $product_id;
		$ss        = $this->state->get( $product_id );
		$mode      = $ss['setup_source'];

		// Build the base sections per mode, then apply this product's
		// overrides on top. For link_to_setup we don't double-apply
		// the source's overrides — they were already baked into the
		// recursive resolve.
		$base   = [];
		$preset = null;
		switch ( $mode ) {
			case SetupSourceState::SOURCE_PRESET:
				$preset = $this->presets->find_by_id( $ss['preset_id'] );
				if ( $preset === null || ! empty( $preset['deleted_at'] ) ) {
					// Linked preset has been removed — fall back to
					// the product's own local sections so the editor
					// still loads. Diagnostics surfaces this as an issue.
					$base = $this->local_sections_with_position( $product_id );
					$mode = SetupSourceState::SOURCE_BLANK;
					break;
				}
				$base = $this->materialize_preset_sections( $product_id, $preset );
				break;

			case SetupSourceState::SOURCE_LINK:
				$source_pid = $ss['source_product_id'];
				if ( $source_pid <= 0 || in_array( $source_pid, $visited, true ) ) {
					$base = $this->local_sections_with_position( $product_id );
					$mode = SetupSourceState::SOURCE_BLANK;
					break;
				}
				$resolved   = $this->resolve( $source_pid, $visited );
				$base       = $resolved['sections'];
				$preset     = $resolved['preset'];
				break;

			case SetupSourceState::SOURCE_BLANK:
			default:
				$base = $this->local_sections_with_position( $product_id );
				break;
		}

		$applied = $this->applier->apply( $base, $ss['overrides'] );
		$sections = $this->annotate_sources( $applied['sections'], $mode, $preset );

		return [
			'setup_source'       => $mode,
			'preset_id'          => $ss['preset_id'],
			'source_product_id'  => $ss['source_product_id'],
			'preset'             => $preset,
			'sections'           => $sections,
			'overrides'          => $ss['overrides'],
			'global_overrides'   => $applied['global'],
			'orphan_paths'       => $applied['orphan_paths'],
		];
	}

	/**
	 * Convenience pass-through for callers (e.g.,
	 * ConfiguratorBuilderService) that only need the resolved
	 * section list.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function resolve_sections( int $product_id ): array {
		return $this->resolve( $product_id )['sections'];
	}

	/**
	 * Read the product's local sections and stamp `type_position` on
	 * each so OverrideApplier paths resolve consistently no matter
	 * which storage path produced the list.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function local_sections_with_position( int $product_id ): array {
		$rows    = $this->sections->list( $product_id );
		$by_type = [];
		foreach ( $rows as $i => $row ) {
			$type = (string) ( $row['type'] ?? '' );
			if ( $type === '' ) continue;
			$by_type[ $type ] = ( $by_type[ $type ] ?? -1 ) + 1;
			$rows[ $i ]['type_position'] = $by_type[ $type ];
			if ( ! isset( $rows[ $i ]['visibility'] ) || ! is_array( $rows[ $i ]['visibility'] ) ) {
				$rows[ $i ]['visibility'] = [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ];
			}
		}
		return $rows;
	}

	/**
	 * Build the section list from a preset snapshot. Section ids are
	 * deterministic given (product_id, type, type_position, preset_id)
	 * so the Phase 4.4 UI's section_id-keyed editor URLs (e.g.
	 * /sections/{id}/options) keep pointing at the same logical
	 * section across resolves — owner-edits-preset → reload here
	 * doesn't strand the open modal.
	 *
	 * Visibility conditions in the snapshot reference the source
	 * product's hex section_ids; they are rewritten to this product's
	 * deterministic ids via an old→new map built in a first pass.
	 *
	 * @param array<string,mixed> $preset
	 * @return list<array<string,mixed>>
	 */
	private function materialize_preset_sections( int $product_id, array $preset ): array {
		$snapshot = is_array( $preset['sections'] ?? null ) ? $preset['sections'] : [];
		$preset_id = (int) ( $preset['id'] ?? 0 );

		// Pass 1 — pre-mint deterministic ids and build the rewrite map.
		$id_map  = [];
		$entries = [];
		foreach ( $snapshot as $snap ) {
			if ( ! is_array( $snap ) ) continue;
			$type = (string) ( $snap['type'] ?? '' );
			if ( $type === '' ) continue;
			$pos = isset( $snap['type_position'] ) ? (int) $snap['type_position'] : 0;
			$new_id = $this->stable_id( $product_id, $type, $pos, $preset_id );
			$old_id = (string) ( $snap['_source_section_id'] ?? '' );
			if ( $old_id !== '' ) $id_map[ $old_id ] = $new_id;
			$entries[] = [ 'snap' => $snap, 'new_id' => $new_id, 'type_position' => $pos ];
		}

		// Pass 2 — emit fully wired section records.
		$out      = [];
		$position = 0;
		foreach ( $entries as $entry ) {
			$snap = $entry['snap'];
			$row = [
				'id'            => $entry['new_id'],
				'type'          => (string) $snap['type'],
				'type_position' => $entry['type_position'],
				'label'         => (string) ( $snap['label'] ?? '' ),
				'position'      => $position++,
				'visibility'    => $this->rewrite_visibility( $snap['visibility'] ?? null, $id_map ),
				// Preset metadata travels with each section so the UI
				// can render the "Shared from {preset_name}" badge.
				'_preset_id'   => $preset_id,
				'_preset_name' => (string) ( $preset['name'] ?? '' ),
			];
			if ( ! empty( $snap['library_key'] ) )      $row['library_key']      = (string) $snap['library_key'];
			if ( ! empty( $snap['module_key'] ) )       $row['module_key']       = (string) $snap['module_key'];
			if ( ! empty( $snap['lookup_table_key'] ) ) $row['lookup_table_key'] = (string) $snap['lookup_table_key'];
			if ( isset( $snap['range_rows'] ) && is_array( $snap['range_rows'] ) ) $row['range_rows'] = $snap['range_rows'];
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param mixed $raw
	 * @param array<string,string> $id_map
	 * @return array<string,mixed>
	 */
	private function rewrite_visibility( mixed $raw, array $id_map ): array {
		if ( ! is_array( $raw ) ) return [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ];
		$mode  = ( $raw['mode']  ?? 'always' ) === 'when' ? 'when' : 'always';
		$match = ( $raw['match'] ?? 'all' )    === 'any'  ? 'any'  : 'all';
		$conditions = [];
		if ( $mode === 'when' && is_array( $raw['conditions'] ?? null ) ) {
			foreach ( $raw['conditions'] as $cond ) {
				if ( ! is_array( $cond ) ) continue;
				$old = (string) ( $cond['section_id'] ?? '' );
				if ( $old === '' || ! isset( $id_map[ $old ] ) ) continue;
				$conditions[] = [
					'section_id' => $id_map[ $old ],
					'op'         => ( $cond['op'] ?? 'equals' ) === 'not_equals' ? 'not_equals' : 'equals',
					'value'      => isset( $cond['value'] ) ? (string) $cond['value'] : '',
				];
			}
		}
		if ( $mode === 'always' ) $conditions = [];
		return [ 'mode' => $mode, 'conditions' => $conditions, 'match' => $match ];
	}

	/**
	 * Walk the resolved sections and stamp `source` (shared /
	 * overridden / local) plus, for shared sections, the preset
	 * pointer the UI uses for the badge.
	 *
	 * @param list<array<string,mixed>> $sections
	 * @param array<string,mixed>|null  $preset
	 * @return list<array<string,mixed>>
	 */
	private function annotate_sources( array $sections, string $mode, ?array $preset ): array {
		foreach ( $sections as $i => $section ) {
			$has_overrides = count( $section['overridden_paths'] ?? [] ) > 0
				|| count( $section['option_overrides'] ?? [] ) > 0
				|| count( $section['section_overrides'] ?? [] ) > 0;
			if ( $mode === SetupSourceState::SOURCE_BLANK ) {
				$sections[ $i ]['source'] = $has_overrides ? 'overridden' : 'local';
			} else {
				$sections[ $i ]['source'] = $has_overrides ? 'overridden' : 'shared';
			}
			if ( $preset !== null ) {
				$sections[ $i ]['preset_id']   = (int) ( $preset['id'] ?? 0 );
				$sections[ $i ]['preset_name'] = (string) ( $preset['name'] ?? '' );
			}
		}
		return $sections;
	}

	private function stable_id( int $product_id, string $type, int $position, int $preset_id ): string {
		$slug = preg_replace( '/[^a-z0-9]+/', '_', strtolower( $type ) ) ?? '';
		$slug = trim( $slug, '_' );
		$hash = substr( md5( $product_id . '|' . $type . '|' . $position . '|' . $preset_id ), 0, 4 );
		return 'sec_' . ( $slug !== '' ? $slug . '_' : '' ) . $hash;
	}
}
