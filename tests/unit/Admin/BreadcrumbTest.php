<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Admin;

use ConfigKit\Admin\Breadcrumb;
use PHPUnit\Framework\TestCase;

final class BreadcrumbTest extends TestCase {

	public function test_render_emits_single_nav_with_one_segment(): void {
		$html = Breadcrumb::render( [ [ 'label' => 'ConfigKit' ] ] );
		$this->assertSame( 1, substr_count( $html, '<nav ' ) );
		$this->assertStringContainsString( 'configkit-breadcrumb__current', $html );
	}

	public function test_render_intermediate_is_link_last_is_span(): void {
		$html = Breadcrumb::render( [
			[ 'label' => 'ConfigKit', 'href' => '/wp-admin/admin.php?page=configkit-dashboard' ],
			[ 'label' => 'Modules',   'href' => '/wp-admin/admin.php?page=configkit-modules' ],
		] );
		$this->assertStringContainsString( 'configkit-breadcrumb__link', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		// Last segment must NOT be rendered as a link.
		$this->assertSame( 1, substr_count( $html, 'configkit-breadcrumb__link' ) );
	}

	public function test_last_segment_with_href_emits_data_cf_href(): void {
		// The current span carries the href as a data attribute so the
		// JS subBreadcrumb() helper can promote it back into a link
		// when a sub-view is open.
		$html = Breadcrumb::render( [
			[ 'label' => 'ConfigKit', 'href' => '/wp-admin/admin.php?page=configkit-dashboard' ],
			[ 'label' => 'Modules',   'href' => '/wp-admin/admin.php?page=configkit-modules' ],
		] );
		$this->assertStringContainsString( 'data-cf-href="/wp-admin/admin.php?page=configkit-modules"', $html );
	}

	public function test_last_segment_without_href_has_no_data_cf_href(): void {
		$html = Breadcrumb::render( [ [ 'label' => 'ConfigKit' ] ] );
		$this->assertStringNotContainsString( 'data-cf-href', $html );
	}

	public function test_render_with_no_segments_returns_empty_string(): void {
		$this->assertSame( '', Breadcrumb::render( [] ) );
	}

	public function test_render_skips_segments_with_blank_labels(): void {
		$html = Breadcrumb::render( [
			[ 'label' => 'ConfigKit', 'href' => '/x' ],
			[ 'label' => '' ],
			[ 'label' => 'Modules' ],
		] );
		$this->assertStringContainsString( 'Modules', $html );
		// Two non-empty segments → exactly one separator.
		$this->assertSame( 1, substr_count( $html, 'configkit-breadcrumb__sep' ) );
	}
}
