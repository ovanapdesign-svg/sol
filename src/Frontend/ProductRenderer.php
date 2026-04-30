<?php
declare(strict_types=1);

namespace ConfigKit\Frontend;

use ConfigKit\Repository\ProductBindingRepository;

/**
 * Hooks the ConfigKit configurator into the WooCommerce single-product
 * template. Strategy:
 *
 *   1. On a product page where _configkit_enabled = 1, swap the
 *      "Add to cart" form for our configurator mount point.
 *   2. The mount point ships with a JSON `data-config` blob carrying
 *      product_id + REST URL + nonce so the JS can boot before the
 *      first network call. The full snapshot is fetched from
 *      `/products/{id}/render-data` once the script runs.
 *   3. CSS + JS are enqueued only on configurator pages so non-Woo /
 *      non-bound pages stay clean.
 *
 * Disabled or unready products fall back to the stock Woo template.
 */
final class ProductRenderer {

	public function __construct(
		private ProductBindingRepository $bindings,
		private string $plugin_url,
		private string $version,
	) {}

	public function register(): void {
		if ( \is_admin() ) return;

		\add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );

		// Replace the add-to-cart form on simple Woo product pages
		// with our mount point. Uses priority 5 so any theme overrides
		// later still win for non-bound products.
		\add_action( 'woocommerce_before_single_product_summary', [ $this, 'maybe_inject_config_mount' ], 5 );
	}

	public function maybe_enqueue(): void {
		if ( ! $this->should_render_for_current_request() ) return;

		\wp_register_style(
			'configkit-configurator',
			$this->plugin_url . 'assets/frontend/configurator.css',
			[],
			$this->version
		);
		\wp_enqueue_style( 'configkit-configurator' );

		\wp_register_script(
			'configkit-configurator',
			$this->plugin_url . 'assets/frontend/configurator.js',
			[],
			$this->version,
			true
		);

		$product_id = $this->resolve_product_id();
		\wp_localize_script(
			'configkit-configurator',
			'CONFIGKIT_FRONT',
			[
				'productId' => $product_id,
				'restUrl'   => \esc_url_raw( \rest_url( 'configkit/v1' ) ),
				'nonce'     => \wp_create_nonce( 'wp_rest' ),
				'cartUrl'   => function_exists( 'wc_get_cart_url' ) ? \wc_get_cart_url() : home_url( '/cart/' ),
			]
		);
		\wp_enqueue_script( 'configkit-configurator' );
	}

	/**
	 * Render the mount point. Bound products render an opaque shell
	 * the JS will hydrate; everything else returns silently so the
	 * stock Woo template remains.
	 */
	public function maybe_inject_config_mount(): void {
		if ( ! $this->should_render_for_current_request() ) return;

		$product_id = $this->resolve_product_id();
		echo '<div class="configkit-frontend">';
		echo '<div id="configkit-configurator" data-product-id="' . \esc_attr( (string) $product_id ) . '">';
		echo '<noscript><p>' . \esc_html__( 'Enable JavaScript to use the configurator.', 'configkit' ) . '</p></noscript>';
		echo '<div class="configkit-frontend__loading" aria-busy="true">';
		echo '<div class="configkit-frontend__skeleton-bar"></div>';
		echo '<div class="configkit-frontend__skeleton-bar"></div>';
		echo '<div class="configkit-frontend__skeleton-bar"></div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		// Hide the default Woo add-to-cart form — the configurator
		// owns the purchase flow.
		\remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}

	private function should_render_for_current_request(): bool {
		if ( ! function_exists( 'is_singular' ) || ! \is_singular( 'product' ) ) return false;
		$pid = $this->resolve_product_id();
		if ( $pid <= 0 ) return false;
		$binding = $this->bindings->find( $pid );
		if ( $binding === null ) return false;
		if ( empty( $binding['enabled'] ) ) return false;
		if ( empty( $binding['template_key'] ) ) return false;
		return true;
	}

	private function resolve_product_id(): int {
		if ( function_exists( 'get_the_ID' ) ) {
			$id = (int) \get_the_ID();
			if ( $id > 0 ) return $id;
		}
		global $post;
		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}
}
