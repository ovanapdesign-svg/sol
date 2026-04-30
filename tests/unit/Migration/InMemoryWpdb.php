<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Migration;

/**
 * In-memory \wpdb stand-in for unit tests.
 *
 * Pretends the wp_configkit_migrations table exists and stores INSERTs in
 * a public array so tests can assert on logged migrations.
 */
class InMemoryWpdb extends \wpdb {

	public bool $migrations_table_exists = true;

	/** @var array<string,bool> table_name → exists. Used by tests
	 *  that exercise the post-dbDelta verification path. The
	 *  default empty array means SHOW TABLES echoes back any name
	 *  passed in (legacy behaviour); declaring a key flips that to
	 *  the explicit value.
	 */
	public array $table_existence = [];

	/** @var list<array<string,mixed>> */
	public array $logged = [];

	public string $last_error = '';

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
			if ( ! preg_match( "/'([^']+)'/", $query, $m ) ) {
				return null;
			}
			$table = $m[1];

			// Per-table override beats the global migrations flag.
			if ( array_key_exists( $table, $this->table_existence ) ) {
				return $this->table_existence[ $table ] ? $table : null;
			}
			if ( str_ends_with( $table, 'configkit_migrations' ) && ! $this->migrations_table_exists ) {
				return null;
			}
			return $table;
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
