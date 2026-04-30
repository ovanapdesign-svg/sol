<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

/**
 * Test fixture: simulates a migration that runs SQL through wpdb
 * (analogous to dbDelta) where wpdb internally records a syntax
 * error in `last_error` but doesn't throw. The Runner must detect
 * the populated last_error and refuse to mark the migration applied.
 */
return new class implements Migration {

	public function key(): string {
		return '0001_silent_sql_error';
	}

	public function description(): string {
		return 'Test fixture: silent SQL error reflected in wpdb->last_error.';
	}

	public function up( \wpdb $wpdb ): void {
		// Triggers the wpdb stub's query() override which writes a
		// canned error to last_error.
		$wpdb->query( 'CREATE TABLE example_does_not_matter ( row_number INT NOT NULL )' );
	}
};
