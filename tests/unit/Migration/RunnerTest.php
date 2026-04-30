<?php
declare(strict_types=1);

namespace ConfigKit\Tests\Unit\Migration;

use ConfigKit\Migration\Runner;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase {

	private string $fixtures_dir;
	private InMemoryWpdb $wpdb;

	protected function setUp(): void {
		$this->fixtures_dir = __DIR__ . '/fixtures/';
		$this->wpdb         = new InMemoryWpdb();
	}

	public function test_migrate_applies_pending_migrations(): void {
		$runner = new Runner( $this->wpdb, $this->fixtures_dir . 'success/' );

		$result = $runner->migrate();

		$this->assertCount( 2, $result );
		$this->assertSame( '0001_first', $result[0]['key'] );
		$this->assertSame( 'applied', $result[0]['status'] );
		$this->assertSame( '0002_second', $result[1]['key'] );
		$this->assertSame( 'applied', $result[1]['status'] );
	}

	public function test_migrate_is_idempotent(): void {
		$runner = new Runner( $this->wpdb, $this->fixtures_dir . 'success/' );

		$first  = $runner->migrate();
		$second = $runner->migrate();

		$this->assertCount( 2, $first );
		$this->assertCount( 0, $second, 'Re-running migrate() must apply nothing.' );
	}

	public function test_failed_migration_is_logged_with_failed_status(): void {
		$runner = new Runner( $this->wpdb, $this->fixtures_dir . 'failing/' );

		$result = $runner->migrate();

		$this->assertCount( 1, $result );
		$this->assertSame( 'failed', $result[0]['status'] );
		$this->assertArrayHasKey( 'error', $result[0] );
		$this->assertSame( 'simulated migration failure', $result[0]['error'] );

		$logged_failed = array_filter(
			$this->wpdb->logged,
			static fn( array $row ): bool => ( $row['status'] ?? '' ) === 'failed'
		);
		$this->assertCount( 1, $logged_failed, 'Failed migration must produce a wp_configkit_migrations row.' );
	}

	public function test_silent_dbdelta_failure_does_not_mark_migration_applied(): void {
		// Phase 4 dalis 4 BUG 1 — defend against migrations whose
		// `up()` returns cleanly but never created the expected
		// table (e.g. unquoted reserved word in CREATE TABLE).
		$this->wpdb->table_existence[ 'wp_configkit_ghost_table' ] = false;

		$runner = new Runner( $this->wpdb, $this->fixtures_dir . 'missing_table/' );
		$result = $runner->migrate();

		$this->assertCount( 1, $result );
		$this->assertSame( 'failed', $result[0]['status'] );
		$this->assertStringContainsString( 'configkit_ghost_table', $result[0]['error'] );

		// Re-running picks the same migration up again — proves we did
		// NOT mark it applied.
		$status_after = $runner->status();
		$this->assertContains( '0001_silent_failure', $status_after['pending'] );
	}

	public function test_wpdb_last_error_after_up_marks_migration_failed(): void {
		// If a migration writes a SQL error to wpdb->last_error
		// (dbDelta's silent path) the runner refuses to advance even
		// without an expected_tables() declaration.
		$wpdb = new class extends InMemoryWpdb {
			public function query( string $query ): int|false {
				$this->last_error = 'Syntax error near `row_number`';
				return false;
			}
		};
		$runner = new Runner( $wpdb, $this->fixtures_dir . 'silent_sql_error/' );
		$result = $runner->migrate();
		$this->assertSame( 'failed', $result[0]['status'] );
		$this->assertStringContainsString( 'row_number', $result[0]['error'] );
	}

	public function test_status_returns_applied_and_pending_lists(): void {
		$runner = new Runner( $this->wpdb, $this->fixtures_dir . 'success/' );

		$before = $runner->status();
		$this->assertSame( [], $before['applied'] );
		$this->assertSame( [ '0001_first', '0002_second' ], $before['pending'] );

		$runner->migrate();

		$after = $runner->status();
		$this->assertSame( [ '0001_first', '0002_second' ], $after['applied'] );
		$this->assertSame( [], $after['pending'] );
	}
}
