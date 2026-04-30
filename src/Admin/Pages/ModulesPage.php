<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\Breadcrumb;

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

	/**
	 * Modules sit under Settings per ADMIN_SITEMAP §2.8.2.
	 *
	 * @return list<array{label:string, href?:string|null}>
	 */
	protected function breadcrumb_segments(): array {
		return [
			[ 'label' => 'ConfigKit', 'href' => Breadcrumb::configkit_root_href() ],
			[ 'label' => 'Settings', 'href' => Breadcrumb::admin_page_href( 'configkit-settings' ) ],
			[ 'label' => 'Modules' ],
		];
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap( \__( 'Modules', 'configkit' ) );

		echo '<p class="description">'
			. \esc_html__( 'A module declares a kind of option group. Libraries are concrete instances of a module.', 'configkit' )
			. '</p>';

		echo '<div id="configkit-modules-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage modules.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
