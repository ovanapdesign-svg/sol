<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

final class FamiliesPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-families';
	}

	public function capability(): string {
		return 'configkit_manage_families';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Families', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Families', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap( \__( 'Families', 'configkit' ) );

		echo '<p class="description">'
			. \esc_html__( 'Families group templates by product taxonomy (e.g. Markiser, Screens). Used for filtering and defaults.', 'configkit' )
			. '</p>';

		echo '<div id="configkit-families-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage families.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
