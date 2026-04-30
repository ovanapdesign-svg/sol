<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use ConfigKit\Repository\ImportBatchRepository;
use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\ModuleRepository;

/**
 * Phase 4 dalis 3 — orchestrates a library-items import batch through
 * the same lifecycle as the lookup-cells Runner:
 *
 *   create()  → 'received'   (file persisted, summary recorded)
 *   parse()   → 'parsed' → 'validated' (parser + validator)
 *   commit()  → 'applied' or 'failed' (insert/update or replace_all)
 *   cancel()  → 'cancelled'
 *
 * Engines untouched. wpdb access lives in repos only.
 *
 * Idempotency contract (IMPORT_WIZARD_SPEC §6):
 *   insert_update — match (library_key, item_key); update existing,
 *                   insert new. Re-running the same file is a no-op.
 *   replace_all   — soft-delete every item in the library, then
 *                   insert all valid rows. Items not in the file
 *                   stay deleted; matching item_keys are recreated
 *                   as new rows (their old ids are gone, but item_key
 *                   stays stable so cross-references keep working).
 */
final class LibraryItemRunner {

	public const TYPE_LIBRARY_ITEMS = 'library_items';

	public const MODE_INSERT_UPDATE = 'insert_update';
	public const MODE_REPLACE_ALL   = 'replace_all';

	public function __construct(
		private \wpdb $wpdb,
		private ImportBatchRepository $batches,
		private ImportRowRepository $rows,
		private LibraryItemRepository $items,
		private LibraryRepository $libraries,
		private LibraryItemParser $parser,
		private LibraryItemValidator $validator,
		private ?ModuleRepository $modules = null,
	) {}

	/**
	 * @param array{
	 *   import_type:string,
	 *   filename:string,
	 *   file_path:string,
	 *   target_library_key:string,
	 *   mode:string,
	 *   created_by?:int,
	 * } $input
	 *
	 * @return array{batch_id:int, batch_key:string}
	 */
	public function create( array $input ): array {
		$batch_key = bin2hex( random_bytes( 8 ) );
		$summary = [
			'target_library_key' => (string) $input['target_library_key'],
			'mode'               => (string) ( $input['mode'] ?? self::MODE_INSERT_UPDATE ),
			'file_path'          => (string) $input['file_path'],
		];
		$batch_id = $this->batches->create( [
			'batch_key'  => $batch_key,
			'import_type' => self::TYPE_LIBRARY_ITEMS,
			'filename'   => (string) $input['filename'],
			'status'     => ImportBatchRepository::STATE_RECEIVED,
			'created_by' => (int) ( $input['created_by'] ?? 0 ),
			'summary'    => $summary,
		] );
		return [ 'batch_id' => $batch_id, 'batch_key' => $batch_key ];
	}

