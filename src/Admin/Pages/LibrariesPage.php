<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

/**
 * Libraries admin page.
 *
 * Single page handles two views:
 *  - List (no id query param): grouped by module
 *  - Detail (id query param): library metadata + items
 *
 * libraries.js drives both views via the configkit/v1/libraries[/items]
 * REST endpoints.
 */
final class LibrariesPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-libraries';
	}

	public function capability(): string {
		return 'configkit_manage_libraries';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Libraries', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Libraries', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap_with_header( [
			'title'     => 'Libraries',
			'subtitle'  => 'Catalogs of items grouped by module',
			'intro'     => "A library is the actual catalog of items in a module. For example, the 'Dickson Orchestra' library belongs to the Textiles module and contains fabric items.",
			'intro_id'  => 'libraries',
			'primary'   => [
				'label' => '+ Create library',
				'href'  => \admin_url( 'admin.php?page=configkit-libraries&action=new' ),
			],
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-libraries-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage libraries.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
