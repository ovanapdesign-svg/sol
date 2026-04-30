<?php
declare(strict_types=1);

namespace ConfigKit\Admin;

/**
 * Phase 4.3 — Product Builder recipes (Simple Mode).
 *
 * Each recipe describes which blocks appear in the Woo product
 * ConfigKit tab when the owner picks a product type, plus per-block
 * defaults the orchestrator uses behind the scenes (template family,
 * default match mode for the pricing lookup, etc.).
 *
 * Recipes are CONFIG, not behavior. Adding a new product type =
 * editing this file. The orchestrator never branches on the recipe
 * id beyond what's in this map.
 *
 * Read-only constants only — no DB / no WP function calls.
 */
final class ProductTypeRecipes {

	public const TYPE_MARKISE      = 'markise';
	public const TYPE_SCREEN       = 'screen';
	public const TYPE_PERGOLA      = 'pergola';
	public const TYPE_TERRASSETAK  = 'terrassetak';
	public const TYPE_CUSTOM       = 'custom';

	/**
	 * @return list<array{
	 *   id:string,
	 *   label:string,
	 *   icon:string,
	 *   description:string,
	 *   family_key:string,
	 *   family_label:string,
	 *   blocks:list<string>
	 * }>
	 */
	public static function all(): array {
		return [
			[
				'id'           => self::TYPE_MARKISE,
				'label'        => 'Markise',
				'icon'         => '🌞',
				'description'  => 'Awning with width × height pricing, fabric, profile color, and motor / stang options.',
				'family_key'   => 'markiser',
				'family_label' => 'Markiser',
				'blocks'       => [ 'pricing', 'fabrics', 'profile_colors', 'operation', 'stang', 'motor', 'controls', 'accessories' ],
			],
			[
				'id'           => self::TYPE_SCREEN,
				'label'        => 'Screen',
				'icon'         => '🪟',
				'description'  => 'Screen / outdoor blind with width × height pricing and motor options.',
				'family_key'   => 'screens',
				'family_label' => 'Screens',
				'blocks'       => [ 'pricing', 'fabrics', 'operation', 'motor', 'controls', 'accessories' ],
			],
			[
				'id'           => self::TYPE_PERGOLA,
				'label'        => 'Pergola',
				'icon'         => '🏡',
				'description'  => 'Pergola with size pricing, profile color, and motor options.',
				'family_key'   => 'pergolas',
				'family_label' => 'Pergolas',
				'blocks'       => [ 'pricing', 'profile_colors', 'operation', 'motor', 'controls', 'accessories' ],
			],
			[
				'id'           => self::TYPE_TERRASSETAK,
				'label'        => 'Terrassetak',
				'icon'         => '⛱',
				'description'  => 'Terrace roof with size pricing, profile color, and accessory options.',
				'family_key'   => 'terrassetak',
				'family_label' => 'Terrassetak',
				'blocks'       => [ 'pricing', 'profile_colors', 'accessories' ],
			],
			[
				'id'           => self::TYPE_CUSTOM,
				'label'        => 'Custom',
				'icon'         => '✨',
				'description'  => 'Build a configurable product from scratch — pick blocks individually.',
				'family_key'   => 'custom',
				'family_label' => 'Custom products',
				'blocks'       => [ 'pricing' ],
			],
		];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $recipe ) {
			if ( $recipe['id'] === $id ) return $recipe;
		}
		return null;
	}

	public static function has_block( string $product_type, string $block_id ): bool {
		$recipe = self::find( $product_type );
		if ( $recipe === null ) return false;
		return in_array( $block_id, $recipe['blocks'], true );
	}
}
