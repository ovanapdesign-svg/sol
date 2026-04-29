<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0004_create_library_items';
	}

	public function description(): string {
		return 'Create wp_configkit_library_items table per DATA_MODEL.md §3.3.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_library_items';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			library_key VARCHAR(64) NOT NULL,
			item_key VARCHAR(64) NOT NULL,
			sku VARCHAR(64) NULL,
			label VARCHAR(255) NOT NULL,
			short_label VARCHAR(64) NULL,
			image_url VARCHAR(2048) NULL,
			main_image_url VARCHAR(2048) NULL,
			description TEXT NULL,
			price DECIMAL(12,2) NULL,
			sale_price DECIMAL(12,2) NULL,
			price_group_key VARCHAR(32) NOT NULL DEFAULT '',
			color_family VARCHAR(64) NULL,
			woo_product_id BIGINT UNSIGNED NULL,
			filters_json LONGTEXT NULL,
			compatibility_json LONGTEXT NULL,
			attributes_json LONGTEXT NULL,
			legacy_source VARCHAR(64) NULL,
			legacy_id VARCHAR(128) NULL,
			is_active TINYINT NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY library_item (library_key, item_key),
			KEY library_active_sort (library_key, is_active, sort_order),
			KEY sku (sku),
			KEY woo_product_id (woo_product_id),
			KEY legacy (legacy_source, legacy_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
