<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Import\LibraryItemRunner;
use ConfigKit\Import\Runner;
use ConfigKit\Repository\ImportBatchRepository;
use ConfigKit\Repository\ImportRowRepository;

/**
 * Public service surface for the wizard's REST controller. Delegates
 * batch lifecycle to a runner picked off the batch's `import_type`.
 *
 * Phase 4 dalis 3 — library_items joins lookup_cells. Both runners
 * share the same state machine (`ImportBatchRepository` constants);
 * only the parser / validator / commit step differ.
 */
final class ImportService {

	public function __construct(
		private Runner $lookup_runner,
		private ImportBatchRepository $batches,
		private ImportRowRepository $rows,
		private ?LibraryItemRunner $library_runner = null,
	) {}

	/**
	 * @param array<string,mixed> $input
	 * @return array{batch_id:int, batch_key:string}
	 */
	public function create_and_parse( array $input ): array {
		$type = (string) ( $input['import_type'] ?? Runner::TYPE_LOOKUP_CELLS );
		if ( $type === LibraryItemRunner::TYPE_LIBRARY_ITEMS ) {
			$created = $this->require_library_runner()->create( $input );
			$this->require_library_runner()->parse( $created['batch_id'] );
			return $created;
		}
		$created = $this->lookup_runner->create( $input );
		$this->lookup_runner->parse( $created['batch_id'] );
		return $created;
	}

	public function parse( int $batch_id ): array {
		return $this->dispatch( $batch_id, 'parse' );
	}

	public function commit( int $batch_id ): array {
		return $this->dispatch( $batch_id, 'commit' );
	}

	public function cancel( int $batch_id ): array {
		return $this->dispatch( $batch_id, 'cancel' );
	}

	private function dispatch( int $batch_id, string $method ): array {
		$batch = $this->batches->find_by_id( $batch_id );
		if ( $batch !== null && (string) ( $batch['import_type'] ?? '' ) === LibraryItemRunner::TYPE_LIBRARY_ITEMS ) {
			return $this->require_library_runner()->{$method}( $batch_id );
		}
		return $this->lookup_runner->{$method}( $batch_id );
	}

	private function require_library_runner(): LibraryItemRunner {
		if ( $this->library_runner === null ) {
			throw new \RuntimeException( 'Library-items import runner is not wired in this environment.' );
		}
		return $this->library_runner;
	}

	public function get( int $batch_id, int $row_page = 1, int $row_per_page = 100 ): ?array {
		$batch = $this->batches->find_by_id( $batch_id );
		if ( $batch === null ) return null;
		$rows = $this->rows->list_for_batch( (string) $batch['batch_key'], [], $row_page, $row_per_page );
		$batch['rows']   = $rows;
		$batch['counts'] = $this->rows->counts( (string) $batch['batch_key'] );
		return $batch;
	}

	/**
	 * @param array<string,mixed> $filters
	 */
	public function list( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		return $this->batches->list( $filters, $page, $per_page );
	}
}
