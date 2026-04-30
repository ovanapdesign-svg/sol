<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

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
		$this->open_wrap_with_header( [
			'title'     => 'Lookup Tables',
			'subtitle'  => 'Size-based pricing grids',
			'intro'     => 'A lookup table stores size-based prices. Each cell is a width × height combination with a price. Excel import comes in Phase 4.',
			'intro_id'  => 'lookup-tables',
			'primary'   => [
				'label' => '+ Create lookup table',
				'href'  => \admin_url( 'admin.php?page=configkit-lookup-tables&action=new' ),
			],
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-lookup-tables-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage lookup tables.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
