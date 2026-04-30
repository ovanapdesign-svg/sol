<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

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
		$this->open_wrap_with_header( [
			'title'     => 'Families',
			'subtitle'  => 'Group related products and templates',
			'intro'     => "Families group related products and templates together. For example, the 'Markiser' family includes all markise templates and lookup tables.",
			'intro_id'  => 'families',
			'primary'   => [
				'label' => '+ Create family',
				'href'  => \admin_url( 'admin.php?page=configkit-families&action=new' ),
			],
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-families-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage families.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
