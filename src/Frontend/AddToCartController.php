<?php
declare(strict_types=1);

namespace ConfigKit\Frontend;

use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\ProductBindingRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Rest\AbstractController;

/**
 * Public add-to-cart endpoint for the storefront configurator.
 *
 * The client-side configurator computes a live price for UX, but the
 * server is the source of truth: this controller re-validates the
 * submitted selections (does the field exist? is the product binding
 * still ready? are required fields filled?) and pushes the line item
 * into the WooCommerce cart with a `_configkit_selections` meta blob
 * that downstream pricing / display code can consume.
 *
 * Only a thin validation pass lives here. Full server-side pricing
 * via the PHP PricingEngine is wired via a `woocommerce_before_calculate_totals`
 * filter in a later chunk; for now we accept the cart line at the
 * Woo product's stock price plus the meta blob.
 */
final class AddToCartController extends AbstractController {

	public function __construct(
		private ProductBindingRepository $bindings,
		private TemplateRepository $templates,
		private StepRepository $steps,
		private FieldRepository $fields,
	) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)/add-to-cart',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'add_to_cart' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'product_id' => [ 'type' => 'integer', 'required' => true ],
					],
				],
			]
		);
	}

	public function add_to_cart( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) $body = $request->get_body_params();
		if ( ! is_array( $body ) ) $body = [];

		$selections = is_array( $body['selections'] ?? null ) ? $body['selections'] : [];
		$quantity   = max( 1, (int) ( $body['quantity'] ?? 1 ) );

		$binding = $this->bindings->find( $product_id );
		if ( $binding === null || empty( $binding['enabled'] ) ) {
			return $this->error( 'not_configurable', 'This product is not configurable.', [], 400 );
		}
		$template_key = (string) ( $binding['template_key'] ?? '' );
		if ( $template_key === '' ) {
			return $this->error( 'not_configurable', 'This product has no template.', [], 400 );
		}
		$template = $this->templates->find_by_key( $template_key );
		if ( $template === null ) {
			return $this->error( 'not_configurable', 'Template missing.', [], 400 );
		}

		// Cross-check selections against the live template structure.
		$errors = $this->validate_selections( $template_key, $selections, $binding );
		if ( count( $errors ) > 0 ) {
			return $this->error( 'invalid_selections', 'Some selections are invalid.', [ 'errors' => $errors ], 400 );
		}

		// Push into Woo cart.
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_cart_url' ) ) {
			return $this->error( 'wc_unavailable', 'WooCommerce is not available.', [], 500 );
		}
		$cart = \WC()->cart;
		if ( ! is_object( $cart ) ) {
			return $this->error( 'wc_unavailable', 'WooCommerce cart unavailable.', [], 500 );
		}

		$cart_item_data = [
			'_configkit_selections'   => $selections,
			'_configkit_template_key' => $template_key,
			'_configkit_binding_hash' => (string) ( $binding['version_hash'] ?? '' ),
		];

		try {
			$key = $cart->add_to_cart( $product_id, $quantity, 0, [], $cart_item_data );
		} catch ( \Throwable $e ) {
			return $this->error( 'cart_error', $e->getMessage(), [], 500 );
		}
		if ( ! $key ) {
			return $this->error( 'cart_rejected', 'WooCommerce rejected the line item.', [], 400 );
		}

		return $this->ok( [
			'cart_key' => (string) $key,
			'cart_url' => \wc_get_cart_url(),
		], 201 );
	}

	/**
	 * Validate that every submitted selection targets a real field
	 * in the bound template, and that locked field overrides are
	 * respected. Required-field enforcement is gated on `is_required`
	 * + the rule-engine's required-field overlay (sent client-side
	 * via the binding's field_overrides.require flag).
	 *
	 * @param array<string,mixed> $selections
	 * @param array<string,mixed> $binding
	 * @return list<array{field?:string,code:string,message:string}>
	 */
	private function validate_selections( string $template_key, array $selections, array $binding ): array {
		$errors      = [];
		$known_keys  = [];
		$by_key      = [];
		foreach ( $this->steps->list_in_template( $template_key )['items'] as $step ) {
			foreach ( $this->fields->list_in_step( $template_key, (string) $step['step_key'] )['items'] as $field ) {
				$known_keys[]                     = (string) $field['field_key'];
				$by_key[ (string) $field['field_key'] ] = $field;
			}
		}

		foreach ( $selections as $field_key => $value ) {
			if ( ! is_string( $field_key ) || ! in_array( $field_key, $known_keys, true ) ) {
				$errors[] = [
					'field'   => is_string( $field_key ) ? $field_key : '',
					'code'    => 'unknown_field',
					'message' => 'Field "' . ( is_string( $field_key ) ? $field_key : '?' ) . '" is not part of this template.',
				];
			}
		}

		// Locked overrides: if the binding locks a field to a value,
		// the submitted selection must match (or be missing — the
		// server fills it in below).
		$overrides = is_array( $binding['field_overrides'] ?? null ) ? $binding['field_overrides'] : [];
		foreach ( $overrides as $field_key => $cfg ) {
			if ( ! is_array( $cfg ) ) continue;
			if ( ! array_key_exists( 'lock', $cfg ) ) continue;
			if ( ! array_key_exists( $field_key, $selections ) ) continue;
			if ( $selections[ $field_key ] !== $cfg['lock'] ) {
				$errors[] = [
					'field'   => (string) $field_key,
					'code'    => 'locked_value_mismatch',
					'message' => 'Field "' . (string) $field_key . '" is locked to a fixed value.',
				];
			}
		}

		// Required-field check.
		foreach ( $by_key as $key => $field ) {
			$require_override = isset( $overrides[ $key ]['require'] ) ? (bool) $overrides[ $key ]['require'] : false;
			$is_required = ! empty( $field['is_required'] ) || $require_override;
			if ( ! $is_required ) continue;
			$value = $selections[ $key ] ?? null;
			$is_empty = $value === null || $value === ''
				|| ( is_array( $value ) && count( $value ) === 0 );
			if ( $is_empty ) {
				$errors[] = [
					'field'   => $key,
					'code'    => 'required_missing',
					'message' => 'Field "' . (string) $field['label'] . '" is required.',
				];
			}
		}

		return $errors;
	}
}
