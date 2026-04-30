<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

/**
 * Test fixture: simulates `dbDelta()` swallowing a CREATE TABLE
 * error. up() returns cleanly (no throw) but the expected table is
 * never actually created. The Runner must catch this via the
 * `expected_tables()` post-check and mark the migration failed.
 */
return new class implements Migration {

	public function key(): string {
		return '0001_silent_failure';
	}

	public function description(): string {
		return 'Test fixture: silent dbDelta-style failure.';
	}

	public function up( \wpdb $wpdb ): void {
		// Pretend dbDelta ran but silently failed — no-op.
	}

	/**
	 * @return list<string>
	 */
	public function expected_tables( \wpdb $wpdb ): array {
		return [ $wpdb->prefix . 'configkit_ghost_table' ];
	}
};
