<?php
declare(strict_types=1);

namespace ConfigKit\Repository;

/**
 * Read-only counts for the Dashboard. Single class to avoid pulling each
 * full repository in early Phase 3 chunks. Each query is bounded and
 * uses indexed columns.
 */
final class CountsService {

	public function __construct( private \wpdb $wpdb ) {}

	/**
	 * @return array<string,int>
	 */
	public function snapshot(): array {
		return [
			'configurable_products' => $this->count_meta_enabled(),
			'templates_published'   => $this->count_templates( 'published' ),
			'templates_draft'       => $this->count_templates( 'draft' ),
			'libraries_active'      => $this->count_libraries_active(),
			'lookup_tables'         => $this->count_simple( 'configkit_lookup_tables' ),
			'modules'               => $this->count_simple( 'configkit_modules' ),
		];
	}

	private function count_simple( string $table_suffix ): int {
		$table = $this->wpdb->prefix . $table_suffix;
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		$value = $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function count_templates( string $status ): int {
		$table = $this->wpdb->prefix . 'configkit_templates';
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE status = %s",
				$status
			)
		);
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function count_libraries_active(): int {
		$table = $this->wpdb->prefix . 'configkit_libraries';
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		$value = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE is_active = 1"
		);
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function count_meta_enabled(): int {
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$this->wpdb->postmeta}` WHERE meta_key = %s AND meta_value = %s",
				'_configkit_enabled',
				'1'
			)
		);
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function table_exists( string $table ): bool {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		return $found === $table;
	}
}
