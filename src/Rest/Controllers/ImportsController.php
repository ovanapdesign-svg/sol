<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\ImportService;

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

	private const CAP             = 'configkit_manage_lookup_tables';
	private const ALLOWED_TYPES   = [ 'lookup_cells' ];
	private const ALLOWED_MODES   = [ 'insert_update', 'replace_all' ];
	private const MAX_BYTES       = 10 * 1024 * 1024; // 10 MB
	private const ALLOWED_MIME    = [
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-excel',
		'application/octet-stream',
		'application/zip',
	];

	public function __construct( private ImportService $service ) {}

	public function register_routes(): void {
		\register_rest_route( self::NAMESPACE, '/imports', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list' ],
				'permission_callback' => $this->require_cap( self::CAP ),
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
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)/parse', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'parse' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)/commit', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'commit' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );

		\register_rest_route( self::NAMESPACE, '/imports/(?P<batch_id>\d+)/cancel', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => $this->require_cap( self::CAP ),
			],
		] );
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
		$import_type   = (string) ( $request->get_param( 'import_type' ) ?? 'lookup_cells' );
		$target_key    = (string) ( $request->get_param( 'target_lookup_table_key' ) ?? '' );
		$mode          = (string) ( $request->get_param( 'mode' ) ?? 'insert_update' );

		if ( ! in_array( $import_type, self::ALLOWED_TYPES, true ) ) {
			return $this->error( 'unsupported_type', 'Only lookup_cells imports are supported in this chunk.', [], 400 );
		}
		if ( $target_key === '' ) {
			return $this->error( 'missing_target', 'target_lookup_table_key is required.', [], 400 );
		}
		if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
			return $this->error( 'invalid_mode', 'mode must be insert_update or replace_all.', [], 400 );
		}

		$stored_path = $this->store_upload( $tmp_name, (string) $file['name'] );
		if ( $stored_path === null ) {
			return $this->error( 'store_failed', 'Could not store the uploaded file.', [], 500 );
		}

		try {
			$result = $this->service->create_and_parse( [
				'import_type'             => $import_type,
				'filename'                => (string) $file['name'],
				'file_path'               => $stored_path,
				'target_lookup_table_key' => $target_key,
				'mode'                    => $mode,
				'created_by'              => function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0,
			] );
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
	 * Move the upload into a private plugin uploads dir.
	 * Owner-supplied filename is sanitized; the on-disk name is a
	 * unique batch-keyed slug to avoid collisions.
	 */
	private function store_upload( string $tmp_name, string $original_name ): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			// Test/CLI fallback: use a temp file.
			$tmp = tempnam( sys_get_temp_dir(), 'configkit-import-' );
			if ( $tmp === false ) return null;
			if ( ! @copy( $tmp_name, $tmp ) ) return null;
			return $tmp;
		}

		$uploads = \wp_upload_dir();
		$base    = ( is_array( $uploads ) && isset( $uploads['basedir'] ) ) ? (string) $uploads['basedir'] : sys_get_temp_dir();
		$dir     = rtrim( $base, '/\\' ) . '/configkit-imports';
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return null;
		}
		// Guard rail: drop a .htaccess that denies direct access.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\n" );
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