	/**
	 * @return array{ok:bool, batch:array<string,mixed>|null, counts:array<string,int>, format:string, notes:list<string>, error?:string}
	 */
	public function parse( int $batch_id ): array {
		$batch = $this->batches->find_by_id( $batch_id );
		if ( $batch === null ) {
			return [ 'ok' => false, 'batch' => null, 'counts' => [], 'format' => '', 'notes' => [], 'error' => 'Batch not found.' ];
		}
		if ( ! in_array( $batch['status'], [
			ImportBatchRepository::STATE_RECEIVED,
			ImportBatchRepository::STATE_PARSED,
			ImportBatchRepository::STATE_VALIDATED,
			ImportBatchRepository::STATE_FAILED,
		], true ) ) {
			return [ 'ok' => false, 'batch' => $batch, 'counts' => [], 'format' => '', 'notes' => [], 'error' => 'Batch is in state "' . $batch['status'] . '" — cannot re-parse.' ];
		}

		$summary    = is_array( $batch['summary'] ?? null ) ? $batch['summary'] : [];
		$path       = (string) ( $summary['file_path'] ?? '' );
		$target_key = (string) ( $summary['target_library_key'] ?? '' );
		$mode       = (string) ( $summary['mode'] ?? self::MODE_INSERT_UPDATE );

		$this->batches->update( $batch_id, [ 'status' => ImportBatchRepository::STATE_PARSING ] );

		// Phase 4.2c — load the target module so the parser can route
		// schema-declared attribute columns into the attributes bucket
		// instead of dumping them into unknown_columns.
		$this->parser->set_module_context( $this->load_module_for_library( $target_key ) );

		try {
			$parsed = $this->parser->parse_file( $path );
		} catch ( \Throwable $e ) {
			$this->batches->update( $batch_id, [
				'status' => ImportBatchRepository::STATE_FAILED,
				'notes'  => $e->getMessage(),
			] );
			return [
				'ok'     => false,
				'batch'  => $this->batches->find_by_id( $batch_id ),
				'counts' => [],
				'format' => '',
				'notes'  => [],
				'error'  => $e->getMessage(),
			];
		}

		$this->rows->delete_for_batch( (string) $batch['batch_key'] );
		$this->batches->update( $batch_id, [ 'status' => ImportBatchRepository::STATE_PARSED ] );

		$validated = $this->validator->validate( $parsed['rows'], [
			'target_library_key' => $target_key,
			'mode'               => $mode,
		] );

		$bulk = [];
		foreach ( $validated as $r ) {
			$bulk[] = [
				'row_number'      => (int) $r['row_number'],
				'action'          => (string) $r['action'],
				'object_type'     => (string) ( $r['object_type'] ?? 'library_item' ),
				'object_key'      => (string) ( $r['object_key'] ?? '' ),
				'severity'        => (string) $r['severity'],
				'message'         => (string) ( $r['message'] ?? '' ),
				'raw_data'        => $r['raw'] ?? [],
				'normalized_data' => $r['normalized'] ?? [],
			];
		}
		if ( count( $bulk ) > 0 ) $this->rows->bulk_create( (string) $batch['batch_key'], $bulk );

		$counts = $this->rows->counts( (string) $batch['batch_key'] );

		$summary['format']        = $parsed['format'];
		$summary['sheet_titles']  = $parsed['sheet_titles'];
		$summary['parser_notes']  = $parsed['notes'];
		$summary['columns']       = $parsed['columns'] ?? [];
		$summary['stats']         = $this->compute_stats( $validated );
		$this->batches->update( $batch_id, [
			'status'  => ImportBatchRepository::STATE_VALIDATED,
			'summary' => $summary,
		] );

		return [
			'ok'     => true,
			'batch'  => $this->batches->find_by_id( $batch_id ),
			'counts' => $counts,
			'format' => $parsed['format'],
			'notes'  => $parsed['notes'],
		];
	}

