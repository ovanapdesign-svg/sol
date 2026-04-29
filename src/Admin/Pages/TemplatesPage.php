<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

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
		$this->open_wrap( \__( 'Templates', 'configkit' ) );

		echo '<p class="description">'
			. \esc_html__( 'Templates define the configurator for a product family. Phase 3 B1 ships list + metadata management; the steps / fields / rules editor lands in B2–B5.', 'configkit' )
			. '</p>';

		echo '<div id="configkit-templates-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>'
			. \esc_html__( 'JavaScript is required to manage templates.', 'configkit' )
			. '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
