<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

/**
 * Modules admin page.
 *
 * Renders an HTML shell + mount points; modules.js fetches data and
 * builds the list and edit form via the REST namespace. No initial
 * server-rendered list — fully driven by REST so the page state stays
 * consistent with the data the controller would return for any other
 * client.
 */
final class ModulesPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-modules';
	}

	public function capability(): string {
		return 'configkit_manage_modules';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Modules', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Modules', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap_with_header( [
			'title'     => 'Modules',
			'subtitle'  => 'Define types of options you sell',
			'intro'     => 'A module is the TYPE of option you sell — for example Textiles, Motors, Colors, Accessories. Modules define what attributes items can have.',
			'intro_id'  => 'modules',
			'primary'   => [
				'label' => '+ Create module',
				'href'  => \admin_url( 'admin.php?page=configkit-modules&action=new' ),
			],
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-modules-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage modules.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
