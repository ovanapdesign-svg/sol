<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

/**
 * ConfigKit → Products: read-only-with-jumps overview per
 * PRODUCT_BINDING_SPEC.md §2.2. All editing happens on the WooCommerce
 * product edit screen's ConfigKit tab.
 */
final class ProductsPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-products';
	}

	public function capability(): string {
		return 'configkit_manage_products';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Products', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Products', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap_with_header( [
			'title'     => 'Products',
			'subtitle'  => 'WooCommerce products and their ConfigKit setup',
			'intro'     => "Overview of all WooCommerce products and their ConfigKit setup status. Click 'Edit binding' to configure a specific product (opens in WooCommerce).",
			'intro_id'  => 'products',
			'primary'   => [
				'label'    => 'Open Products in WooCommerce',
				'href'     => \admin_url( 'edit.php?post_type=product' ),
				'external' => true,
			],
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-products-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>' . \esc_html__( 'JavaScript is required.', 'configkit' ) . '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
