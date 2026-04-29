<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\TemplateValidator;
use ConfigKit\Service\TemplateVersionService;

final class TemplateVersionsController extends AbstractController {

	private const CAP = 'configkit_manage_templates';

	public function __construct(
		private TemplateVersionService $service,
		private TemplateValidator $validator,
	) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)/versions',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)/versions/(?P<version_id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)/validate',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'validate' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<id>\d+)/publish',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'publish' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->list_for_template( (int) $request['id'] );
		if ( $result === null ) {
			return $this->error( 'not_found', 'Template not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = $this->service->get_version( (int) $request['id'], (int) $request['version_id'] );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Template version not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function validate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->validator->validate( (int) $request['id'] );
		if ( $result === null ) {
			return $this->error( 'not_found', 'Template not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function publish( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->publish( (int) $request['id'] );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'not_found' ) {
				return $this->error( 'not_found', 'Template not found.', [], 404 );
			}
			$payload = [ 'errors' => $result['errors'] ?? [] ];
			if ( isset( $result['validation'] ) ) {
				$payload['validation'] = $result['validation'];
			}
			return $this->error( 'validation_failed', 'Pre-publish validation failed. Fix errors and try again.', $payload, 400 );
		}
		return $this->ok( [
			'record'     => $result['record'] ?? null,
			'validation' => $result['validation'] ?? null,
		] );
	}
}
