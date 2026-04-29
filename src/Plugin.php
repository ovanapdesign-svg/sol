<?php
/**
 * ConfigKit plugin bootstrap.
 */
declare(strict_types=1);

namespace ConfigKit;

use ConfigKit\Admin\AssetLoader;
use ConfigKit\Admin\Menu;
use ConfigKit\Admin\Pages\AbstractPage;
use ConfigKit\Admin\Pages\DashboardPage;
use ConfigKit\Capabilities\Registrar;
use ConfigKit\CLI\Command;
use ConfigKit\Migration\Runner;
use ConfigKit\Repository\CountsService;
use ConfigKit\Rest\Router;

final class Plugin {

	public function __construct( private string $plugin_file ) {}

	public function boot(): void {
		\register_activation_hook( $this->plugin_file, [ $this, 'on_activation' ] );

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'configkit', new Command( $this->build_runner() ) );
		}

		if ( \is_admin() ) {
			\add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
			\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		}

		$this->build_rest_router()->init();
	}

	public function on_activation(): void {
		$this->build_runner()->migrate();
		( new Registrar() )->register();
	}

	public function register_admin_menu(): void {
		( new Menu( $this->build_admin_pages() ) )->register();
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		$this->build_asset_loader()->enqueue( $hook_suffix );
	}

	/**
	 * @return list<AbstractPage>
	 */
	private function build_admin_pages(): array {
		global $wpdb;

		return [
			new DashboardPage( new CountsService( $wpdb ) ),
		];
	}

	private function build_rest_router(): Router {
		return new Router();
	}

	private function build_asset_loader(): AssetLoader {
		return new AssetLoader(
			\plugin_dir_url( $this->plugin_file ),
			\defined( 'CONFIGKIT_VERSION' ) ? \CONFIGKIT_VERSION : '0.0.0'
		);
	}

	private function build_runner(): Runner {
		global $wpdb;
		return new Runner( $wpdb, $this->migrations_dir() );
	}

	private function migrations_dir(): string {
		return \plugin_dir_path( $this->plugin_file ) . 'migrations/';
	}
}
