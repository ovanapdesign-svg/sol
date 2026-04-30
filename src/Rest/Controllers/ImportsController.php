<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Import\UploadPaths;
use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\ImportService;
use ConfigKit\Service\QuickImportService;

/**
 * REST surface for the import wizard.
 *
 * Routes:
 *   POST   /imports                        — multipart upload, creates batch + auto-parses
 *   GET    /imports                        — paginated batch list
 *   GET    /imports/{batch_id}             — batch + paginated rows
 *   POST   /imports/{batch_id}/parse       — re-parse / re-validate
 *   POST   /imports/{batch_id}/commit      — apply to lookup_cells
 *   POST   /imports/{batch_id}/cancel      — terminal cancel
 *
 * Capability: configkit_manage_lookup_tables (the only import target
 * available in this Phase 4 chunk).
 */
final class ImportsController extends AbstractController {

	private const CAP_LOOKUP_CELLS  = 'configkit_manage_lookup_tables';
	private const CAP_LIBRARY_ITEMS = 'configkit_manage_libraries';
	private const ALLOWED_TYPES     = [ 'lookup_cells', 'library_items' ];
	private const ALLOWED_MODES     = [ 'insert_update', 'replace_all' ];
	private const MAX_BYTES         = 10 * 1024 * 1024; // 10 MB
	private const ALLOWED_MIME    = [
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-excel',
		'application/octet-stream',
		'application/zip',
	];

	public function __construct(
		private ImportService $service,
		private ?QuickImportService $quick = null,
	) {}

