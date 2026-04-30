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

// Phase 4.2b.2 — minimal REST stubs so controllers are unit-testable
// without booting WordPress. Only the surface our controllers use.
if ( ! class_exists( 'WP_REST_Request', false ) ) {
	class WP_REST_Request implements ArrayAccess {

		/** @var array<string,mixed> */
		private array $params = [];

		/** @var array<string,mixed>|null */
		private ?array $json = null;

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param array<string,mixed> $body
		 */
		public function set_json_params( array $body ): void {
			$this->json = $body;
		}

		/**
		 * @return array<string,mixed>|null
		 */
		public function get_json_params(): ?array {
			return $this->json;
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_body_params(): array {
			return [];
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_file_params(): array {
			return [];
		}

		public function offsetExists( mixed $offset ): bool {
			return array_key_exists( (string) $offset, $this->params );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ (string) $offset ] ?? null;
		}

		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ (string) $offset ] = $value;
		}

		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ (string) $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	class WP_REST_Response {

		public function __construct(
			public mixed $data = null,
			public int $status = 200,
		) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {

		/** @var array<string,array<int,string>> */
		private array $errors = [];

		/** @var array<string,mixed> */
		private array $data = [];

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			if ( $code !== '' ) {
				$this->errors[ $code ] = [ $message ];
				if ( $data !== null ) $this->data[ $code ] = $data;
			}
		}

		public function get_error_code(): string {
			$keys = array_keys( $this->errors );
			return $keys[0] ?? '';
		}

		public function get_error_message( string $code = '' ): string {
			$code = $code === '' ? $this->get_error_code() : $code;
			$messages = $this->errors[ $code ] ?? [];
			return $messages[0] ?? '';
		}

		public function get_error_data( string $code = '' ): mixed {
			$code = $code === '' ? $this->get_error_code() : $code;
			return $this->data[ $code ] ?? null;
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [] ): bool {
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	$GLOBALS['__configkit_transients'] = [];
	function get_transient( string $key ): mixed {
		return $GLOBALS['__configkit_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		$GLOBALS['__configkit_transients'][ $key ] = $value;
		return true;
	}
}
