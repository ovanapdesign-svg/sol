<?php
declare(strict_types=1);

namespace ConfigKit\Service;

/**
 * Phase 4.2c — central, capability-driven form schema generator.
 *
 * Given a module record, returns the canonical schema for every form
 * downstream of that module: library item form, library form fields,
 * Excel import column whitelist. Every UI surface that reacts to a
 * module's capabilities reads from this one place — there is no
 * per-preset hardcoding anywhere else in the codebase.
 *
 * Three concerns kept separate so callers can ask for what they need:
 *
 *   for_library_items( $module ) — full library-item form schema
 *   for_library     ( $module ) — capability-driven library fields
 *   import_columns  ( $module ) — list of accepted Excel headers
 *
 * Pure-PHP (no WP / no DB). Stays inside the service boundary.
 */
final class CapabilityFormSchema {

	/** Capability flag → library-item field descriptor. */
	private const CAPABILITY_FIELDS = [
		'supports_sku'              => [ 'key' => 'sku',             'label' => 'SKU',                   'type' => 'text',          'group' => 'capability', 'helper' => 'Unique product code per library.' ],
		'supports_image'            => [ 'key' => 'image_url',       'label' => 'Thumbnail image URL',   'type' => 'url',           'group' => 'capability', 'helper' => 'Small image shown in pickers and carts.' ],
		'supports_main_image'       => [ 'key' => 'main_image_url',  'label' => 'Hero image URL',        'type' => 'url',           'group' => 'capability', 'helper' => 'Larger image used on detail views.' ],
		'supports_price'            => [ 'key' => 'price',           'label' => 'Price (NOK)',           'type' => 'number',        'group' => 'pricing',    'helper' => 'Base price.' ],
		'supports_sale_price'       => [ 'key' => 'sale_price',      'label' => 'Sale price (NOK)',      'type' => 'number',        'group' => 'pricing',    'helper' => 'Discounted price; leave blank for no sale.', 'depends_on' => 'supports_price' ],
		'supports_price_group'      => [ 'key' => 'price_group_key', 'label' => 'Price group',           'type' => 'text',          'group' => 'capability', 'helper' => 'Bucket key (I, II, III…) used by lookup tables.' ],
		'supports_color_family'     => [ 'key' => 'color_family',    'label' => 'Color family',          'type' => 'text',          'group' => 'capability', 'helper' => 'Group label like blue / green / neutral.' ],
		'supports_filters'          => [ 'key' => 'filter_tags',     'label' => 'Filter tags',           'type' => 'tags',          'group' => 'capability', 'helper' => 'Comma-separated tags used by storefront filters.', 'storage_key' => 'filters' ],
		'supports_compatibility'    => [ 'key' => 'compatibility_tags', 'label' => 'Compatibility tags', 'type' => 'tags',         'group' => 'capability', 'helper' => 'Tags that gate which products this item works with.', 'storage_key' => 'compatibility' ],
		'supports_woo_product_link' => [ 'key' => 'woo_product_id',  'label' => 'Linked Woo product',    'type' => 'woo_product',   'group' => 'capability', 'helper' => 'WooCommerce product this item maps to (for cart line items).' ],
	];

	/** Capability flag → library-side inherited field descriptor. */
	private const INHERITED_FIELDS = [
		'supports_brand'      => [ 'key' => 'brand',      'label' => 'Brand',      'type' => 'text', 'helper' => 'Inherited from library; toggle Override to change per-item.' ],
		'supports_collection' => [ 'key' => 'collection', 'label' => 'Collection', 'type' => 'text', 'helper' => 'Inherited from library; toggle Override to change per-item.' ],
	];

	/** Capability flag → library-form field descriptor (those that live on the library record itself). */
	private const LIBRARY_FIELDS = [
		'supports_brand'      => [ 'key' => 'brand',      'label' => 'Brand',      'type' => 'text' ],
		'supports_collection' => [ 'key' => 'collection', 'label' => 'Collection', 'type' => 'text' ],
	];

