<?php
declare(strict_types=1);

use ConfigKit\Migration\Migration;

return new class implements Migration {

	public function key(): string {
		return '0002_second';
	}

	public function description(): string {
		return 'Test fixture: second success migration.';
	}

	public function up( \wpdb $wpdb ): void {
		// no-op for unit test
	}
};
