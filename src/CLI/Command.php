<?php
declare(strict_types=1);

namespace ConfigKit\CLI;

use ConfigKit\Migration\Runner;

final class Command {

	public function __construct( private Runner $runner ) {}

	/**
	 * Run pending ConfigKit migrations.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show pending migrations without applying.
	 *
	 * [--status]
	 * : Show applied and pending migration lists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp configkit migrate
	 *     wp configkit migrate --dry-run
	 *     wp configkit migrate --status
	 *
	 * @param array<int,string>     $args
	 * @param array<string,mixed>   $assoc_args
	 */
	public function migrate( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['status'] ) ) {
			$this->print_status();
			return;
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$this->print_pending();
			return;
		}
		$this->run_migrate();
	}

	private function print_status(): void {
		$status = $this->runner->status();

		\WP_CLI::log( 'Applied: ' . count( $status['applied'] ) );
		foreach ( $status['applied'] as $key ) {
			\WP_CLI::log( "  [x] {$key}" );
		}
		\WP_CLI::log( 'Pending: ' . count( $status['pending'] ) );
		foreach ( $status['pending'] as $key ) {
			\WP_CLI::log( "  [ ] {$key}" );
		}
	}

	private function print_pending(): void {
		$status = $this->runner->status();

		if ( count( $status['pending'] ) === 0 ) {
			\WP_CLI::success( 'No pending migrations. Database is up to date.' );
			return;
		}

		\WP_CLI::log( 'Pending migrations: ' . count( $status['pending'] ) );
		foreach ( $status['pending'] as $key ) {
			\WP_CLI::log( "  [ ] {$key}" );
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Dry run only — no changes applied.' );
	}

	private function run_migrate(): void {
		$results = $this->runner->migrate();

		if ( count( $results ) === 0 ) {
			\WP_CLI::success( 'No pending migrations. Database is up to date.' );
			return;
		}

		$failed = 0;
		foreach ( $results as $entry ) {
			if ( $entry['status'] === 'failed' ) {
				$failed++;
				\WP_CLI::log(
					sprintf(
						'  [!] %s failed (%d ms): %s',
						$entry['key'],
						$entry['duration_ms'],
						$entry['error'] ?? 'unknown error'
					)
				);
			} else {
				\WP_CLI::log(
					sprintf( '  [x] %s (%d ms)', $entry['key'], $entry['duration_ms'] )
				);
			}
		}

		if ( $failed > 0 ) {
			\WP_CLI::error( sprintf( '%d migration(s) failed. See list above.', $failed ) );
		}

		\WP_CLI::success( sprintf( '%d migration(s) applied.', count( $results ) ) );
	}
}
