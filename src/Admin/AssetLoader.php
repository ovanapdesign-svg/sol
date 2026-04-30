<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

/**
 * Enqueues shared admin assets (CSS + a tiny common JS bootstrap that
 * exposes the REST URL and a wp_rest nonce to per-page scripts).
 */
final class AssetLoader {

	public function __construct(
		private string $plugin_url,
		private string $version,
	) {}

	public function enqueue( string $hook_suffix ): void {
		$is_configkit_page = $this->is_configkit_page( $hook_suffix );
		$is_product_edit   = $this->is_product_edit_screen( $hook_suffix );
		if ( ! $is_configkit_page && ! $is_product_edit ) {
			return;
		}

		\wp_enqueue_style(
			'configkit-admin',
			$this->plugin_url . 'assets/admin/css/admin.css',
			[],
			$this->version
		);

		\wp_register_script(
			'configkit-admin',
			$this->plugin_url . 'assets/admin/js/admin.js',
			[],
			$this->version,
			true
		);

		\wp_localize_script(
			'configkit-admin',
			'CONFIGKIT',
			[
				'restUrl' => \esc_url_raw( \rest_url( 'configkit/v1' ) ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
			]
		);

		\wp_enqueue_script( 'configkit-admin' );

		if ( $is_product_edit ) {
			\wp_enqueue_script(
				'configkit-product-binding',
				$this->plugin_url . 'assets/admin/js/product-binding.js',
				[ 'configkit-admin' ],
				$this->version,
				true
			);
			return;
		}

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-modules',
			'configkit-modules',
			'assets/admin/js/modules.js'
		);

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-libraries',
			'configkit-libraries',
			'assets/admin/js/libraries.js'
		);

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-lookup-tables',
			'configkit-lookup-tables',
			'assets/admin/js/lookup-tables.js'
		);

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-families',
			'configkit-families',
			'assets/admin/js/families.js'
		);

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-templates',
			'configkit-templates',
			'assets/admin/js/templates.js'
		);

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-products',
			'configkit-products',
			'assets/admin/js/products.js'
		);

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-diagnostics',
			'configkit-diagnostics',
			'assets/admin/js/diagnostics.js'
		);

		$this->maybe_enqueue_page_script(
			$hook_suffix,
			'configkit-imports',
			'configkit-imports',
			'assets/admin/js/import-wizard.js'
		);
	}

	private function is_product_edit_screen( string $hook_suffix ): bool {
		if ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
		if ( $screen === null ) {
			return false;
		}
		return ( $screen->post_type ?? '' ) === 'product';
	}

	private function maybe_enqueue_page_script(
		string $hook_suffix,
		string $page_slug_match,
		string $handle,
		string $relative_path
	): void {
		if ( ! str_contains( $hook_suffix, $page_slug_match ) ) {
			return;
		}
		\wp_enqueue_script(
			$handle,
			$this->plugin_url . $relative_path,
			[ 'configkit-admin' ],
			$this->version,
			true
		);
	}

	private function is_configkit_page( string $hook_suffix ): bool {
		// Hook suffixes look like:
		//   toplevel_page_configkit-dashboard
		//   configkit_page_configkit-settings
		return str_contains( $hook_suffix, 'configkit' );
	}
}
