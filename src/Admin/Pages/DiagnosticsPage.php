<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

/**
 * ConfigKit → Diagnostics. System-wide critical issues per
 * ADMIN_SITEMAP.md §2.7 + OWNER_UX_FLOW.md §8 (Flow F).
 */
final class DiagnosticsPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-diagnostics';
	}

	public function capability(): string {
		return 'configkit_view_diagnostics';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Diagnostics', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Diagnostics', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap_with_header( [
			'title'     => 'Diagnostics',
			'subtitle'  => 'Critical issues that block customer purchases',
			'intro'     => 'This page shows critical issues across ConfigKit that prevent customers from purchasing. Fix all critical issues before going live.',
			'intro_id'  => 'diagnostics',
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-diagnostics-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>' . \esc_html__( 'JavaScript is required.', 'configkit' ) . '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
