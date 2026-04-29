<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0015_create_import_batches';
	}

	public function description(): string {
		return 'Create wp_configkit_import_batches table per DATA_MODEL.md §3.15.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_import_batches';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_key VARCHAR(64) NOT NULL,
			import_type VARCHAR(32) NOT NULL,
			filename VARCHAR(255) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			dry_run TINYINT NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			committed_at DATETIME NULL,
			summary_json LONGTEXT NULL,
			rollback_status VARCHAR(32) NULL,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_key (batch_key),
			KEY type_status (import_type, status),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
