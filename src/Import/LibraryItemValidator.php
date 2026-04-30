<?php
declare(strict_types=1);

namespace ConfigKit\Import;

use ConfigKit\Adapters\WooSkuResolver;
use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\ModuleRepository;

/**
 * Phase 4 dalis 3 — per-row + cross-row validation for library-items
 * imports (IMPORT_WIZARD_SPEC §6).
 *
 * Severity rules mirror the lookup-cells path:
 *   green  — valid; commit will insert or update
 *   yellow — non-blocking warning (capability mismatch, dup-in-file
 *            override, woo_product_sku resolved fine)
 *   red    — blocking error (skipped on commit)
 *
 * Cross-row rule: duplicate (library_key, item_key) within a single
 * file → last row wins, prior row demoted to yellow + skip
 * (matches lookup-cells behaviour).
 *
 * Capability awareness: the validator looks up the target library's
 * module and warns when the file carries a column the module doesn't
 * advertise (e.g. `brand` against a module without `supports_brand`).
 * The column is dropped from `normalized` so commit doesn't store it,
 * but the row still imports. This lets owners pull a single file
 * across libraries with different capability flags without surgery.
 */
final class LibraryItemValidator {

	public const ATTRIBUTE_TO_CAPABILITY = [
		'brand'              => 'supports_brand',
		'collection'         => 'supports_collection',
		'color_family'       => 'supports_color_family',
		'image_url'          => 'supports_image',
		'main_image_url'     => 'supports_main_image',
		'filter_tags'        => 'supports_filters',
		'compatibility_tags' => 'supports_compatibility',
	];

	public const TOP_LEVEL_TO_CAPABILITY = [
		'sku'             => 'supports_sku',
		'price'           => 'supports_price',
		'sale_price'      => 'supports_sale_price',
		'price_group_key' => 'supports_price_group',
		'woo_product_id'  => 'supports_woo_product_link',
		'woo_product_sku' => 'supports_woo_product_link',
	];

	public const VALID_PRICE_SOURCES_SIMPLE = [ 'configkit', 'woo', 'product_override' ];
	public const VALID_ITEM_TYPES           = [ 'simple_option', 'bundle' ];

	public function __construct(
		private LibraryRepository $libraries,
		private ModuleRepository $modules,
		private LibraryItemRepository $items,
		private WooSkuResolver $woo_skus,
	) {}

