<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Validation\KeyValidator;

final class FieldService {

	public const FIELD_KINDS  = [ 'input', 'display', 'computed', 'addon', 'lookup' ];
	public const INPUT_TYPES  = [ 'number', 'radio', 'checkbox', 'dropdown', 'text', 'hidden' ];
	public const DISPLAY_TYPES = [ 'plain', 'cards', 'image_grid', 'swatch_grid', 'accordion', 'summary', 'info_block', 'heading' ];
	public const VALUE_SOURCES = [ 'manual_options', 'library', 'woo_products', 'woo_category', 'lookup_table', 'computed' ];
	public const BEHAVIORS    = [ 'normal_option', 'product_addon', 'lookup_dimension', 'price_modifier', 'presentation_only' ];
	public const PRICING_MODES = [ 'none', 'fixed', 'per_unit', 'per_m2', 'lookup_dimension' ];

	private const LABEL_MIN = 2;
	private const LABEL_MAX = 200;

	public function __construct(
		private FieldRepository $fields,
		private StepRepository $steps,
		private TemplateRepository $templates,
	) {}

	/**
	 * @return array{items:list<array<string,mixed>>,total:int}|null
	 */
	public function list_in_step( int $template_id, int $step_id ): ?array {
		$ctx = $this->resolve( $template_id, $step_id );
		if ( $ctx === null ) {
			return null;
		}
		[ $template, $step ] = $ctx;
		return $this->fields->list_in_step( (string) $template['template_key'], (string) $step['step_key'] );
	}