	/**
	 * @return array{ok:bool, batch:array<string,mixed>|null, summary?:array<string,int>, error?:string}
	 */
	public function commit( int $batch_id ): array {
		$batch = $this->batches->find_by_id( $batch_id );
		if ( $batch === null ) {
			return [ 'ok' => false, 'batch' => null, 'error' => 'Batch not found.' ];
		}
		if ( $batch['status'] !== ImportBatchRepository::STATE_VALIDATED ) {
			return [
				'ok'    => false,
				'batch' => $batch,
				'error' => 'Batch must be in "validated" state before commit (current: "' . $batch['status'] . '").',
			];
		}

		$summary    = is_array( $batch['summary'] ?? null ) ? $batch['summary'] : [];
		$target_key = (string) ( $summary['target_library_key'] ?? '' );
		$mode       = (string) ( $summary['mode'] ?? self::MODE_INSERT_UPDATE );

		$library = $target_key !== '' ? $this->libraries->find_by_key( $target_key ) : null;
		if ( $library === null ) {
			$this->batches->update( $batch_id, [
				'status' => ImportBatchRepository::STATE_FAILED,
				'notes'  => 'Target library "' . $target_key . '" not found.',
			] );
			return [
				'ok'    => false,
				'batch' => $this->batches->find_by_id( $batch_id ),
				'error' => 'Target library not found.',
			];
		}

		$this->batches->update( $batch_id, [ 'status' => ImportBatchRepository::STATE_COMMITTING ] );

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			if ( $mode === self::MODE_REPLACE_ALL ) {
				$this->items->soft_delete_all_in_library( $target_key );
			}

			$rows  = $this->rows->all_for_batch( (string) $batch['batch_key'] );
			$stats = [ 'inserted' => 0, 'updated' => 0, 'skipped' => 0 ];

			foreach ( $rows as $r ) {
				if ( $r['severity'] === ImportRowRepository::SEVERITY_RED ) {
					$stats['skipped']++;
					continue;
				}
				if ( $r['action'] === ImportRowRepository::ACTION_SKIP ) {
					$stats['skipped']++;
					continue;
				}
				$norm = is_array( $r['normalized_data'] ?? null ) ? $r['normalized_data'] : [];
				$data = $this->build_repo_payload( $norm, $target_key );

				$existing = $mode === self::MODE_REPLACE_ALL
					? null
					: $this->items->find_by_library_and_key( $target_key, (string) $data['item_key'] );

				if ( $existing !== null ) {
					$this->items->update( (int) $existing['id'], $data );
					$stats['updated']++;
				} else {
					$this->items->create( $data );
					$stats['inserted']++;
				}
			}

			$this->wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			$this->batches->update( $batch_id, [
				'status'          => ImportBatchRepository::STATE_FAILED,
				'rollback_status' => 'rolled_back',
				'notes'           => $e->getMessage(),
			] );
			return [
				'ok'    => false,
				'batch' => $this->batches->find_by_id( $batch_id ),
				'error' => $e->getMessage(),
			];
		}

		$summary['commit_stats'] = $stats;
		$this->batches->update( $batch_id, [
			'status'       => ImportBatchRepository::STATE_APPLIED,
			'committed_at' => function_exists( 'current_time' ) ? \current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
			'summary'      => $summary,
		] );

