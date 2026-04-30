<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\PresetRepository;

/**
 * Phase 4.3b half A — Configurator Presets data layer.
 *
 * A preset is a JSON snapshot of one product's section structure that
 * the owner can re-apply to other products. Half A does the entity +
 * snapshot + apply. Override resolution (price overrides, hidden
 * options, default values, max_dimension) lands in Half B and reads
 * `_configkit_overrides_json` post meta on top of the preset's
 * snapshot — Half A does NOT touch overrides.
 *
 * Library / lookup-table ownership rule (locked by owner):
 *   library_key + lookup_table_key are SHARED REFERENCES. When a
 *   preset is applied to a new product, the new product's sections
 *   point at the SAME library_key / lookup_table_key strings the
 *   source product was already using. We never duplicate library
 *   items or lookup cells. AutoManagedRegistry retains the original
 *   owner; apply-preset does NOT mark anything in the registry.
 *
 * Snapshot shape (preset.sections):
 *   list<{
 *     type:               string,       // SectionTypeRegistry id
 *     type_position:      int,          // 0-based index within type
 *     label:              string,
 *     visibility:         { mode, conditions, match },
 *     library_key?:       string,       // for library-bearing types
 *     module_key?:        string,
 *     lookup_table_key?:  string,       // for size_pricing only
 *     range_rows?:        list<map>,    // editor fallback for legacy reads
 *   }>
 *
 * Override paths in Half B will reference sections via
 * `{type}.{type_position}` — that's why we record type_position at
 * snapshot time even though the section list is already ordered.
 */
final class PresetService {

	public function __construct(
		private PresetRepository $presets,
		private SectionListState $sections,
		private ?LibraryRepository $libraries = null,
		private ?LookupTableRepository $lookup_tables = null,
		private ?SetupSourceState $setup_source = null,
	) {}

	/**
	 * Snapshot the current section structure of the supplied product
	 * into a new preset row. Returns the inserted preset id +
	 * hydrated record.
	 *
	 * @param array{name:string,description?:string,product_type?:string,created_by?:int} $meta
	 *
	 * @return array{ok:bool,message?:string,preset_id?:int,preset_key?:string,preset?:array<string,mixed>,errors?:list<array<string,mixed>>}
	 */
	public function save_as_preset( int $product_id, array $meta ): array {
		$name = isset( $meta['name'] ) ? trim( (string) $meta['name'] ) : '';
		if ( $name === '' ) {
			return [ 'ok' => false, 'message' => 'Preset name is required.' ];
		}
		$sections = $this->sections->list( $product_id );
		if ( count( $sections ) === 0 ) {
			return [ 'ok' => false, 'message' => 'This product has no sections to save as a preset.' ];
		}

		$snapshot     = $this->snapshot_sections( $sections );
		$default_tkey = $this->detect_default_lookup_table_key( $sections );
		$preset_key   = $this->mint_preset_key( $name );

		$id = $this->presets->create( [
			'preset_key'               => $preset_key,
			'name'                     => $name,
			'description'              => isset( $meta['description'] ) ? (string) $meta['description'] : null,
			'product_type'             => isset( $meta['product_type'] ) && $meta['product_type'] !== '' ? (string) $meta['product_type'] : null,
			'sections'                 => $snapshot,
			'default_lookup_table_key' => $default_tkey,
			'default_frontend_mode'    => 'stepper',
			'created_by'               => isset( $meta['created_by'] ) ? (int) $meta['created_by'] : 0,
		] );

		$preset = $this->presets->find_by_id( $id );
		return [
			'ok'         => true,
			'message'    => sprintf( 'Preset "%s" saved.', $name ),
			'preset_id'  => $id,
			'preset_key' => $preset_key,
			'preset'     => $preset,
		];
	}