	public function get( int $template_id, int $field_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		$field = $this->fields->find_by_id( $field_id );
		if ( $field === null || $field['template_key'] !== $template['template_key'] ) {
			return null;
		}
		return $field;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, id?:int, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function create( int $template_id, int $step_id, array $input ): array {
		$ctx = $this->resolve( $template_id, $step_id );
		if ( $ctx === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Template or step not found.' ] ] ];
		}
		[ $template, $step ] = $ctx;

		$errors = $this->validate( $input, null, $template );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized                 = $this->sanitize( $input );
		$sanitized['template_key'] = (string) $template['template_key'];
		$sanitized['step_key']     = (string) $step['step_key'];

		if ( ! array_key_exists( 'sort_order', $input ) || $input['sort_order'] === '' || $input['sort_order'] === null ) {
			$sanitized['sort_order'] = $this->fields->max_sort_order(
				(string) $template['template_key'],
				(string) $step['step_key']
			) + 1;
		}

		$id = $this->fields->create( $sanitized );
		return [ 'ok' => true, 'id' => $id, 'record' => $this->fields->find_by_id( $id ) ?? [] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool, record?:array<string,mixed>, errors?:list<array<string,string>>}
	 */
	public function update( int $template_id, int $field_id, array $input, string $expected_version_hash ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Template not found.' ] ] ];
		}
		$existing = $this->fields->find_by_id( $field_id );
		if ( $existing === null || $existing['template_key'] !== $template['template_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Field not found.' ] ] ];
		}
		if ( (string) $existing['version_hash'] !== $expected_version_hash ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'conflict', 'message' => 'Field was edited elsewhere. Reload and try again.' ] ] ];
		}

		$errors = $this->validate( $input, $existing, $template );
		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}
		$sanitized                 = $this->sanitize( $input );
		$sanitized['template_key'] = (string) $existing['template_key'];
		// Allow moving a field between steps inside the same template.
		if ( ! array_key_exists( 'step_key', $input ) || $input['step_key'] === '' ) {
			$sanitized['step_key'] = (string) $existing['step_key'];
		}
		// field_key is immutable.
		$sanitized['field_key'] = (string) $existing['field_key'];

		if ( ! array_key_exists( 'sort_order', $input ) || $input['sort_order'] === '' || $input['sort_order'] === null ) {
			$sanitized['sort_order'] = (int) $existing['sort_order'];
		}

		$this->fields->update( $field_id, $sanitized );
		return [ 'ok' => true, 'record' => $this->fields->find_by_id( $field_id ) ?? [] ];
	}

	/**
	 * @return array{ok:bool, errors?:list<array<string,string>>}
	 */
	public function delete( int $template_id, int $field_id ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'template_not_found', 'message' => 'Template not found.' ] ] ];
		}
		$existing = $this->fields->find_by_id( $field_id );
		if ( $existing === null || $existing['template_key'] !== $template['template_key'] ) {
			return [ 'ok' => false, 'errors' => [ [ 'code' => 'not_found', 'message' => 'Field not found.' ] ] ];
		}
		$this->fields->delete( $field_id );
		return [ 'ok' => true ];
	}

	/**
	 * Bulk reorder. Each item: { field_id, sort_order }. Items that don't
	 * belong to this template are silently skipped.
	 *
	 * @param list<array<string,mixed>> $items
	 * @return array{ok:bool, summary:array<string,int>, errors:list<array<string,mixed>>}
	 */
	public function reorder( int $template_id, array $items ): array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return [
				'ok'      => false,
				'summary' => [ 'updated' => 0, 'skipped' => 0 ],
				'errors'  => [ [ 'code' => 'template_not_found', 'message' => 'Template not found.' ] ],
			];
		}
		$updated = 0;
		$skipped = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['field_id'], $item['sort_order'] ) ) {
				$skipped++;
				continue;
			}
			$field = $this->fields->find_by_id( (int) $item['field_id'] );
			if ( $field === null || $field['template_key'] !== $template['template_key'] ) {
				$skipped++;
				continue;
			}
			$this->fields->set_sort_order( (int) $item['field_id'], (int) $item['sort_order'] );
			$updated++;
		}
		return [
			'ok'      => true,
			'summary' => [ 'updated' => $updated, 'skipped' => $skipped ],
			'errors'  => [],
		];
	}

	/**
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}|null
	 */
	private function resolve( int $template_id, int $step_id ): ?array {
		$template = $this->templates->find_by_id( $template_id );
		if ( $template === null ) {
			return null;
		}
		$step = $this->steps->find_by_id( $step_id );
		if ( $step === null || $step['template_key'] !== $template['template_key'] ) {
			return null;
		}
		return [ $template, $step ];
	}

	/**
	 * @param array<string,mixed>      $input
	 * @param array<string,mixed>|null $existing
	 * @param array<string,mixed>      $template
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	public function validate( array $input, ?array $existing, array $template ): array {
		$errors = [];

		// field_key — immutable on update; validate format/uniqueness on create.
		if ( $existing === null ) {
			$field_key  = isset( $input['field_key'] ) ? (string) $input['field_key'] : '';
			$key_errors = KeyValidator::validate( 'field_key', $field_key );
			if ( count( $key_errors ) > 0 ) {
				$errors = array_merge( $errors, $key_errors );
			} elseif ( $this->fields->key_exists_in_template( (string) $template['template_key'], $field_key ) ) {
				$errors[] = [
					'field'   => 'field_key',
					'code'    => 'duplicate',
					'message' => 'A field with this key already exists in this template.',
				];
			}
		}

		$label        = isset( $input['label'] ) ? trim( (string) $input['label'] ) : '';
		$label_length = strlen( $label );
		if ( $label === '' ) {
			$errors[] = [ 'field' => 'label', 'code' => 'required', 'message' => 'label is required.' ];
		} elseif ( $label_length < self::LABEL_MIN ) {
			$errors[] = [ 'field' => 'label', 'code' => 'too_short', 'message' => sprintf( 'label must be at least %d characters.', self::LABEL_MIN ) ];
		} elseif ( $label_length > self::LABEL_MAX ) {
			$errors[] = [ 'field' => 'label', 'code' => 'too_long', 'message' => sprintf( 'label must be at most %d characters.', self::LABEL_MAX ) ];
		}

		// 5-axis enum membership.
		$field_kind   = (string) ( $input['field_kind']  ?? ( $existing['field_kind']  ?? '' ) );
		$display_type = (string) ( $input['display_type'] ?? ( $existing['display_type'] ?? '' ) );
		$value_source = (string) ( $input['value_source'] ?? ( $existing['value_source'] ?? '' ) );
		$behavior     = (string) ( $input['behavior']    ?? ( $existing['behavior']    ?? '' ) );
		$input_type_raw = $input['input_type'] ?? ( $existing['input_type'] ?? null );
		$input_type   = ( $input_type_raw === null || $input_type_raw === '' ) ? null : (string) $input_type_raw;

		if ( ! in_array( $field_kind, self::FIELD_KINDS, true ) ) {
			$errors[] = [ 'field' => 'field_kind', 'code' => 'invalid_value', 'message' => 'field_kind must be one of: ' . implode( ', ', self::FIELD_KINDS ) . '.' ];
		}
		if ( $input_type !== null && ! in_array( $input_type, self::INPUT_TYPES, true ) ) {
			$errors[] = [ 'field' => 'input_type', 'code' => 'invalid_value', 'message' => 'input_type must be one of: ' . implode( ', ', self::INPUT_TYPES ) . ' (or null).' ];
		}
		if ( ! in_array( $display_type, self::DISPLAY_TYPES, true ) ) {
			$errors[] = [ 'field' => 'display_type', 'code' => 'invalid_value', 'message' => 'display_type must be one of: ' . implode( ', ', self::DISPLAY_TYPES ) . '.' ];
		}
		if ( ! in_array( $value_source, self::VALUE_SOURCES, true ) ) {
			$errors[] = [ 'field' => 'value_source', 'code' => 'invalid_value', 'message' => 'value_source must be one of: ' . implode( ', ', self::VALUE_SOURCES ) . '.' ];
		}
		if ( ! in_array( $behavior, self::BEHAVIORS, true ) ) {
			$errors[] = [ 'field' => 'behavior', 'code' => 'invalid_value', 'message' => 'behavior must be one of: ' . implode( ', ', self::BEHAVIORS ) . '.' ];
		}

		// Stop early if any axis value is bogus — combination check below assumes canonical values.
		if ( count( $errors ) > 0 ) {
			return $errors;
		}

		$errors = array_merge(
			$errors,
			$this->validate_axis_combination( $field_kind, $input_type, $display_type, $value_source, $behavior )
		);

		// pricing_mode (optional) and pricing_value (optional).
		if ( array_key_exists( 'pricing_mode', $input ) && $input['pricing_mode'] !== null && $input['pricing_mode'] !== '' ) {
			if ( ! in_array( (string) $input['pricing_mode'], self::PRICING_MODES, true ) ) {
				$errors[] = [ 'field' => 'pricing_mode', 'code' => 'invalid_value', 'message' => 'pricing_mode must be one of: ' . implode( ', ', self::PRICING_MODES ) . '.' ];
			}
		}
		if ( array_key_exists( 'pricing_value', $input ) && $input['pricing_value'] !== null && $input['pricing_value'] !== '' ) {
			if ( ! is_numeric( $input['pricing_value'] ) ) {
				$errors[] = [ 'field' => 'pricing_value', 'code' => 'invalid_type', 'message' => 'pricing_value must be numeric.' ];
			}
		}

		// source_config validation (must match value_source shape).
		$source_config = $input['source_config'] ?? ( $existing['source_config'] ?? [] );
		if ( ! is_array( $source_config ) ) {
			$errors[] = [ 'field' => 'source_config', 'code' => 'invalid_type', 'message' => 'source_config must be an object.' ];
		} else {
			$errors = array_merge( $errors, $this->validate_source_config( $value_source, $source_config ) );
		}

		return $errors;
	}

	/**
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	private function validate_axis_combination(
		string $field_kind,
		?string $input_type,
		string $display_type,
		string $value_source,
		string $behavior
	): array {
		$errors = [];

		if ( $field_kind === 'display' ) {
			if ( $input_type !== null ) {
				$errors[] = [ 'field' => 'input_type', 'code' => 'invalid_combination', 'message' => 'display fields must have input_type=null.' ];
			}
			if ( $behavior !== 'presentation_only' ) {
				$errors[] = [ 'field' => 'behavior', 'code' => 'invalid_combination', 'message' => 'display fields must use behavior=presentation_only.' ];
			}
			if ( ! in_array( $display_type, [ 'heading', 'info_block', 'summary' ], true ) ) {
				$errors[] = [ 'field' => 'display_type', 'code' => 'invalid_combination', 'message' => 'display fields use display_type heading|info_block|summary.' ];
			}
		} elseif ( $field_kind === 'lookup' ) {
			if ( $behavior !== 'lookup_dimension' ) {
				$errors[] = [ 'field' => 'behavior', 'code' => 'invalid_combination', 'message' => 'lookup fields must use behavior=lookup_dimension.' ];
			}
			if ( ! in_array( $value_source, [ 'lookup_table', 'manual_options' ], true ) ) {
				$errors[] = [ 'field' => 'value_source', 'code' => 'invalid_combination', 'message' => 'lookup fields use value_source lookup_table or manual_options.' ];
			}
		} elseif ( $field_kind === 'addon' ) {
			if ( $behavior !== 'product_addon' ) {
				$errors[] = [ 'field' => 'behavior', 'code' => 'invalid_combination', 'message' => 'addon fields must use behavior=product_addon.' ];
			}
			if ( ! in_array( $value_source, [ 'woo_products', 'woo_category' ], true ) ) {
				$errors[] = [ 'field' => 'value_source', 'code' => 'invalid_combination', 'message' => 'addon fields use value_source woo_products or woo_category.' ];
			}
			if ( ! in_array( $input_type, [ 'checkbox', 'radio' ], true ) ) {
				$errors[] = [ 'field' => 'input_type', 'code' => 'invalid_combination', 'message' => 'addon fields use input_type checkbox or radio.' ];
			}
		} elseif ( $field_kind === 'computed' ) {
			if ( $value_source !== 'computed' ) {
				$errors[] = [ 'field' => 'value_source', 'code' => 'invalid_combination', 'message' => 'computed fields use value_source=computed.' ];
			}
		}

		// Cross-axis: woo_* sources only with addon kind.
		if ( in_array( $value_source, [ 'woo_products', 'woo_category' ], true ) && $field_kind !== 'addon' ) {
			$errors[] = [ 'field' => 'value_source', 'code' => 'invalid_combination', 'message' => 'woo_products / woo_category sources are only available for addon fields.' ];
		}

		// lookup_table source only with lookup kind.
		if ( $value_source === 'lookup_table' && $field_kind !== 'lookup' ) {
			$errors[] = [ 'field' => 'value_source', 'code' => 'invalid_combination', 'message' => 'lookup_table source is only available for lookup fields.' ];
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $config
	 * @return list<array{field?:string, code:string, message:string}>
	 */
	private function validate_source_config( string $value_source, array $config ): array {
		$errors = [];
		switch ( $value_source ) {
			case 'library':
				$libs = $config['libraries'] ?? null;
				if ( ! is_array( $libs ) || count( $libs ) === 0 ) {
					$errors[] = [ 'field' => 'source_config', 'code' => 'library_required', 'message' => 'source_config.libraries must be a non-empty array of library_keys.' ];
				}
				break;
			case 'woo_category':
				if ( empty( $config['category_slug'] ) || ! is_string( $config['category_slug'] ) ) {
					$errors[] = [ 'field' => 'source_config', 'code' => 'category_required', 'message' => 'source_config.category_slug is required.' ];
				}
				break;
			case 'woo_products':
				$skus = $config['product_skus'] ?? null;
				if ( ! is_array( $skus ) || count( $skus ) === 0 ) {
					$errors[] = [ 'field' => 'source_config', 'code' => 'product_skus_required', 'message' => 'source_config.product_skus must be a non-empty array.' ];
				}
				break;
			case 'lookup_table':
				if ( empty( $config['lookup_table_key'] ) || ! is_string( $config['lookup_table_key'] ) ) {
					$errors[] = [ 'field' => 'source_config', 'code' => 'lookup_table_required', 'message' => 'source_config.lookup_table_key is required.' ];
				}
				if ( empty( $config['dimension'] ) || ! in_array( (string) $config['dimension'], [ 'width', 'height', 'price_group' ], true ) ) {
					$errors[] = [ 'field' => 'source_config', 'code' => 'dimension_required', 'message' => 'source_config.dimension must be one of: width, height, price_group.' ];
				}
				break;
			case 'computed':
				if ( empty( $config['rule_key'] ) || ! is_string( $config['rule_key'] ) ) {
					$errors[] = [ 'field' => 'source_config', 'code' => 'rule_key_required', 'message' => 'source_config.rule_key is required.' ];
				}
				break;
			case 'manual_options':
			default:
				// no params required
				break;
		}
		return $errors;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		$source_config = is_array( $input['source_config'] ?? null ) ? $input['source_config'] : [];
		$value_source  = (string) ( $input['value_source'] ?? '' );
		// Stamp the source type into the config for round-trippability.
		if ( $value_source !== '' && ! isset( $source_config['type'] ) ) {
			$source_config['type'] = $value_source;
		}

		return [
			'field_key'              => (string) ( $input['field_key'] ?? '' ),
			'label'                  => trim( (string) ( $input['label'] ?? '' ) ),
			'helper_text'            => isset( $input['helper_text'] ) && $input['helper_text'] !== '' ? (string) $input['helper_text'] : null,
			'field_kind'             => (string) ( $input['field_kind'] ?? '' ),
			'input_type'             => isset( $input['input_type'] ) && $input['input_type'] !== '' ? (string) $input['input_type'] : null,
			'display_type'           => (string) ( $input['display_type'] ?? 'plain' ),
			'value_source'           => $value_source,
			'source_config'          => $source_config,
			'behavior'               => (string) ( $input['behavior'] ?? '' ),
			'pricing_mode'           => isset( $input['pricing_mode'] ) && $input['pricing_mode'] !== '' ? (string) $input['pricing_mode'] : null,
			'pricing_value'          => isset( $input['pricing_value'] ) && $input['pricing_value'] !== '' && $input['pricing_value'] !== null ? (float) $input['pricing_value'] : null,
			'is_required'            => ! empty( $input['is_required'] ),
			'default_value'          => isset( $input['default_value'] ) && $input['default_value'] !== '' ? (string) $input['default_value'] : null,
			'show_in_cart'           => array_key_exists( 'show_in_cart', $input ) ? (bool) $input['show_in_cart'] : true,
			'show_in_checkout'       => array_key_exists( 'show_in_checkout', $input ) ? (bool) $input['show_in_checkout'] : true,
			'show_in_admin_order'    => array_key_exists( 'show_in_admin_order', $input ) ? (bool) $input['show_in_admin_order'] : true,
			'show_in_customer_email' => array_key_exists( 'show_in_customer_email', $input ) ? (bool) $input['show_in_customer_email'] : true,
			'sort_order'             => isset( $input['sort_order'] ) && $input['sort_order'] !== '' ? (int) $input['sort_order'] : 0,
		];
	}
}
