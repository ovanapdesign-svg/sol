<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

use ConfigKit\Admin\Breadcrumb;

/**
 * Registers the ConfigKit tab inside WooCommerce's product data meta
 * box. The panel is an HTML shell — product-binding.js hydrates it
 * via REST after the tab activates.
 */
final class WooIntegration {

	public function register(): void {
		\add_filter( 'woocommerce_product_data_tabs', [ $this, 'register_tab' ] );
		\add_action( 'woocommerce_product_data_panels', [ $this, 'render_panel' ] );
	}

	/**
	 * @param array<string,array<string,mixed>> $tabs
	 * @return array<string,array<string,mixed>>
	 */
	public function register_tab( array $tabs ): array {
		$tabs['configkit'] = [
			'label'    => \__( 'ConfigKit', 'configkit' ),
			'target'   => 'configkit_product_data',
			'class'    => [ 'hide_if_grouped' ],
			'priority' => 65,
		];
		return $tabs;
	}

	public function render_panel(): void {
		global $post;
		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$post_title = $post instanceof \WP_Post && (string) $post->post_title !== '' ? (string) $post->post_title : '';

		echo '<div id="configkit_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="configkit-product-tab configkit-admin" data-product-id="' . \esc_attr( (string) $product_id ) . '">';

		echo Breadcrumb::render( [
			[
				'label' => 'WooCommerce',
				'href'  => function_exists( 'admin_url' )
					? \admin_url( 'edit.php?post_type=product' )
					: 'edit.php?post_type=product',
			],
			[
				'label' => 'Products',
				'href'  => function_exists( 'admin_url' )
					? \admin_url( 'edit.php?post_type=product' )
					: 'edit.php?post_type=product',
			],
			[ 'label' => $post_title !== '' ? 'Edit "' . $post_title . '"' : 'Edit product' ],
			[ 'label' => 'ConfigKit' ],
		] );

		echo '<div class="options_group">';
		echo '<p class="form-field">';
		echo '<strong>' . \esc_html__( 'ConfigKit', 'configkit' ) . '</strong> — ';
		echo \esc_html__(
			'Bind this product to a ConfigKit template. Status, defaults, and allowed options are managed here.',
			'configkit'
		);
		echo '</p>';
		echo '</div>';

		// Mount point. Sections are rendered by product-binding.js.
		echo '<div id="configkit-product-binding-app" class="configkit-app" data-loading="true" data-product-id="'
			. \esc_attr( (string) $product_id ) . '">';
		echo '<noscript><p>' . \esc_html__( 'JavaScript is required to manage ConfigKit binding.', 'configkit' ) . '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		echo '</div>'; // .configkit-product-tab
		echo '</div>'; // #configkit_product_data
	}
}
