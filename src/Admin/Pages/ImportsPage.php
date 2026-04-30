<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\PageHeader;

/**
 * ConfigKit → Imports.
 *
 * Hosts the 4-step import wizard (pick destination → upload →
 * preview → commit) plus a recent-batches list. Static shell —
 * import-wizard.js drives the views.
 */
final class ImportsPage extends AbstractPage {

	public function slug(): string {
		return 'configkit-imports';
	}

	public function capability(): string {
		// Phase 4 chunk only ships lookup-cell imports, so reusing
		// configkit_manage_lookup_tables is correct. Library-item
		// import (a future chunk) will broaden this.
		return 'configkit_manage_lookup_tables';
	}

	public function page_title(): string {
		return \__( 'ConfigKit Import history', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Import history', 'configkit' );
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap_with_header( [
			'title'     => 'Import history',
			'subtitle'  => 'Past Excel imports — and a fallback wizard for ad-hoc uploads',
			'intro'     => 'Most imports start from the entity that owns the data — ConfigKit → Lookup Tables → open a table → Import from Excel. This page lists every past batch and lets you run a one-off import without picking a destination first.',
			'intro_id'  => 'import-history',
			'secondary' => [ 'label' => '← Back to dashboard', 'href' => PageHeader::dashboard_href() ],
		] );

		echo '<div id="configkit-imports-app" class="configkit-app" data-loading="true">';
		echo '<noscript><p>' . \esc_html__( 'JavaScript is required to run the import wizard.', 'configkit' ) . '</p></noscript>';
		echo '<p class="configkit-app__loading">' . \esc_html__( 'Loading…', 'configkit' ) . '</p>';
		echo '</div>';

		$this->close_wrap();
	}
}
