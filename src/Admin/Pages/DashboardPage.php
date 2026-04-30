<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Repository\CountsService;
use ConfigKit\Service\SystemDiagnosticsService;

/**
 * Dashboard — owner home screen.
 *
 * Phase 3.6: a "Next step" guidance card replaces the bare counts as
 * the primary surface. Counts now sit beneath as an Overview block.
 *
 * Real counts only — no fake activity, no demo data
 * (TEMPLATE_BUILDER_UX.md §13).
 */
final class DashboardPage extends AbstractPage {

	public function __construct(
		private CountsService $counts,
		private SystemDiagnosticsService $diagnostics,
	) {}

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
	 * @return list<array{label:string, href?:string|null}>
	 */
	protected function breadcrumb_segments(): array {
		return [ [ 'label' => 'ConfigKit' ] ];
	}

	public function render(): void {
		$this->ensure_capability();
		$this->open_wrap( \__( 'ConfigKit Dashboard', 'configkit' ) );

		$snapshot     = $this->counts->snapshot();
		$critical     = $this->critical_issues_count();
		$next_step    = $this->resolve_next_step( $snapshot, $critical );

		echo '<div class="configkit-dashboard">';

		echo $this->render_guidance( $next_step );

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

	/**
	 * @param array<string,int> $s
	 * @return array{state:string, heading:string, body:string, cta_label:string, cta_href:string, cta_external?:bool}
	 */
	private function resolve_next_step( array $s, int $critical ): array {
		$modules     = (int) ( $s['modules'] ?? 0 );
		$libraries   = (int) ( $s['libraries_active'] ?? 0 );
		$lookups     = (int) ( $s['lookup_tables'] ?? 0 );
		$templates   = (int) ( $s['templates_published'] ?? 0 ) + (int) ( $s['templates_draft'] ?? 0 );
		$products    = (int) ( $s['configurable_products'] ?? 0 );

		$admin = static function ( string $page ): string {
			return function_exists( 'admin_url' )
				? \admin_url( 'admin.php?page=' . $page )
				: 'admin.php?page=' . $page;
		};

		if ( $modules === 0 ) {
			return [
				'state'     => 'modules_empty',
				'heading'   => 'Start here',
				'body'      => 'Create your first module to define the kinds of options you sell. Examples: Textiles, Motors, Colors, Accessories.',
				'cta_label' => '+ Create your first module',
				'cta_href'  => $admin( 'configkit-modules' ) . '&action=new',
			];
		}
		if ( $libraries === 0 ) {
			return [
				'state'     => 'libraries_empty',
				'heading'   => 'Next: create a library',
				'body'      => "A library is your actual catalog — like 'Dickson Orchestra fabrics' or 'Somfy IO motors'. Each library belongs to a module.",
				'cta_label' => '+ Create a library',
				'cta_href'  => $admin( 'configkit-libraries' ) . '&action=new',
			];
		}
		if ( $lookups === 0 ) {
			return [
				'state'     => 'lookups_empty',
				'heading'   => 'Next: create a lookup table',
				'body'      => 'A lookup table stores prices by size. For example, a 2000×1500 markise costs 5990 kr. Excel import lands in Phase 4.',
				'cta_label' => '+ Create a lookup table',
				'cta_href'  => $admin( 'configkit-lookup-tables' ) . '&action=new',
			];
		}
		if ( $templates === 0 ) {
			return [
				'state'     => 'templates_empty',
				'heading'   => 'Next: build a template',
				'body'      => 'A template controls what the customer sees on the product page — the steps, fields, and rules.',
				'cta_label' => '+ Create a template',
				'cta_href'  => $admin( 'configkit-templates' ) . '&action=new',
			];
		}
		if ( $products === 0 ) {
			$href = function_exists( 'admin_url' )
				? \admin_url( 'edit.php?post_type=product' )
				: 'edit.php?post_type=product';
			return [
				'state'        => 'products_empty',
				'heading'      => 'Next: connect a WooCommerce product',
				'body'         => 'Open a product in WooCommerce and find the ConfigKit tab to bind it to a template.',
				'cta_label'    => 'Open WooCommerce Products',
				'cta_href'     => $href,
				'cta_external' => true,
			];
		}
		if ( $critical > 0 ) {
			return [
				'state'     => 'issues_present',
				'heading'   => 'Issues need attention',
				'body'      => $critical . ' critical issue' . ( $critical === 1 ? '' : 's' ) . ' found. Fix them before customers see the configurator.',
				'cta_label' => 'View diagnostics',
				'cta_href'  => $admin( 'configkit-diagnostics' ),
			];
		}
		return [
			'state'     => 'ready',
			'heading'   => 'ConfigKit is ready',
			'body'      => $products . ' product(s) configured, '
				. (int) ( $s['templates_published'] ?? 0 ) . ' template(s) published, '
				. $libraries . ' active librar' . ( $libraries === 1 ? 'y' : 'ies' ) . '. The configurator is live for customers.',
			'cta_label' => 'View product readiness',
			'cta_href'  => $admin( 'configkit-products' ),
		];
	}

	private function critical_issues_count(): int {
		try {
			$result = $this->diagnostics->run();
		} catch ( \Throwable $e ) {
			return 0;
		}
		$counts = $result['counts'] ?? [];
		return (int) ( $counts['critical'] ?? 0 );
	}

	/**
	 * @param array{state:string, heading:string, body:string, cta_label:string, cta_href:string, cta_external?:bool} $g
	 */
	private function render_guidance( array $g ): string {
		$external = ! empty( $g['cta_external'] );
		$target_attr = $external ? ' target="_blank" rel="noopener"' : '';
		$state_class = 'configkit-guidance--' . preg_replace( '/[^a-z_]/', '_', $g['state'] );
		return '<section class="configkit-guidance ' . \esc_attr( $state_class ) . '">'
			. '<h2 class="configkit-guidance__heading">' . \esc_html( $g['heading'] ) . '</h2>'
			. '<p class="configkit-guidance__body">' . \esc_html( $g['body'] ) . '</p>'
			. '<p class="configkit-guidance__actions">'
			. '<a class="button button-primary button-hero" href="' . \esc_url( $g['cta_href'] ) . '"' . $target_attr . '>'
			. \esc_html( $g['cta_label'] )
			. '</a>'
			. '</p>'
			. '</section>';
	}
}
