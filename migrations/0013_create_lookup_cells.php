<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0013_create_lookup_cells';
	}

	public function description(): string {
		return 'Create wp_configkit_lookup_cells table per DATA_MODEL.md §3.12.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_lookup_cells';
		$charset_collate = $wpdb->get_charset_collate();

		// price_group_key NOT NULL DEFAULT '' so the UNIQUE index treats
		// 2D-table cells as unique (MySQL UNIQUE allows multiple NULLs).
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lookup_table_key VARCHAR(64) NOT NULL,
			width INT NOT NULL,
			height INT NOT NULL,
			price_group_key VARCHAR(32) NOT NULL DEFAULT '',
			price DECIMAL(12,2) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cell (lookup_table_key, width, height, price_group_key),
			KEY table_dims (lookup_table_key, width, height),
			KEY table_price_group (lookup_table_key, price_group_key)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
