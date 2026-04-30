<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

/**
 * Phase 4.2b.1 — extend wp_configkit_library_items with the columns
 * required by the Pricing Source + Bundle model.
 *
 * Locked decisions:
 *   - PRICING_SOURCE_MODEL §9 — backfill price_source = 'configkit'
 *   - BUNDLE_MODEL §10        — backfill item_type = 'simple_option',
 *                               bundle fields NULL, cart_behavior +
 *                               admin_order_display defaults preserved
 *                               only on bundles (NULL on simple items)
 *
 * Idempotent: each ALTER is gated by an INFORMATION_SCHEMA check so
 * `wp configkit migrate` can re-run cleanly.
 */
return new class implements Migration {

	public function key(): string {
		return '0017_extend_library_items_pricing_bundles';
	}

	public function description(): string {
		return 'Add price_source / bundle_fixed_price / item_type / bundle_components_json / cart_behavior / admin_order_display columns to library_items per PRICING_SOURCE_MODEL §8 + BUNDLE_MODEL §9.';
	}

	public function up( \wpdb $wpdb ): void {
		$table = $wpdb->prefix . 'configkit_library_items';

		$columns = [
			'price_source' => "VARCHAR(32) NOT NULL DEFAULT 'configkit' AFTER `price`",
			'bundle_fixed_price' => "DECIMAL(10,2) NULL AFTER `price_source`",
			'item_type' => "VARCHAR(32) NOT NULL DEFAULT 'simple_option' AFTER `bundle_fixed_price`",
			'bundle_components_json' => "LONGTEXT NULL AFTER `item_type`",
			'cart_behavior' => "VARCHAR(32) NULL DEFAULT 'price_inside_main' AFTER `bundle_components_json`",
			'admin_order_display' => "VARCHAR(32) NULL DEFAULT 'expanded' AFTER `cart_behavior`",
		];

		foreach ( $columns as $name => $definition ) {
			if ( $this->column_exists( $wpdb, $table, $name ) ) {
				continue;
			}
			$sql = "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}";
			$result = $wpdb->query( $sql );
			if ( $result === false ) {
				throw new \RuntimeException(
					sprintf(
						'Failed to add column %s on %s: %s',
						$name,
						$table,
						(string) $wpdb->last_error
					)
				);
			}
		}

		$indexes = [
			'idx_item_type'    => '(`item_type`)',
			'idx_price_source' => '(`price_source`)',
		];
		foreach ( $indexes as $name => $definition ) {
			if ( $this->index_exists( $wpdb, $table, $name ) ) {
				continue;
			}
			$sql = "ALTER TABLE `{$table}` ADD INDEX `{$name}` {$definition}";
			$result = $wpdb->query( $sql );
			if ( $result === false ) {
				throw new \RuntimeException(
					sprintf(
						'Failed to add index %s on %s: %s',
						$name,
						$table,
						(string) $wpdb->last_error
					)
				);
			}
		}
	}

	private function column_exists( \wpdb $wpdb, string $table, string $column ): bool {
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);
		return $value !== null;
	}

	private function index_exists( \wpdb $wpdb, string $table, string $index ): bool {
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
				$table,
				$index
			)
		);
		return $value !== null;
	}
};
