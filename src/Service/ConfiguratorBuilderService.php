<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Adapters\WooSkuResolver;
use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\ModuleRepository;

/**
 * Phase 4.4 — Yith-style section orchestrator.
 *
 * Sits ON TOP of ProductBuilderService (Phase 4.3 dalis 1). The
 * existing orchestrator handles entity provisioning; this service
 * handles the section list metadata that the new UI drags / drops
 * / opens-modal-on. Each section card on the builder maps to one
 * underlying entity (a lookup_table for size_pricing, a library for
 * everything else). Multiple sections of the same type are
 * supported by minting a per-section element_id and using it as the
 * entity key suffix.
 *
 *   list_sections(product_id)
 *   create_section(product_id, type, label?)
 *   update_section(product_id, section_id, patch)
 *   delete_section(product_id, section_id)
 *   reorder_sections(product_id, ordered_ids)
 *
 * Each method returns the same shape:
 *   { ok, message?, sections, errors? }
 */
final class ConfiguratorBuilderService {

	public function __construct(
		private SectionListState $sections,
		private ProductBuilderState $product_state,
		private AutoManagedRegistry $registry,
		private LookupTableService $lookup_tables,
		private LookupTableRepository $lookup_table_repo,
		private ?ModuleService $modules = null,
		private ?LibraryService $libraries = null,
		private ?LibraryRepository $library_repo = null,
		private ?LookupCellService $lookup_cells = null,
		private ?LookupCellRepository $lookup_cell_repo = null,
		private ?LibraryItemService $items = null,
		private ?LibraryItemRepository $item_repo = null,
		private ?ModuleRepository $module_repo = null,
		private ?WooSkuResolver $woo_sku_resolver = null,
		private ?SetupSourceResolver $source_resolver = null,
		private ?SetupSourceState $setup_source_state = null,
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function list_sections( int $product_id ): array {
		// Half B: route through SetupSourceResolver when wired so
		// use_preset / link_to_setup products get their effective
		// view (with source / overridden_paths / option_overrides
		// annotations) instead of the raw local section list. When
		// no resolver is supplied (Half A test wiring) we keep the
		// pre-resolver behavior so existing tests stay green.
		if ( $this->source_resolver !== null ) {
			$resolved = $this->source_resolver->resolve( $product_id );
			return [
				'ok'                => true,
				'sections'          => $resolved['sections'],
				'setup_source'      => $resolved['setup_source'],
				'preset_id'         => $resolved['preset_id'],
				'source_product_id' => $resolved['source_product_id'],
				'preset'            => $resolved['preset'] !== null ? [
					'id'           => (int) ( $resolved['preset']['id'] ?? 0 ),
					'preset_key'   => (string) ( $resolved['preset']['preset_key'] ?? '' ),
					'name'         => (string) ( $resolved['preset']['name'] ?? '' ),
					'product_type' => $resolved['preset']['product_type'] ?? null,
				] : null,
			];
		}
		return [ 'ok' => true, 'sections' => $this->sections->list( $product_id ) ];
	}

	/**
	 * Half B section-CRUD guard: builder operations that mutate the
	 * local section list (create / delete / reorder / save_options /
	 * save_ranges) must be refused when the product is not in
	 * start_blank mode — preset and link products inherit their
	 * structure and should be edited via overrides or by detaching
	 * first.
	 *
	 * Returns null when the operation is allowed; otherwise an
	 * array<string,mixed> the caller can return verbatim.
	 *
	 * @return array{ok:bool,message:string}|null
	 */
	private function guard_local_writes( int $product_id, string $verb = 'modify sections' ): ?array {
		if ( $this->setup_source_state === null ) return null;
		$ss = $this->setup_source_state->get( $product_id );
		if ( $ss['setup_source'] === SetupSourceState::SOURCE_BLANK ) return null;
		$readable = $ss['setup_source'] === SetupSourceState::SOURCE_PRESET ? 'preset' : 'linked source';
		return [
			'ok'      => false,
			'message' => sprintf( 'This product inherits from a %s. Detach first to %s directly.', $readable, $verb ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create_section( int $product_id, string $type, ?string $label = null ): array {
		$blocked = $this->guard_local_writes( $product_id, 'add sections' );
		if ( $blocked !== null ) return $blocked;
		$registry_entry = SectionTypeRegistry::find( $type );
		if ( $registry_entry === null ) {
			return [ 'ok' => false, 'message' => sprintf( 'Unknown section type "%s".', $type ) ];
		}
		if ( $this->product_state->product_type( $product_id ) === null ) {
			// Custom-typed builder works without a product type
			// preset; we still need a stable per-product anchor though,
			// so default to "custom" if missing.
			$this->product_state->patch( $product_id, [ 'product_type' => 'custom', 'builder_version' => 1 ] );
		}

		$section_id   = SectionListState::mint_id( $type );
		$entity_key   = sprintf( 'product_%d_%s', $product_id, $section_id );
		$display_label = $label !== null && trim( $label ) !== '' ? trim( $label ) : (string) $registry_entry['default_label'];
		$position     = count( $this->sections->list( $product_id ) );

		$record = [
			'id'         => $section_id,
			'type'       => $type,
			'label'      => $display_label,
			'position'   => $position,
			'visibility' => [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ],
		];

		if ( $registry_entry['entity_kind'] === 'lookup_table' ) {
			$record['lookup_table_key'] = $entity_key;
			$created = $this->lookup_tables->create( [
				'lookup_table_key'     => $entity_key,
				'name'                 => sprintf( '%s — %s', $display_label, $section_id ),
				'unit'                 => 'mm',
				'match_mode'           => 'round_up',
				'supports_price_group' => true,
				'is_active'            => true,
			] );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [ 'ok' => false, 'message' => 'Could not create the underlying lookup table.', 'errors' => $created['errors'] ?? [] ];
			}
			$this->registry->mark( AutoManagedRegistry::TYPE_LOOKUP_TABLE, $entity_key, $product_id, 'section_' . $type );
		} elseif ( $registry_entry['entity_kind'] === 'library' && $this->modules !== null && $this->libraries !== null ) {
			$module_id = (string) ( $registry_entry['module_id'] ?? '' );
			$record['library_key'] = $entity_key;
			$record['module_key']  = $module_id;
			$this->ensure_module( $module_id, $product_id );
			$created = $this->libraries->create( [
				'library_key' => $entity_key,
				'name'        => sprintf( '%s — %s', $display_label, $section_id ),
				'module_key'  => $module_id,
				'is_active'   => true,
			] );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [ 'ok' => false, 'message' => 'Could not create the underlying library.', 'errors' => $created['errors'] ?? [] ];
			}
			$this->registry->mark( AutoManagedRegistry::TYPE_LIBRARY, $entity_key, $product_id, 'section_' . $type );
		}

		$this->sections->add( $product_id, $record );
		return [
			'ok'       => true,
			'message'  => sprintf( '%s section added.', $registry_entry['label'] ),
			'section'  => $record,
			'sections' => $this->sections->list( $product_id ),
		];
	}

	/**
	 * @param array<string,mixed> $patch
	 */
	public function update_section( int $product_id, string $section_id, array $patch ): array {
		$blocked = $this->guard_local_writes( $product_id, 'rename or change visibility on sections' );
		if ( $blocked !== null ) return $blocked;
		// Owners may only update the label and visibility through this
		// endpoint — entity keys + type stay immutable to keep the
		// auto-managed downstream entities stable.
		$allowed = [];
		if ( isset( $patch['label'] ) )      $allowed['label']      = trim( (string) $patch['label'] );
		if ( isset( $patch['visibility'] ) ) $allowed['visibility'] = $this->sanitize_visibility( $patch['visibility'] );

		if ( count( $allowed ) === 0 ) {
			return [ 'ok' => false, 'message' => 'Nothing to update.' ];
		}
		$ok = $this->sections->update( $product_id, $section_id, $allowed );
		if ( ! $ok ) {
			return [ 'ok' => false, 'message' => 'Section not found.' ];
		}
		return [
			'ok'       => true,
			'message'  => 'Section updated.',
			'section'  => $this->sections->find( $product_id, $section_id ),
			'sections' => $this->sections->list( $product_id ),
		];
	}

	public function delete_section( int $product_id, string $section_id ): array {
		$blocked = $this->guard_local_writes( $product_id, 'remove sections' );
		if ( $blocked !== null ) return $blocked;
		$existing = $this->sections->find( $product_id, $section_id );
		if ( $existing === null ) {
			return [ 'ok' => false, 'message' => 'Section not found.' ];
		}
		// Soft-detach: remove from list + drop the auto-managed flag.
		// We do NOT delete the underlying lookup_table / library so a
		// re-add doesn't destroy historical data — the orchestrator's
		// soft-delete already covers re-purposing.
		if ( ! empty( $existing['lookup_table_key'] ) ) {
			$this->registry->unmark( AutoManagedRegistry::TYPE_LOOKUP_TABLE, (string) $existing['lookup_table_key'] );
		}
		if ( ! empty( $existing['library_key'] ) ) {
			$this->registry->unmark( AutoManagedRegistry::TYPE_LIBRARY, (string) $existing['library_key'] );
		}
		$this->sections->remove( $product_id, $section_id );
		return [
			'ok'       => true,
			'message'  => 'Section removed.',
			'sections' => $this->sections->list( $product_id ),
		];
	}

	/**
	 * @param list<string> $ordered_ids
	 */
	public function reorder_sections( int $product_id, array $ordered_ids ): array {
		$blocked = $this->guard_local_writes( $product_id, 'reorder sections' );
		if ( $blocked !== null ) return $blocked;
		$ids = [];
		foreach ( $ordered_ids as $id ) {
			if ( is_string( $id ) && $id !== '' ) $ids[] = $id;
		}
		$this->sections->reorder( $product_id, $ids );
		return [ 'ok' => true, 'sections' => $this->sections->list( $product_id ) ];
	}

	/**
	 * Phase 4.4 — save the range rows for a size_pricing section.
	 *
	 * Storage strategy without an engine touch: the engine's
	 * `round_up` matcher already provides range semantics — a cell
	 * at (width=W, height=H) catches any customer dimension ≤ W,
	 * ≤ H (and gets picked when no smaller cell qualifies). We
	 * write each range row as a single lookup_cell at
	 * (width = width_to, height = height_to, price, price_group_key)
	 * so the customer-facing engine path stays unchanged. The
	 * owner's literal from-side bounds are preserved on the section
	 * record so the editor reads back exactly what was typed —
	 * `range_rows` ride alongside the auto-managed entity key.
	 *
	 * Idempotent: re-saving a range list wipes the prior cells (and
	 * the prior `range_rows` snapshot) and inserts the new ones —
	 * the section reflects the UI exactly.
	 *
	 * @param list<array<string,mixed>> $rows
	 *
	 * @return array{ok:bool, message?:string, errors?:list<array<string,mixed>>, section?:array<string,mixed>}
	 */
	public function save_range_rows( int $product_id, string $section_id, array $rows ): array {
		$blocked = $this->guard_local_writes( $product_id, 'edit ranges' );
		if ( $blocked !== null ) return $blocked;
		if ( $this->lookup_cells === null || $this->lookup_cell_repo === null ) {
			return [ 'ok' => false, 'message' => 'Range pricing is not wired in this environment.' ];
		}
		$section = $this->sections->find( $product_id, $section_id );
		if ( $section === null || ( $section['type'] ?? '' ) !== SectionTypeRegistry::TYPE_SIZE_PRICING ) {
			return [ 'ok' => false, 'message' => 'Section not found, or not a size-pricing section.' ];
		}
		$lookup_key = (string) ( $section['lookup_table_key'] ?? '' );
		if ( $lookup_key === '' ) {
			return [ 'ok' => false, 'message' => 'Section has no underlying lookup table.' ];
		}
		$table = $this->lookup_table_repo->find_by_key( $lookup_key );
		if ( $table === null ) {
			return [ 'ok' => false, 'message' => 'Lookup table missing for this section.' ];
		}

		$normalised = [];
		$errors     = [];
		$cells      = [];
		foreach ( $rows as $i => $row ) {
			$wf = $this->to_int( $row['width_from']  ?? null );
			$wt = $this->to_int( $row['width_to']    ?? null );
			$hf = $this->to_int( $row['height_from'] ?? null );
			$ht = $this->to_int( $row['height_to']   ?? null );
			$price = $this->to_float( $row['price']  ?? null );
			$pgk   = isset( $row['price_group_key'] ) ? (string) $row['price_group_key'] : '';

			if ( $wt === null || $wt <= 0 || $ht === null || $ht <= 0 ) {
				$errors[] = [ 'field' => 'range', 'code' => 'invalid', 'message' => sprintf( 'Row %d needs a width and height "to" value.', $i + 1 ) ];
				continue;
			}
			if ( $price === null || $price < 0 ) {
				$errors[] = [ 'field' => 'price', 'code' => 'invalid', 'message' => sprintf( 'Row %d needs a non-negative price.', $i + 1 ) ];
				continue;
			}
			// `from` defaults to 0 when missing — the round_up matcher
			// doesn't care about lower bounds anyway, but the editor
			// needs them for display.
			$normalised[] = [
				'width_from'      => $wf !== null && $wf >= 0 ? $wf : 0,
				'width_to'        => $wt,
				'height_from'     => $hf !== null && $hf >= 0 ? $hf : 0,
				'height_to'       => $ht,
				'price'           => $price,
				'price_group_key' => $pgk,
			];
			$cells[] = [
				'lookup_table_key' => $lookup_key,
				'width'            => $wt,
				'height'           => $ht,
				'price'            => $price,
				'price_group_key'  => $pgk,
				'is_active'        => true,
			];
		}
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'message' => sprintf( '%d invalid range row(s).', count( $errors ) ), 'errors' => $errors ];
		}

		$this->lookup_cell_repo->delete_all_in_table( $lookup_key );
		$bulk = $this->lookup_cells->bulk_upsert( (int) $table['id'], $cells );
		if ( ! ( $bulk['ok'] ?? false ) ) {
			return [ 'ok' => false, 'message' => 'Range rows could not be saved.', 'errors' => $bulk['errors'] ?? [] ];
		}

		$this->sections->update( $product_id, $section_id, [ 'range_rows' => $normalised ] );
		$diagnostics = $this->analyse_ranges( $normalised );
		return [
			'ok'          => true,
			'message'     => sprintf( '%d range row(s) saved.', count( $normalised ) ),
			'section'     => $this->sections->find( $product_id, $section_id ),
			'diagnostics' => $diagnostics,
		];
	}

