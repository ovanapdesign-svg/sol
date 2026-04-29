<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\SystemDiagnosticsService;

final class DiagnosticsController extends AbstractController {

	private const CAP = 'configkit_view_diagnostics';

	public function __construct( private SystemDiagnosticsService $service ) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/diagnostics',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'run' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'include_acknowledged' => [ 'type' => 'boolean', 'default' => false ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/diagnostics/refresh',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'run' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'include_acknowledged' => [ 'type' => 'boolean', 'default' => false ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/diagnostics/acknowledge',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'acknowledge' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);
	}

	public function run( \WP_REST_Request $request ): \WP_REST_Response {
		$include_acknowledged = (bool) $request->get_param( 'include_acknowledged' );
		return $this->ok( $this->service->run( $include_acknowledged ) );
	}

	public function acknowledge( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = $request->get_body_params();
		if ( ! is_array( $body ) ) $body = [];

		$issue_id    = (string) ( $body['issue_id'] ?? '' );
		$object_type = (string) ( $body['object_type'] ?? '' );
		$note        = (string) ( $body['note'] ?? '' );
		$raw_oid     = $body['object_id'] ?? null;

		if ( $issue_id === '' || $object_type === '' ) {
			return $this->error(
				'validation_failed',
				'issue_id and object_type are required.',
				[ 'errors' => [ [ 'field' => 'issue_id', 'message' => 'required' ] ] ],
				400
			);
		}

		$object_id = null;
		if ( is_int( $raw_oid ) ) $object_id = $raw_oid;
		elseif ( is_string( $raw_oid ) && $raw_oid !== '' ) $object_id = $raw_oid;
		elseif ( is_numeric( $raw_oid ) ) $object_id = (int) $raw_oid;

		$this->service->acknowledge( $issue_id, $object_type, $object_id, $note );
		return $this->ok( [ 'ok' => true ] );
	}
}