	public function register_routes(): void {
		\register_rest_route( self::NAMESPACE, '/imports', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list' ],
				'permission_callback' => $this->require_either_cap(),
				'args'                => [
					'page'        => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
					'per_page'    => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
					'status'      => [ 'type' => 'string' ],
					'import_type' => [ 'type' => 'string' ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => $this->require_either_cap(),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read' ],
				'permission_callback' => $this->require_either_cap(),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)/parse', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'parse' ],
				'permission_callback' => $this->require_either_cap(),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)/commit', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'commit' ],
				'permission_callback' => $this->require_either_cap(),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)/cancel', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => $this->require_either_cap(),
			],
		] );

		// Phase 4 dalis 4 — Quick import (Excel-first wizard).
		\register_rest_route( self::NAMESPACE, '/imports/quick/detect', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'quick_detect' ],
				'permission_callback' => $this->require_either_cap(),
			],
		] );
		\register_rest_route( self::NAMESPACE, '/imports/quick/create', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'quick_create' ],
				'permission_callback' => $this->require_either_cap(),
			],
		] );
	}

	/**
	 * Phase 4 dalis 3 — let owners with EITHER capability hit the
	 * import routes. Per-import-type fine-grained gating happens
	 * inside `create()`.
	 */
	private function require_either_cap(): \Closure {
		return static function (): bool {
			return \current_user_can( self::CAP_LOOKUP_CELLS )
				|| \current_user_can( self::CAP_LIBRARY_ITEMS );
		};
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [];
		if ( $request->get_param( 'status' )      !== null ) $filters['status']      = (string) $request->get_param( 'status' );
		if ( $request->get_param( 'import_type' ) !== null ) $filters['import_type'] = (string) $request->get_param( 'import_type' );
		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 50 );
		return $this->ok( $this->service->list( $filters, $page === 0 ? 1 : $page, $per_page === 0 ? 50 : $per_page ) );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = (int) $request['batch_id'];
		$page     = (int) ( $request->get_param( 'row_page' ) ?? 1 );
		$per_page = (int) ( $request->get_param( 'row_per_page' ) ?? 100 );
		$record   = $this->service->get( $batch_id, $page === 0 ? 1 : $page, $per_page === 0 ? 100 : $per_page );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Import batch not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_file_params();
		$file  = is_array( $files ) && isset( $files['file'] ) ? $files['file'] : null;
		if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return $this->error( 'no_file', 'Upload a single .xlsx file in the "file" form field.', [], 400 );
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return $this->error( 'file_too_big', 'File exceeds the 10 MB limit.', [], 400 );
		}

		$tmp_name = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp_name ) && ! is_file( $tmp_name ) ) {
			return $this->error( 'no_file', 'Uploaded file is missing.', [], 400 );
		}

		$ext = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( $ext !== 'xlsx' ) {
			return $this->error( 'bad_extension', 'Only .xlsx files are accepted in this Phase 4 chunk.', [], 400 );
		}
		if ( ! empty( $file['type'] ) && ! in_array( (string) $file['type'], self::ALLOWED_MIME, true ) ) {
			return $this->error( 'bad_mime', 'Unrecognized MIME type: ' . (string) $file['type'], [], 400 );
		}

		// Move the upload into a stable plugin-owned dir so the parser
		// can re-open it. Owner-supplied filenames are not trusted.
		$import_type = (string) ( $request->get_param( 'import_type' ) ?? 'lookup_cells' );
		$mode        = (string) ( $request->get_param( 'mode' ) ?? 'insert_update' );

		if ( ! in_array( $import_type, self::ALLOWED_TYPES, true ) ) {
			return $this->error( 'unsupported_type', 'Unknown import_type "' . $import_type . '".', [], 400 );
		}
		if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
			return $this->error( 'invalid_mode', 'mode must be insert_update or replace_all.', [], 400 );
		}

		// Per-type gate: lookup_cells needs lookup-table cap; library_items
		// needs libraries cap. Owners with the wrong cap can't smuggle one
		// type through a route admin authorised them for the other.
		$needs_cap = $import_type === 'library_items' ? self::CAP_LIBRARY_ITEMS : self::CAP_LOOKUP_CELLS;
		if ( ! \current_user_can( $needs_cap ) ) {
			return $this->error( 'forbidden', 'You do not have permission to run this import type.', [], 403 );
		}

		$target_lookup = (string) ( $request->get_param( 'target_lookup_table_key' ) ?? '' );
		$target_lib    = (string) ( $request->get_param( 'target_library_key' ) ?? '' );

		if ( $import_type === 'library_items' ) {
			if ( $target_lib === '' ) {
				return $this->error( 'missing_target', 'target_library_key is required for library_items imports.', [], 400 );
			}
		} elseif ( $target_lookup === '' ) {
			return $this->error( 'missing_target', 'target_lookup_table_key is required.', [], 400 );
		}

		$stored_path = $this->store_upload( $tmp_name, (string) $file['name'] );
		if ( $stored_path === null ) {
			return $this->error( 'store_failed', 'Could not store the uploaded file.', [], 500 );
		}

		$payload = [
			'import_type' => $import_type,
			'filename'    => (string) $file['name'],
			'file_path'   => $stored_path,
			'mode'        => $mode,
			'created_by'  => function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0,
		];
		if ( $import_type === 'library_items' ) {
			$payload['target_library_key'] = $target_lib;
		} else {
			$payload['target_lookup_table_key'] = $target_lookup;
		}

		try {
			$result = $this->service->create_and_parse( $payload );
		} catch ( \Throwable $e ) {
			return $this->error( 'parse_failed', $e->getMessage(), [], 500 );
		}

		$record = $this->service->get( $result['batch_id'] );
		return $this->ok( [ 'record' => $record ], 201 );
	}

	public function parse( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = (int) $request['batch_id'];
		$result   = $this->service->parse( $batch_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'parse_failed', (string) ( $result['error'] ?? 'Parse failed.' ), [ 'batch' => $result['batch'] ?? null ], 400 );
		}
		return $this->ok( [ 'record' => $this->service->get( $batch_id ), 'counts' => $result['counts'], 'format' => $result['format'] ] );
	}

	public function commit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = (int) $request['batch_id'];
		$result   = $this->service->commit( $batch_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'commit_failed', (string) ( $result['error'] ?? 'Commit failed.' ), [ 'batch' => $result['batch'] ?? null ], 400 );
		}
		return $this->ok( [ 'record' => $this->service->get( $batch_id ), 'summary' => $result['summary'] ?? [] ] );
	}

	public function cancel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$batch_id = (int) $request['batch_id'];
		$result   = $this->service->cancel( $batch_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'cancel_failed', (string) ( $result['error'] ?? 'Cancel failed.' ), [ 'batch' => $result['batch'] ?? null ], 400 );
		}
		return $this->ok( [ 'record' => $this->service->get( $batch_id ) ] );
	}

	/**
	 * Phase 4 dalis 4 — Quick import: file upload + detect.
	 */
	public function quick_detect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->quick === null ) {
			return $this->error( 'quick_import_unavailable', 'Quick-import service is not wired in this environment.', [], 500 );
		}
		[ $stored_path, $original_name, $err ] = $this->intake_upload( $request );
		if ( $err !== null ) return $err;

		$result = $this->quick->detect( $stored_path, $original_name );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error( 'detect_failed', (string) ( $result['error'] ?? 'Could not detect format.' ), [], 400 );
		}

		// File token = basename of the stored upload. quick_create reads
		// the file from UploadPaths::ensure() / token. Owner-supplied
		// filename is sanitised by store_upload(); the basename is safe.
		$result['file_token']     = basename( $stored_path );
		$result['original_name']  = $original_name;
		return $this->ok( $result );
	}

	/**
	 * Phase 4 dalis 4 — Quick import: confirm + create entity + commit.
	 */
	public function quick_create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->quick === null ) {
			return $this->error( 'quick_import_unavailable', 'Quick-import service is not wired in this environment.', [], 500 );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = $request->get_body_params();
		if ( ! is_array( $body ) ) $body = [];

		$file_token = (string) ( $body['file_token'] ?? '' );
		if ( $file_token === '' || preg_match( '/[\\/]/', $file_token ) ) {
			return $this->error( 'invalid_token', 'file_token is required and must not contain path separators.', [], 400 );
		}

		$dir = UploadPaths::ensure();
		$file_path = $dir !== null ? $dir . '/' . $file_token : sys_get_temp_dir() . '/' . $file_token;
		if ( ! is_file( $file_path ) ) {
			return $this->error( 'token_not_found', 'Uploaded file expired — re-upload to start over.', [], 404 );
		}

		$target_type = (string) ( $body['target_type'] ?? '' );
		if ( ! in_array( $target_type, [ 'lookup_table', 'library' ], true ) ) {
			return $this->error( 'invalid_target_type', 'target_type must be lookup_table or library.', [], 400 );
		}

		// Per-type capability gate (matches the standard wizard's logic).
		$needs_cap = $target_type === 'library' ? self::CAP_LIBRARY_ITEMS : self::CAP_LOOKUP_CELLS;
		if ( ! \current_user_can( $needs_cap ) ) {
			return $this->error( 'forbidden', 'You do not have permission to create this entity type.', [], 403 );
		}

		$confirmed = [
			'target_type'   => $target_type,
			'name'          => (string) ( $body['name'] ?? '' ),
			'technical_key' => (string) ( $body['technical_key'] ?? '' ),
			'mode'          => (string) ( $body['mode'] ?? 'insert_update' ),
			'module_key'    => (string) ( $body['module_key'] ?? '' ),
			'filename'      => (string) ( $body['filename'] ?? basename( $file_path ) ),
		];

		$result = $this->quick->create_and_import( $file_path, $confirmed );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->error(
				'quick_create_failed',
				(string) ( $result['error'] ?? 'Quick import failed.' ),
				[ 'errors' => $result['errors'] ?? [], 'batch_id' => $result['batch_id'] ?? null ],
				400
			);
		}
		return $this->ok( $result, 201 );
	}

	/**
	 * Shared upload-intake logic for create() + quick_detect().
	 *
	 * @return array{0:string,1:string,2:?\WP_Error}
	 */
	private function intake_upload( \WP_REST_Request $request ): array {
		$files = $request->get_file_params();
		$file  = is_array( $files ) && isset( $files['file'] ) ? $files['file'] : null;
		if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return [ '', '', $this->error( 'no_file', 'Upload a single .xlsx file in the "file" form field.', [], 400 ) ];
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return [ '', '', $this->error( 'file_too_big', 'File exceeds the 10 MB limit.', [], 400 ) ];
		}
		$tmp_name = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp_name ) && ! is_file( $tmp_name ) ) {
			return [ '', '', $this->error( 'no_file', 'Uploaded file is missing.', [], 400 ) ];
		}
		$ext = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( $ext !== 'xlsx' ) {
			return [ '', '', $this->error( 'bad_extension', 'Only .xlsx files are accepted.', [], 400 ) ];
		}
		$stored = $this->store_upload( $tmp_name, (string) $file['name'] );
		if ( $stored === null ) {
			return [ '', '', $this->error( 'store_failed', 'Could not store the uploaded file.', [], 500 ) ];
		}
		return [ $stored, (string) $file['name'], null ];
	}

	/**
	 * Move the upload into the plugin's uploads dir. Owner-supplied
	 * filename is sanitized; the on-disk name is a unique batch-keyed
	 * slug to avoid collisions. Falls back to sys_get_temp_dir() in
	 * raw CLI / test contexts where wp_upload_dir() is not loaded.
	 */
	private function store_upload( string $tmp_name, string $original_name ): ?string {
		$dir = UploadPaths::ensure();
		if ( $dir === null ) {
			// CLI fallback: use a temp file. Production should never
			// land here because activation hook ran ensure() at install
			// time.
			$tmp = tempnam( sys_get_temp_dir(), 'configkit-import-' );
			if ( $tmp === false ) return null;
			if ( ! @copy( $tmp_name, $tmp ) ) return null;
			return $tmp;
		}

		$ext  = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$slug = bin2hex( random_bytes( 8 ) );
		$dest = $dir . '/' . $slug . '.' . ( $ext ?: 'xlsx' );

		if ( is_uploaded_file( $tmp_name ) ) {
			if ( ! @move_uploaded_file( $tmp_name, $dest ) ) return null;
		} elseif ( ! @copy( $tmp_name, $dest ) ) {
			return null;
		}
		return $dest;
	}
}
