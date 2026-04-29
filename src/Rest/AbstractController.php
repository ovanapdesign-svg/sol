<?php
declare(strict_types=1);

namespace ConfigKit\Rest;

abstract class AbstractController {

	public const NAMESPACE = 'configkit/v1';

	abstract public function register_routes(): void;

	/**
	 * Build a permission_callback closure that checks a single capability.
	 */
	protected function require_cap( string $capability ): \Closure {
		return static function () use ( $capability ): bool {
			return \current_user_can( $capability );
		};
	}

	/**
	 * Wrap a payload as a successful WP_REST_Response.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function ok( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Build a structured WP_Error for a validation failure.
	 *
	 * @param array<string,mixed> $details
	 */
	protected function error( string $code, string $message, array $details = [], int $status = 400 ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array_merge( [ 'status' => $status ], $details )
		);
	}
}
