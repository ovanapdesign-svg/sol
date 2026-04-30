<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

/**
 * Phase 4.4 — Yith-style section type catalog.
 *
 * Each section card on the Product Configurator Builder belongs to
 * one of these types. The registry is config-only — adding a new
 * section type is editing this file. The orchestrator
 * (ConfiguratorBuilderService) reads `entity_kind`, `module_id`,
 * and `default_label` to decide which underlying entity to mint
 * when the owner drops a new section.
 */
final class SectionTypeRegistry {

	public const TYPE_SIZE_PRICING     = 'size_pricing';
	public const TYPE_OPTION_GROUP     = 'option_group';
	public const TYPE_MOTOR            = 'motor';
	public const TYPE_MANUAL_OPERATION = 'manual_operation';
	public const TYPE_CONTROLS         = 'controls';
	public const TYPE_ACCESSORIES      = 'accessories';
	public const TYPE_CUSTOM           = 'custom';

	/**
	 * Each entry:
	 *   id, label, icon, description
	 *   entity_kind: 'lookup_table' | 'library'
	 *   module_id  : preset id from ModuleTypePresets (used when
	 *                entity_kind = library); null otherwise.
	 *   default_label: shown on a fresh section card.
	 *   bulk_paste_columns: ordered list of paste-column hints.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function all(): array {
		return [
			[
				'id'           => self::TYPE_SIZE_PRICING,
				'label'        => 'Size pricing',
				'icon'         => 'grid',
				'description'  => 'Width × height ranges with prices. Each row is a range that catches a span of customer-entered dimensions.',
				'entity_kind'  => 'lookup_table',
				'module_id'    => null,
				'default_label' => 'Size pricing',
				'bulk_paste_columns' => [ 'width_from', 'width_to', 'height_from', 'height_to', 'price', 'price_group' ],
			],
			[
				'id'           => self::TYPE_OPTION_GROUP,
				'label'        => 'Option group',
				'icon'         => 'palette',
				'description'  => 'Choices the customer picks from — fabrics, colors, finishes. Supports bulk paste and image ZIP matching.',
				'entity_kind'  => 'library',
				'module_id'    => 'textiles',
				'default_label' => 'Options',
				'bulk_paste_columns' => [ 'sku', 'label', 'brand', 'collection', 'price_group', 'color_family', 'image_filename' ],
			],
			[
				'id'           => self::TYPE_MOTOR,
				'label'        => 'Motor options',
				'icon'         => 'cog',
				'description'  => 'Single motors and motor packages (bundles). Linked to Woo products with optional custom prices.',
				'entity_kind'  => 'library',
				'module_id'    => 'motors',
				'default_label' => 'Motor',
				'bulk_paste_columns' => [ 'sku', 'label', 'woo_product_sku', 'price' ],
			],
			[
				'id'           => self::TYPE_MANUAL_OPERATION,
				'label'        => 'Manual operation',
				'icon'         => 'wrench',
				'description'  => 'Stang / sveiv / crank options.',
				'entity_kind'  => 'library',
				'module_id'    => 'accessories',
				'default_label' => 'Manual operation',
				'bulk_paste_columns' => [ 'sku', 'label', 'length_cm', 'price' ],
			],
			[
				'id'           => self::TYPE_CONTROLS,
				'label'        => 'Controls',
				'icon'         => 'remote',
				'description'  => 'Remotes, sensors, switches. Linked to Woo products.',
				'entity_kind'  => 'library',
				'module_id'    => 'controls',
				'default_label' => 'Controls',
				'bulk_paste_columns' => [ 'sku', 'label', 'woo_product_sku', 'price' ],
			],
			[
				'id'           => self::TYPE_ACCESSORIES,
				'label'        => 'Accessories',
				'icon'         => 'tool',
				'description'  => 'Bracket sets, covers, anything the customer can tick on as an extra.',
				'entity_kind'  => 'library',
				'module_id'    => 'accessories',
				'default_label' => 'Accessories',
				'bulk_paste_columns' => [ 'sku', 'label', 'woo_product_sku', 'price' ],
			],
			[
				'id'           => self::TYPE_CUSTOM,
				'label'        => 'Custom',
				'icon'         => 'sparkles',
				'description'  => 'Free-form library. Pick attributes when the standard types do not fit.',
				'entity_kind'  => 'library',
				'module_id'    => 'textiles',
				'default_label' => 'Custom section',
				'bulk_paste_columns' => [ 'sku', 'label' ],
			],
		];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $type ) {
			if ( $type['id'] === $id ) return $type;
		}
		return null;
	}

	public static function exists( string $id ): bool {
		return self::find( $id ) !== null;
	}
}