	/**
	 * Apply a preset's section structure to the target product.
	 *
	 * Behavior:
	 *   - The target's existing section list is REPLACED. Underlying
	 *     entities (libraries / lookup_tables / library_items) the
	 *     target previously owned are NOT touched — Phase 4.4's
	 *     soft-detach semantics ensure history survives so a later
	 *     re-apply of the original setup recovers them.
	 *   - Each preset section gets a freshly minted local section_id;
	 *     library_key / lookup_table_key are copied verbatim from the
	 *     preset (shared reference, no duplication).
	 *   - Visibility conditions that referenced the source product's
	 *     section_ids are rewritten via the old→new id map so they
	 *     keep working in the target.
	 *
	 * @return array{ok:bool,message?:string,sections?:list<array<string,mixed>>,preset?:array<string,mixed>}
	 */
	public function apply_preset( int $product_id, int $preset_id ): array {
		$preset = $this->presets->find_by_id( $preset_id );
		if ( $preset === null || $preset['deleted_at'] !== null ) {
			return [ 'ok' => false, 'message' => 'Preset not found.' ];
		}

		// Half B: apply_preset flips the product into use_preset mode.
		// SetupSourceResolver synthesizes the effective sections from
		// preset.sections on every read, so we DO NOT clone into
		// SectionListState. Local sections are wiped so a later
		// detach (which materializes the resolved view back into the
		// local list) starts from a clean slate.
		if ( $this->setup_source !== null ) {
			foreach ( $this->sections->list( $product_id ) as $row ) {
				$id = (string) ( $row['id'] ?? '' );
				if ( $id !== '' ) $this->sections->remove( $product_id, $id );
			}
			$this->setup_source->patch( $product_id, [
				'setup_source'      => SetupSourceState::SOURCE_PRESET,
				'preset_id'         => $preset_id,
				'source_product_id' => 0,
				'overrides'         => [],
			] );
			return [
				'ok'       => true,
				'message'  => sprintf( '%d section(s) inherited from preset.', count( is_array( $preset['sections'] ?? null ) ? $preset['sections'] : [] ) ),
				'preset'   => $preset,
				'sections' => [], // resolver-driven; UI re-fetches via /configurator/{id}/sections
			];
		}

		// Legacy path (Half A test wiring without SetupSourceState):
		// behave as Half A did — clone the preset into SectionListState
		// directly so the data-layer tests written against the
		// pre-resolver model still verify the snapshot/apply contract.
		$snapshot = is_array( $preset['sections'] ?? null ) ? $preset['sections'] : [];

		$id_map = [];
		$new_rows = [];
		foreach ( $snapshot as $i => $snap ) {
			if ( ! is_array( $snap ) ) continue;
			$type = (string) ( $snap['type'] ?? '' );
			if ( $type === '' ) continue;
			$old_id = isset( $snap['_source_section_id'] ) ? (string) $snap['_source_section_id'] : '';
			$new_id = SectionListState::mint_id( $type );
			if ( $old_id !== '' ) $id_map[ $old_id ] = $new_id;
			$new_rows[ $i ] = [
				'snap'   => $snap,
				'new_id' => $new_id,
			];
		}

		$out = [];
		$position = 0;
		foreach ( $new_rows as $entry ) {
			$snap   = $entry['snap'];
			$new_id = $entry['new_id'];
			$type   = (string) $snap['type'];
			$row = [
				'id'         => $new_id,
				'type'       => $type,
				'label'      => (string) ( $snap['label'] ?? '' ),
				'position'   => $position++,
				'visibility' => $this->rewrite_visibility( $snap['visibility'] ?? null, $id_map ),
			];
			if ( ! empty( $snap['library_key'] ) ) {
				$row['library_key'] = (string) $snap['library_key'];
			}
			if ( ! empty( $snap['module_key'] ) ) {
				$row['module_key'] = (string) $snap['module_key'];
			}
			if ( ! empty( $snap['lookup_table_key'] ) ) {
				$row['lookup_table_key'] = (string) $snap['lookup_table_key'];
			}
			if ( isset( $snap['range_rows'] ) && is_array( $snap['range_rows'] ) ) {
				$row['range_rows'] = $snap['range_rows'];
			}
			$out[] = $row;
		}

		$existing = $this->sections->list( $product_id );
		foreach ( $existing as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( $id !== '' ) $this->sections->remove( $product_id, $id );
		}
		foreach ( $out as $row ) {
			$this->sections->add( $product_id, $row );
		}

		return [
			'ok'       => true,
			'message'  => sprintf( '%d section(s) applied from preset.', count( $out ) ),
			'sections' => $this->sections->list( $product_id ),
			'preset'   => $preset,
		];
	}

