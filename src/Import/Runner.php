<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use ConfigKit\Repository\ImportBatchRepository;
use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;

/**
 * Orchestrates the lifecycle of an import batch:
 *
 *   create()  — receive file, store in import_batches with state
 *               'received'
 *   parse()   — drive Parser + Validator, store import_rows, move
 *               state to 'validated' (or 'failed' on parse error)
 *   commit()  — apply approved rows to wp_configkit_lookup_cells
 *               idempotently, transition to 'applied' (or 'failed').
 *               In "replace_all" mode, all existing cells in the
 *               target table are deleted first.
 *   cancel()  — owner backs out before commit; state → 'cancelled'.
 *
 * Engines are NOT touched. wpdb access lives in repos only.
 */
final class Runner {

	public const TYPE_LOOKUP_CELLS = 'lookup_cells';

	public const MODE_INSERT_UPDATE = 'insert_update';
	public const MODE_REPLACE_ALL   = 'replace_all';

	public function __construct(
		private \wpdb $wpdb,
		private ImportBatchRepository $batches,
		private ImportRowRepository $rows,
		private LookupCellRepository $cells,
		private LookupTableRepository $tables,
		private Parser $parser,
		private Validator $validator,
	) {}

	/**
	 * Persist the upload, store its file path, and transition to
	 * 'received'. The wizard calls parse() right after.
	 *
	 * @param array{
	 *   import_type:string,
	 *   filename:string,
	 *   file_path:string,
	 *   target_lookup_table_key:string,
	 *   mode:string,
	 *   created_by?:int,
	 * } $input
	 *
	 * @return array{batch_id:int, batch_key:string}
	 */
	public function create( array $input ): array {
		$batch_key = bin2hex( random_bytes( 8 ) );
		$summary = [
			'target_lookup_table_key' => (string) $input['target_lookup_table_key'],
			'mode'                    => (string) ( $input['mode'] ?? self::MODE_INSERT_UPDATE ),
			'file_path'               => (string) $input['file_path'],
		];
		$batch_id = $this->batches->create( [
			'batch_key'  => $batch_key,
			'import_type' => (string) ( $input['import_type'] ?? self::TYPE_LOOKUP_CELLS ),
			'filename'   => (string) $input['filename'],
			'status'     => ImportBatchRepository::STATE_RECEIVED,
			'created_by' => (int) ( $input['created_by'] ?? 0 ),
			'summary'    => $summary,
		] );
		return [ 'batch_id' => $batch_id, 'batch_key' => $batch_key ];
	}

