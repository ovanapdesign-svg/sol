<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

final class TemplatesPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-templates';
	}

	public function capability(): string {
		return 'configkit_manage_templates';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Templates', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Templates', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap_with_header( [
			'title'     => 'Templates',
			'subtitle'  => 'Define the customer configuration experience',
			'intro'     => 'A template controls what the customer sees on the product page — the configuration steps, the fields they can pick, and the rules that guide them.',
			'intro_id'  => 'templates',
			'primary'   => [
				'label' => '+ Create template',
				'href'  => \admin_url( 'admin.php?page=configkit-templates&action=new' ),
			],
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-templates-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage templates.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
