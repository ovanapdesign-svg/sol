<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0014_create_log';
	}

	public function description(): string {
		return 'Create wp_configkit_log table per DATA_MODEL.md §3.13.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_log';
		$charset_collate = $wpdb->get_charset_collate();

		// DATETIME(6) for microsecond precision per DATA_MODEL.md §3.13.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME(6) NOT NULL,
			level VARCHAR(16) NOT NULL DEFAULT 'info',
			event_type VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NULL,
			order_id BIGINT UNSIGNED NULL,
			template_key VARCHAR(64) NULL,
			context_json LONGTEXT NULL,
			message TEXT NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event_created (event_type, created_at),
			KEY level_created (level, created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
