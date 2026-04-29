<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Validation\KeyValidator;

final class LibraryItemService {

	public function __construct(
		private LibraryItemRepository $items,
		private LibraryRepository $libraries,
		private ModuleRepository $modules,
	) {}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}|null
	 */
	public function list_for_library( int $library_id, int $page = 1, int $per_page = 100 ): ?array {
		$library = $this->libraries->find_by_id( $library_id );
		if ( $library === null ) {
			return null;
		}
		return $this->items->list_in_library( (string) $library['library_key'], $page, $per_page );
	}

	public function get( int $library_id, int $item_id ): ?array {
		$library = $this->libraries->find_by_id( $library_id );
		if ( $library === null ) {
			return null;
		}
		$item = $this->items->find_by_id( $item_id );
		if ( $item === null || $item['library_key'] !== $library['library_key'] ) {
			return null;
		}
		return $item;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function create( int $library_id, array $input ): array {
		$context = $this->resolve_context( $library_id );
		if ( $context === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'library_not_found', 'message' => 'Parent library not found.' ] ] ];
		}
		[ $library, $module ] = $context;

		$errors = $this->validate( $input, null, $library, $module );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input, $module );
		$sanitized['library_key'] = (string) $library['library_key'];
		$id = $this->items->create( $sanitized );
		return [ 'ok' => true, 'id' => $id, 'record' => $this->items->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $library_id, int $item_id, array $input, string $expected_version_hash ): array {
		$context = $this->resolve_context( $library_id );
		if ( $context === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'library_not_found', 'message' => 'Parent library not found.' ] ] ];
		}
		[ $library, $module ] = $context;

		$existing = $this->items->find_by_id( $item_id );
		if ( $existing === null || $existing['library_key'] !== $library['library_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Library item not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Library item was edited elsewhere. Reload and try again.' ] ] ];
		}

		$errors = $this->validate( $input, $existing, $library, $module );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized = $this->sanitize( $input, $module );
		$sanitized['library_key'] = (string) $library['library_key'];
		$this->items->update( $item_id, $sanitized );
		return [ 'ok' => true, 'record' => $this->items->find_by_id( $item_id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function soft_delete( int $library_id, int $item_id ): array {
		$context = $this->resolve_context( $library_id );
		if ( $context === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'library_not_found', 'message' => 'Parent library not found.' ] ] ];
		}
		[ $library ] = $context;

		$existing = $this->items->find_by_id( $item_id );
		if ( $existing === null || $existing['library_key'] !== $library['library_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Library item not found.' ] ] ];
		}
		$this->items->soft_delete( $item_id );
		return [ 'ok' => true ];
	}

	/**
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}|null
	 */
	private function resolve_context( int $library_id ): ?array {
		$library = $this->libraries->find_by_id( $library_id );
		if ( $library === null ) {
			return null;
		}
		$module = $this->modules->find_by_key( (string) $library['module_key'] );
		if ( $module === null ) {
			return null;
		}
		return [ $library, $module ];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @param array<string,mixed>      $library
	 * @param array<string,mixed>      $module
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing, array $library, array $module ): array {
		$errors = [];

		$item_key   = isset( $input['item_key'] ) ? (string) $input['item_key'] : '';
		$key_errors = KeyValidator::validate( 'item_key', $item_key );
		if ( count( $key_errors ) > 0 ) {
			$errors = array_merge( $errors, $key_errors );
		} else {
			$exclude_id = isset( $existing['id'] ) ? (int) $existing['id'] : null;
			if ( $this->items->key_exists_in_library( (string) $library['library_key'], $item_key, $exclude_id ) ) {
				$errors[] = [
					'field'   => 'item_key',
					'code'    => 'duplicate',
					'message' => 'An item with this key already exists in this library.',
				];
			}
		}

		$label = isset( $input['label'] ) ? trim( (string) $input['label'] ) : '';
		if ( $label === '' ) {
			$errors[] = [ 'field' => 'label', 'code' => 'required', 'message' => 'label is required.' ];
		} elseif ( strlen( $label ) > 255 ) {
			$errors[] = [ 'field' => 'label', 'code' => 'too_long', 'message' => 'label must be 255 characters or fewer.' ];
		}

		// Capability gates: reject fields the module does not support.
		$gates = [
			'sku'             => 'supports_sku',
			'image_url'       => 'supports_image',
			'main_image_url'  => 'supports_main_image',
			'price'           => 'supports_price',
			'sale_price'      => 'supports_sale_price',
			'price_group_key' => 'supports_price_group',
			'color_family'    => 'supports_color_family',
			'woo_product_id'  => 'supports_woo_product_link',
			'filters'         => 'supports_filters',
			'compatibility'   => 'supports_compatibility',
		];
		foreach ( $gates as $field => $cap ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = $input[ $field ];
			if ( $value === null || $value === '' || ( is_array( $value ) && count( $value ) === 0 ) ) {
				continue;
			}
			if ( ! ( $module[ $cap ] ?? false ) ) {
				$errors[] = [
					'field'   => $field,
					'code'    => 'unsupported_capability',
					'message' => sprintf( 'Module %s does not support %s.', (string) $module['module_key'], $field ),
				];
			}
		}

		if ( isset( $input['price'] ) && $input['price'] !== '' && $input['price'] !== null && ! is_numeric( $input['price'] ) ) {
			$errors[] = [ 'field' => 'price', 'code' => 'invalid_type', 'message' => 'price must be numeric.' ];
		}
		if ( isset( $input['sale_price'] ) && $input['sale_price'] !== '' && $input['sale_price'] !== null && ! is_numeric( $input['sale_price'] ) ) {
			$errors[] = [ 'field' => 'sale_price', 'code' => 'invalid_type', 'message' => 'sale_price must be numeric.' ];
		}

		// Attribute schema validation
		$schema = is_array( $module['attribute_schema'] ?? null ) ? $module['attribute_schema'] : [];
		$attrs  = is_array( $input['attributes'] ?? null ) ? $input['attributes'] : [];
		foreach ( $attrs as $attr_key => $attr_value ) {
			if ( ! is_string( $attr_key ) ) {
				continue;
			}
			if ( ! array_key_exists( $attr_key, $schema ) ) {
				$errors[] = [
					'field'   => 'attributes',
					'code'    => 'unknown_attribute',
					'message' => sprintf( 'Attribute "%s" is not declared in the module schema.', $attr_key ),
				];
				continue;
			}
			$expected_type = (string) $schema[ $attr_key ];
			if ( ! $this->matches_type( $attr_value, $expected_type ) ) {
				$errors[] = [
					'field'   => 'attributes',
					'code'    => 'attribute_type_mismatch',
					'message' => sprintf( 'Attribute "%s" must be %s.', $attr_key, $expected_type ),
				];
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $module
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input, array $module ): array {
		$out = [
			'item_key'        => (string) ( $input['item_key'] ?? '' ),
			'label'           => trim( (string) ( $input['label'] ?? '' ) ),
			'short_label'     => isset( $input['short_label'] ) && $input['short_label'] !== '' ? (string) $input['short_label'] : null,
			'description'     => isset( $input['description'] ) && $input['description'] !== '' ? (string) $input['description'] : null,
			'price_group_key' => '',
			'is_active'       => array_key_exists( 'is_active', $input ) ? (bool) $input['is_active'] : true,
			'sort_order'      => (int) ( $input['sort_order'] ?? 0 ),
			'filters'         => [],
			'compatibility'   => [],
			'attributes'      => is_array( $input['attributes'] ?? null ) ? $input['attributes'] : [],
			'sku'             => null,
			'image_url'       => null,
			'main_image_url'  => null,
			'price'           => null,
			'sale_price'      => null,
			'color_family'    => null,
			'woo_product_id'  => null,
		];

		if ( ( $module['supports_sku'] ?? false ) && isset( $input['sku'] ) && $input['sku'] !== '' ) {
			$out['sku'] = (string) $input['sku'];
		}
		if ( ( $module['supports_image'] ?? false ) && isset( $input['image_url'] ) && $input['image_url'] !== '' ) {
			$out['image_url'] = (string) $input['image_url'];
		}
		if ( ( $module['supports_main_image'] ?? false ) && isset( $input['main_image_url'] ) && $input['main_image_url'] !== '' ) {
			$out['main_image_url'] = (string) $input['main_image_url'];
		}
		if ( ( $module['supports_price'] ?? false ) && isset( $input['price'] ) && $input['price'] !== '' && $input['price'] !== null ) {
			$out['price'] = (float) $input['price'];
		}
		if ( ( $module['supports_sale_price'] ?? false ) && isset( $input['sale_price'] ) && $input['sale_price'] !== '' && $input['sale_price'] !== null ) {
			$out['sale_price'] = (float) $input['sale_price'];
		}
		if ( ( $module['supports_price_group'] ?? false ) && isset( $input['price_group_key'] ) ) {
			$out['price_group_key'] = (string) $input['price_group_key'];
		}
		if ( ( $module['supports_color_family'] ?? false ) && isset( $input['color_family'] ) && $input['color_family'] !== '' ) {
			$out['color_family'] = (string) $input['color_family'];
		}
		if ( ( $module['supports_woo_product_link'] ?? false ) && isset( $input['woo_product_id'] ) && $input['woo_product_id'] !== '' && $input['woo_product_id'] !== null ) {
			$out['woo_product_id'] = (int) $input['woo_product_id'];
		}
		if ( ( $module['supports_filters'] ?? false ) && is_array( $input['filters'] ?? null ) ) {
			$out['filters'] = array_values( array_filter(
				$input['filters'],
				static fn( $v ): bool => is_string( $v ) && $v !== ''
			) );
		}
		if ( ( $module['supports_compatibility'] ?? false ) && is_array( $input['compatibility'] ?? null ) ) {
			$out['compatibility'] = array_values( array_filter(
				$input['compatibility'],
				static fn( $v ): bool => is_string( $v ) && $v !== ''
			) );
		}

		return $out;
	}

	private function matches_type( mixed $value, string $type ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'boolean':
				return is_bool( $value );
			default:
				return false;
		}
	}
}
