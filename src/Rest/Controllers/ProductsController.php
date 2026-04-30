<?php
declare(strict_types=1);

namespace ConfigKit\Rest\Controllers;

use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Rest\AbstractController;
use ConfigKit\Service\ProductBindingService;
use ConfigKit\Service\ProductDiagnosticsService;
use ConfigKit\Service\TestDefaultPriceService;

final class ProductsController extends AbstractController {

	private const CAP = 'configkit_manage_products';

	public function __construct(
		private ProductBindingService $service,
		private ProductDiagnosticsService $diagnostics,
		private TemplateRepository $templates,
		private StepRepository $steps,
		private FieldRepository $fields,
		private ?TestDefaultPriceService $test_default_price = null,
	) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/products',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => $this->require_cap( self::CAP ),
					'args'                => [
						'page'       => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page'   => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
						'family_key' => [ 'type' => 'string' ],
						'enabled'    => [ 'type' => 'boolean' ],
					],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)/binding',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)/diagnostics',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'diagnostics' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)/test-default-price',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'test_default_price' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)/template-fields',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'template_fields' ],
					'permission_callback' => $this->require_cap( self::CAP ),
				],
			]
		);
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = [];
		if ( $request->get_param( 'family_key' ) !== null && $request->get_param( 'family_key' ) !== '' ) {
			$filters['family_key'] = (string) $request->get_param( 'family_key' );
		}
		if ( $request->get_param( 'enabled' ) !== null ) {
			$filters['enabled'] = (bool) $request->get_param( 'enabled' );
		}
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		return $this->ok( $this->service->list_overview( $filters, $page === 0 ? 1 : $page, $per_page === 0 ? 50 : $per_page ) );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$record = $this->service->get( (int) $request['product_id'] );
		if ( $record === null ) {
			return $this->error( 'not_found', 'Product not found.', [], 404 );
		}
		return $this->ok( [ 'record' => $record ] );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload  = $this->payload( $request );
		$expected = isset( $payload['version_hash'] ) ? (string) $payload['version_hash'] : '';
		$result   = $this->service->update( (int) $request['product_id'], $payload, $expected );
		if ( ! ( $result['ok'] ?? false ) ) {
			$first = $result['errors'][0]['code'] ?? '';
			if ( $first === 'conflict' ) {
				return $this->error( 'conflict', 'Stale version_hash.', [ 'errors' => $result['errors'] ], 409 );
			}
			if ( $first === 'not_found' ) {
				return $this->error( 'not_found', 'Product not found.', [], 404 );
			}
			return $this->error( 'validation_failed', 'Binding could not be saved.', [ 'errors' => $result['errors'] ?? [] ], 400 );
		}
		return $this->ok( [ 'record' => $result['record'] ?? null ] );
	}

	public function diagnostics( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->diagnostics->run( (int) $request['product_id'] );
		if ( $result === null ) {
			return $this->error( 'not_found', 'Product not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function test_default_price( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->test_default_price === null ) {
			return $this->error( 'preview_unavailable', 'Test-default-price service is not wired in this environment.', [], 500 );
		}
		$result = $this->test_default_price->compute( (int) $request['product_id'] );
		if ( ! ( $result['ok'] ?? false ) && ( $result['not_found'] ?? false ) ) {
			return $this->error( 'not_found', 'Product not found.', [], 404 );
		}
		return $this->ok( $result );
	}

	public function template_fields( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$binding = $this->service->get( (int) $request['product_id'] );
		if ( $binding === null ) {
			return $this->error( 'not_found', 'Product not found.', [], 404 );
		}
		$template_key = (string) ( $binding['template_key'] ?? '' );
		if ( $template_key === '' ) {
			return $this->ok( [ 'items' => [], 'total' => 0 ] );
		}
		$template = $this->templates->find_by_key( $template_key );
		if ( $template === null ) {
			return $this->ok( [ 'items' => [], 'total' => 0 ] );
		}
		$items = [];
		foreach ( $this->steps->list_in_template( $template_key )['items'] as $step ) {
			foreach ( $this->fields->list_in_step( $template_key, (string) $step['step_key'] )['items'] as $field ) {
				$items[] = [
					'step_key'      => (string) $step['step_key'],
					'step_label'    => (string) $step['label'],
					'field_key'     => (string) $field['field_key'],
					'label'         => (string) $field['label'],
					'helper_text'   => $field['helper_text'] ?? null,
					'field_kind'    => (string) $field['field_kind'],
					'input_type'    => $field['input_type'] ?? null,
					'display_type'  => (string) $field['display_type'],
					'value_source'  => (string) $field['value_source'],
					'source_config' => is_array( $field['source_config'] ?? null ) ? $field['source_config'] : [],
					'is_required'   => (bool) ( $field['is_required'] ?? false ),
				];
			}
		}
		return $this->ok( [ 'items' => $items, 'total' => count( $items ) ] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		return is_array( $body ) ? $body : [];
	}
}
