<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

abstract class AbstractPage {

	abstract public function slug(): string;

	abstract public function capability(): string;

	abstract public function page_title(): string;

	abstract public function menu_title(): string;

	abstract public function render(): void;

	/**
	 * @return list<string>
	 */
	public function asset_handles(): array {
		return [];
	}

	protected function ensure_capability(): void {
		if ( ! \current_user_can( $this->capability() ) ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'configkit' ) );
		}
	}

	protected function open_wrap( string $heading ): void {
		echo '<div class="wrap configkit-admin">';
		echo '<h1 class="wp-heading-inline">' . \esc_html( $heading ) . '</h1>';
		echo '<hr class="wp-header-end">';
	}

	protected function close_wrap(): void {
		echo '</div>';
	}
}
