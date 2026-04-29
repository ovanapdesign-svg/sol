<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0009_create_fields';
	}

	public function description(): string {
		return 'Create wp_configkit_fields table per DATA_MODEL.md §3.8.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_fields';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_key VARCHAR(64) NOT NULL,
			step_key VARCHAR(64) NOT NULL,
			field_key VARCHAR(64) NOT NULL,
			label VARCHAR(255) NOT NULL,
			helper_text TEXT NULL,
			field_kind VARCHAR(32) NOT NULL,
			input_type VARCHAR(32) NULL,
			display_type VARCHAR(32) NOT NULL,
			value_source VARCHAR(32) NOT NULL,
			source_config_json LONGTEXT NOT NULL,
			behavior VARCHAR(32) NOT NULL,
			pricing_mode VARCHAR(32) NULL,
			pricing_value DECIMAL(12,2) NULL,
			is_required TINYINT NOT NULL DEFAULT 0,
			default_value TEXT NULL,
			show_in_cart TINYINT NOT NULL DEFAULT 1,
			show_in_checkout TINYINT NOT NULL DEFAULT 1,
			show_in_admin_order TINYINT NOT NULL DEFAULT 1,
			show_in_customer_email TINYINT NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			legacy_source VARCHAR(64) NULL,
			legacy_id VARCHAR(128) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY template_field (template_key, field_key),
			KEY template_step_sort (template_key, step_key, sort_order),
			KEY field_kind (field_kind),
			KEY value_source (value_source)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
