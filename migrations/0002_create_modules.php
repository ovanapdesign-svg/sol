<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0002_create_modules';
	}

	public function description(): string {
		return 'Create wp_configkit_modules table per DATA_MODEL.md §3.1.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_modules';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			module_key VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			supports_sku TINYINT NOT NULL DEFAULT 0,
			supports_image TINYINT NOT NULL DEFAULT 0,
			supports_main_image TINYINT NOT NULL DEFAULT 0,
			supports_price TINYINT NOT NULL DEFAULT 0,
			supports_sale_price TINYINT NOT NULL DEFAULT 0,
			supports_filters TINYINT NOT NULL DEFAULT 0,
			supports_compatibility TINYINT NOT NULL DEFAULT 0,
			supports_price_group TINYINT NOT NULL DEFAULT 0,
			supports_brand TINYINT NOT NULL DEFAULT 0,
			supports_collection TINYINT NOT NULL DEFAULT 0,
			supports_color_family TINYINT NOT NULL DEFAULT 0,
			supports_woo_product_link TINYINT NOT NULL DEFAULT 0,
			allowed_field_kinds_json LONGTEXT NOT NULL,
			attribute_schema_json LONGTEXT NOT NULL,
			is_builtin TINYINT NOT NULL DEFAULT 0,
			is_active TINYINT NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY module_key (module_key),
			KEY active_sort (is_active, sort_order)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
