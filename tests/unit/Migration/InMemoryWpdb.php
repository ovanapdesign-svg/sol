<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Migration;

/**
 * In-memory \wpdb stand-in for unit tests.
 *
 * Pretends the wp_configkit_migrations table exists and stores INSERTs in
 * a public array so tests can assert on logged migrations.
 */
final class InMemoryWpdb extends \wpdb {

	public bool $migrations_table_exists = true;

	/** @var list<array<string,mixed>> */
	public array $logged = [];

	public function __construct() {
		// Skip parent constructor — we do not connect to a database.
	}

	public function prepare( string $query, mixed ...$args ): string {
		$flat = [];
		foreach ( $args as $arg ) {
			if ( is_array( $arg ) ) {
				foreach ( $arg as $a ) {
					$flat[] = $a;
				}
			} else {
				$flat[] = $arg;
			}
		}
		foreach ( $flat as $value ) {
			$replacement = is_string( $value ) ? "'" . $value . "'" : (string) $value;
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_var( string $query ): mixed {
		if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
			if ( ! $this->migrations_table_exists ) {
				return null;
			}
			if ( preg_match( "/'([^']+)'/", $query, $m ) ) {
				return $m[1];
			}
			return null;
		}
		return null;
	}

	/**
	 * @return list<string>
	 */
	public function get_col( string $query ): array {
		if ( str_contains( $query, 'SELECT migration_key' ) ) {
			$applied = array_filter(
				$this->logged,
				static fn( array $row ): bool => ( $row['status'] ?? '' ) === 'applied'
			);
			return array_values( array_map(
				static fn( array $row ): string => (string) $row['migration_key'],
				$applied
			) );
		}
		return [];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function insert( string $table, array $data ): int|false {
		if ( str_ends_with( $table, 'configkit_migrations' ) ) {
			$this->logged[] = $data;
		}
		return 1;
	}
}
