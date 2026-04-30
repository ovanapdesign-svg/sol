<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

/**
 * Phase 4.3b half A — Configurator Presets.
 *
 * A preset is a JSON snapshot of one product's section structure that
 * can be re-applied to other products. Library / lookup table entries
 * are stored as references by their stable keys — apply-preset NEVER
 * duplicates underlying entities.
 */
return new class implements Migration {

	public function key(): string {
		return '0019_create_presets_table';
	}

	public function description(): string {
		return 'Create wp_configkit_presets table per Phase 4.3b half A spec.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_presets';
		$charset_collate = $wpdb->get_charset_collate();

		// `key` / `name` are not reserved in the contexts used here, but
		// we backtick the table identifier and any column the model uses
		// in indexes to stay consistent with the post-real-data fixes
		// from Phase 4 dalis 4.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			preset_key VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			product_type VARCHAR(64) NULL,
			sections_json LONGTEXT NOT NULL,
			default_lookup_table_key VARCHAR(64) NULL,
			default_frontend_mode VARCHAR(32) NOT NULL DEFAULT 'stepper',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY preset_key (preset_key),
			KEY product_type (product_type),
			KEY active_sort (deleted_at, updated_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Phase 4 dalis 4 safety net — verify the table actually exists
	 * after dbDelta() so a silent SQL failure refuses to advance.
	 *
	 * @return list<string>
	 */
	public function expected_tables( \wpdb $wpdb ): array {
		return [ $wpdb->prefix . 'configkit_presets' ];
	}
};
