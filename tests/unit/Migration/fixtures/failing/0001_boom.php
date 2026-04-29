<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0001_boom';
	}

	public function description(): string {
		return 'Test fixture: failing migration.';
	}

	public function up( \wpdb $wpdb ): void {
		throw new \RuntimeException( 'simulated migration failure' );
	}
};
