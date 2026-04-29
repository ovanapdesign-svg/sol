<?php
declare(strict_types=1);

namespace ConfigKit\Migration;

interface Migration {

	public function key(): string;

	public function description(): string;

	public function up( \wpdb $wpdb ): void;
}
