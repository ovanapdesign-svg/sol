<?php
declare(strict_types=1);

namespace ConfigKit\Frontend;

use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Repository\ProductBindingRepository;
use ConfigKit\Repository\RuleRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Rest\AbstractController;

/**
 * Public REST endpoint that hands the storefront configurator
 * everything it needs to render a product page client-side:
 *   - the bound template's structure (steps + fields + options + rules)
 *   - the product binding (defaults / overrides / locked values)
 *   - hydrated library items for any field whose value_source = 'library'
 *   - the lookup table's cells (or a paginated subset)
 *   - module capability flags so the renderer knows which fields the
 *     library item exposes (image url, price group, etc.)
 *
 * No authentication required — this powers the public product page.
 * Disabled bindings or unready products return 404.
 */
final class RenderDataController extends AbstractController {

	public function __construct(
		private ProductBindingRepository $bindings,
		private TemplateRepository $templates,
		private StepRepository $steps,
		private FieldRepository $fields,
		private FieldOptionRepository $options,
		private RuleRepository $rules,
		private LibraryRepository $libraries,
		private LibraryItemRepository $library_items,
		private LookupTableRepository $lookup_tables,
		private LookupCellRepository $lookup_cells,
		private ModuleRepository $modules,
	) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)/render-data',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'render_data' ],
					'permission_callback' => '__return_true',
					'args'                => [ 'product_id' => [ 'type' => 'integer', 'required' => true ] ],
				],
			]
		);
	}

	public function render_data( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$product_id = (int) $request['product_id'];
		$binding = $this->bindings->find( $product_id );
		if ( $binding === null ) {
			return $this->error( 'not_found', 'Product not found.', [], 404 );
		}
		if ( empty( $binding['enabled'] ) ) {
			return $this->error( 'not_enabled', 'ConfigKit is not enabled on this product.', [], 404 );
		}
		$template_key = (string) ( $binding['template_key'] ?? '' );
		if ( $template_key === '' ) {
			return $this->error( 'not_ready', 'Product binding has no template.', [], 404 );
		}
		$template = $this->templates->find_by_key( $template_key );
		if ( $template === null ) {
			return $this->error( 'not_ready', 'Bound template not found.', [], 404 );
		}

		$steps  = $this->steps->list_in_template( $template_key )['items'];
		$fields = [];
		$options = [];
		$library_keys_needed = [];
		foreach ( $steps as $step ) {
			$step_fields = $this->fields->list_in_step( $template_key, (string) $step['step_key'] )['items'];
			foreach ( $step_fields as $field ) {
				$fields[] = $field;
				$opts = $this->options->list_for_field( $template_key, (string) $field['field_key'] )['items'];
				foreach ( $opts as $o ) $options[] = $o;
				if ( ( $field['value_source'] ?? '' ) === 'library' ) {
					$cfg = is_array( $field['source_config'] ?? null ) ? $field['source_config'] : [];
					$libs = is_array( $cfg['libraries'] ?? null ) ? $cfg['libraries'] : [];
					foreach ( $libs as $key ) {
						if ( is_string( $key ) ) $library_keys_needed[ $key ] = true;
					}
				}
			}
		}
		$rules = $this->rules->list_in_template( $template_key )['items'];

		// Hydrate libraries + items.
		$libraries_out = [];
		$module_keys_needed = [];
		foreach ( array_keys( $library_keys_needed ) as $library_key ) {
			$lib = $this->libraries->find_by_key( $library_key );
			if ( $lib === null || empty( $lib['is_active'] ) ) continue;
			$module_keys_needed[ (string) $lib['module_key'] ] = true;
			$items_listing = $this->library_items->list_in_library( $library_key, 1, 500 );
			$libraries_out[] = [
				'library_key' => $library_key,
				'name'        => (string) $lib['name'],
				'module_key'  => (string) $lib['module_key'],
				'is_active'   => true,
				'items'       => array_map(
					static fn ( $item ): array => [
						'item_key'        => (string) $item['item_key'],
						'label'           => (string) $item['label'],
						'short_label'     => (string) ( $item['short_label'] ?? '' ),
						'sku'             => $item['sku'] ?? null,
						'image_url'       => $item['image_url'] ?? null,
						'main_image_url'  => $item['main_image_url'] ?? null,
						'price'           => $item['price'] ?? null,
						'sale_price'      => $item['sale_price'] ?? null,
						'price_group_key' => (string) ( $item['price_group_key'] ?? '' ),
						'color_family'    => $item['color_family'] ?? null,
						'woo_product_id'  => $item['woo_product_id'] ?? null,
						'filters'         => is_array( $item['filters'] ?? null ) ? $item['filters'] : [],
						'compatibility'   => is_array( $item['compatibility'] ?? null ) ? $item['compatibility'] : [],
						'is_active'       => ! empty( $item['is_active'] ),
					],
					array_values( array_filter( $items_listing['items'], static fn ( $i ): bool => ! empty( $i['is_active'] ) ) )
				),
			];
		}

		// Hydrate modules so the renderer knows which fields each
		// library exposes (image_url etc.).
		$modules_out = [];
		foreach ( array_keys( $module_keys_needed ) as $module_key ) {
			$mod = $this->modules->find_by_key( $module_key );
			if ( $mod === null ) continue;
			$flat = [
				'module_key' => $module_key,
				'name'       => (string) $mod['name'],
			];
			foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
				$flat[ $flag ] = ! empty( $mod[ $flag ] );
			}
			$modules_out[] = $flat;
		}

		// Lookup table snapshot (cells + table meta). Capped to 5000
		// cells per the import spec's perf budget.
		$lookup_table_out = null;
		$lookup_table_key = (string) ( $binding['lookup_table_key'] ?? '' );
		if ( $lookup_table_key !== '' ) {
			$tbl = $this->lookup_tables->find_by_key( $lookup_table_key );
			if ( $tbl !== null && ! empty( $tbl['is_active'] ) ) {
				$cells_listing = $this->lookup_cells->list_in_table( $lookup_table_key, [], 1, 5000 );
				$lookup_table_out = [
					'lookup_table_key'     => $lookup_table_key,
					'name'                 => (string) $tbl['name'],
					'unit'                 => (string) ( $tbl['unit'] ?? 'mm' ),
					'match_mode'           => (string) ( $tbl['match_mode'] ?? 'round_up' ),
					'supports_price_group' => ! empty( $tbl['supports_price_group'] ),
					'width_min'            => $tbl['width_min']  ?? null,
					'width_max'            => $tbl['width_max']  ?? null,
					'height_min'           => $tbl['height_min'] ?? null,
					'height_max'           => $tbl['height_max'] ?? null,
					'cells'                => array_map(
						static fn ( $c ): array => [
							'width'           => (int) $c['width'],
							'height'          => (int) $c['height'],
							'price_group_key' => (string) ( $c['price_group_key'] ?? '' ),
							'price'           => (float) $c['price'],
						],
						$cells_listing['items']
					),
				];
			}
		}

		return $this->ok( [
			'product'      => [ 'id' => $product_id ],
			'template'     => [
				'template_key' => (string) $template['template_key'],
				'name'         => (string) $template['name'],
				'family_key'   => $template['family_key'] ?? null,
			],
			'steps'        => array_values( array_map( [ $this, 'flatten_step' ], $steps ) ),
			'fields'       => array_values( array_map( [ $this, 'flatten_field' ], $fields ) ),
			'field_options' => array_values( array_map( [ $this, 'flatten_option' ], $options ) ),
			'rules'        => array_values( array_map( [ $this, 'flatten_rule' ], $rules ) ),
			'libraries'    => $libraries_out,
			'modules'      => $modules_out,
			'lookup_table' => $lookup_table_out,
			'binding'      => [
				'enabled'             => true,
				'template_key'        => $template_key,
				'template_version_id' => (int) ( $binding['template_version_id'] ?? 0 ),
				'lookup_table_key'    => $lookup_table_key !== '' ? $lookup_table_key : null,
				'family_key'          => $binding['family_key'] ?? null,
				'frontend_mode'       => (string) ( $binding['frontend_mode'] ?? 'stepper' ),
				'defaults'            => is_array( $binding['defaults'] ?? null ) ? $binding['defaults'] : [],
				'allowed_sources'     => is_array( $binding['allowed_sources'] ?? null ) ? $binding['allowed_sources'] : [],
				'pricing_overrides'   => is_array( $binding['pricing_overrides'] ?? null ) ? $binding['pricing_overrides'] : [],
				'field_overrides'     => is_array( $binding['field_overrides'] ?? null ) ? $binding['field_overrides'] : [],
			],
		] );
	}

	/**
	 * @param array<string,mixed> $step
	 * @return array<string,mixed>
	 */
	private function flatten_step( array $step ): array {
		return [
			'step_key'    => (string) $step['step_key'],
			'label'       => (string) $step['label'],
			'helper_text' => $step['helper_text'] ?? null,
			'sort_order'  => (int) ( $step['sort_order'] ?? 0 ),
		];
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	private function flatten_field( array $field ): array {
		return [
			'step_key'      => (string) $field['step_key'],
			'field_key'     => (string) $field['field_key'],
			'label'         => (string) $field['label'],
			'helper_text'   => $field['helper_text'] ?? null,
			'field_kind'    => (string) $field['field_kind'],
			'input_type'    => $field['input_type'] ?? null,
			'display_type'  => (string) $field['display_type'],
			'value_source'  => (string) $field['value_source'],
			'behavior'      => (string) $field['behavior'],
			'source_config' => is_array( $field['source_config'] ?? null ) ? $field['source_config'] : [],
			'pricing_mode'  => $field['pricing_mode'] ?? null,
			'pricing_amount' => $field['pricing_amount'] ?? null,
			'is_required'   => ! empty( $field['is_required'] ),
			'min'           => $field['min'] ?? null,
			'max'           => $field['max'] ?? null,
			'step'          => $field['step'] ?? null,
			'unit'          => $field['unit'] ?? null,
			'sort_order'    => (int) ( $field['sort_order'] ?? 0 ),
		];
	}

	/**
	 * @param array<string,mixed> $option
	 * @return array<string,mixed>
	 */
	private function flatten_option( array $option ): array {
		return [
			'field_key'  => (string) $option['field_key'],
			'option_key' => (string) $option['option_key'],
			'label'      => (string) $option['label'],
			'image_url'  => $option['image_url'] ?? null,
			'helper_text' => $option['helper_text'] ?? null,
			'price_delta' => $option['price_delta'] ?? null,
			'sort_order' => (int) ( $option['sort_order'] ?? 0 ),
			'is_active'  => ! empty( $option['is_active'] ),
		];
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return array<string,mixed>
	 */
	private function flatten_rule( array $rule ): array {
		return [
			'rule_key'  => (string) $rule['rule_key'],
			'name'      => (string) ( $rule['name'] ?? $rule['rule_key'] ),
			'priority'  => (int) ( $rule['priority'] ?? 100 ),
			'sort_order' => (int) ( $rule['sort_order'] ?? 0 ),
			'is_active' => ! empty( $rule['is_active'] ),
			'spec'      => is_array( $rule['spec'] ?? null ) ? $rule['spec'] : [],
		];
	}
}
