<?php
declare(strict_types=1);

namespace ConfigKit\Capabilities;

/**
 * Registers ConfigKit capabilities on plugin activation.
 *
 * Maps per ADMIN_SITEMAP.md §4.1. Only standard WP roles are populated;
 * custom roles (`content_editor`, `viewer`) are deferred to Phase 4.
 */
final class Registrar {

	public const CAPS = [
		'configkit_view_dashboard',
		'configkit_manage_products',
		'configkit_manage_templates',
		'configkit_manage_libraries',
		'configkit_manage_lookup_tables',
		'configkit_manage_rules',
		'configkit_view_diagnostics',
		'configkit_manage_settings',
		'configkit_manage_modules',
	];

	private const ROLE_MAP = [
		'administrator' => self::CAPS, // all caps
		'shop_manager'  => [
			'configkit_view_dashboard',
			'configkit_manage_products',
			'configkit_manage_templates',
			'configkit_manage_libraries',
			'configkit_manage_lookup_tables',
			'configkit_manage_rules',
			'configkit_view_diagnostics',
		],
	];

	public function register(): void {
		foreach ( self::ROLE_MAP as $role_name => $caps ) {
			$role = \get_role( $role_name );
			if ( $role === null ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}
}
