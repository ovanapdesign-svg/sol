<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0006_create_templates';
	}

	public function description(): string {
		return 'Create wp_configkit_templates table per DATA_MODEL.md §3.5.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_templates';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_key VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			family_key VARCHAR(64) NULL,
			description TEXT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'draft',
			published_version_id BIGINT UNSIGNED NULL,
			legacy_source VARCHAR(64) NULL,
			legacy_id VARCHAR(128) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY template_key (template_key),
			KEY family_key (family_key),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
