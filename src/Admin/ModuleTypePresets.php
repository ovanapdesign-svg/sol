<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

use ConfigKit\Repository\ModuleRepository;

/**
 * Owner-friendly module-type starting points used by the Modules
 * Create flow. The preset only seeds the default capability set +
 * allowed_field_kinds; the owner can still adjust every flag before
 * saving.
 *
 * Read-only constants only — no DB / no WP function calls. Server-side
 * apply happens in ModulesController when the POST body carries
 * `module_type`.
 */
final class ModuleTypePresets {

	public const TYPE_TEXTILES    = 'textiles';
	public const TYPE_COLORS      = 'colors';
	public const TYPE_MOTORS      = 'motors';
	public const TYPE_ACCESSORIES = 'accessories';
	public const TYPE_CUSTOM      = 'custom';

	/**
	 * @return list<array{
	 *   id:string, label:string, icon:string, description:string,
	 *   capabilities:array<string,bool>, allowed_field_kinds:list<string>
	 * }>
	 */
	public static function all(): array {
		return [
			[
				'id'           => self::TYPE_TEXTILES,
				'label'        => 'Textiles',
				'icon'         => '🧵',
				'description'  => 'Fabric collections with brand, collection, color family, filter tags, and price groups.',
				'capabilities' => array_merge(
					self::all_caps_default_false(),
					[
						'supports_sku'           => true,
						'supports_image'         => true,
						'supports_price_group'   => true,
						'supports_brand'         => true,
						'supports_collection'    => true,
						'supports_color_family'  => true,
						'supports_filters'       => true,
						'supports_compatibility' => true,
					]
				),
				'allowed_field_kinds' => [ 'input' ],
			],
			[
				'id'           => self::TYPE_COLORS,
				'label'        => 'Colors',
				'icon'         => '🎨',
				'description'  => 'Color palettes with images and color family grouping.',
				'capabilities' => array_merge(
					self::all_caps_default_false(),
					[
						'supports_sku'          => true,
						'supports_image'        => true,
						'supports_color_family' => true,
					]
				),
				'allowed_field_kinds' => [ 'input' ],
			],
			[
				'id'           => self::TYPE_MOTORS,
				'label'        => 'Motors',
				'icon'         => '⚙',
				'description'  => 'Motor products with price, compatibility, and a linked Woo product.',
				'capabilities' => array_merge(
					self::all_caps_default_false(),
					[
						'supports_sku'              => true,
						'supports_price'            => true,
						'supports_sale_price'       => true,
						'supports_woo_product_link' => true,
						'supports_compatibility'    => true,
					]
				),
				'allowed_field_kinds' => [ 'addon', 'input' ],
			],
			[
				'id'           => self::TYPE_ACCESSORIES,
				'label'        => 'Accessories',
				'icon'         => '🔧',
				'description'  => 'Add-on products with price and Woo link.',
				'capabilities' => array_merge(
					self::all_caps_default_false(),
					[
						'supports_sku'              => true,
						'supports_price'            => true,
						'supports_sale_price'       => true,
						'supports_image'            => true,
						'supports_woo_product_link' => true,
					]
				),
				'allowed_field_kinds' => [ 'addon' ],
			],
			[
				'id'                  => self::TYPE_CUSTOM,
				'label'               => 'Custom',
				'icon'                => '✨',
				'description'         => 'Build a module with custom capabilities — pick everything yourself.',
				'capabilities'        => self::all_caps_default_false(),
				'allowed_field_kinds' => [],
			],
		];
	}

	/**
	 * @return array<string,bool>
	 */
	private static function all_caps_default_false(): array {
		$out = [];
		foreach ( ModuleRepository::CAPABILITY_FLAGS as $flag ) {
			$out[ $flag ] = false;
		}
		return $out;
	}

	/**
	 * Look up a preset by id; returns null for unknown / 'custom'-only
	 * (custom carries no capability seeds).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $preset ) {
			if ( $preset['id'] === $id ) {
				return $preset;
			}
		}
		return null;
	}

	/**
	 * Seed capability flags + allowed_field_kinds in a payload from the
	 * named preset. Owner-supplied keys win (preset never overwrites a
	 * value the caller already set). The `module_type` key is consumed
	 * and stripped on the way through.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function apply_to_payload( array $payload ): array {
		$type = isset( $payload['module_type'] ) ? (string) $payload['module_type'] : '';
		unset( $payload['module_type'] );
		if ( $type === '' ) {
			return $payload;
		}
		$preset = self::find( $type );
		if ( $preset === null ) {
			return $payload;
		}
		foreach ( $preset['capabilities'] as $cap_key => $cap_value ) {
			if ( ! array_key_exists( $cap_key, $payload ) ) {
				$payload[ $cap_key ] = $cap_value;
			}
		}
		if ( ! array_key_exists( 'allowed_field_kinds', $payload )
			|| ! is_array( $payload['allowed_field_kinds'] )
			|| count( $payload['allowed_field_kinds'] ) === 0
		) {
			$payload['allowed_field_kinds'] = $preset['allowed_field_kinds'];
		}
		return $payload;
	}
}
