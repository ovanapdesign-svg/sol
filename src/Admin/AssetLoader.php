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
		if ( ! $this->is_configkit_page( $hook_suffix ) ) {
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
	}

	private function is_configkit_page( string $hook_suffix ): bool {
		// Hook suffixes look like:
		//   toplevel_page_configkit-dashboard
		//   configkit_page_configkit-settings
		return str_contains( $hook_suffix, 'configkit' );
	}
}