	/**
	 * Read the section's range rows back. Falls back to lookup_cells
	 * (with synthetic from = 0) when the section state has no
	 * `range_rows` snapshot — covers older sections that pre-date
	 * Phase 4.4.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function read_range_rows( int $product_id, string $section_id ): array {
		$section = $this->resolve_section_for_read( $product_id, $section_id );
		if ( $section === null ) return [];
		if ( is_array( $section['range_rows'] ?? null ) ) {
			return $section['range_rows'];
		}
		if ( $this->lookup_cell_repo === null ) return [];
		$lookup_key = (string) ( $section['lookup_table_key'] ?? '' );
		if ( $lookup_key === '' ) return [];
		$cells = $this->lookup_cell_repo->list_in_table( $lookup_key, [], 1, 5000 )['items'] ?? [];
		$out = [];
		foreach ( $cells as $c ) {
			$out[] = [
				'width_from'      => 0,
				'width_to'        => (int) ( $c['width']  ?? 0 ),
				'height_from'     => 0,
				'height_to'       => (int) ( $c['height'] ?? 0 ),
				'price'           => (float) ( $c['price'] ?? 0 ),
				'price_group_key' => (string) ( $c['price_group_key'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Phase 4.4 — save the option list for a non-pricing section
	 * (option_group / motor / manual_operation / controls /
	 * accessories / custom). Each owner row creates a library item;
	 * replace_all semantics — re-saving wipes prior items.
	 *
	 * @param list<array<string,mixed>> $options
	 *
	 * @return array{ok:bool, message?:string, errors?:list<array<string,mixed>>, section?:array<string,mixed>}
	 */
	public function save_section_options( int $product_id, string $section_id, array $options ): array {
		$blocked = $this->guard_local_writes( $product_id, 'edit options' );
		if ( $blocked !== null ) return $blocked;
		if ( $this->items === null || $this->item_repo === null || $this->library_repo === null ) {
			return [ 'ok' => false, 'message' => 'Options are not wired in this environment.' ];
		}
		$section = $this->sections->find( $product_id, $section_id );
		if ( $section === null ) {
			return [ 'ok' => false, 'message' => 'Section not found.' ];
		}
		if ( ( $section['type'] ?? '' ) === SectionTypeRegistry::TYPE_SIZE_PRICING ) {
			return [ 'ok' => false, 'message' => 'Use the ranges endpoint for size_pricing sections.' ];
		}
		$lib_key = (string) ( $section['library_key'] ?? '' );
		if ( $lib_key === '' ) {
			return [ 'ok' => false, 'message' => 'Section has no underlying library.' ];
		}
		$library = $this->library_repo->find_by_key( $lib_key );
		if ( $library === null ) {
			return [ 'ok' => false, 'message' => 'Library missing for this section.' ];
		}
		$module = $this->module_repo !== null ? $this->module_repo->find_by_key( (string) $library['module_key'] ) : null;

		// Wipe prior items so the saved list mirrors the editor.
		$this->item_repo->soft_delete_all_in_library( $lib_key );

		$inserted = 0;
		$errors   = [];
		foreach ( $options as $i => $row ) {
			$payload = $this->option_to_item_payload( $row, $module ?? [], $section );
			if ( empty( $payload['label'] ) ) {
				$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => sprintf( 'Option #%d needs a name.', $i + 1 ) ];
				continue;
			}
			$payload['item_key'] = $this->mint_item_key( $lib_key, (string) $payload['label'], $payload['sku'] ?? null );
			$result = $this->items->create( (int) $library['id'], $payload );
			if ( ! ( $result['ok'] ?? false ) ) {
				$errors[] = [
					'field'   => 'option',
					'code'    => 'create_failed',
					'message' => sprintf( 'Option #%d (%s) could not be saved: %s', $i + 1, (string) $payload['label'], (string) ( $result['errors'][0]['message'] ?? 'unknown' ) ),
				];
				continue;
			}
			$inserted++;
		}

		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'message' => sprintf( '%d option(s) failed.', count( $errors ) ), 'errors' => $errors ];
		}
		return [
			'ok'      => true,
			'message' => sprintf( '%d option(s) saved.', $inserted ),
			'section' => $section,
		];
	}

	/**
	 * Read the library items behind a section. Hydrated rows include
	 * the attribute schema so the JS editor can show the right
	 * fields (only those the section's module declares).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function read_section_options( int $product_id, string $section_id ): array {
		if ( $this->item_repo === null ) return [];
		$section = $this->resolve_section_for_read( $product_id, $section_id );
		if ( $section === null ) return [];
		$lib_key = (string) ( $section['library_key'] ?? '' );
		if ( $lib_key === '' ) return [];
		$items = $this->item_repo->list_in_library( $lib_key, 1, 1000 )['items'] ?? [];
		// In use_preset / link mode the resolver attached
		// option_overrides for hidden + price overrides; surface them
		// inline so the UI can render the per-option indicator + the
		// effective (overridden) price without a second resolve.
		$option_overrides = is_array( $section['option_overrides'] ?? null ) ? $section['option_overrides'] : [];
		if ( count( $option_overrides ) > 0 ) {
			foreach ( $items as $i => $item ) {
				$key = (string) ( $item['item_key'] ?? '' );
				if ( $key !== '' && isset( $option_overrides[ $key ] ) ) {
					$o = $option_overrides[ $key ];
					if ( ! empty( $o['is_hidden'] ) ) $items[ $i ]['is_hidden_by_override'] = true;
					if ( isset( $o['price'] ) )      $items[ $i ]['overridden_price'] = (float) $o['price'];
				}
			}
		}
		return $items;
	}

	/**
	 * Find a section regardless of whether it lives in the local
	 * SectionListState (start_blank) or is synthesized by the
	 * SetupSourceResolver (use_preset / link). Used by the read
	 * paths so the Phase 4.4 modal editors keep working in every
	 * mode.
	 *
	 * @return array<string,mixed>|null
	 */
	private function resolve_section_for_read( int $product_id, string $section_id ): ?array {
		if ( $this->source_resolver !== null ) {
			$resolved = $this->source_resolver->resolve_sections( $product_id );
			foreach ( $resolved as $row ) {
				if ( ( $row['id'] ?? '' ) === $section_id ) return $row;
			}
		}
		return $this->sections->find( $product_id, $section_id );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $module
	 * @param array<string,mixed> $section
	 * @return array<string,mixed>
	 */
	private function option_to_item_payload( array $row, array $module, array $section ): array {
		$has_components = isset( $row['components'] ) && is_array( $row['components'] ) && count( array_filter( $row['components'], 'is_array' ) ) > 0;
		$payload = [
			'label'        => isset( $row['label'] ) ? trim( (string) $row['label'] ) : '',
			'sku'          => isset( $row['sku'] ) && $row['sku'] !== '' ? (string) $row['sku'] : null,
			'is_active'    => array_key_exists( 'active', $row ) ? (bool) $row['active'] : true,
			'attributes'   => [],
			'item_type'    => $has_components ? 'bundle' : 'simple_option',
		];

		if ( ! empty( $module['supports_image'] ) && ! empty( $row['image_url'] ) ) {
			$payload['image_url'] = (string) $row['image_url'];
		}
		if ( ! empty( $module['supports_main_image'] ) && ! empty( $row['main_image_url'] ) ) {
			$payload['main_image_url'] = (string) $row['main_image_url'];
		}
		if ( ! empty( $module['supports_color_family'] ) && ! empty( $row['color_family'] ) ) {
			$payload['color_family'] = (string) $row['color_family'];
		}
		if ( ! empty( $module['supports_price_group'] ) && isset( $row['price_group'] ) ) {
			$payload['price_group_key'] = (string) $row['price_group'];
		}
		if ( ! empty( $module['supports_price'] ) && isset( $row['price'] )
			&& $row['price'] !== '' && $row['price'] !== null && (float) $row['price'] >= 0
		) {
			$payload['price'] = (float) $row['price'];
		}
		// Woo product link: id wins; SKU is resolved through the
		// adapter when only an SKU is supplied (the bulk paste path
		// gives owners SKUs, not numeric ids).
		if ( ! empty( $module['supports_woo_product_link'] ) ) {
			if ( isset( $row['woo_product_id'] ) && (int) $row['woo_product_id'] > 0 ) {
				$payload['woo_product_id'] = (int) $row['woo_product_id'];
			} elseif ( ! empty( $row['woo_product_sku'] ) && $this->woo_sku_resolver !== null ) {
				$resolved = $this->woo_sku_resolver->resolveBySku( (string) $row['woo_product_sku'] );
				if ( $resolved !== null && $resolved > 0 ) $payload['woo_product_id'] = $resolved;
			}
		}

		// Bundle components — Phase 4.4 motor section. Components are
		// parallel rows the customer never sees as separate options;
		// they simply describe the line items the bundle adds to the
		// cart. Mirrors Phase 4.2b shape so downstream cart code stays
		// untouched.
		if ( $has_components ) {
			$components = [];
			foreach ( $row['components'] as $c ) {
				if ( ! is_array( $c ) ) continue;
				$wid = isset( $c['woo_product_id'] ) ? (int) $c['woo_product_id'] : 0;
				if ( $wid <= 0 && ! empty( $c['woo_product_sku'] ) && $this->woo_sku_resolver !== null ) {
					$resolved = $this->woo_sku_resolver->resolveBySku( (string) $c['woo_product_sku'] );
					$wid      = $resolved !== null ? $resolved : 0;
				}
				if ( $wid <= 0 ) continue;
				$components[] = [
					'component_key'  => isset( $c['component_key'] ) && $c['component_key'] !== '' ? (string) $c['component_key'] : 'c_' . $wid,
					'woo_product_id' => $wid,
					'qty'            => isset( $c['qty'] ) && (int) $c['qty'] > 0 ? (int) $c['qty'] : 1,
					'price_source'   => isset( $c['price_source'] ) && in_array( $c['price_source'], [ 'woo', 'configkit' ], true ) ? (string) $c['price_source'] : 'woo',
				];
			}
			if ( count( $components ) === 0 ) {
				$payload['item_type']    = 'simple_option';
				$payload['price_source'] = 'configkit';
			} else {
				$payload['bundle_components'] = $components;
				if ( isset( $row['bundle_fixed_price'] ) && $row['bundle_fixed_price'] !== '' && (float) $row['bundle_fixed_price'] >= 0 ) {
					$payload['price_source']       = 'fixed_bundle';
					$payload['bundle_fixed_price'] = (float) $row['bundle_fixed_price'];
				} else {
					$payload['price_source'] = 'bundle_sum';
				}
			}
		} else {
			// Single option price source: explicit choice wins. Implicit
			// "Woo-linked, no price typed" → Woo, matching ProductBuilder's
			// normalise_price_source.
			$raw = isset( $row['price_source'] ) ? strtolower( (string) $row['price_source'] ) : '';
			if ( $raw === 'woo' && ! empty( $payload['woo_product_id'] ) ) {
				$payload['price_source'] = 'woo';
				unset( $payload['price'] );
			} elseif ( $raw === 'configkit' ) {
				$payload['price_source'] = 'configkit';
			} elseif ( ! empty( $payload['woo_product_id'] ) && empty( $payload['price'] ) ) {
				$payload['price_source'] = 'woo';
			} else {
				$payload['price_source'] = 'configkit';
			}
		}

		// Brand / collection are library-level fields the bulk paste
		// can carry per row; store them on the item's `attributes`
		// blob when the module supports them so the section editor
		// can read them back without a join through the library.
		if ( ! empty( $module['capabilities']['supports_brand'] ?? false ) && ! empty( $row['brand'] ) ) {
			$payload['attributes']['brand'] = (string) $row['brand'];
		}
		if ( ! empty( $module['capabilities']['supports_collection'] ?? false ) && ! empty( $row['collection'] ) ) {
			$payload['attributes']['collection'] = (string) $row['collection'];
		}

		// Module-declared attributes (fabric_code, transparency, …).
		$schema = is_array( $module['attribute_schema'] ?? null ) ? $module['attribute_schema'] : [];
		foreach ( array_keys( $schema ) as $key ) {
			if ( ! is_string( $key ) ) continue;
			if ( isset( $row[ $key ] ) && $row[ $key ] !== '' && $row[ $key ] !== null ) {
				$payload['attributes'][ $key ] = $row[ $key ];
			}
		}

		unset( $section );
		return $payload;
	}

	private function mint_item_key( string $library_key, string $label, ?string $code ): string {
		$base = $code !== null && $code !== '' ? $code : $label;
		$slug = strtolower( $base );
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug ) ?? '';
		$slug = trim( $slug, '_' );
		if ( $slug === '' ) $slug = 'item';
		if ( strlen( $slug ) < 3 ) {
			$pad = strtolower( preg_replace( '/[^a-z0-9]+/', '_', $label ) ?? '' );
			$pad = trim( $pad, '_' );
			$slug = $pad !== '' && strlen( $pad ) >= 3 ? $pad : ( $slug . '_item' );
			$slug = substr( $slug, 0, 64 );
		}
		if ( $this->item_repo === null ) return $slug;
		if ( ! $this->item_repo->key_exists_in_library( $library_key, $slug ) ) return $slug;
		$i = 2;
		while ( $this->item_repo->key_exists_in_library( $library_key, $slug . '_' . $i ) ) $i++;
		return $slug . '_' . $i;
	}

	/**
	 * Phase 4.4 chunk 8 — per-product readiness scan.
	 *
	 * Walks every section, asks each for its content count, runs the
	 * range overlap analyser on size_pricing sections, and validates
	 * that every visibility condition still references an existing
	 * section. Output shape is consumed by the diagnostics modal +
	 * the per-card status pills.
	 *
	 * Status taxonomy (kept tiny on purpose):
	 *   ready         — section has content, no detected issues.
	 *   setup_needed  — section is empty (no rows / no options).
	 *   issues        — overlap / dangling visibility / save error.
	 *
	 * @return array{
	 *   summary:array{ready:int,setup_needed:int,issues:int,total:int,overall:string},
	 *   sections:list<array{id:string,type:string,label:string,status:string,issues:list<string>}>
	 * }
	 */
	public function analyse_product( int $product_id ): array {
		$sections = $this->sections->list( $product_id );
		$out      = [];
		$counts   = [ 'ready' => 0, 'setup_needed' => 0, 'issues' => 0 ];
		$known_ids = array_column( $sections, 'id' );

		foreach ( $sections as $section ) {
			$id     = (string) ( $section['id'] ?? '' );
			$type   = (string) ( $section['type'] ?? '' );
			$label  = (string) ( $section['label'] ?? $id );
			$issues = [];
			$has_content = false;

			if ( $type === SectionTypeRegistry::TYPE_SIZE_PRICING ) {
				$rows = is_array( $section['range_rows'] ?? null ) ? $section['range_rows'] : [];
				$has_content = count( $rows ) > 0;
				if ( $has_content ) {
					$diag = $this->analyse_ranges( $rows );
					foreach ( $diag['overlaps'] ?? [] as $o ) {
						$issues[] = (string) $o['message'];
					}
				}
			} else {
				$count = 0;
				if ( $this->item_repo !== null && ! empty( $section['library_key'] ) ) {
					$count = $this->item_repo->count_in_library( (string) $section['library_key'] );
				}
				$has_content = $count > 0;
			}

			// Dangling visibility references — flag every condition whose
			// target section id no longer exists.
			$vis = is_array( $section['visibility'] ?? null ) ? $section['visibility'] : [];
			if ( ( $vis['mode'] ?? 'always' ) === 'when' ) {
				foreach ( $vis['conditions'] ?? [] as $cond ) {
					$ref = (string) ( $cond['section_id'] ?? '' );
					if ( $ref === '' ) continue;
					if ( ! in_array( $ref, $known_ids, true ) ) {
						$issues[] = sprintf( 'Visibility condition references unknown section "%s".', $ref );
					}
				}
			}

			if ( count( $issues ) > 0 ) {
				$status = 'issues';
			} elseif ( ! $has_content ) {
				$status = 'setup_needed';
			} else {
				$status = 'ready';
			}
			$counts[ $status ]++;
			$out[] = [
				'id'     => $id,
				'type'   => $type,
				'label'  => $label,
				'status' => $status,
				'issues' => $issues,
			];
		}

		$total = count( $sections );
		$overall = $counts['issues'] > 0 ? 'issues'
			: ( $total === 0 ? 'empty'
			: ( $counts['setup_needed'] > 0 ? 'in_progress' : 'ready' ) );
		return [
			'summary' => [
				'ready'        => $counts['ready'],
				'setup_needed' => $counts['setup_needed'],
				'issues'       => $counts['issues'],
				'total'        => $total,
				'overall'      => $overall,
			],
			'sections' => $out,
		];
	}

	/**
	 * Compute overlap + gap diagnostics over the supplied ranges.
	 * Pure-PHP — no DB. Used by save_range_rows so the controller
	 * can hand the JS a ready-to-render warning list.
	 *
	 * Overlap: two rows whose width AND height ranges intersect.
	 * Gap (informational): the smallest from-edge that no row covers
	 * — the JS surfaces this as a soft warning rather than an error.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return array{overlaps:list<array{a:int,b:int,message:string}>,gaps:list<string>,ok:bool}
	 */
	public function analyse_ranges( array $rows ): array {
		$overlaps = [];
		for ( $i = 0; $i < count( $rows ); $i++ ) {
			for ( $j = $i + 1; $j < count( $rows ); $j++ ) {
				if ( $this->ranges_overlap( $rows[ $i ], $rows[ $j ] ) ) {
					$overlaps[] = [
						'a' => $i,
						'b' => $j,
						'message' => sprintf(
							'Rows %d and %d overlap at width %d-%d × height %d-%d.',
							$i + 1,
							$j + 1,
							max( (int) $rows[ $i ]['width_from'],  (int) $rows[ $j ]['width_from'] ),
							min( (int) $rows[ $i ]['width_to'],    (int) $rows[ $j ]['width_to'] ),
							max( (int) $rows[ $i ]['height_from'], (int) $rows[ $j ]['height_from'] ),
							min( (int) $rows[ $i ]['height_to'],   (int) $rows[ $j ]['height_to'] ),
						),
					];
				}
			}
		}
		// Gap heuristic: did the rows cover [0, max-to] continuously
		// on each axis? Naive but useful — flags the most common
		// "missing 2400-2500" mistake.
		$gaps = [];
		if ( count( $rows ) > 0 ) {
			$widths = array_unique( array_map( static fn ( $r ) => (int) $r['width_to'], $rows ) );
			sort( $widths );
			$prev_end = 0;
			foreach ( $widths as $end ) {
				$next_starts = array_filter( $rows, static fn ( $r ) => (int) $r['width_to'] === $end );
				$min_from    = min( array_map( static fn ( $r ) => (int) $r['width_from'], $next_starts ) );
				if ( $min_from > $prev_end + 1 ) {
					$gaps[] = sprintf( 'Width gap: no row covers %d-%d.', $prev_end + 1, $min_from - 1 );
				}
				$prev_end = max( $prev_end, $end );
			}
		}
		return [
			'overlaps' => $overlaps,
			'gaps'     => $gaps,
			'ok'       => count( $overlaps ) === 0,
		];
	}

	/**
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 */
	private function ranges_overlap( array $a, array $b ): bool {
		$aw_from = (int) ( $a['width_from']  ?? 0 );
		$aw_to   = (int) ( $a['width_to']    ?? 0 );
		$ah_from = (int) ( $a['height_from'] ?? 0 );
		$ah_to   = (int) ( $a['height_to']   ?? 0 );
		$bw_from = (int) ( $b['width_from']  ?? 0 );
		$bw_to   = (int) ( $b['width_to']    ?? 0 );
		$bh_from = (int) ( $b['height_from'] ?? 0 );
		$bh_to   = (int) ( $b['height_to']   ?? 0 );
		$width_overlap  = $aw_from <= $bw_to && $bw_from <= $aw_to;
		$height_overlap = $ah_from <= $bh_to && $bh_from <= $ah_to;
		// Same price-group rows that share an exact (width_to, height_to)
		// bucket are duplicates from the engine's point of view, so we
		// also flag exact-equal rows even though strictly they don't
		// "overlap" in the from/to model.
		if ( $width_overlap && $height_overlap ) return true;
		return false;
	}

	private function to_int( mixed $value ): ?int {
		if ( $value === null || $value === '' ) return null;
		if ( is_int( $value ) ) return $value;
		if ( is_float( $value ) ) return (int) round( $value );
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) return (int) round( (float) trim( $value ) );
		return null;
	}

	private function to_float( mixed $value ): ?float {
		if ( $value === null || $value === '' ) return null;
		if ( is_int( $value ) || is_float( $value ) ) return (float) $value;
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) return (float) trim( $value );
		return null;
	}

	/**
	 * Idempotently provision the shared module behind a library
	 * section. Reuses ModuleService + ModuleTypePresets so each
	 * section type's module looks identical to one created via the
	 * Phase 4.3 fabric / motor saver.
	 */
	private function ensure_module( string $module_id, int $product_id ): void {
		if ( $this->modules === null ) return;
		$preset = \ConfigKit\Admin\ModuleTypePresets::find( $module_id );
		if ( $preset === null ) return;
		// ModuleService rejects duplicate keys, so a quick "does it
		// exist" check happens via the repository. We keep the
		// repository lookup local to this service to avoid yet
		// another constructor parameter when ModuleService::list
		// would do.
		$existing = $this->module_exists_via_service( $module_id );
		if ( $existing ) return;

		$payload = \ConfigKit\Admin\ModuleTypePresets::apply_to_payload( [
			'module_key'  => $module_id,
			'name'        => (string) $preset['label'],
			'module_type' => $module_id,
			'is_active'   => true,
		] );
		$created = $this->modules->create( $payload );
		if ( $created['ok'] ?? false ) {
			$this->registry->mark( AutoManagedRegistry::TYPE_MODULE, $module_id, $product_id, 'section_module_' . $module_id );
		}
	}

	private function module_exists_via_service( string $module_id ): bool {
		if ( $this->modules === null ) return false;
		$listing = $this->modules->list( 1, 200 );
		foreach ( $listing['items'] ?? [] as $row ) {
			if ( ( $row['module_key'] ?? '' ) === $module_id ) return true;
		}
		return false;
	}

	/**
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_visibility( mixed $raw ): array {
		if ( ! is_array( $raw ) ) return [ 'mode' => 'always', 'conditions' => [], 'match' => 'all' ];
		$mode = ( $raw['mode'] ?? 'always' ) === 'when' ? 'when' : 'always';
		$match = ( $raw['match'] ?? 'all' ) === 'any' ? 'any' : 'all';
		$conditions = [];
		if ( $mode === 'when' && is_array( $raw['conditions'] ?? null ) ) {
			foreach ( $raw['conditions'] as $cond ) {
				if ( ! is_array( $cond ) ) continue;
				$section_id = (string) ( $cond['section_id'] ?? '' );
				$op         = (string) ( $cond['op'] ?? 'equals' );
				$value      = isset( $cond['value'] ) ? (string) $cond['value'] : '';
				if ( $section_id === '' ) continue;
				if ( ! in_array( $op, [ 'equals', 'not_equals' ], true ) ) $op = 'equals';
				$conditions[] = [ 'section_id' => $section_id, 'op' => $op, 'value' => $value ];
			}
		}
		// "always" never carries conditions.
		if ( $mode === 'always' ) $conditions = [];
		return [ 'mode' => $mode, 'conditions' => $conditions, 'match' => $match ];
	}
}