	/**
	 * Run parser + validator and persist annotated rows. Returns the
	 * fresh batch record + counts so the controller can render the
	 * preview without an extra fetch.
	 *
	 * @return array{ok:bool, batch:array<string,mixed>|null, counts:array<string,int>, format:string, notes:list<string>, error?:string}
	 */
	public function parse( int $batch_id ): array {
		$batch = $this->batches->find_by_id( $batch_id );
		if ( $batch === null ) {
			return [ 'ok' => false, 'batch' => null, 'counts' => [], 'format' => '', 'notes' => [], 'error' => 'Batch not found.' ];
		}
		if ( ! in_array( $batch['status'], [ ImportBatchRepository::STATE_RECEIVED, ImportBatchRepository::STATE_PARSED, ImportBatchRepository::STATE_VALIDATED, ImportBatchRepository::STATE_FAILED ], true ) ) {
			return [ 'ok' => false, 'batch' => $batch, 'counts' => [], 'format' => '', 'notes' => [], 'error' => 'Batch is in state "' . $batch['status'] . '" — cannot re-parse.' ];
		}

		$summary = is_array( $batch['summary'] ?? null ) ? $batch['summary'] : [];
		$path    = (string) ( $summary['file_path'] ?? '' );
		$target  = (string) ( $summary['target_lookup_table_key'] ?? '' );
		$mode    = (string) ( $summary['mode'] ?? self::MODE_INSERT_UPDATE );

		$this->batches->update( $batch_id, [ 'status' => ImportBatchRepository::STATE_PARSING ] );

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

		// Wipe any prior rows so re-parse is idempotent.
		$this->rows->delete_for_batch( (string) $batch['batch_key'] );

		$this->batches->update( $batch_id, [ 'status' => ImportBatchRepository::STATE_PARSED ] );

		$validated = $this->validator->validate( $parsed['rows'], [
			'target_lookup_table_key' => $target,
			'mode'                    => $mode,
		] );

		// Persist as import_rows. Even if the parsed list is empty,
		// the batch still progresses to 'validated' so the preview
		// can show "0 rows parsed" clearly.
		$bulk = [];
		foreach ( $validated as $r ) {
			$bulk[] = [
				'row_number'      => (int) $r['row_number'],
				'action'          => (string) $r['action'],
				'object_type'     => (string) ( $r['object_type'] ?? 'lookup_cell' ),
				'object_key'      => (string) ( $r['object_key'] ?? '' ),
				'severity'        => (string) $r['severity'],
				'message'         => (string) ( $r['message'] ?? '' ),
				'raw_data'        => $r['raw'] ?? [],
				'normalized_data' => $r['normalized'] ?? [],
			];
		}
		if ( count( $bulk ) > 0 ) $this->rows->bulk_create( (string) $batch['batch_key'], $bulk );

		$counts = $this->rows->counts( (string) $batch['batch_key'] );

		// Add stats useful for the preview header.
		$summary['format']        = $parsed['format'];
		$summary['sheet_titles']  = $parsed['sheet_titles'];
		$summary['parser_notes']  = $parsed['notes'];
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
	 * Apply the validated rows to the target lookup table. Idempotent
	 * within a batch — running commit() twice is a no-op the second
	 * time because state moves to 'applied'.
	 *
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

		$summary = is_array( $batch['summary'] ?? null ) ? $batch['summary'] : [];
		$target  = (string) ( $summary['target_lookup_table_key'] ?? '' );
		$mode    = (string) ( $summary['mode'] ?? self::MODE_INSERT_UPDATE );

		$this->batches->update( $batch_id, [ 'status' => ImportBatchRepository::STATE_COMMITTING ] );

		// Wrap insert+update in a transaction so a single bad row
		// rolls back the whole batch (per spec §9.3).
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			if ( $mode === self::MODE_REPLACE_ALL ) {
				$this->cells->delete_all_in_table( $target );
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
				$data = [
					'lookup_table_key' => $target,
					'width'            => (int) ( $norm['width'] ?? 0 ),
					'height'           => (int) ( $norm['height'] ?? 0 ),
					'price_group_key'  => (string) ( $norm['price_group_key'] ?? '' ),
					'price'            => (float) ( $norm['price'] ?? 0 ),
				];

				// Idempotent insert/update per business-key tuple.
				$existing = $this->cells->find_by_coordinates(
					$target,
					$data['width'],
					$data['height'],
					$data['price_group_key']
				);
				if ( $existing !== null ) {
					$this->cells->update( (int) $existing['id'], $data );
					$stats['updated']++;
				} else {
					$this->cells->create( $data );
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

	/**
	 * Cancel a batch that hasn't been committed.
	 */
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
	 * @param list<array<string,mixed>> $validated
	 * @return array<string,mixed>
	 */
	private function compute_stats( array $validated ): array {
		$widths = [];
		$heights = [];
		$prices = [];
		$pgs    = [];
		foreach ( $validated as $r ) {
			if ( ( $r['severity'] ?? '' ) === ImportRowRepository::SEVERITY_RED ) continue;
			$norm = $r['normalized'] ?? [];
			if ( is_int( $norm['width']  ?? null ) ) $widths[]  = (int) $norm['width'];
			if ( is_int( $norm['height'] ?? null ) ) $heights[] = (int) $norm['height'];
			if ( is_float( $norm['price'] ?? null ) ) $prices[] = (float) $norm['price'];
			$pg = (string) ( $norm['price_group_key'] ?? '' );
			if ( $pg !== '' ) $pgs[ $pg ] = true;
		}
		return [
			'width_min'    => count( $widths ) > 0 ? min( $widths ) : null,
			'width_max'    => count( $widths ) > 0 ? max( $widths ) : null,
			'height_min'   => count( $heights ) > 0 ? min( $heights ) : null,
			'height_max'   => count( $heights ) > 0 ? max( $heights ) : null,
			'price_min'    => count( $prices ) > 0 ? min( $prices ) : null,
			'price_max'    => count( $prices ) > 0 ? max( $prices ) : null,
			'price_groups' => array_values( array_keys( $pgs ) ),
		];
	}
}
