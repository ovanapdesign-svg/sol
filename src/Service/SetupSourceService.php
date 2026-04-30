<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\LookupTableRepository;

/**
 * Phase 4.3b half B — owner-facing actions that flip a product's
 * setup_source mode and manage its overrides.
 *
 * The five methods sit ON TOP of the existing builder layers:
 *   - SectionListState owns the per-product local section list.
 *   - SetupSourceState owns the per-product source / overrides state.
 *   - SetupSourceResolver reads both and produces the effective
 *     sections shown to the UI.
 *
 * This service is the owner-action layer — every method writes
 * through SetupSourceState (and SectionListState where required) and
 * returns the post-write resolved view so the controller can hand it
 * straight back to the UI.
 *
 * Library / lookup ownership rule (locked in Half A): copy /
 * apply / link NEVER duplicates library items or lookup cells.
 * library_key + lookup_table_key strings are shared references.
 */
final class SetupSourceService {

	public const LOOKUP_INHERIT = 'inherit';
	public const LOOKUP_REUSE   = 'reuse';
	public const LOOKUP_NEW     = 'new';

	public function __construct(
		private SetupSourceState $state,
		private SectionListState $sections,
		private SetupSourceResolver $resolver,
		private ?LookupTableService $lookup_tables = null,
		private ?LookupTableRepository $lookup_table_repo = null,
	) {}

