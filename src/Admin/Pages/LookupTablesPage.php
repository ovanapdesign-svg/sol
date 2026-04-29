<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

final class LookupTablesPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-lookup-tables';
	}

	public function capability(): string {
		return 'configkit_manage_lookup_tables';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Lookup Tables', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Lookup Tables', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap( \__( 'Lookup Tables', 'configkit' ) );

		echo '<p class="description">'
			. \esc_html__( 'Width × height (× price group) → price grids. Phase 3 supports manual cell entry; Excel import lands in Phase 4.', 'configkit' )
			. '</p>';

		echo '<div id="configkit-lookup-tables-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage lookup tables.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
