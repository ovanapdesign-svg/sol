<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0010_create_field_options';
	}

	public function description(): string {
		return 'Create wp_configkit_field_options table per DATA_MODEL.md §3.9.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_field_options';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_key VARCHAR(64) NOT NULL,
			field_key VARCHAR(64) NOT NULL,
			option_key VARCHAR(64) NOT NULL,
			label VARCHAR(255) NOT NULL,
			price DECIMAL(12,2) NULL,
			sale_price DECIMAL(12,2) NULL,
			image_url VARCHAR(2048) NULL,
			description TEXT NULL,
			attributes_json LONGTEXT NULL,
			is_active TINYINT NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			legacy_source VARCHAR(64) NULL,
			legacy_id VARCHAR(128) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY tpl_field_option (template_key, field_key, option_key),
			KEY tpl_field_sort (template_key, field_key, sort_order),
			KEY is_active (is_active)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
