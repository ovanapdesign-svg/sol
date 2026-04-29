<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

use ConfigKit\Admin\Pages\AbstractPage;

/**
 * Registers the ConfigKit admin menu and its sub-pages.
 *
 * Pages are added incrementally as each Phase 3 chunk lands. Per the
 * "no fake buttons" rule (TEMPLATE_BUILDER_UX.md §13), a sub-page is
 * only registered once its callback renders something real.
 */
final class Menu {

	public const TOP_SLUG  = 'configkit-dashboard';
	public const MENU_NAME = 'ConfigKit';
	public const POSITION  = '58.5'; // just below WooCommerce (55) and below customers (56)

	/** @var list<AbstractPage> */
	private array $pages;

	/**
	 * @param list<AbstractPage> $pages
	 */
	public function __construct( array $pages ) {
		$this->pages = $pages;
	}

	public function register(): void {
		if ( count( $this->pages ) === 0 ) {
			return;
		}

		$first = $this->pages[0];

		\add_menu_page(
			self::MENU_NAME,
			self::MENU_NAME,
			$first->capability(),
			$first->slug(),
			[ $first, 'render' ],
			'dashicons-admin-generic',
			(float) self::POSITION
		);

		foreach ( $this->pages as $page ) {
			\add_submenu_page(
				self::TOP_SLUG,
				$page->page_title(),
				$page->menu_title(),
				$page->capability(),
				$page->slug(),
				[ $page, 'render' ]
			);
		}
	}
}
