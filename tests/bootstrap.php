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

		public function query( string $query ): int|false {
			// Tests pass mock wpdb instances when transactional behaviour
			// matters; the bare stub treats all queries as no-ops.
			return 0;
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

// Minimal escaper stubs for tests that exercise admin renderers
// (Breadcrumb, PageHeader). Production uses WordPress's real
// escapers; these are pass-through with HTML entity safety.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ): string {
		return (string) $value;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
