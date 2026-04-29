<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0011_create_rules';
	}

	public function description(): string {
		return 'Create wp_configkit_rules table per DATA_MODEL.md §3.10.';
	}

	public function up( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'configkit_rules';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_key VARCHAR(64) NOT NULL,
			rule_key VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			spec_json LONGTEXT NOT NULL,
			priority INT NOT NULL DEFAULT 0,
			is_active TINYINT NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			version_hash VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY template_rule (template_key, rule_key),
			KEY template_priority (template_key, priority)
		) {$charset_collate};";

		dbDelta( $sql );
	}
};
