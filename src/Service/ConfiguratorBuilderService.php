<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Admin\SectionTypeRegistry;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LookupTableRepository;

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
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function list_sections( int $product_id ): array {
		return [ 'ok' => true, 'sections' => $this->sections->list( $product_id ) ];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create_section( int $product_id, string $type, ?string $label = null ): array {
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
		$ids = [];
		foreach ( $ordered_ids as $id ) {
			if ( is_string( $id ) && $id !== '' ) $ids[] = $id;
		}
		$this->sections->reorder( $product_id, $ids );
		return [ 'ok' => true, 'sections' => $this->sections->list( $product_id ) ];
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
