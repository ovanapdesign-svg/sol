<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0016_create_import_rows';
	}

	public function description(): string {
		return 'Create wp_configkit_import_rows table per DATA_MODEL.md §3.16.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_import_rows';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_key VARCHAR(64) NOT NULL,
			row_number INT NOT NULL,
			action VARCHAR(32) NOT NULL,
			object_type VARCHAR(32) NOT NULL,
			object_key VARCHAR(128) NULL,
			severity VARCHAR(32) NOT NULL DEFAULT 'green',
			message TEXT NULL,
			raw_data_json LONGTEXT NULL,
			normalized_data_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_row (batch_key, row_number),
			KEY batch_severity (batch_key, severity),
			KEY object_lookup (object_type, object_key)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
