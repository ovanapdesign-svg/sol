<?php
declare(strict_types=1);

namespace ConfigKit\Admin\Pages;

use ConfigKit\Admin\Breadcrumb;
use ConfigKit\Admin\PageHeader;

abstract class AbstractPage {

	abstract public function slug(): string;

	abstract public function capability(): string;

	abstract public function page_title(): string;

	abstract public function menu_title(): string;

	abstract public function render(): void;

	/**
	 * @return list<string>
	 */
	public function asset_handles(): array {
		return [];
	}

	protected function ensure_capability(): void {
		if ( ! \current_user_can( $this->capability() ) ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'configkit' ) );
		}
	}

	protected function open_wrap( string $heading ): void {
		echo '<div class="wrap configkit-admin">';
		echo $this->render_breadcrumb();
		echo '<h1 class="wp-heading-inline">' . \esc_html( $heading ) . '</h1>';
		echo '<hr class="wp-header-end">';
	}

	protected function close_wrap(): void {
		echo '</div>';
	}

	/**
	 * Pages override this to declare their breadcrumb trail. Default
	 * is "ConfigKit › <menu_title>" which suits every top-level page.
	 * The current segment carries its own slug as `href` so the
	 * client-side `subBreadcrumb()` helper can promote it into a real
	 * link when the page transitions into a sub-view (Edit, New, etc.).
	 *
	 * @return list<array{label:string, href?:string|null}>
	 */
	protected function breadcrumb_segments(): array {
		return [
			[ 'label' => 'ConfigKit', 'href' => Breadcrumb::configkit_root_href() ],
			[ 'label' => $this->menu_title(), 'href' => Breadcrumb::admin_page_href( $this->slug() ) ],
		];
	}

	protected function render_breadcrumb(): string {
		return Breadcrumb::render( $this->breadcrumb_segments() );
	}

	/**
	 * Open a page with the new H1 + subtitle + action bar + intro
	 * structure. Replaces open_wrap() for list pages.
	 *
	 * @param array<string,mixed> $opts See PageHeader::render.
	 */
	protected function open_wrap_with_header( array $opts ): void {
		echo '<div class="wrap configkit-admin">';
		echo $this->render_breadcrumb();
		echo PageHeader::render( $opts );
	}
}
