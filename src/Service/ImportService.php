<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Import\Runner;
use ConfigKit\Repository\ImportBatchRepository;
use ConfigKit\Repository\ImportRowRepository;

/**
 * Public service surface for the wizard's REST controller. Delegates
 * batch lifecycle to the Runner and read access to the repos.
 */
final class ImportService {

	public function __construct(
		private Runner $runner,
		private ImportBatchRepository $batches,
		private ImportRowRepository $rows,
	) {}

	/**
	 * @param array<string,mixed> $input
	 * @return array{batch_id:int, batch_key:string}
	 */
	public function create_and_parse( array $input ): array {
		$created = $this->runner->create( $input );
		$this->runner->parse( $created['batch_id'] );
		return $created;
	}

	public function parse( int $batch_id ): array {
		return $this->runner->parse( $batch_id );
	}

	public function commit( int $batch_id ): array {
		return $this->runner->commit( $batch_id );
	}

	public function cancel( int $batch_id ): array {
		return $this->runner->cancel( $batch_id );
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
