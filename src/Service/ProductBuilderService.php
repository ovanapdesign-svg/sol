<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Admin\ModuleTypePresets;
use ConfigKit\Admin\ProductTypeRecipes;
use ConfigKit\Repository\FamilyRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Repository\TemplateRepository;

/**
 * Phase 4.3 — Product Builder (Simple Mode) orchestrator.
 *
 * Translates owner-facing actions on the Woo product ConfigKit tab
 * into existing-service operations:
 *
 *   setProductType   → create family + template skeleton
 *   savePricingRows  → create lookup table + cells
 *   saveFabrics      → create Textiles module + library + items
 *   saveProfileColors→ create Colors module + library + items
 *   saveOperationMode→ create operation_type field + show/hide rules
 *   saveStangOptions → create stang library + items
 *   saveMotors       → create motor library + items (with bundles)
 *   saveControls     → create controls library + items
 *   saveAccessories  → create accessories library + items
 *   canEnableConfigurator / enableConfigurator
 *
 * Owner never sees module_key, library_key, item_key, template_key —
 * those are auto-generated from the product's slug + role and stored
 * on `_configkit_pb_meta` post meta. The orchestrator marks every
 * entity it creates in `AutoManagedRegistry` so Advanced admin can
 * surface them with a 🔧 badge.
 *
 * Each method returns:
 *   [ 'ok' => bool, 'message' => string, 'state' => array, 'errors' => [] ]
 *
 * Errors are owner-friendly (no module_key / template_key in copy).
 */
final class ProductBuilderService {

	private const BUILDER_VERSION = 1;

	public function __construct(
		private TemplateService $templates,
		private TemplateRepository $template_repo,
		private FamilyService $families,
		private FamilyRepository $family_repo,
		private ProductBuilderState $state,
		private AutoManagedRegistry $registry,
		private ?LookupTableService $lookup_tables = null,
		private ?LookupTableRepository $lookup_table_repo = null,
		private ?LookupCellService $lookup_cells = null,
		private ?LookupCellRepository $lookup_cell_repo = null,
		private ?ModuleService $modules = null,
		private ?ModuleRepository $module_repo = null,
		private ?LibraryService $libraries = null,
		private ?LibraryRepository $library_repo = null,
		private ?LibraryItemService $items = null,
		private ?LibraryItemRepository $item_repo = null,
	) {}

	/**
	 * @return array{ok:bool, message?:string, state?:array<string,mixed>, errors?:list<array<string,mixed>>}
	 */
	public function set_product_type( int $product_id, string $product_type ): array {
		$recipe = ProductTypeRecipes::find( $product_type );
		if ( $recipe === null ) {
			return [
				'ok'      => false,
				'message' => sprintf( 'Unknown product type "%s".', $product_type ),
				'errors'  => [ [ 'field' => 'product_type', 'code' => 'unknown_type', 'message' => 'Pick one of the listed product types.' ] ],
			];
		}

		// 1. Family — create if not exists, reuse if it does (families
		//    are shared across all products of the same type, e.g. one
		//    "Markiser" family for every Markise the owner sells).
		$family_key = (string) $recipe['family_key'];
		if ( $this->family_repo->find_by_key( $family_key ) === null ) {
			$result = $this->families->create( [
				'family_key' => $family_key,
				'name'       => (string) $recipe['family_label'],
				'is_active'  => true,
			] );
			if ( ! ( $result['ok'] ?? false ) ) {
				return [
					'ok'      => false,
					'message' => sprintf( 'Could not set up the %s family.', $recipe['label'] ),
					'errors'  => $result['errors'] ?? [],
				];
			}
		}

		// 2. Template — one per product. Reuse the existing key if the
		//    owner already picked a product type before, otherwise mint
		//    "product_{id}_{type}".
		$existing_template_key = $this->state->get_string( $product_id, 'template_key' );
		$template_key = $existing_template_key ?? sprintf( 'product_%d_%s', $product_id, $product_type );

		if ( $this->template_repo->find_by_key( $template_key ) === null ) {
			$created = $this->templates->create( [
				'template_key' => $template_key,
				'name'         => sprintf( '%s template (#%d)', $recipe['label'], $product_id ),
				'family_key'   => $family_key,
				'status'       => 'draft',
			] );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [
					'ok'      => false,
					'message' => 'Could not create the product template.',
					'errors'  => $created['errors'] ?? [],
				];
			}
			$this->registry->mark( AutoManagedRegistry::TYPE_TEMPLATE, $template_key, $product_id, 'product_template' );
		}

		// 3. Persist the per-product state. Auto-managed flag toggles
		//    on so any later opening of this product on the Woo tab
		//    knows we're in Simple Mode.
		$state = $this->state->patch( $product_id, [
			'product_type'    => $product_type,
			'builder_version' => self::BUILDER_VERSION,
			'template_key'    => $template_key,
			'family_key'      => $family_key,
		] );

