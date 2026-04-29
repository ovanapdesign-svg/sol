<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader and provides minimal stubs for the WordPress
 * symbols that the Migration runner touches, so unit tests can run without a
 * full WordPress install.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {

		public string $prefix = 'wp_';

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}

		public function get_var( string $query ): mixed {
			return null;
		}

		/**
		 * @return array<int,string>
		 */
		public function get_col( string $query ): array {
			return [];
		}

		/**
		 * @param array<string,mixed> $data
		 */
		public function insert( string $table, array $data ): int|false {
			return 1;
		}

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, int|bool $gmt = 0 ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 0;
	}
}
