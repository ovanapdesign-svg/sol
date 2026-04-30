<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Import\FormatDetector;
use ConfigKit\Import\LibraryItemParser;
use ConfigKit\Import\LibraryItemRunner;
use ConfigKit\Import\Parser as LookupParser;
use ConfigKit\Import\Runner as LookupRunner;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\ModuleRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Phase 4 dalis 4 BUG 5 — Excel-first wizard.
 *
 * The advanced flow is "Create lookup table → Save → Open it → Import".
 * Owners want to drop an .xlsx and have the system create the lookup
 * table or library automatically. This service powers the new flow:
 *
 *   detect(string $file_path)  → tells the UI what to propose
 *   create_and_import(...)      → creates entity + runs import in one
 *                                  call after the owner confirms.
 *
 * Lookup tables map to Format A (grid) or Format B (long). Libraries
 * map to Format C (long with library_key + item_key). Multi-library
 * files are rejected here — owner uses the standard wizard instead so
 * each library's confirm step gets its own attention.
 */
final class QuickImportService {

	public function __construct(
		private LookupTableService $lookup_tables,
		private LibraryService $libraries,
		private ModuleRepository $modules,
		private LookupTableRepository $lookup_table_repo,
		private LibraryRepository $library_repo,
		private LookupRunner $lookup_runner,
		private LibraryItemRunner $library_runner,
	) {}