	/**
	 * Pass-through to the repository so the controller can list /
	 * fetch presets without bringing the repository into the route
	 * layer.
	 *
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function list_presets( int $page = 1, int $per_page = 100, ?string $product_type = null ): array {
		$filters = [];
		if ( $product_type !== null && $product_type !== '' ) {
			$filters['product_type'] = $product_type;
		}
		return $this->presets->list( $filters, $page, $per_page );
	}

	public function get_preset( int $id ): ?array {
		return $this->presets->find_by_id( $id );
	}

	/**
	 * Phase 4.3b half B — list products that currently use this preset.
	 * Implementation walks `_configkit_setup_source` post meta via
	 * WP_Query (available at request time). Returns minimal records:
	 *   list<{ product_id:int, name:string, edit_url?:string }>
	 *
	 * The post-meta query is broad on purpose: WP_Query with a
	 * meta_query on a JSON-serialized field scans every product. The
	 * cost is acceptable for the small product counts (~30-60) the
	 * sol1 owner is targeting and avoids a custom index column.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function products_using( int $preset_id ): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) return [];
		$post_ids = \get_posts( [
			'post_type'      => [ 'product' ],
			'post_status'    => 'any',
			'numberposts'    => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => SetupSourceState::META_KEY, 'compare' => 'EXISTS' ],
			],
		] );
		$out = [];
		foreach ( (array) $post_ids as $pid ) {
			$pid = (int) $pid;
			$meta = \get_post_meta( $pid, SetupSourceState::META_KEY, true );
			if ( ! is_array( $meta ) || (int) ( $meta['preset_id'] ?? 0 ) !== $preset_id ) continue;
			if ( ( $meta['setup_source'] ?? '' ) !== SetupSourceState::SOURCE_PRESET ) continue;
			$out[] = [
				'product_id' => $pid,
				'name'       => function_exists( 'get_the_title' ) ? (string) \get_the_title( $pid ) : '',
				'edit_url'   => function_exists( 'get_edit_post_link' ) ? (string) \get_edit_post_link( $pid, 'raw' ) : '',
			];
		}
		return $out;
	}

	// =========================================================
	// Internals
	// =========================================================

	/**
	 * Build the canonical preset.sections payload from a live section
	 * list. type_position is recomputed here so it is independent of
	 * the source product's draft positions.
	 *
	 * @param list<array<string,mixed>> $sections
	 * @return list<array<string,mixed>>
	 */
	private function snapshot_sections( array $sections ): array {
		$by_type = [];
		$out     = [];
		foreach ( $sections as $section ) {
			$type = (string) ( $section['type'] ?? '' );
			if ( $type === '' || ! SectionTypeRegistry::exists( $type ) ) continue;
			$by_type[ $type ] = ( $by_type[ $type ] ?? -1 ) + 1;
			$snap = [
				'type'          => $type,
				'type_position' => $by_type[ $type ],
				'label'         => (string) ( $section['label'] ?? '' ),
				'visibility'    => is_array( $section['visibility'] ?? null )
					? $section['visibility']
					: [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
				// Source section_id is recorded so visibility conditions
				// in this snapshot keep referencing the same node when
				// the snapshot is rewritten on apply.
				'_source_section_id' => (string) ( $section['id'] ?? '' ),
			];
			if ( ! empty( $section['library_key'] ) ) {
				$snap['library_key'] = (string) $section['library_key'];
			}
			if ( ! empty( $section['module_key'] ) ) {
				$snap['module_key'] = (string) $section['module_key'];
			}
			if ( ! empty( $section['lookup_table_key'] ) ) {
				$snap['lookup_table_key'] = (string) $section['lookup_table_key'];
			}
			if ( isset( $section['range_rows'] ) && is_array( $section['range_rows'] ) ) {
				$snap['range_rows'] = $section['range_rows'];
			}
			$out[] = $snap;
		}
		return $out;
	}

	/**
	 * Pick the first size_pricing section's lookup_table_key as the
	 * preset's default. Owners with multiple size_pricing sections in
	 * one product still get a sensible default; the rest carry their
	 * own per-section lookup_table_key in the snapshot.
	 *
	 * @param list<array<string,mixed>> $sections
	 */
	private function detect_default_lookup_table_key( array $sections ): ?string {
		foreach ( $sections as $section ) {
			if ( ( $section['type'] ?? '' ) !== SectionTypeRegistry::TYPE_SIZE_PRICING ) continue;
			$key = (string) ( $section['lookup_table_key'] ?? '' );
			if ( $key !== '' ) return $key;
		}
		return null;
	}

	/**
	 * Rewrite visibility conditions so any condition.section_id that
	 * points at one of the snapshot's source sections gets remapped
	 * to the freshly-minted target section_id. Conditions referring
	 * to sections OUTSIDE the snapshot are dropped — they would never
	 * resolve in the target product.
	 *
	 * @param mixed $raw
	 * @param array<string,string> $id_map
	 * @return array<string,mixed>
	 */
	private function rewrite_visibility( mixed $raw, array $id_map ): array {
		if ( ! is_array( $raw ) ) {
			return [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ];
		}
		$mode  = ( $raw['mode']  ?? 'always' ) === 'when' ? 'when' : 'always';
		$match = ( $raw['match'] ?? 'all' )    === 'any'  ? 'any'  : 'all';
		$conditions = [];
		if ( $mode === 'when' && is_array( $raw['conditions'] ?? null ) ) {
			foreach ( $raw['conditions'] as $cond ) {
				if ( ! is_array( $cond ) ) continue;
				$old = (string) ( $cond['section_id'] ?? '' );
				if ( $old === '' ) continue;
				if ( ! isset( $id_map[ $old ] ) ) continue; // dangling — drop
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
	 * Owner-supplied names become preset_key seeds; the slug is
	 * suffixed with a short hex tail when the seed already exists so
	 * "Markise standard" can be saved twice without a 409.
	 */
	private function mint_preset_key( string $name ): string {
		$slug = strtolower( $name );
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug ) ?? '';
		$slug = trim( $slug, '_' );
		if ( $slug === '' ) $slug = 'preset';
		if ( strlen( $slug ) > 56 ) $slug = substr( $slug, 0, 56 );
		if ( ! $this->presets->key_exists( $slug ) ) return $slug;
		$i = 2;
		while ( $this->presets->key_exists( $slug . '_' . $i ) ) $i++;
		return $slug . '_' . $i;
	}
}
