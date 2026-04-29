<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0007_create_template_versions';
	}

	public function description(): string {
		return 'Create wp_configkit_template_versions table per DATA_MODEL.md §3.6.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_template_versions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_key VARCHAR(64) NOT NULL,
			version_number INT NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'published',
			snapshot_json LONGTEXT NOT NULL,
			published_at DATETIME NULL,
			published_by BIGINT UNSIGNED NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY template_version (template_key, version_number),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
