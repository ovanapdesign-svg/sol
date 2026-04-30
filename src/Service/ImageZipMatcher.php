<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.4 — owner-facing helper that matches a list of image
 * filenames to a list of option SKUs. Used by the Configurator
 * Builder option-group editor: owner uploads a ZIP of images,
 * the front-end (or a future PHP unzip path) lists the inner
 * filenames, and this matcher reports which option each filename
 * belongs to.
 *
 * Matching rules — case-insensitive, ignoring extension and any
 * trailing "_main" / "_thumb" / numeric suffix:
 *
 *   "U171.jpg"        → SKU "U171"
 *   "u171_main.png"   → SKU "U171"
 *   "U171-2.jpg"      → SKU "U171"
 *   "u171_thumb.webp" → SKU "U171"
 *
 * Pure-PHP. The actual ZIP extraction is left to the caller — this
 * helper takes the list of filenames already extracted (or pasted
 * by the owner) and returns the matches.
 */
final class ImageZipMatcher {

	private const STRIP_SUFFIXES = [ '_main', '_thumb', '_large', '_small', '_alt' ];

	/**
	 * @param list<string> $filenames  basenames inside the ZIP
	 * @param list<string> $skus       option SKUs to match against
	 *
	 * @return array{
	 *   matches:array<string,string>,    sku → filename
	 *   unmatched_filenames:list<string>,
	 *   unmatched_skus:list<string>
	 * }
	 */
	public function match( array $filenames, array $skus ): array {
		$normalised_skus = [];
		foreach ( $skus as $sku ) {
			if ( ! is_string( $sku ) || $sku === '' ) continue;
			$normalised_skus[ strtolower( $sku ) ] = $sku; // preserve owner case
		}

		$matches = [];
		$unmatched_filenames = [];
		foreach ( $filenames as $name ) {
			if ( ! is_string( $name ) || $name === '' ) continue;
			$key = $this->normalise_filename( $name );
			if ( $key === '' ) {
				$unmatched_filenames[] = $name;
				continue;
			}
			if ( isset( $normalised_skus[ $key ] ) && ! isset( $matches[ $normalised_skus[ $key ] ] ) ) {
				$matches[ $normalised_skus[ $key ] ] = $name;
			} else {
				$unmatched_filenames[] = $name;
			}
		}

		$unmatched_skus = [];
		foreach ( $normalised_skus as $original ) {
			if ( ! isset( $matches[ $original ] ) ) $unmatched_skus[] = $original;
		}

		return [
			'matches'             => $matches,
			'unmatched_filenames' => $unmatched_filenames,
			'unmatched_skus'      => $unmatched_skus,
		];
	}

	/**
	 * Strip extension + common suffix patterns + trailing numeric
	 * suffixes ("_2", "-3"). Returns the lowercased SKU candidate.
	 */
	private function normalise_filename( string $name ): string {
		$base = preg_replace( '/\.[A-Za-z0-9]{2,5}$/', '', $name ); // strip ext
		if ( ! is_string( $base ) ) return '';
		$lower = strtolower( $base );
		foreach ( self::STRIP_SUFFIXES as $suffix ) {
			if ( str_ends_with( $lower, $suffix ) ) {
				$lower = substr( $lower, 0, -strlen( $suffix ) );
				break;
			}
		}
		// Trailing -N or _N (e.g., "U171-2") → drop.
		$lower = preg_replace( '/[-_]\d+$/', '', $lower );
		return is_string( $lower ) ? trim( $lower, '-_ ' ) : '';
	}
}
