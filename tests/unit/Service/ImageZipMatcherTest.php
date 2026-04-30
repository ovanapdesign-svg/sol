<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Service;

use ConfigKit\Service\ImageZipMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.4 — Image ZIP filename → SKU matcher. Used after the
 * owner uploads a ZIP of images alongside an option-group section.
 */
final class ImageZipMatcherTest extends TestCase {

	private ImageZipMatcher $matcher;

	protected function setUp(): void {
		$this->matcher = new ImageZipMatcher();
	}

	public function test_exact_match_with_extension(): void {
		$result = $this->matcher->match( [ 'U171.jpg', 'U172.png' ], [ 'U171', 'U172' ] );
		$this->assertSame( 'U171.jpg', $result['matches']['U171'] );
		$this->assertSame( 'U172.png', $result['matches']['U172'] );
		$this->assertSame( [], $result['unmatched_filenames'] );
		$this->assertSame( [], $result['unmatched_skus'] );
	}

	public function test_main_thumb_suffix_stripped(): void {
		$result = $this->matcher->match( [ 'u171_main.png', 'U172_thumb.jpg' ], [ 'U171', 'U172' ] );
		$this->assertSame( 'u171_main.png', $result['matches']['U171'] );
		$this->assertSame( 'U172_thumb.jpg', $result['matches']['U172'] );
	}

	public function test_numeric_suffix_stripped(): void {
		$result = $this->matcher->match( [ 'U171-2.jpg' ], [ 'U171' ] );
		$this->assertSame( 'U171-2.jpg', $result['matches']['U171'] );
	}

	public function test_case_insensitive_sku_lookup(): void {
		$result = $this->matcher->match( [ 'u171.jpg' ], [ 'U171' ] );
		$this->assertArrayHasKey( 'U171', $result['matches'] );
	}

	public function test_unmatched_returns_in_separate_lists(): void {
		$result = $this->matcher->match( [ 'unknown.jpg', 'U171.png' ], [ 'U171', 'U999' ] );
		$this->assertSame( [ 'unknown.jpg' ], $result['unmatched_filenames'] );
		$this->assertSame( [ 'U999' ],         $result['unmatched_skus'] );
	}

	public function test_first_match_wins_when_two_files_normalise_to_same_sku(): void {
		$result = $this->matcher->match( [ 'u171.jpg', 'u171_main.png' ], [ 'U171' ] );
		$this->assertSame( 'u171.jpg', $result['matches']['U171'] );
		$this->assertSame( [ 'u171_main.png' ], $result['unmatched_filenames'] );
	}
}