		return [
			'ok'      => true,
			'message' => sprintf( 'Product type set to %s.', $recipe['label'] ),
			'state'   => $state,
		];
	}

	/**
	 * Save the per-product pricing grid. Each owner-supplied row maps
	 * to a single lookup_cell at (to_width, to_height) — round_up
	 * match mode means the cell acts as the upper-bound for every
	 * smaller dimension combination.
	 *
	 * Idempotent: re-saving wipes the previous cells (replace_all
	 * semantics) so the lookup table always reflects the current UI
	 * state. Mints "product_{id}_pricing" as the table key on first
	 * save, reuses on subsequent saves.
	 *
	 * @param list<array{
	 *   to_width:int|float|string|null,
	 *   to_height:int|float|string|null,
	 *   price:int|float|string|null,
	 *   price_group_key?:string,
	 *   from_width?:int|float|string|null,
	 *   from_height?:int|float|string|null
	 * }> $rows
	 *
	 * @return array{ok:bool, message?:string, state?:array<string,mixed>, errors?:list<array<string,mixed>>}
	 */
	public function save_pricing_rows( int $product_id, array $rows ): array {
		if ( $this->lookup_tables === null || $this->lookup_table_repo === null || $this->lookup_cells === null ) {
			return [ 'ok' => false, 'message' => 'Pricing block is not wired in this environment.' ];
		}
		if ( $this->state->product_type( $product_id ) === null ) {
			return [
				'ok'      => false,
				'message' => 'Pick a product type before adding pricing rows.',
				'errors'  => [ [ 'field' => 'product_type', 'code' => 'required', 'message' => 'Product type required.' ] ],
			];
		}

		$existing_key = $this->state->get_string( $product_id, 'lookup_table_key' );
		$lookup_key   = $existing_key ?? sprintf( 'product_%d_pricing', $product_id );

		$table = $this->lookup_table_repo->find_by_key( $lookup_key );
		if ( $table === null ) {
			$created = $this->lookup_tables->create( [
				'lookup_table_key'     => $lookup_key,
				'name'                 => sprintf( 'Pricing for product #%d', $product_id ),
				'unit'                 => 'mm',
				'match_mode'           => 'round_up',
				'supports_price_group' => true,
				'is_active'            => true,
			] );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [
					'ok'      => false,
					'message' => 'Could not create the pricing table.',
					'errors'  => $created['errors'] ?? [],
				];
			}
			$table = $this->lookup_table_repo->find_by_key( $lookup_key );
			if ( $table === null ) {
				return [ 'ok' => false, 'message' => 'Could not load the pricing table after creation.' ];
			}
			$this->registry->mark( AutoManagedRegistry::TYPE_LOOKUP_TABLE, $lookup_key, $product_id, 'product_pricing' );
		}

		// Wipe existing cells so the saved grid always matches the UI.
		if ( $this->lookup_cell_repo !== null ) {
			$this->lookup_cell_repo->delete_all_in_table( $lookup_key );
		}

		// Bulk upsert the new cells. Empty list is acceptable — the
		// table just has no cells. Validation happens inside the
		// service so bad rows surface as field errors.
		$cells_input = [];
		$row_errors  = [];
		foreach ( $rows as $i => $row ) {
			$width  = $this->to_int( $row['to_width']  ?? null );
			$height = $this->to_int( $row['to_height'] ?? null );
			$price  = $this->to_float( $row['price']    ?? null );
			if ( $width === null || $width <= 0 ) {
				$row_errors[] = [ 'field' => 'to_width', 'code' => 'invalid', 'message' => sprintf( 'Row %d needs a width.', $i + 1 ) ];
				continue;
			}
			if ( $height === null || $height <= 0 ) {
				$row_errors[] = [ 'field' => 'to_height', 'code' => 'invalid', 'message' => sprintf( 'Row %d needs a height.', $i + 1 ) ];
				continue;
			}
			if ( $price === null || $price < 0 ) {
				$row_errors[] = [ 'field' => 'price', 'code' => 'invalid', 'message' => sprintf( 'Row %d needs a non-negative price.', $i + 1 ) ];
				continue;
			}
			$cells_input[] = [
				'lookup_table_key' => $lookup_key,
				'width'            => $width,
				'height'           => $height,
				'price'            => $price,
				'price_group_key'  => isset( $row['price_group_key'] ) ? (string) $row['price_group_key'] : '',
				'is_active'        => true,
			];
		}
		if ( count( $row_errors ) > 0 ) {
			return [
				'ok'      => false,
				'message' => sprintf( 'Pricing has %d invalid row(s).', count( $row_errors ) ),
				'errors'  => $row_errors,
			];
		}

		$bulk = $this->lookup_cells->bulk_upsert( (int) $table['id'], $cells_input );
		if ( ! ( $bulk['ok'] ?? false ) ) {
			return [
				'ok'      => false,
				'message' => 'Pricing rows could not be saved.',
				'errors'  => $bulk['errors'] ?? [],
			];
		}

		$state = $this->state->patch( $product_id, [ 'lookup_table_key' => $lookup_key ] );
		return [
			'ok'      => true,
			'message' => sprintf( '%d pricing row(s) saved.', count( $cells_input ) ),
			'state'   => $state,
		];
	}

	/**
	 * Save the per-product fabric list. Behind the scenes:
	 *
	 *   1. Ensure the shared "Textiles" module exists (uses the
	 *      ModuleTypePresets defaults so capabilities + attribute
	 *      schema match what the rest of the admin assumes).
	 *   2. Mint "product_{id}_fabrics" library under that module on
	 *      first save; reuse on subsequent saves.
	 *   3. Replace all items in that library with the supplied fabric
	 *      list (by soft-deleting prior items and inserting the new
	 *      ones — the runner equivalent of replace_all mode).
	 *
	 * Each fabric input row may carry:
	 *   name (required) → label
	 *   code            → sku
	 *   collection      → library + per-item attribute
	 *   color_family    → top-level color_family column
	 *   image_url, main_image_url
	 *   price_group     → price_group_key
	 *   extra_price     → price (added vs. lookup base, or stand-alone)
	 *   active          → is_active
	 *
	 * @param list<array<string,mixed>> $fabrics
	 * @return array{ok:bool, message?:string, state?:array<string,mixed>, errors?:list<array<string,mixed>>}
	 */
	public function save_fabrics( int $product_id, array $fabrics ): array {
		$context = $this->ensure_role_library( $product_id, 'fabric', [
			'module_id'         => 'textiles',
			'library_role'      => 'product_fabrics',
			'library_label_fmt' => 'Fabrics for product #%d',
			'state_key'         => 'fabric_library_key',
		] );
		if ( ! $context['ok'] ) return $context;

		$lib = $context['library'];

		// Wipe existing items so the library matches the UI.
		if ( $this->item_repo !== null && $lib !== null ) {
			$this->item_repo->soft_delete_all_in_library( (string) $lib['library_key'] );
		}

		$module   = $this->module_repo !== null ? $this->module_repo->find_by_key( (string) $lib['module_key'] ) : null;
		$inserted = 0;
		$errors   = [];
		foreach ( $fabrics as $i => $fabric ) {
			$payload = $this->fabric_to_item_payload( $fabric, $module ?? [] );
			if ( empty( $payload['label'] ) ) {
				$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => sprintf( 'Fabric #%d needs a name.', $i + 1 ) ];
				continue;
			}
			$payload['item_key'] = $this->mint_item_key( (string) $lib['library_key'], $payload['label'], $payload['sku'] ?? null );
			$result = $this->items->create( (int) $lib['id'], $payload );
			if ( ! ( $result['ok'] ?? false ) ) {
				$errors[] = [
					'field'   => 'fabric',
					'code'    => 'create_failed',
					'message' => sprintf( 'Fabric #%d (%s) could not be saved: %s', $i + 1, (string) $payload['label'], (string) ( $result['errors'][0]['message'] ?? 'unknown' ) ),
				];
				continue;
			}
			$inserted++;
		}

		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'message' => sprintf( '%d fabric(s) failed.', count( $errors ) ), 'errors' => $errors ];
		}

		$state = $this->state->patch( $product_id, [ 'fabric_library_key' => (string) $lib['library_key'] ] );
		return [
			'ok'      => true,
			'message' => sprintf( '%d fabric(s) saved.', $inserted ),
			'state'   => $state,
		];
	}

	/**
	 * Read the saved fabric items so the JS can rehydrate the editor.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function read_fabrics( int $product_id ): array {
		return $this->read_role_library_items( $product_id, 'fabric_library_key' );
	}

	/** @param list<array<string,mixed>> $items */
	public function save_profile_colors( int $product_id, array $items ): array {
		return $this->save_role_items( $product_id, 'color', [
			'module_id'         => 'colors',
			'library_role'      => 'product_profile_colors',
			'library_label_fmt' => 'Profile colors for product #%d',
			'state_key'         => 'color_library_key',
			'noun_singular'     => 'color',
			'noun_plural'       => 'colors',
		], $items );
	}

	public function read_profile_colors( int $product_id ): array {
		return $this->read_role_library_items( $product_id, 'color_library_key' );
	}

	/** @param list<array<string,mixed>> $items */
	public function save_stangs( int $product_id, array $items ): array {
		// Manual operators: simple SKU + image + price, no compatibility tags.
		return $this->save_role_items( $product_id, 'stang', [
			'module_id'         => 'accessories',
			'library_role'      => 'product_stangs',
			'library_label_fmt' => 'Stang options for product #%d',
			'state_key'         => 'stang_library_key',
			'noun_singular'     => 'stang option',
			'noun_plural'       => 'stang options',
		], $items );
	}

	public function read_stangs( int $product_id ): array {
		return $this->read_role_library_items( $product_id, 'stang_library_key' );
	}

	/** @param list<array<string,mixed>> $items */
	public function save_motors( int $product_id, array $items ): array {
		return $this->save_role_items( $product_id, 'motor', [
			'module_id'         => 'motors',
			'library_role'      => 'product_motors',
			'library_label_fmt' => 'Motor options for product #%d',
			'state_key'         => 'motor_library_key',
			'noun_singular'     => 'motor',
			'noun_plural'       => 'motors',
		], $items );
	}

	public function read_motors( int $product_id ): array {
		return $this->read_role_library_items( $product_id, 'motor_library_key' );
	}

	/** @param list<array<string,mixed>> $items */
	public function save_controls( int $product_id, array $items ): array {
		return $this->save_role_items( $product_id, 'control', [
			'module_id'         => 'controls',
			'library_role'      => 'product_controls',
			'library_label_fmt' => 'Controls for product #%d',
			'state_key'         => 'control_library_key',
			'noun_singular'     => 'control',
			'noun_plural'       => 'controls',
		], $items );
	}

	public function read_controls( int $product_id ): array {
		return $this->read_role_library_items( $product_id, 'control_library_key' );
	}

	/** @param list<array<string,mixed>> $items */
	public function save_accessories( int $product_id, array $items ): array {
		return $this->save_role_items( $product_id, 'accessory', [
			'module_id'         => 'accessories',
			'library_role'      => 'product_accessories',
			'library_label_fmt' => 'Accessories for product #%d',
			'state_key'         => 'accessory_library_key',
			'noun_singular'     => 'accessory',
			'noun_plural'       => 'accessories',
		], $items );
	}

	public function read_accessories( int $product_id ): array {
		return $this->read_role_library_items( $product_id, 'accessory_library_key' );
	}

	/**
	 * Record the customer-facing operation mode for the product:
	 *   manual_only      — only stang options shown to the customer
	 *   motorized_only   — only motor options shown
	 *   both             — customer picks, JS renders accordingly
	 *
	 * Stored on `_configkit_pb_meta`. The actual show/hide rule
	 * wiring lives on the template — for now the Simple-Mode UI
	 * uses the recorded mode to decide which blocks to render. A
	 * future chunk can promote this into a structured Rule when
	 * the template builder accepts simpler inputs.
	 *
	 * @return array{ok:bool, message?:string, state?:array<string,mixed>}
	 */
	public function save_operation_mode( int $product_id, string $mode ): array {
		$valid = [ 'manual_only', 'motorized_only', 'both' ];
		if ( ! in_array( $mode, $valid, true ) ) {
			return [
				'ok'      => false,
				'message' => 'Pick how the product is operated: manual, motorized, or both.',
			];
		}
		if ( $this->state->product_type( $product_id ) === null ) {
			return [
				'ok'      => false,
				'message' => 'Pick a product type before setting the operation mode.',
			];
		}
		$state = $this->state->patch( $product_id, [ 'operation_mode' => $mode ] );
		$message = match ( $mode ) {
			'manual_only'    => 'Customer will only see stang options.',
			'motorized_only' => 'Customer will only see motor options.',
			default          => 'Customer will choose between stang and motor.',
		};
		return [ 'ok' => true, 'message' => $message, 'state' => $state ];
	}

	/**
	 * Generic per-product items saver shared by stangs / motors /
	 * controls / accessories / profile colors. Same shape as
	 * save_fabrics but the input rows are mapped through a
	 * generic_to_item_payload that respects the target module's
	 * capabilities (so a Motors module that doesn't advertise
	 * supports_color_family won't try to store color_family on a
	 * motor item).
	 *
	 * @param array{
	 *   module_id:string, library_role:string, library_label_fmt:string,
	 *   state_key:string, noun_singular:string, noun_plural:string
	 * } $cfg
	 * @param list<array<string,mixed>> $items
	 *
	 * @return array{ok:bool, message?:string, state?:array<string,mixed>, errors?:list<array<string,mixed>>}
	 */
	private function save_role_items( int $product_id, string $role, array $cfg, array $items ): array {
		$context = $this->ensure_role_library( $product_id, $role, $cfg );
		if ( ! $context['ok'] ) return $context;

		$lib = $context['library'];
		if ( $this->item_repo !== null && $lib !== null ) {
			$this->item_repo->soft_delete_all_in_library( (string) $lib['library_key'] );
		}

		$module   = $this->module_repo !== null ? $this->module_repo->find_by_key( (string) $lib['module_key'] ) : null;
		$inserted = 0;
		$errors   = [];
		foreach ( $items as $i => $row ) {
			$payload = $this->generic_to_item_payload( $row, $module ?? [] );
			if ( empty( $payload['label'] ) ) {
				$errors[] = [ 'field' => 'name', 'code' => 'required', 'message' => sprintf( '%s #%d needs a name.', ucfirst( $cfg['noun_singular'] ), $i + 1 ) ];
				continue;
			}
			$payload['item_key'] = $this->mint_item_key( (string) $lib['library_key'], $payload['label'], $payload['sku'] ?? null );
			$result = $this->items->create( (int) $lib['id'], $payload );
			if ( ! ( $result['ok'] ?? false ) ) {
				$errors[] = [
					'field'   => $cfg['noun_singular'],
					'code'    => 'create_failed',
					'message' => sprintf( '%s #%d (%s) could not be saved: %s', ucfirst( $cfg['noun_singular'] ), $i + 1, (string) $payload['label'], (string) ( $result['errors'][0]['message'] ?? 'unknown' ) ),
				];
				continue;
			}
			$inserted++;
		}

		if ( count( $errors ) > 0 ) {
			return [ 'ok' => false, 'message' => sprintf( '%d %s failed.', count( $errors ), $cfg['noun_plural'] ), 'errors' => $errors ];
		}

		$state = $this->state->patch( $product_id, [ $cfg['state_key'] => (string) $lib['library_key'] ] );
		return [
			'ok'      => true,
			'message' => sprintf( '%d %s saved.', $inserted, $cfg['noun_plural'] ),
			'state'   => $state,
		];
	}

	/**
	 * Capability-aware row → item payload for the generic role saver.
	 *
	 * Recognised input fields:
	 *   name (required), code, image_url, main_image_url, color_family,
	 *   hex (mapped to color_family for the Colors block when no
	 *   palette label is supplied), price_source ('configkit' / 'woo'),
	 *   price, woo_product_id, components (motor bundle), active.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $module
	 * @return array<string,mixed>
	 */
	private function generic_to_item_payload( array $row, array $module ): array {
		$payload = [
			'label'        => isset( $row['name'] ) ? trim( (string) $row['name'] ) : '',
			'sku'          => isset( $row['code'] ) && $row['code'] !== '' ? (string) $row['code'] : null,
			'is_active'    => array_key_exists( 'active', $row ) ? (bool) $row['active'] : true,
			'attributes'   => [],
			'price_source' => $this->normalise_price_source( $row, $module ),
			'item_type'    => isset( $row['components'] ) && is_array( $row['components'] ) && count( $row['components'] ) > 0 ? 'bundle' : 'simple_option',
		];

		if ( ! empty( $module['supports_image'] ) && ! empty( $row['image_url'] ) ) {
			$payload['image_url'] = (string) $row['image_url'];
		}
		if ( ! empty( $module['supports_main_image'] ) && ! empty( $row['main_image_url'] ) ) {
			$payload['main_image_url'] = (string) $row['main_image_url'];
		}
		if ( ! empty( $module['supports_color_family'] ) ) {
			$cf = $row['color_family'] ?? $row['hex'] ?? null;
			if ( $cf !== null && $cf !== '' ) $payload['color_family'] = (string) $cf;
		}
		if ( ! empty( $module['supports_price'] ) && isset( $row['price'] )
			&& $row['price'] !== '' && $row['price'] !== null && (float) $row['price'] >= 0
		) {
			$payload['price'] = (float) $row['price'];
		}
		if ( ! empty( $module['supports_woo_product_link'] ) && isset( $row['woo_product_id'] )
			&& (int) $row['woo_product_id'] > 0
		) {
			$payload['woo_product_id'] = (int) $row['woo_product_id'];
		}

		// Bundle components — Phase 4.2b shape.
		if ( $payload['item_type'] === 'bundle' ) {
			$payload['bundle_components'] = array_values( array_filter( array_map( static function ( $c ): ?array {
				if ( ! is_array( $c ) ) return null;
				$wid = isset( $c['woo_product_id'] ) ? (int) $c['woo_product_id'] : 0;
				if ( $wid <= 0 ) return null;
				return [
					'component_key'  => isset( $c['component_key'] ) ? (string) $c['component_key'] : 'c_' . $wid,
					'woo_product_id' => $wid,
					'qty'            => isset( $c['qty'] ) && (int) $c['qty'] > 0 ? (int) $c['qty'] : 1,
					'price_source'   => isset( $c['price_source'] ) ? (string) $c['price_source'] : 'woo',
				];
			}, $row['components'] ) ) );
			if ( count( $payload['bundle_components'] ) === 0 ) {
				$payload['item_type'] = 'simple_option';
				unset( $payload['bundle_components'] );
				$payload['price_source'] = 'configkit';
			} elseif ( ! empty( $row['fixed_price'] ) ) {
				$payload['price_source']       = 'fixed_bundle';
				$payload['bundle_fixed_price'] = (float) $row['fixed_price'];
			} else {
				$payload['price_source'] = 'bundle_sum';
			}
		}

		return $payload;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $module
	 */
	private function normalise_price_source( array $row, array $module ): string {
		$raw = isset( $row['price_source'] ) ? strtolower( (string) $row['price_source'] ) : '';
		if ( $raw === 'woo' && ! empty( $module['supports_woo_product_link'] ) ) return 'woo';
		if ( $raw === 'free' )      return 'configkit'; // free → 0 stored as configkit price
		if ( $raw === 'configkit' ) return 'configkit';
		// Implicit: a Woo-linked row with no explicit source hints uses Woo when supported.
		if ( ! empty( $module['supports_woo_product_link'] ) && isset( $row['woo_product_id'] ) && (int) $row['woo_product_id'] > 0 && empty( $row['price'] ) ) {
			return 'woo';
		}
		return 'configkit';
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function read_role_library_items( int $product_id, string $state_key ): array {
		if ( $this->item_repo === null ) return [];
		$lib_key = $this->state->get_string( $product_id, $state_key );
		if ( $lib_key === null ) return [];
		return $this->item_repo->list_in_library( $lib_key, 1, 500 )['items'] ?? [];
	}

	/**
	 * Ensure (module, library) exist for a given role and return the
	 * library record. Used by every block that owns a library
	 * (fabrics / colors / motors / stangs / controls / accessories).
	 *
	 * @param array{module_id:string, library_role:string, library_label_fmt:string, state_key:string} $cfg
	 * @return array{ok:bool, message?:string, library?:array<string,mixed>, errors?:list<array<string,mixed>>}
	 */
	private function ensure_role_library( int $product_id, string $role, array $cfg ): array {
		if ( $this->modules === null || $this->module_repo === null
			|| $this->libraries === null || $this->library_repo === null
			|| $this->items === null
		) {
			return [ 'ok' => false, 'message' => 'This block is not wired in this environment.' ];
		}
		if ( $this->state->product_type( $product_id ) === null ) {
			return [
				'ok'      => false,
				'message' => 'Pick a product type before adding ' . $role . ' options.',
				'errors'  => [ [ 'field' => 'product_type', 'code' => 'required', 'message' => 'Product type required.' ] ],
			];
		}

		// 1. Module — apply the matching ModuleTypePresets defaults so
		//    every product's textiles/colors/motors module looks the
		//    same and the rest of the admin works.
		$preset = ModuleTypePresets::find( $cfg['module_id'] );
		if ( $preset === null ) {
			return [ 'ok' => false, 'message' => 'No preset for module ' . $cfg['module_id'] . '.' ];
		}
		$module_key = (string) $preset['id'];
		if ( $this->module_repo->find_by_key( $module_key ) === null ) {
			$payload = ModuleTypePresets::apply_to_payload( [
				'module_key'  => $module_key,
				'name'        => (string) $preset['label'],
				'module_type' => $module_key,
				'is_active'   => true,
			] );
			$created = $this->modules->create( $payload );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [ 'ok' => false, 'message' => 'Could not create the ' . $preset['label'] . ' module.', 'errors' => $created['errors'] ?? [] ];
			}
			$this->registry->mark( AutoManagedRegistry::TYPE_MODULE, $module_key, $product_id, $cfg['module_id'] );
		}

		// 2. Library — one per (product, role).
		$existing_key = $this->state->get_string( $product_id, $cfg['state_key'] );
		$library_key  = $existing_key ?? sprintf( 'product_%d_%s', $product_id, $role . 's' );

		$library = $this->library_repo->find_by_key( $library_key );
		if ( $library === null ) {
			$created = $this->libraries->create( [
				'library_key' => $library_key,
				'name'        => sprintf( (string) $cfg['library_label_fmt'], $product_id ),
				'module_key'  => $module_key,
				'is_active'   => true,
			] );
			if ( ! ( $created['ok'] ?? false ) ) {
				return [ 'ok' => false, 'message' => 'Could not create the library.', 'errors' => $created['errors'] ?? [] ];
			}
			$library = $this->library_repo->find_by_key( $library_key );
			if ( $library === null ) {
				return [ 'ok' => false, 'message' => 'Library missing after creation.' ];
			}
			$this->registry->mark( AutoManagedRegistry::TYPE_LIBRARY, $library_key, $product_id, $cfg['library_role'] );
		}

		return [ 'ok' => true, 'library' => $library ];
	}

	/**
	 * @param array<string,mixed> $fabric
	 * @param array<string,mixed> $module  used to gate fields the module
	 *                                     doesn't advertise.
	 * @return array<string,mixed>
	 */
	private function fabric_to_item_payload( array $fabric, array $module ): array {
		// Only carry attributes the module actually declares; the
		// library-item validator rejects unknown attribute keys.
		$schema    = is_array( $module['attribute_schema'] ?? null ) ? $module['attribute_schema'] : [];
		$declared  = array_keys( $schema );
		$candidate = [];
		if ( ! empty( $fabric['collection'] ) )   $candidate['collection']  = (string) $fabric['collection'];
		if ( ! empty( $fabric['fabric_code'] ) )  $candidate['fabric_code'] = (string) $fabric['fabric_code'];
		if ( ! empty( $fabric['material'] ) )     $candidate['material']    = (string) $fabric['material'];
		if ( ! empty( $fabric['transparency'] ) ) $candidate['transparency']= (string) $fabric['transparency'];

		$attrs = [];
		foreach ( $candidate as $k => $v ) {
			if ( in_array( $k, $declared, true ) ) $attrs[ $k ] = $v;
		}

		$payload = [
			'label'           => isset( $fabric['name'] ) ? trim( (string) $fabric['name'] ) : '',
			'sku'             => isset( $fabric['code'] ) && $fabric['code'] !== '' ? (string) $fabric['code'] : null,
			'price_group_key' => isset( $fabric['price_group'] ) ? (string) $fabric['price_group'] : '',
			'is_active'       => array_key_exists( 'active', $fabric ) ? (bool) $fabric['active'] : true,
			'attributes'      => $attrs,
			'price_source'    => 'configkit',
			'item_type'       => 'simple_option',
		];

		// Only ship capability-gated fields when the module actually
		// supports them. The library-item validator rejects any
		// non-empty value that violates a module flag.
		if ( ! empty( $module['supports_image'] ) && ! empty( $fabric['image_url'] ) ) {
			$payload['image_url'] = (string) $fabric['image_url'];
		}
		if ( ! empty( $module['supports_main_image'] ) && ! empty( $fabric['main_image_url'] ) ) {
			$payload['main_image_url'] = (string) $fabric['main_image_url'];
		}
		if ( ! empty( $module['supports_color_family'] ) && ! empty( $fabric['color_family'] ) ) {
			$payload['color_family'] = (string) $fabric['color_family'];
		}
		if ( ! empty( $module['supports_price'] ) && isset( $fabric['extra_price'] )
			&& $fabric['extra_price'] !== '' && $fabric['extra_price'] !== null
			&& (float) $fabric['extra_price'] > 0
		) {
			$payload['price'] = (float) $fabric['extra_price'];
		}
		return $payload;
	}

	/**
	 * Generate a snake_case item_key unique within the given library.
	 * Prefers `code` (typically a SKU) when present, falls back to a
	 * slugified label, suffixes with a counter on collision.
	 */
	private function mint_item_key( string $library_key, string $label, ?string $code ): string {
		$base = $code !== null && $code !== '' ? $code : $label;
		$slug = strtolower( $base );
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug ) ?? '';
		$slug = trim( $slug, '_' );
		if ( $slug === '' ) $slug = 'item';
		// KeyValidator requires 3-64 chars; pad short slugs with the
		// label or a trailing _ to clear the floor without surprising
		// the owner.
		if ( strlen( $slug ) < 3 ) {
			$pad = strtolower( preg_replace( '/[^a-z0-9]+/', '_', $label ) ?? '' );
			$pad = trim( $pad, '_' );
			$slug = $pad !== '' && strlen( $pad ) >= 3 ? $pad : ( $slug . '_item' );
			$slug = substr( $slug, 0, 64 );
		}
		if ( $this->item_repo === null ) return $slug;
		if ( ! $this->item_repo->key_exists_in_library( $library_key, $slug ) ) return $slug;
		$i = 2;
		while ( $this->item_repo->key_exists_in_library( $library_key, $slug . '_' . $i ) ) $i++;
		return $slug . '_' . $i;
	}

	/**
	 * Phase 4.3 — Block 10. Compute the readiness checklist for the
	 * product. Each entry is owner-friendly: id (machine-readable
	 * for the JS to bind icons/anchors), label, done, required
	 * (recipe-driven). The configurator can only be enabled when
	 * every required entry is done.
	 *
	 * @return array{ready:bool, product_type:?string, checklist:list<array{id:string,label:string,done:bool,required:bool}>}
	 */
	public function can_enable_configurator( int $product_id ): array {
		$state        = $this->state->get( $product_id );
		$product_type = is_string( $state['product_type'] ?? null ) ? $state['product_type'] : null;
		$recipe       = $product_type !== null ? ProductTypeRecipes::find( $product_type ) : null;
		$blocks       = is_array( $recipe['blocks'] ?? null ) ? $recipe['blocks'] : [];

		$checklist = [];
		$checklist[] = [
			'id'       => 'product_type',
			'label'    => 'Product type selected',
			'done'     => $product_type !== null,
			'required' => true,
		];
		$checklist[] = [
			'id'       => 'pricing_rows',
			'label'    => 'At least one pricing row',
			'done'     => $this->pricing_row_count( $product_id ) > 0,
			'required' => in_array( 'pricing', $blocks, true ),
		];
		$checklist[] = [
			'id'       => 'fabrics',
			'label'    => 'At least one fabric',
			'done'     => count( $this->read_fabrics( $product_id ) ) > 0,
			'required' => in_array( 'fabrics', $blocks, true ),
		];
		$checklist[] = [
			'id'       => 'operation_mode',
			'label'    => 'Operation options set',
			'done'     => $this->state->get_string( $product_id, 'operation_mode' ) !== null,
			'required' => in_array( 'operation', $blocks, true ),
		];
		if ( in_array( 'stang', $blocks, true ) || in_array( 'motor', $blocks, true ) ) {
			$has_stang = count( $this->read_stangs( $product_id ) ) > 0;
			$has_motor = count( $this->read_motors( $product_id ) ) > 0;
			$checklist[] = [
				'id'       => 'operation_options',
				'label'    => 'Stang or motor options',
				'done'     => $has_stang || $has_motor,
				'required' => true,
			];
		}

		$ready = true;
		foreach ( $checklist as $row ) {
			if ( $row['required'] && ! $row['done'] ) { $ready = false; break; }
		}

		return [
			'ready'        => $ready,
			'product_type' => $product_type,
			'checklist'    => $checklist,
		];
	}

	private function pricing_row_count( int $product_id ): int {
		if ( $this->lookup_cell_repo === null ) return 0;
		$key = $this->state->get_string( $product_id, 'lookup_table_key' );
		if ( $key === null ) return 0;
		$listing = $this->lookup_cell_repo->list_in_table( $key, [], 1, 5 );
		return (int) ( $listing['total'] ?? count( $listing['items'] ?? [] ) );
	}

	/**
	 * Phase 4.3 — Block 10 enable. Flip `_configkit_enabled` on the
	 * product post + write the orchestrator's auto-managed
	 * template_key / lookup_table_key onto the binding so the
	 * storefront renderer picks them up.
	 *
	 * Refuses to enable when the readiness check fails — owners
	 * shouldn't ship half-configured products. Returns the checklist
	 * alongside the failure so the UI can highlight what's missing.
	 *
	 * The post-meta writer is injectable so unit tests run without
	 * WordPress; production passes a callable that delegates to
	 * `update_post_meta()`.
	 *
	 * @return array{ok:bool, message?:string, checklist?:list<array<string,mixed>>, state?:array<string,mixed>}
	 */
	public function enable_configurator( int $product_id, ?callable $writer = null ): array {
		$status = $this->can_enable_configurator( $product_id );
		if ( ! $status['ready'] ) {
			return [
				'ok'        => false,
				'message'   => 'Some blocks still need attention.',
				'checklist' => $status['checklist'],
			];
		}
		$state = $this->state->get( $product_id );

		$write = $writer ?? static function ( int $pid, string $key, mixed $value ): void {
			if ( function_exists( 'update_post_meta' ) ) \update_post_meta( $pid, $key, $value );
		};
		$write( $product_id, '_configkit_enabled', 1 );
		if ( ! empty( $state['template_key'] ) ) {
			$write( $product_id, '_configkit_template_key', (string) $state['template_key'] );
		}
		if ( ! empty( $state['lookup_table_key'] ) ) {
			$write( $product_id, '_configkit_lookup_table_key', (string) $state['lookup_table_key'] );
		}
		return [
			'ok'      => true,
			'message' => 'Configurator enabled.',
			'state'   => $state,
		];
	}

	/**
	 * Public read-only state — the controller serialises this back to
	 * the JS so it can render which blocks are already filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_state( int $product_id ): array {
		return $this->state->get( $product_id );
	}

	/**
	 * Phase 4.3 dalis 2 — single aggregated snapshot the Simple Mode
	 * UI loads on page open. One round-trip returns everything the
	 *10 blocks need to render pre-filled.
	 *
	 * Shape:
	 *   {
	 *     state:           ProductBuilderState meta (product_type,
	 *                      template_key, library keys, operation_mode,
	 *                      auto_managed, builder_version),
	 *     pricing_rows:    list,
	 *     fabrics:         list,
	 *     profile_colors:  list,
	 *     stangs:          list,
	 *     motors:          list,
	 *     controls:        list,
	 *     accessories:     list,
	 *     checklist:       can_enable_configurator() output
	 *   }
	 *
	 * @return array<string,mixed>
	 */
	public function get_full_snapshot( int $product_id ): array {
		return [
			'state'           => $this->state->get( $product_id ),
			'pricing_rows'    => $this->read_pricing_rows( $product_id ),
			'fabrics'         => $this->read_fabrics( $product_id ),
			'profile_colors'  => $this->read_profile_colors( $product_id ),
			'stangs'          => $this->read_stangs( $product_id ),
			'motors'          => $this->read_motors( $product_id ),
			'controls'        => $this->read_controls( $product_id ),
			'accessories'    => $this->read_accessories( $product_id ),
			'checklist'       => $this->can_enable_configurator( $product_id ),
		];
	}

	/**
	 * Read the saved pricing rows so the JS can rehydrate the editor
	 * after a page reload. Returns the cells in (height ASC, width ASC)
	 * order so the table reads top-to-bottom-left-to-right.
	 *
	 * @return list<array{to_width:int,to_height:int,price:float,price_group_key:string}>
	 */
	public function read_pricing_rows( int $product_id ): array {
		if ( $this->lookup_cell_repo === null ) return [];
		$key = $this->state->get_string( $product_id, 'lookup_table_key' );
		if ( $key === null ) return [];
		$cells = $this->lookup_cell_repo->list_in_table( $key, [], 1, 5000 )['items'] ?? [];
		$out   = [];
		foreach ( $cells as $c ) {
			$out[] = [
				'to_width'        => (int) ( $c['width'] ?? 0 ),
				'to_height'       => (int) ( $c['height'] ?? 0 ),
				'price'           => (float) ( $c['price'] ?? 0 ),
				'price_group_key' => (string) ( $c['price_group_key'] ?? '' ),
			];
		}
		usort( $out, static fn ( $a, $b ) => $a['to_height'] === $b['to_height']
			? ( $a['to_width'] <=> $b['to_width'] )
			: ( $a['to_height'] <=> $b['to_height'] ) );
		return $out;
	}

	private function to_int( mixed $value ): ?int {
		if ( $value === null || $value === '' ) return null;
		if ( is_int( $value ) ) return $value;
		if ( is_float( $value ) ) return (int) round( $value );
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) return (int) round( (float) trim( $value ) );
		return null;
	}

	private function to_float( mixed $value ): ?float {
		if ( $value === null || $value === '' ) return null;
		if ( is_int( $value ) || is_float( $value ) ) return (float) $value;
		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) return (float) trim( $value );
		return null;
	}
}
