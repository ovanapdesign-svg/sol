<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0012_create_lookup_tables';
	}

	public function description(): string {
		return 'Create wp_configkit_lookup_tables table per DATA_MODEL.md §3.11.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_lookup_tables';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lookup_table_key VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			family_key VARCHAR(64) NULL,
			unit VARCHAR(16) NOT NULL DEFAULT 'mm',
			supports_price_group TINYINT NOT NULL DEFAULT 0,
			width_min INT NULL,
			width_max INT NULL,
			height_min INT NULL,
			height_max INT NULL,
			match_mode VARCHAR(32) NOT NULL DEFAULT 'round_up',
			import_source VARCHAR(255) NULL,
			last_imported_at DATETIME NULL,
			is_active TINYINT NOT NULL DEFAULT 1,
			legacy_source VARCHAR(64) NULL,
			legacy_id VARCHAR(128) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY lookup_table_key (lookup_table_key),
			KEY family_key (family_key)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
