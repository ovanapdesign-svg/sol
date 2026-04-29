<?php
/**
 * ConfigKit plugin bootstrap.
 */
declare(strict_types=1);

namespace ConfigKit;

use ConfigKit\CLI\Command;
use ConfigKit\Migration\Runner;

final class Plugin {

	public function __construct( private string $plugin_file ) {}

	public function boot(): void {
		register_activation_hook( $this->plugin_file, [ $this, 'on_activation' ] );

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'configkit', new Command( $this->build_runner() ) );
		}
	}

	public function on_activation(): void {
		$this->build_runner()->migrate();
	}

	private function build_runner(): Runner {
		global $wpdb;
		return new Runner( $wpdb, $this->migrations_dir() );
	}

	private function migrations_dir(): string {
		return plugin_dir_path( $this->plugin_file ) . 'migrations/';
	}
}