	/**
	 * @param list<array<string,mixed>> $parsed_rows
	 * @param array{
	 *   target_library_key:string,
	 *   mode:'insert_update'|'replace_all',
	 * } $context
	 *
	 * @return list<array<string,mixed>>
	 */
	public function validate( array $parsed_rows, array $context ): array {
		$target_key = (string) $context['target_library_key'];
		$library    = $target_key !== '' ? $this->libraries->find_by_key( $target_key ) : null;
		$module     = $library !== null ? $this->modules->find_by_key( (string) $library['module_key'] ) : null;

		$out     = [];
		$winners = []; // (library_key:item_key) → index in $out
		foreach ( $parsed_rows as $i => $row ) {
			$row = $this->validate_row( $row, $target_key, $library, $module );
			$norm = $row['normalized'] ?? [];
			if ( $row['severity'] !== ImportRowRepository::SEVERITY_RED ) {
				$key = (string) ( $norm['library_key'] ?? '' )
					. ':' . (string) ( $norm['item_key'] ?? '' );
				if ( isset( $winners[ $key ] ) ) {
					$prev = $winners[ $key ];
					$out[ $prev ]['severity'] = ImportRowRepository::SEVERITY_YELLOW;
					$out[ $prev ]['action']   = ImportRowRepository::ACTION_SKIP;
					$out[ $prev ]['message']  = trim(
						( $out[ $prev ]['message'] ?? '' )
						. ' Duplicate within file — superseded by row ' . ( $i + 1 ) . '.'
					);
				}
				$winners[ $key ] = count( $out );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed>|null $library
	 * @param array<string,mixed>|null $module
	 * @return array<string,mixed>
	 */
	private function validate_row( array $row, string $target_key, ?array $library, ?array $module ): array {
		$errors   = [];
		$warnings = [];
		$norm     = is_array( $row['normalized'] ?? null ) ? $row['normalized'] : [];

		// Force the target library on every row so the file can omit
		// the column when the wizard pre-selects a library.
		$file_library_key = $norm['library_key'] ?? null;
		if ( $target_key !== '' ) {
			$norm['library_key'] = $target_key;
		}

		// 1. Required: library_key + item_key + label.
		if ( empty( $norm['library_key'] ) ) {
			$errors[] = [ 'field' => 'library_key', 'message' => 'library_key is required.' ];
		}
		if ( empty( $norm['item_key'] ) ) {
			$errors[] = [ 'field' => 'item_key', 'message' => 'item_key is required.' ];
		} elseif ( ! preg_match( '/^[a-z][a-z0-9_]{2,63}$/', (string) $norm['item_key'] ) ) {
			$errors[] = [ 'field' => 'item_key', 'message' => 'item_key must be 3-64 chars, lowercase snake_case, starting with a letter.' ];
		}
		if ( empty( $norm['label'] ) ) {
			$errors[] = [ 'field' => 'label', 'message' => 'label is required.' ];
		}

		// 2. Library must exist + match the wizard target (when both file
		//    and target carry a value).
		if ( $library === null ) {
			$errors[] = [ 'field' => 'library_key', 'message' => sprintf( 'Library "%s" not found.', $target_key ) ];
		} elseif ( $file_library_key !== null && $file_library_key !== '' && (string) $file_library_key !== $target_key ) {
			$errors[] = [
				'field'   => 'library_key',
				'message' => sprintf( 'Row library_key "%s" does not match wizard target "%s".', (string) $file_library_key, $target_key ),
			];
		}

		// 3. Numeric ranges.
		if ( isset( $norm['price'] ) && is_float( $norm['price'] ) && $norm['price'] < 0 ) {
			$errors[] = [ 'field' => 'price', 'message' => 'price must be ≥ 0.' ];
		}
		if ( isset( $norm['sale_price'] ) && is_float( $norm['sale_price'] ) && $norm['sale_price'] < 0 ) {
			$errors[] = [ 'field' => 'sale_price', 'message' => 'sale_price must be ≥ 0.' ];
		}

		// 4. Enum allow-lists.
		if ( ! empty( $norm['price_source'] ) && ! in_array( (string) $norm['price_source'], self::VALID_PRICE_SOURCES_SIMPLE, true ) ) {
			$errors[] = [
				'field'   => 'price_source',
				'message' => 'price_source must be configkit, woo, or product_override.',
			];
		}
		if ( ! empty( $norm['item_type'] ) ) {
			$it = $this->canonical_item_type( (string) $norm['item_type'] );
			if ( $it === null ) {
				$errors[] = [ 'field' => 'item_type', 'message' => 'item_type must be simple or bundle.' ];
			} else {
				$norm['item_type'] = $it;
			}
		}

		// 5. Woo SKU lookup. If the row carries woo_product_sku but no id,
		//    resolve the SKU now so the runner can store the id verbatim.
		if ( $module !== null && ! empty( $module['supports_woo_product_link'] ) ) {
			if ( ( $norm['woo_product_id'] ?? null ) === null && ! empty( $norm['woo_product_sku'] ) ) {
				$resolved = $this->woo_skus->resolveBySku( (string) $norm['woo_product_sku'] );
				if ( $resolved === null ) {
					$errors[] = [
						'field'   => 'woo_product_sku',
						'message' => sprintf( 'Woo product SKU "%s" not found.', (string) $norm['woo_product_sku'] ),
					];
				} else {
					$norm['woo_product_id'] = $resolved;
					$warnings[] = [
						'field'   => 'woo_product_sku',
						'message' => sprintf( 'Resolved SKU "%s" → woo_product_id %d.', (string) $norm['woo_product_sku'], $resolved ),
					];
				}
			} elseif ( ( $norm['woo_product_id'] ?? null ) !== null && (int) $norm['woo_product_id'] > 0 ) {
				if ( ! $this->woo_skus->productExists( (int) $norm['woo_product_id'] ) ) {
					$errors[] = [
						'field'   => 'woo_product_id',
						'message' => sprintf( 'Woo product id %d does not exist.', (int) $norm['woo_product_id'] ),
					];
				}
			}
		}

		// 6. Capability mismatch warnings — drop the offending column from
		//    `normalized` so the runner doesn't persist data the module
		//    can't surface.
		if ( $module !== null ) {
			foreach ( self::TOP_LEVEL_TO_CAPABILITY as $field => $cap ) {
				if ( ! array_key_exists( $field, $norm ) ) continue;
				$value = $norm[ $field ];
				if ( $value === null || $value === '' || $value === 0 ) continue;
				if ( empty( $module[ $cap ] ) ) {
					$warnings[] = [
						'field'   => $field,
						'message' => sprintf( 'Module "%s" does not support %s — column ignored.', (string) ( $module['module_key'] ?? '' ), $field ),
					];
					$norm[ $field ] = $field === 'price_group_key' ? '' : null;
				}
			}
			$attrs = is_array( $norm['attributes'] ?? null ) ? $norm['attributes'] : [];
			foreach ( self::ATTRIBUTE_TO_CAPABILITY as $field => $cap ) {
				if ( ! array_key_exists( $field, $attrs ) ) continue;
				if ( empty( $module[ $cap ] ) ) {
					$warnings[] = [
						'field'   => $field,
						'message' => sprintf( 'Module "%s" does not support %s — column ignored.', (string) ( $module['module_key'] ?? '' ), $field ),
					];
					unset( $attrs[ $field ] );
				}
			}
			$norm['attributes'] = $attrs;
		}

		// 7. Unknown columns → soft warning, no row failure.
		$unknown = is_array( $norm['unknown_columns'] ?? null ) ? $norm['unknown_columns'] : [];
		foreach ( $unknown as $col ) {
			$warnings[] = [
				'field'   => 'header',
				'message' => sprintf( 'Unknown column "%s" — ignored.', (string) $col ),
			];
		}

		$severity = count( $errors ) > 0
			? ImportRowRepository::SEVERITY_RED
			: ( count( $warnings ) > 0
				? ImportRowRepository::SEVERITY_YELLOW
				: ImportRowRepository::SEVERITY_GREEN );

		// Decide insert vs update. Lookup happens against the live table
		// using (library_key, item_key) so the preview is accurate.
		$action = ImportRowRepository::ACTION_SKIP;
		if ( $severity !== ImportRowRepository::SEVERITY_RED && $library !== null ) {
			$exists = $this->items->key_exists_in_library(
				(string) $norm['library_key'],
				(string) $norm['item_key']
			);
			$action = $exists ? ImportRowRepository::ACTION_UPDATE : ImportRowRepository::ACTION_INSERT;
		}

		$message_parts = [];
		foreach ( $errors as $e )   $message_parts[] = $e['message'];
		foreach ( $warnings as $w ) $message_parts[] = $w['message'];

		$row['normalized']  = $norm;
		$row['severity']    = $severity;
		$row['action']      = $action;
		$row['message']     = implode( ' ', $message_parts );
		$row['errors']      = $errors;
		$row['warnings']    = $warnings;
		$row['object_type'] = 'library_item';
		$row['object_key']  = (string) ( $norm['library_key'] ?? '' ) . ':' . (string) ( $norm['item_key'] ?? '' );
		return $row;
	}

	/**
	 * Accept both the legacy "simple" wording from the spec doc and
	 * the canonical 'simple_option' enum locked in Phase 4.2a.1.
	 */
	private function canonical_item_type( string $raw ): ?string {
		$lower = strtolower( trim( $raw ) );
		if ( in_array( $lower, [ 'simple', 'simple_option' ], true ) ) return 'simple_option';
		if ( $lower === 'bundle' ) return 'bundle';
		return null;
	}
}