	/**
	 * Deep-clone the source product's effective sections into the
	 * target's local section list. After the copy, target is
	 * `start_blank` and editing target does NOT affect source.
	 *
	 * @return array{ok:bool,message?:string,sections?:list<array<string,mixed>>}
	 */
	public function copy_from_product(
		int $target_product_id,
		int $source_product_id,
		string $lookup_table_choice = self::LOOKUP_INHERIT,
		?string $lookup_table_key_for_reuse = null
	): array {
		if ( $target_product_id === $source_product_id ) {
			return [ 'ok' => false, 'message' => 'Cannot copy a product onto itself.' ];
		}
		$resolved = $this->resolver->resolve( $source_product_id );
		$base     = $resolved['sections'];
		if ( count( $base ) === 0 ) {
			return [ 'ok' => false, 'message' => 'Source product has no sections to copy.' ];
		}

		// Wipe target's existing local sections — copy is wholesale.
		foreach ( $this->sections->list( $target_product_id ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( $id !== '' ) $this->sections->remove( $target_product_id, $id );
		}

		// Translate the resolver's stable ids into freshly minted
		// owner-visible ids so the target reads as an independent
		// product. Visibility section_id refs are rewritten via the
		// old→new map.
		$id_map = [];
		$entries = [];
		foreach ( $base as $row ) {
			$type   = (string) ( $row['type'] ?? '' );
			$old_id = (string) ( $row['id'] ?? '' );
			$new_id = SectionListState::mint_id( $type );
			if ( $old_id !== '' ) $id_map[ $old_id ] = $new_id;
			$entries[] = [ 'row' => $row, 'new_id' => $new_id ];
		}

		$position = 0;
		foreach ( $entries as $entry ) {
			$row    = $entry['row'];
			$new_id = $entry['new_id'];
			$record = [
				'id'         => $new_id,
				'type'       => (string) $row['type'],
				'label'      => (string) ( $row['label'] ?? '' ),
				'position'   => $position++,
				'visibility' => $this->rewrite_visibility( $row['visibility'] ?? null, $id_map ),
			];
			if ( ! empty( $row['library_key'] ) ) $record['library_key'] = (string) $row['library_key'];
			if ( ! empty( $row['module_key'] ) )  $record['module_key']  = (string) $row['module_key'];
			if ( ! empty( $row['lookup_table_key'] ) ) {
				$record['lookup_table_key'] = $this->resolve_lookup_choice(
					(string) $row['lookup_table_key'],
					$lookup_table_choice,
					$lookup_table_key_for_reuse,
					$record['label']
				);
			}
			if ( isset( $row['range_rows'] ) && is_array( $row['range_rows'] ) ) {
				$record['range_rows'] = $row['range_rows'];
			}
			$this->sections->add( $target_product_id, $record );
		}

		// Target is now independent. setup_source = start_blank, no
		// preset link, no overrides — the cloned sections ARE the
		// effective state.
		$this->state->clear( $target_product_id );

		return [
			'ok'       => true,
			'message'  => sprintf( 'Copied %d section(s) from product #%d.', count( $entries ), $source_product_id ),
			'sections' => $this->sections->list( $target_product_id ),
		];
	}

	/**
	 * @return array{ok:bool,message?:string,setup_source?:string}
	 */
	public function link_to_setup( int $target_product_id, int $source_product_id ): array {
		if ( $target_product_id === $source_product_id ) {
			return [ 'ok' => false, 'message' => 'Cannot link a product to itself.' ];
		}
		// Sanity-check the source resolves cleanly — if it's already
		// in link_to_setup mode and pointed at this target, there's a
		// cycle and we refuse rather than letting the resolver fall
		// back silently.
		$resolved = $this->resolver->resolve( $source_product_id );
		if ( $resolved['source_product_id'] === $target_product_id ) {
			return [ 'ok' => false, 'message' => 'That would create a link cycle.' ];
		}
		$this->state->patch( $target_product_id, [
			'setup_source'      => SetupSourceState::SOURCE_LINK,
			'source_product_id' => $source_product_id,
			'preset_id'         => 0,
			'overrides'         => [],
		] );
		// Linked products share the source's effective state, so the
		// target's local section list goes unused. Wipe it so the
		// resolver isn't confused by leftovers.
		foreach ( $this->sections->list( $target_product_id ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( $id !== '' ) $this->sections->remove( $target_product_id, $id );
		}
		return [
			'ok'           => true,
			'message'      => sprintf( 'Linked to product #%d.', $source_product_id ),
			'setup_source' => SetupSourceState::SOURCE_LINK,
		];
	}

	/**
	 * Detach a use_preset / link_to_setup product from its source by
	 * materializing the current resolved sections into the local
	 * section list, then flipping setup_source back to start_blank.
	 *
	 * Spec deviation noted: option-level overrides (price_overrides /
	 * hidden_options) cannot be baked into the underlying library
	 * items because libraries are SHARED — writing a price into the
	 * library would mutate every other product using it. So detach
	 * copies the SECTION STRUCTURE and CLEARS overrides per the
	 * Half B spec; the visible price returns to the library's stock
	 * value. Diagnostics warns about lost overrides ahead of time.
	 *
	 * @return array{ok:bool,message?:string,sections?:list<array<string,mixed>>}
	 */
	public function detach_from_preset( int $product_id ): array {
		$ss = $this->state->get( $product_id );
		if ( $ss['setup_source'] === SetupSourceState::SOURCE_BLANK ) {
			return [ 'ok' => false, 'message' => 'Product is already independent (start_blank).' ];
		}

		$resolved = $this->resolver->resolve( $product_id );
		$base     = $resolved['sections'];

		foreach ( $this->sections->list( $product_id ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( $id !== '' ) $this->sections->remove( $product_id, $id );
		}

		$id_map = [];
		$entries = [];
		foreach ( $base as $row ) {
			$type   = (string) ( $row['type'] ?? '' );
			$old_id = (string) ( $row['id'] ?? '' );
			$new_id = SectionListState::mint_id( $type );
			if ( $old_id !== '' ) $id_map[ $old_id ] = $new_id;
			$entries[] = [ 'row' => $row, 'new_id' => $new_id ];
		}
		$position = 0;
		foreach ( $entries as $entry ) {
			$row    = $entry['row'];
			$new_id = $entry['new_id'];
			$record = [
				'id'         => $new_id,
				'type'       => (string) $row['type'],
				'label'      => (string) ( $row['label'] ?? '' ),
				'position'   => $position++,
				'visibility' => $this->rewrite_visibility( $row['visibility'] ?? null, $id_map ),
			];
			if ( ! empty( $row['library_key'] ) )      $record['library_key']      = (string) $row['library_key'];
			if ( ! empty( $row['module_key'] ) )       $record['module_key']       = (string) $row['module_key'];
			if ( ! empty( $row['lookup_table_key'] ) ) $record['lookup_table_key'] = (string) $row['lookup_table_key'];
			if ( isset( $row['range_rows'] ) && is_array( $row['range_rows'] ) ) $record['range_rows'] = $row['range_rows'];
			$this->sections->add( $product_id, $record );
		}

		$this->state->clear( $product_id );

		return [
			'ok'       => true,
			'message'  => sprintf( 'Detached. %d section(s) are now local to this product.', count( $entries ) ),
			'sections' => $this->sections->list( $product_id ),
		];
	}

	/**
	 * Remove a single override key. Returns 404-style false when the
	 * key wasn't present so the controller can give the owner a
	 * useful message.
	 *
	 * @return array{ok:bool,message?:string}
	 */
	public function reset_override( int $product_id, string $path ): array {
		$existed = $this->state->unset_override( $product_id, $path );
		if ( ! $existed ) {
			return [ 'ok' => false, 'message' => sprintf( 'No override found at "%s".', $path ) ];
		}
		return [ 'ok' => true, 'message' => sprintf( 'Override "%s" reset.', $path ) ];
	}

	/**
	 * Set a single override path to the supplied value. The path's
	 * top-level bucket is validated so a typo doesn't silently store
	 * garbage that the applier later ignores.
	 *
	 * @return array{ok:bool,message?:string,path?:string}
	 */
	public function write_override( int $product_id, string $path, mixed $value ): array {
		$path = trim( $path );
		if ( $path === '' ) {
			return [ 'ok' => false, 'message' => 'Override path is required.' ];
		}
		$bucket = strpos( $path, '.' ) === false ? $path : substr( $path, 0, strpos( $path, '.' ) );
		if ( ! in_array( $bucket, [ 'lookup_table_key', 'price_overrides', 'hidden_options', 'default_values', 'min_dimensions', 'max_dimensions' ], true ) ) {
			return [ 'ok' => false, 'message' => sprintf( 'Unknown override bucket "%s".', $bucket ) ];
		}
		$this->state->set_override( $product_id, $path, $value );
		return [ 'ok' => true, 'message' => sprintf( 'Override "%s" saved.', $path ), 'path' => $path ];
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
				if ( $old === '' ) continue;
				$new = $id_map[ $old ] ?? $old; // keep the original ref if it's a known target
				$conditions[] = [
					'section_id' => $new,
					'op'         => ( $cond['op'] ?? 'equals' ) === 'not_equals' ? 'not_equals' : 'equals',
					'value'      => isset( $cond['value'] ) ? (string) $cond['value'] : '',
				];
			}
		}
		if ( $mode === 'always' ) $conditions = [];
		return [ 'mode' => $mode, 'conditions' => $conditions, 'match' => $match ];
	}

	/**
	 * Translate the owner's lookup_table_choice into a concrete
	 * lookup_table_key string for a size_pricing section. 'inherit'
	 * keeps the source's key (shared); 'reuse' takes the supplied
	 * key as-is; 'new' provisions an empty per-product table via
	 * LookupTableService when wired.
	 */
	private function resolve_lookup_choice(
		string $source_key,
		string $choice,
		?string $reuse_key,
		string $section_label
	): string {
		switch ( $choice ) {
			case self::LOOKUP_REUSE:
				return $reuse_key !== null && $reuse_key !== '' ? $reuse_key : $source_key;
			case self::LOOKUP_NEW:
				if ( $this->lookup_tables === null ) return $source_key;
				$new_key = 'copy_' . substr( md5( $source_key . '|' . microtime( true ) ), 0, 8 );
				$created = $this->lookup_tables->create( [
					'lookup_table_key'     => $new_key,
					'name'                 => sprintf( '%s — copy', $section_label !== '' ? $section_label : 'Size pricing' ),
					'unit'                 => 'mm',
					'match_mode'           => 'round_up',
					'supports_price_group' => true,
					'is_active'            => true,
				] );
				return ( $created['ok'] ?? false ) ? $new_key : $source_key;
			case self::LOOKUP_INHERIT:
			default:
				return $source_key;
		}
	}
}
