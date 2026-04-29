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
use ConfigKit\Admin\Pages\LibrariesPage;
use ConfigKit\Admin\Pages\ModulesPage;
use ConfigKit\Admin\Pages\SettingsPage;
use ConfigKit\Capabilities\Registrar;
use ConfigKit\CLI\Command;
use ConfigKit\Migration\Runner;
use ConfigKit\Repository\CountsService;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Rest\Controllers\LibrariesController;
use ConfigKit\Rest\Controllers\LibraryItemsController;
use ConfigKit\Rest\Controllers\ModulesController;
use ConfigKit\Rest\Router;
use ConfigKit\Service\LibraryItemService;
use ConfigKit\Service\LibraryService;
use ConfigKit\Service\ModuleService;
use ConfigKit\Settings\GeneralSettings;

final class Plugin {

	public function __construct( private string $plugin_file ) {}

	public function boot(): void {
		\register_activation_hook( $this->plugin_file, [ $this, 'on_activation' ] );
		\register_deactivation_hook( $this->plugin_file, [ $this, 'on_deactivation' ] );

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'configkit', new Command( $this->build_runner() ) );
		}

		if ( \is_admin() ) {
			\add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
			\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
			\add_action( 'admin_init', [ $this, 'register_admin_init' ] );
		}

		$this->build_rest_router()->init();
	}

	public function on_activation(): void {
		$this->build_runner()->migrate();
		( new Registrar() )->register();
	}

	public function on_deactivation(): void {
		( new Registrar() )->deregister();
	}

	public function register_admin_menu(): void {
		( new Menu( $this->build_admin_pages() ) )->register();
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		$this->build_asset_loader()->enqueue( $hook_suffix );
	}

	public function register_admin_init(): void {
		$this->build_general_settings()->register();
		( new Registrar() )->ensure_registered();
	}

	private function build_general_settings(): GeneralSettings {
		static $instance = null;
		if ( $instance === null ) {
			$instance = new GeneralSettings();
		}
		return $instance;
	}

	/**
	 * @return list<AbstractPage>
	 */
	private function build_admin_pages(): array {
		global $wpdb;

		return [
			new DashboardPage( new CountsService( $wpdb ) ),
			new SettingsPage( $this->build_general_settings() ),
			new ModulesPage(),
			new LibrariesPage(),
		];
	}

	private function build_rest_router(): Router {
		global $wpdb;
		$module_repo  = new ModuleRepository( $wpdb );
		$library_repo = new LibraryRepository( $wpdb );
		$item_repo    = new LibraryItemRepository( $wpdb );

		$router = new Router();
		$router->add( new ModulesController( new ModuleService( $module_repo ) ) );
		$router->add( new LibrariesController( new LibraryService( $library_repo, $module_repo ) ) );
		$router->add( new LibraryItemsController( new LibraryItemService( $item_repo, $library_repo, $module_repo ) ) );
		return $router;
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