		return [
			'ok'      => true,
			'batch'   => $this->batches->find_by_id( $batch_id ),
			'summary' => $stats,
		];
	}

	public function cancel( int $batch_id ): array {
		$batch = $this->batches->find_by_id( $batch_id );
		if ( $batch === null ) {
			return [ 'ok' => false, 'batch' => null, 'error' => 'Batch not found.' ];
		}
		if ( in_array( $batch['status'], [ ImportBatchRepository::STATE_APPLIED, ImportBatchRepository::STATE_CANCELLED ], true ) ) {
			return [ 'ok' => false, 'batch' => $batch, 'error' => 'Batch is already terminal.' ];
		}
		$this->batches->update( $batch_id, [ 'status' => ImportBatchRepository::STATE_CANCELLED ] );
		return [ 'ok' => true, 'batch' => $this->batches->find_by_id( $batch_id ) ];
	}

	/**
	 * Phase 4.2c — best-effort module lookup. Returns null when the
	 * module repository wasn't injected (e.g. older test wiring) or
	 * the library has no matching module; the parser then falls back
	 * to its built-in attribute list.
	 *
	 * @return array<string,mixed>|null
	 */
	private function load_module_for_library( string $library_key ): ?array {
		if ( $this->modules === null || $library_key === '' ) return null;
		$library = $this->libraries->find_by_key( $library_key );
		if ( $library === null ) return null;
		$module_key = (string) ( $library['module_key'] ?? '' );
		if ( $module_key === '' ) return null;
		return $this->modules->find_by_key( $module_key );
	}

	/**
	 * Map the validator's normalised shape into the column payload the
	 * repository's create / update method consumes. Attributes (brand,
	 * collection, color_family, image_url, main_image_url) are
	 * promoted to top-level columns the repo already understands;
	 * filter_tags / compatibility_tags become the `filters` /
	 * `compatibility` lists.
	 *
	 * @param array<string,mixed> $norm
	 * @return array<string,mixed>
	 */
	private function build_repo_payload( array $norm, string $library_key ): array {
		$attrs = is_array( $norm['attributes'] ?? null ) ? $norm['attributes'] : [];

		$filters       = is_array( $attrs['filter_tags']        ?? null ) ? array_values( $attrs['filter_tags'] )        : [];
		$compatibility = is_array( $attrs['compatibility_tags'] ?? null ) ? array_values( $attrs['compatibility_tags'] ) : [];
		// Strip tag-style attrs from the JSON bag so they don't end up
		// duplicated in attributes_json — the schema stores them as
		// dedicated columns.
		unset( $attrs['filter_tags'], $attrs['compatibility_tags'] );

		$payload = [
			'library_key'     => $library_key,
			'item_key'        => (string) ( $norm['item_key'] ?? '' ),
			'label'           => (string) ( $norm['label'] ?? '' ),
			'short_label'     => $norm['short_label'] ?? null,
			'description'     => $norm['description'] ?? null,
			'sku'             => $norm['sku'] ?? null,
			'image_url'       => $attrs['image_url']      ?? null,
			'main_image_url'  => $attrs['main_image_url'] ?? null,
			'price'           => $norm['price'] ?? null,
			'sale_price'      => $norm['sale_price'] ?? null,
			'price_group_key' => (string) ( $norm['price_group_key'] ?? '' ),
			'color_family'    => $attrs['color_family'] ?? null,
			'woo_product_id'  => $norm['woo_product_id'] ?? null,
			'filters'         => $filters,
			'compatibility'   => $compatibility,
			// brand + collection currently have no first-class column
			// on library_items (Phase 1 schema lives on libraries) —
			// stash them in attributes_json for downstream consumers.
			'attributes'      => $this->residual_attrs( $attrs ),
			'is_active'       => array_key_exists( 'is_active', $norm ) ? (bool) $norm['is_active'] : true,
			'sort_order'      => (int) ( $norm['sort_order'] ?? 0 ),
			'price_source'    => (string) ( $norm['price_source'] ?? 'configkit' ),
			'item_type'       => (string) ( $norm['item_type']    ?? 'simple_option' ),
		];

		// Optional bundle JSON straight from the file. The runner does
		// no shape validation here — the validator stage is the source
		// of truth.
		if ( ! empty( $norm['bundle_components_json'] ) ) {
			$decoded = json_decode( (string) $norm['bundle_components_json'], true );
			if ( is_array( $decoded ) ) $payload['bundle_components'] = $decoded;
		}
		return $payload;
	}

	/**
	 * Drop columns that already became top-level fields in the
	 * payload. The remaining bucket is what gets written to
	 * attributes_json.
	 *
	 * @param array<string,mixed> $attrs
	 * @return array<string,mixed>
	 */
	private function residual_attrs( array $attrs ): array {
		foreach ( [ 'image_url', 'main_image_url', 'color_family' ] as $promoted ) {
			unset( $attrs[ $promoted ] );
		}
		return $attrs;
	}

	/**
	 * @param list<array<string,mixed>> $validated
	 * @return array<string,mixed>
	 */
	private function compute_stats( array $validated ): array {
		$libraries = [];
		$prices    = [];
		$sources   = [];
		$types     = [];
		foreach ( $validated as $r ) {
			if ( ( $r['severity'] ?? '' ) === ImportRowRepository::SEVERITY_RED ) continue;
			$norm = $r['normalized'] ?? [];
			if ( ! empty( $norm['library_key'] ) ) $libraries[ (string) $norm['library_key'] ] = true;
			if ( is_float( $norm['price'] ?? null ) ) $prices[] = (float) $norm['price'];
			if ( ! empty( $norm['price_source'] ) ) $sources[ (string) $norm['price_source'] ] = true;
			if ( ! empty( $norm['item_type'] ) ) $types[ (string) $norm['item_type'] ] = true;
		}
		return [
			'libraries'     => array_values( array_keys( $libraries ) ),
			'price_min'     => count( $prices ) > 0 ? min( $prices ) : null,
			'price_max'     => count( $prices ) > 0 ? max( $prices ) : null,
			'price_sources' => array_values( array_keys( $sources ) ),
			'item_types'    => array_values( array_keys( $types ) ),
		];
	}
}
