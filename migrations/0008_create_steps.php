<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0008_create_steps';
	}

	public function description(): string {
		return 'Create wp_configkit_steps table per DATA_MODEL.md §3.7.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_steps';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_key VARCHAR(64) NOT NULL,
			step_key VARCHAR(64) NOT NULL,
			label VARCHAR(255) NOT NULL,
			description TEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			is_required TINYINT NOT NULL DEFAULT 0,
			is_collapsed_by_default TINYINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY template_step (template_key, step_key),
			KEY template_sort (template_key, sort_order)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