	/** Universal item-form fields present on every module. */
	private const UNIVERSAL_FIELDS = [
		[ 'key' => 'label',       'label' => 'Label',       'type' => 'text',     'required' => true,  'group' => 'universal' ],
		[ 'key' => 'short_label', 'label' => 'Short label', 'type' => 'text',     'required' => false, 'group' => 'universal' ],
		[ 'key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false, 'group' => 'universal' ],
		[ 'key' => 'sort_order',  'label' => 'Sort order',  'type' => 'number',   'required' => false, 'group' => 'universal' ],
		[ 'key' => 'is_active',   'label' => 'Active',      'type' => 'boolean',  'required' => false, 'group' => 'universal', 'default' => true ],
	];

	private const ALLOWED_ATTRIBUTE_TYPES = [ 'text', 'number', 'boolean', 'enum' ];

	/**
	 * Library-item form schema. The shape is JSON-friendly so the JS
	 * admin can render it directly without a second translation step.
	 *
	 * @param array<string,mixed> $module
	 *
	 * @return array{
	 *   module_key:string,
	 *   module_name:string,
	 *   universal_fields:list<array<string,mixed>>,
	 *   capability_fields:list<array<string,mixed>>,
	 *   inherited_fields:list<array<string,mixed>>,
	 *   attribute_fields:list<array<string,mixed>>,
	 *   pricing_supported:bool,
	 *   woo_supported:bool
	 * }
	 */
	public function for_library_items( array $module ): array {
		$caps = $this->normalised_capabilities( $module );

		$capability_fields = [];
		foreach ( self::CAPABILITY_FIELDS as $flag => $descriptor ) {
			if ( empty( $caps[ $flag ] ) ) continue;
			if ( isset( $descriptor['depends_on'] ) && empty( $caps[ $descriptor['depends_on'] ] ) ) continue;
			$capability_fields[] = array_merge( [ 'capability' => $flag ], $descriptor );
		}

		$inherited_fields = [];
		foreach ( self::INHERITED_FIELDS as $flag => $descriptor ) {
			if ( empty( $caps[ $flag ] ) ) continue;
			$inherited_fields[] = array_merge( [ 'capability' => $flag, 'inherits_from' => 'library' ], $descriptor );
		}

		return [
			'module_key'        => (string) ( $module['module_key'] ?? '' ),
			'module_name'       => (string) ( $module['name'] ?? '' ),
			'universal_fields'  => self::UNIVERSAL_FIELDS,
			'capability_fields' => $capability_fields,
			'inherited_fields'  => $inherited_fields,
			'attribute_fields'  => $this->normalised_attributes( $module ),
			'pricing_supported' => ! empty( $caps['supports_price'] ) || ! empty( $caps['supports_sale_price'] ),
			'woo_supported'     => ! empty( $caps['supports_woo_product_link'] ),
		];
	}

	/**
	 * Library-form schema — capability-driven fields that live on the
	 * library record itself (Brand, Collection).
	 *
	 * @param array<string,mixed> $module
	 * @return array{
	 *   module_key:string,
	 *   library_fields:list<array<string,mixed>>
	 * }
	 */
	public function for_library( array $module ): array {
		$caps = $this->normalised_capabilities( $module );
		$out  = [];
		foreach ( self::LIBRARY_FIELDS as $flag => $descriptor ) {
			if ( empty( $caps[ $flag ] ) ) continue;
			$out[] = array_merge( [ 'capability' => $flag ], $descriptor );
		}
		return [
			'module_key'     => (string) ( $module['module_key'] ?? '' ),
			'library_fields' => $out,
		];
	}

	/**
	 * Excel-importer column whitelist for the given module. Returns the
	 * canonical key paired with every alias the parser will accept.
	 *
	 * @param array<string,mixed> $module
	 * @return list<array{
	 *   key:string,
	 *   aliases:list<string>,
	 *   group:'universal'|'capability'|'attribute',
	 *   type:string
	 * }>
	 */
	public function import_columns( array $module ): array {
		$caps    = $this->normalised_capabilities( $module );
		$columns = [
			[ 'key' => 'item_key',    'aliases' => [ 'item_key' ],                         'group' => 'universal', 'type' => 'text' ],
			[ 'key' => 'label',       'aliases' => [ 'label' ],                            'group' => 'universal', 'type' => 'text' ],
			[ 'key' => 'short_label', 'aliases' => [ 'short_label' ],                      'group' => 'universal', 'type' => 'text' ],
			[ 'key' => 'description', 'aliases' => [ 'description' ],                      'group' => 'universal', 'type' => 'textarea' ],
			[ 'key' => 'is_active',   'aliases' => [ 'is_active', 'active' ],              'group' => 'universal', 'type' => 'boolean' ],
			[ 'key' => 'sort_order',  'aliases' => [ 'sort_order' ],                       'group' => 'universal', 'type' => 'number' ],
		];

		// Capability columns
		foreach ( self::CAPABILITY_FIELDS as $flag => $descriptor ) {
			if ( empty( $caps[ $flag ] ) ) continue;
			$key = (string) $descriptor['key'];
			$aliases = [ $key ];
			if ( $key === 'price_group_key' ) $aliases[] = 'price_group';
			if ( $key === 'woo_product_id' ) $aliases[] = 'woo_product_sku';
			$columns[] = [
				'key'     => $key,
				'aliases' => $aliases,
				'group'   => 'capability',
				'type'    => (string) $descriptor['type'],
			];
		}

		// Inherited columns (brand/collection appear in item Excel too)
		foreach ( self::INHERITED_FIELDS as $flag => $descriptor ) {
			if ( empty( $caps[ $flag ] ) ) continue;
			$columns[] = [
				'key'     => (string) $descriptor['key'],
				'aliases' => [ (string) $descriptor['key'] ],
				'group'   => 'capability',
				'type'    => (string) $descriptor['type'],
			];
		}

		// Attribute columns — both the bare key and the attr.key alias.
		foreach ( $this->normalised_attributes( $module ) as $attr ) {
			$key = (string) $attr['key'];
			$columns[] = [
				'key'     => $key,
				'aliases' => [ $key, 'attr.' . $key ],
				'group'   => 'attribute',
				'type'    => (string) $attr['type'],
			];
		}

		return $columns;
	}

	/**
	 * Normalise the module's `attribute_schema` into the rich shape
	 * regardless of how it's stored on disk:
	 *
	 *   Legacy:  { "fabric_code": "string", "blackout": "boolean" }
	 *   Modern:  { "fabric_code": { "label": "Fabric code", "type": "text" }, ... }
	 *   Mixed:   any combination of the above
	 *
	 * Output shape per attribute:
	 *   { key, label, type ('text'|'number'|'boolean'|'enum'),
	 *     options?: list<string>, required: bool }
	 *
	 * @param array<string,mixed> $module
	 * @return list<array{key:string,label:string,type:string,options?:list<string>,required:bool}>
	 */
	public function normalised_attributes( array $module ): array {
		$schema = $module['attribute_schema'] ?? [];
		if ( ! is_array( $schema ) ) return [];

		$out = [];
		foreach ( $schema as $key => $entry ) {
			if ( ! is_string( $key ) || $key === '' ) continue;
			$out[] = $this->normalise_attribute_entry( $key, $entry );
		}
		// Sort by sort_order then label for stable output.
		usort( $out, static function ( $a, $b ) {
			$so = ( $a['sort_order'] ?? 0 ) <=> ( $b['sort_order'] ?? 0 );
			if ( $so !== 0 ) return $so;
			return strcmp( $a['label'], $b['label'] );
		} );
		// Drop the synthetic sort_order from the public payload.
		return array_map( static function ( $a ) { unset( $a['sort_order'] ); return $a; }, $out );
	}

	/**
	 * @param array<string,mixed> $module
	 * @return array<string,bool>
	 */
	private function normalised_capabilities( array $module ): array {
		$out = [];
		foreach ( array_keys( self::CAPABILITY_FIELDS ) as $flag ) {
			$out[ $flag ] = ! empty( $module[ $flag ] );
		}
		foreach ( array_keys( self::INHERITED_FIELDS ) as $flag ) {
			$out[ $flag ] = ! empty( $module[ $flag ] );
		}
		return $out;
	}

	/**
	 * @param mixed $entry
	 * @return array{key:string,label:string,type:string,options?:list<string>,required:bool,sort_order:int}
	 */
	private function normalise_attribute_entry( string $key, mixed $entry ): array {
		$row = [
			'key'        => $key,
			'label'      => $this->humanize_key( $key ),
			'type'       => 'text',
			'required'   => false,
			'sort_order' => 0,
		];

		if ( is_string( $entry ) ) {
			$row['type'] = $this->canonical_type( $entry );
			return $row;
		}
		if ( ! is_array( $entry ) ) return $row;

		if ( ! empty( $entry['label'] ) ) $row['label'] = (string) $entry['label'];
		if ( ! empty( $entry['type'] ) )  $row['type']  = $this->canonical_type( (string) $entry['type'] );
		if ( array_key_exists( 'required', $entry ) ) $row['required'] = (bool) $entry['required'];
		if ( array_key_exists( 'sort_order', $entry ) && is_numeric( $entry['sort_order'] ) ) {
			$row['sort_order'] = (int) $entry['sort_order'];
		}
		if ( $row['type'] === 'enum' ) {
			$opts = $entry['options'] ?? [];
			if ( is_array( $opts ) ) {
				$row['options'] = array_values( array_map( 'strval', array_filter( $opts, static fn ( $v ) => is_scalar( $v ) && (string) $v !== '' ) ) );
			}
			if ( ! isset( $row['options'] ) || count( $row['options'] ) === 0 ) {
				// Enum without options has nothing to choose from — fall
				// back to text rather than render a broken dropdown.
				$row['type'] = 'text';
				unset( $row['options'] );
			}
		}
		return $row;
	}

	private function canonical_type( string $raw ): string {
		$lower = strtolower( trim( $raw ) );
		// Legacy aliases.
		if ( in_array( $lower, [ 'string', 'str', 'varchar' ], true ) )      return 'text';
		if ( in_array( $lower, [ 'integer', 'int', 'float', 'decimal' ], true ) ) return 'number';
		if ( in_array( $lower, [ 'bool' ], true ) ) return 'boolean';
		if ( in_array( $lower, self::ALLOWED_ATTRIBUTE_TYPES, true ) ) return $lower;
		return 'text';
	}

	private function humanize_key( string $key ): string {
		$out = preg_replace( '/[_\-]+/', ' ', $key );
		$out = trim( (string) $out );
		if ( $out === '' ) return $key;
		return strtoupper( substr( $out, 0, 1 ) ) . substr( $out, 1 );
	}
}
