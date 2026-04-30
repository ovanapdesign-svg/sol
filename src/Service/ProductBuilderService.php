<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Admin\ProductTypeRecipes;
use ConfigKit\Repository\FamilyRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;
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
	 * Public read-only state — the controller serialises this back to
	 * the JS so it can render which blocks are already filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_state( int $product_id ): array {
		return $this->state->get( $product_id );
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
		$cells = $this->lookup_cell_repo->list_in_table( $key, 1, 5000 )['items'] ?? [];
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
