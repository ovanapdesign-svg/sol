<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0003_create_libraries';
	}

	public function description(): string {
		return 'Create wp_configkit_libraries table per DATA_MODEL.md §3.2.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_libraries';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			library_key VARCHAR(64) NOT NULL,
			module_key VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			brand VARCHAR(255) NULL,
			collection VARCHAR(255) NULL,
			is_builtin TINYINT NOT NULL DEFAULT 0,
			is_active TINYINT NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY library_key (library_key),
			KEY module_key (module_key),
			KEY active_sort (is_active, sort_order)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
