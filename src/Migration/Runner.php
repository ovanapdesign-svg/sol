<?php
declare(strict_types=1);

namespace ConfigKit\Migration;

use Throwable;

final class Runner {

	public function __construct(
		private \wpdb $wpdb,
		private string $migrations_dir,
	) {}

	/**
	 * Apply all pending migrations.
	 *
	 * @return list<array{key:string,status:string,duration_ms:int,error?:string}>
	 */
	public function migrate(): array {
		$results = [];
		foreach ( $this->discover() as $migration ) {
			if ( $this->is_applied( $migration->key() ) ) {
				continue;
			}
			$results[] = $this->apply( $migration );
		}
		return $results;
	}

	/**
	 * Inspect applied vs pending migration keys.
	 *
	 * @return array{applied:list<string>,pending:list<string>}
	 */
	public function status(): array {
		$applied_keys = $this->applied_keys();
		$result       = [ 'applied' => [], 'pending' => [] ];
		foreach ( $this->discover() as $migration ) {
			$key = $migration->key();
			if ( in_array( $key, $applied_keys, true ) ) {
				$result['applied'][] = $key;
			} else {
				$result['pending'][] = $key;
			}
		}
		return $result;
	}

	/**
	 * @return list<Migration>
	 */
	private function discover(): array {
		$pattern = rtrim( $this->migrations_dir, '/' ) . '/*.php';
		$files   = glob( $pattern );
		if ( $files === false ) {
			return [];
		}
		sort( $files, SORT_STRING );

		$migrations = [];
		foreach ( $files as $file ) {
			$migration = require $file;
			if ( ! $migration instanceof Migration ) {
				throw new \RuntimeException(
					sprintf( 'Migration file %s did not return a Migration instance.', $file )
				);
			}
			$migrations[] = $migration;
		}
		return $migrations;
	}

	private function is_applied( string $key ): bool {
		return in_array( $key, $this->applied_keys(), true );
	}

	/**
	 * @return list<string>
	 */
	private function applied_keys(): array {
		$table = $this->migrations_table();
		if ( ! $this->table_exists( $table ) ) {
			return [];
		}
		$rows = $this->wpdb->get_col(
			"SELECT migration_key FROM `{$table}` WHERE status = 'applied'"
		);
		return is_array( $rows ) ? array_values( $rows ) : [];
	}

	/**
	 * @return array{key:string,status:string,duration_ms:int,error?:string}
	 */
	private function apply( Migration $migration ): array {
		$key   = $migration->key();
		$start = microtime( true );
		try {
			$migration->up( $this->wpdb );
			$duration_ms = (int) round( ( microtime( true ) - $start ) * 1000.0 );
			$this->log( $key, 'applied', $duration_ms, null );
			return [ 'key' => $key, 'status' => 'applied', 'duration_ms' => $duration_ms ];
		} catch ( Throwable $e ) {
			$duration_ms = (int) round( ( microtime( true ) - $start ) * 1000.0 );
			$this->log( $key, 'failed', $duration_ms, $e->getMessage() );
			return [
				'key'         => $key,
				'status'      => 'failed',
				'duration_ms' => $duration_ms,
				'error'       => $e->getMessage(),
			];
		}
	}

	private function log( string $key, string $status, int $duration_ms, ?string $notes ): void {
		$table = $this->migrations_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		$applied_at = function_exists( 'current_time' )
			? \current_time( 'mysql', true )
			: gmdate( 'Y-m-d H:i:s' );
		$this->wpdb->insert(
			$table,
			[
				'migration_key' => $key,
				'applied_at'    => $applied_at,
				'applied_by'    => $user_id ?: null,
				'duration_ms'   => $duration_ms,
				'status'        => $status,
				'notes'         => $notes,
			]
		);
	}

	private function migrations_table(): string {
		return $this->wpdb->prefix . 'configkit_migrations';
	}

	private function table_exists( string $table ): bool {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		return $found === $table;
	}
}
