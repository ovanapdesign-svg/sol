<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Repository\CountsService;

/**
 * Dashboard — owner home screen.
 *
 * Shows real counts pulled from the database. No fake activity entries,
 * no demo data (per TEMPLATE_BUILDER_UX.md §13). When a section has no
 * data yet (e.g. log empty), the section is hidden rather than mocked.
 */
final class DashboardPage extends AbstractPage {

	public function __construct( private CountsService $counts ) {}

	public function slug(): string {
		return 'configkit-dashboard';
	}

	public function capability(): string {
		return 'configkit_view_dashboard';
	}

	public function page_title(): string {
		return \__( 'ConfigKit', 'configkit' );
	}

	public function menu_title(): string {
		return \__( 'Dashboard', 'configkit' );
	}

	/**
	 * Dashboard is the root — single segment, no link out.
	 *
	 * @return list<array{label:string, href?:string|null}>
	 */
	protected function breadcrumb_segments(): array {
		return [ [ 'label' => 'ConfigKit' ] ];
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap( \__( 'ConfigKit Dashboard', 'configkit' ) );

		$snapshot = $this->counts->snapshot();

		echo '<div class="configkit-dashboard">';

		echo '<section class="configkit-dashboard__counts">';
		echo '<h2>' . \esc_html__( 'Overview', 'configkit' ) . '</h2>';
		echo '<ul class="configkit-counts">';
		$rows = [
			[ \__( 'Configurable products', 'configkit' ), $snapshot['configurable_products'] ?? 0 ],
			[ \__( 'Templates published', 'configkit' ), $snapshot['templates_published'] ?? 0 ],
			[ \__( 'Templates draft', 'configkit' ), $snapshot['templates_draft'] ?? 0 ],
			[ \__( 'Libraries (active)', 'configkit' ), $snapshot['libraries_active'] ?? 0 ],
			[ \__( 'Lookup tables', 'configkit' ), $snapshot['lookup_tables'] ?? 0 ],
			[ \__( 'Modules', 'configkit' ), $snapshot['modules'] ?? 0 ],
		];
		foreach ( $rows as [ $label, $count ] ) {
			echo '<li><span class="configkit-counts__label">'
				. \esc_html( $label )
				. '</span><span class="configkit-counts__value">'
				. \esc_html( (string) $count )
				. '</span></li>';
		}
		echo '</ul>';
		echo '</section>';

		echo '</div>';

		$this->close_wrap();
	}
}
