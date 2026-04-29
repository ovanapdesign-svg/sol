<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0005_create_families';
	}

	public function description(): string {
		return 'Create wp_configkit_families table per DATA_MODEL.md §3.4.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_families';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			family_key VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			default_template_key VARCHAR(64) NULL,
			allowed_modules_json LONGTEXT NULL,
			default_step_order_json LONGTEXT NULL,
			is_active TINYINT NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY family_key (family_key),
			KEY default_template_key (default_template_key),
			KEY is_active (is_active)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
