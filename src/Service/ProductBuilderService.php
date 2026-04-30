<?php
declare(strict_types=1);

namespace ConfigKit\Service;

use ConfigKit\Admin\ProductTypeRecipes;
use ConfigKit\Repository\FamilyRepository;
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
	 * Public read-only state — the controller serialises this back to
	 * the JS so it can render which blocks are already filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_state( int $product_id ): array {
		return $this->state->get( $product_id );
	}
}