	/**
	 * @return array{
	 *   ok:bool,
	 *   format?:string,
	 *   target_type?:'lookup_table'|'library',
	 *   suggested_name?:string,
	 *   suggested_key?:string,
	 *   sample?:array<string,mixed>,
	 *   available_modules?:list<array{module_key:string,name:string}>,
	 *   error?:string
	 * }
	 */
	public function detect( string $file_path, string $original_filename = '' ): array {
		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return [ 'ok' => false, 'error' => 'Uploaded file not readable.' ];
		}
		try {
			$book = IOFactory::load( $file_path );
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'Could not open .xlsx: ' . $e->getMessage() ];
		}

		$detector = new FormatDetector();
		$format   = $detector->detect( $book );

		$base_name = $this->clean_name_from_filename( $original_filename );

		if ( $format === FormatDetector::FORMAT_C ) {
			$parser = new LibraryItemParser();
			$result = $parser->parse_spreadsheet( $book );
			$lib_keys = [];
			foreach ( $result['rows'] as $row ) {
				$key = $row['normalized']['library_key'] ?? null;
				if ( is_string( $key ) && $key !== '' ) $lib_keys[ $key ] = true;
			}
			$lib_keys = array_keys( $lib_keys );

			if ( count( $lib_keys ) > 1 ) {
				return [
					'ok'    => false,
					'error' => sprintf(
						'File spans %d libraries (%s) — Quick import only supports a single library per file. Use the standard wizard.',
						count( $lib_keys ),
						implode( ', ', array_slice( $lib_keys, 0, 3 ) ) . ( count( $lib_keys ) > 3 ? ', …' : '' )
					),
				];
			}

			$suggested_key  = count( $lib_keys ) === 1 ? $lib_keys[0] : $this->slugify( $base_name );
			$suggested_name = $base_name !== '' ? $base_name : $this->humanize( $suggested_key );

			return [
				'ok'                => true,
				'format'            => $format,
				'target_type'       => 'library',
				'suggested_name'    => $suggested_name,
				'suggested_key'     => $suggested_key !== '' ? $suggested_key : 'imported_library',
				'sample'            => [
					'rows_total' => count( $result['rows'] ),
					'columns'    => $result['columns'] ?? [],
					'libraries'  => $lib_keys,
				],
				'available_modules' => $this->list_active_modules(),
			];
		}

		if ( $format === FormatDetector::FORMAT_A || $format === FormatDetector::FORMAT_B ) {
			$parser = new LookupParser();
			$result = $parser->parse_spreadsheet( $book );

			$suggested_name = $base_name !== '' ? $base_name : 'Imported lookup table';
			$suggested_key  = $this->slugify( $suggested_name );

			return [
				'ok'             => true,
				'format'         => $format,
				'target_type'    => 'lookup_table',
				'suggested_name' => $suggested_name,
				'suggested_key'  => $suggested_key !== '' ? $suggested_key : 'imported_table',
				'sample'         => [
					'rows_total'  => count( $result['rows'] ),
					'sheet_titles' => $result['sheet_titles'],
				],
			];
		}

		return [
			'ok'    => false,
			'error' => 'Could not auto-detect format. The file does not match Format A (grid), Format B (long), or Format C (library items).',
		];
	}

	/**
	 * @param array{
	 *   target_type:'lookup_table'|'library',
	 *   name:string,
	 *   technical_key:string,
	 *   mode?:string,
	 *   module_key?:string,
	 *   filename?:string,
	 * } $confirmed
	 *
	 * @return array{
	 *   ok:bool,
	 *   batch_id?:int,
	 *   target_type?:string,
	 *   target?:array<string,mixed>,
	 *   error?:string,
	 *   errors?:list<array<string,mixed>>
	 * }
	 */
	public function create_and_import( string $file_path, array $confirmed ): array {
		$mode     = (string) ( $confirmed['mode'] ?? 'insert_update' );
		$filename = (string) ( $confirmed['filename'] ?? basename( $file_path ) );

		if ( $confirmed['target_type'] === 'lookup_table' ) {
			return $this->create_lookup_table_and_import( $file_path, $filename, $confirmed, $mode );
		}
		if ( $confirmed['target_type'] === 'library' ) {
			return $this->create_library_and_import( $file_path, $filename, $confirmed, $mode );
		}
		return [ 'ok' => false, 'error' => 'Unknown target_type.' ];
	}

	/**
	 * @param array<string,mixed> $confirmed
	 */
	private function create_lookup_table_and_import( string $file_path, string $filename, array $confirmed, string $mode ): array {
		$key  = (string) $confirmed['technical_key'];
		$name = (string) $confirmed['name'];

		// Idempotent: if a table with this key already exists, treat it as
		// the target for the import — the owner can re-run a Quick import
		// after a fix without "duplicate key" errors.
		$existing = $this->lookup_table_repo->find_by_key( $key );
		if ( $existing === null ) {
			$created = $this->lookup_tables->create( [
				'lookup_table_key'     => $key,
				'name'                 => $name,
				'unit'                 => 'mm',
				'match_mode'           => 'round_up',
				'supports_price_group' => true,
				'is_active'            => true,
			] );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [ 'ok' => false, 'error' => 'Could not create lookup table.', 'errors' => $created['errors'] ?? [] ];
			}
		}

		$created_batch = $this->lookup_runner->create( [
			'import_type'             => 'lookup_cells',
			'filename'                => $filename,
			'file_path'               => $file_path,
			'target_lookup_table_key' => $key,
			'mode'                    => $mode,
		] );
		$parsed = $this->lookup_runner->parse( $created_batch['batch_id'] );
		if ( ! ( $parsed['ok'] ?? false ) ) {
			return [ 'ok' => false, 'error' => $parsed['error'] ?? 'Parse failed.' ];
		}
		$committed = $this->lookup_runner->commit( $created_batch['batch_id'] );
		if ( ! ( $committed['ok'] ?? false ) ) {
			return [ 'ok' => false, 'error' => $committed['error'] ?? 'Commit failed.', 'batch_id' => $created_batch['batch_id'] ];
		}

		return [
			'ok'          => true,
			'batch_id'    => $created_batch['batch_id'],
			'target_type' => 'lookup_table',
			'target'      => $this->lookup_table_repo->find_by_key( $key ),
			'summary'     => $committed['summary'] ?? [],
		];
	}

	/**
	 * @param array<string,mixed> $confirmed
	 */
	private function create_library_and_import( string $file_path, string $filename, array $confirmed, string $mode ): array {
		$key        = (string) $confirmed['technical_key'];
		$name       = (string) $confirmed['name'];
		$module_key = (string) ( $confirmed['module_key'] ?? '' );
		if ( $module_key === '' ) {
			return [ 'ok' => false, 'error' => 'A module is required when creating a library from Excel.' ];
		}

		$existing = $this->library_repo->find_by_key( $key );
		if ( $existing === null ) {
			$created = $this->libraries->create( [
				'library_key' => $key,
				'name'        => $name,
				'module_key'  => $module_key,
				'is_active'   => true,
			] );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [ 'ok' => false, 'error' => 'Could not create library.', 'errors' => $created['errors'] ?? [] ];
			}
		}

		$created_batch = $this->library_runner->create( [
			'import_type'        => 'library_items',
			'filename'           => $filename,
			'file_path'          => $file_path,
			'target_library_key' => $key,
			'mode'               => $mode,
		] );
		$parsed = $this->library_runner->parse( $created_batch['batch_id'] );
		if ( ! ( $parsed['ok'] ?? false ) ) {
			return [ 'ok' => false, 'error' => $parsed['error'] ?? 'Parse failed.' ];
		}
		$committed = $this->library_runner->commit( $created_batch['batch_id'] );
		if ( ! ( $committed['ok'] ?? false ) ) {
			return [ 'ok' => false, 'error' => $committed['error'] ?? 'Commit failed.', 'batch_id' => $created_batch['batch_id'] ];
		}

		return [
			'ok'          => true,
			'batch_id'    => $created_batch['batch_id'],
			'target_type' => 'library',
			'target'      => $this->library_repo->find_by_key( $key ),
			'summary'     => $committed['summary'] ?? [],
		];
	}

	/**
	 * Strip extension and normalise spaces.
	 */
	private function clean_name_from_filename( string $original ): string {
		$base = pathinfo( $original, PATHINFO_FILENAME );
		// Replace common separators with spaces, collapse whitespace.
		$base = preg_replace( '/[_\-]+/', ' ', (string) $base );
		$base = preg_replace( '/\s+/', ' ', (string) $base );
		return trim( (string) $base );
	}

	private function humanize( string $key ): string {
		$out = preg_replace( '/[_\-]+/', ' ', $key );
		$out = trim( (string) $out );
		return $out === '' ? '' : strtoupper( substr( $out, 0, 1 ) ) . substr( $out, 1 );
	}

	private function slugify( string $raw ): string {
		$out = strtolower( $raw );
		$out = preg_replace( '/[^a-z0-9]+/', '_', $out );
		$out = preg_replace( '/^_+|_+$/', '', (string) $out );
		return (string) $out;
	}

	/**
	 * @return list<array{module_key:string,name:string}>
	 */
	private function list_active_modules(): array {
		$listing = $this->modules->list( 1, 200 );
		$out = [];
		foreach ( $listing['items'] ?? [] as $m ) {
			if ( empty( $m['is_active'] ) ) continue;
			$out[] = [
				'module_key' => (string) $m['module_key'],
				'name'       => (string) $m['name'],
			];
		}
		return $out;
	}
}
