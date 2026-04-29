<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0001_create_migrations_table';
	}

	public function description(): string {
		return 'Create wp_configkit_migrations bootstrap table.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_migrations';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			migration_key VARCHAR(128) NOT NULL,
			applied_at DATETIME NOT NULL,
			applied_by BIGINT UNSIGNED NULL,
			duration_ms INT NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT 'applied',
			notes TEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY migration_key (migration_key)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
