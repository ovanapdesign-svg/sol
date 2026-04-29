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
