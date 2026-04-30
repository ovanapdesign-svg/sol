<?php
/**
 * ConfigKit plugin bootstrap.
 */
declare(strict_types=1);

namespace ConfigKit;

use ConfigKit\Adapters\WooPriceProvider;
use ConfigKit\Adapters\WooProductSearchProvider;
use ConfigKit\Engines\LookupEngine;
use ConfigKit\Engines\PricingEngine;
use ConfigKit\Admin\AssetLoader;
use ConfigKit\Admin\Menu;
use ConfigKit\Admin\Pages\AbstractPage;
use ConfigKit\Admin\Pages\DashboardPage;
use ConfigKit\Admin\Pages\DiagnosticsPage;
use ConfigKit\Admin\Pages\FamiliesPage;
use ConfigKit\Admin\Pages\ImportsPage;
use ConfigKit\Admin\Pages\LibrariesPage;
use ConfigKit\Admin\Pages\LookupTablesPage;
use ConfigKit\Admin\Pages\ModulesPage;
use ConfigKit\Admin\Pages\ProductsPage;
use ConfigKit\Admin\Pages\SettingsPage;
use ConfigKit\Admin\Pages\TemplatesPage;
use ConfigKit\Admin\WooIntegration;
use ConfigKit\Capabilities\Registrar;
use ConfigKit\CLI\Command;
use ConfigKit\Frontend\AddToCartController;
use ConfigKit\Frontend\ProductRenderer;
use ConfigKit\Frontend\RenderDataController;
use ConfigKit\Migration\Runner;
use ConfigKit\Repository\CountsService;
use ConfigKit\Repository\FamilyRepository;
use ConfigKit\Repository\FieldOptionRepository;
use ConfigKit\Repository\FieldRepository;
use ConfigKit\Import\LibraryItemParser;
use ConfigKit\Import\LibraryItemRunner;
use ConfigKit\Import\LibraryItemValidator;
use ConfigKit\Import\Parser as ImportParser;
use ConfigKit\Import\Runner as ImportRunner;
use ConfigKit\Import\UploadPaths as ImportUploadPaths;
use ConfigKit\Import\Validator as ImportValidator;
use ConfigKit\Adapters\WooSkuResolverImpl;
use ConfigKit\Repository\ImportBatchRepository;
use ConfigKit\Repository\ImportRowRepository;
use ConfigKit\Repository\LibraryItemRepository;
use ConfigKit\Repository\LibraryRepository;
use ConfigKit\Repository\LogRepository;
use ConfigKit\Repository\LookupCellRepository;
use ConfigKit\Repository\LookupTableRepository;
use ConfigKit\Repository\ModuleRepository;
use ConfigKit\Repository\ProductBindingRepository;
use ConfigKit\Repository\RuleRepository;
use ConfigKit\Repository\StepRepository;
use ConfigKit\Repository\TemplateRepository;
use ConfigKit\Repository\TemplateVersionRepository;
use ConfigKit\Rest\Controllers\DiagnosticsController;
use ConfigKit\Rest\Controllers\FamiliesController;
use ConfigKit\Rest\Controllers\ImportsController;
use ConfigKit\Rest\Controllers\FieldOptionsController;
use ConfigKit\Rest\Controllers\FieldsController;
use ConfigKit\Rest\Controllers\LibrariesController;
use ConfigKit\Rest\Controllers\LibraryItemsController;
use ConfigKit\Rest\Controllers\LookupCellsController;
use ConfigKit\Rest\Controllers\LookupTablesController;
use ConfigKit\Rest\Controllers\ModulesController;
use ConfigKit\Rest\Controllers\ProductsController;
use ConfigKit\Rest\Controllers\RulesController;
use ConfigKit\Rest\Controllers\StepsController;
use ConfigKit\Rest\Controllers\TemplatesController;
use ConfigKit\Rest\Controllers\TemplateVersionsController;
use ConfigKit\Rest\Controllers\WooProductsController;
use ConfigKit\Rest\Router;
use ConfigKit\Service\FamilyService;
use ConfigKit\Service\FieldOptionService;
use ConfigKit\Service\FieldService;
use ConfigKit\Service\ImportService;
use ConfigKit\Service\LibraryItemService;
use ConfigKit\Service\LibraryService;
use ConfigKit\Service\LookupCellService;
use ConfigKit\Service\LookupTableService;
use ConfigKit\Service\ModuleService;
use ConfigKit\Service\ProductBindingService;
use ConfigKit\Service\ProductDiagnosticsService;
use ConfigKit\Service\RuleService;
use ConfigKit\Service\StepService;
use ConfigKit\Service\SystemDiagnosticsService;
use ConfigKit\Service\TemplateService;
use ConfigKit\Service\TestDefaultPriceService;
use ConfigKit\Service\TemplateValidator;
use ConfigKit\Service\TemplateVersionService;
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
			( new WooIntegration() )->register();
		} else {
			$this->build_product_renderer()->register();
		}

		$this->build_rest_router()->init();
	}

	/**
	 * Phase 4.2b.1 — production binding of the PriceProvider port that
	 * the engine consumes for `price_source = 'woo'` resolution. The
	 * PricingEngine itself is instantiated by future cart-event wiring;
	 * this helper exists so that wiring has one obvious place to grab
	 * the adapter from instead of re-creating it ad hoc.
	 */
	public function build_price_provider(): WooPriceProvider {
		return new WooPriceProvider();
	}

	private function build_product_renderer(): ProductRenderer {
		global $wpdb;
		return new ProductRenderer(
			new ProductBindingRepository( $wpdb ),
			\plugin_dir_url( $this->plugin_file ),
			\defined( 'CONFIGKIT_VERSION' ) ? \CONFIGKIT_VERSION : '0.0.0'
		);
	}

	public function on_activation(): void {
		$this->build_runner()->migrate();
		( new Registrar() )->register();
		ImportUploadPaths::ensure();
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
		// Belt-and-suspenders: existing installs that pre-date the
		// activation hook get the upload dir lazily on first admin
		// page load. Idempotent.
		ImportUploadPaths::ensure();
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
			new DashboardPage(
				new CountsService( $wpdb ),
				$this->build_system_diagnostics( $wpdb )
			),
			new SettingsPage( $this->build_general_settings() ),
			new ModulesPage(),
			new LibrariesPage(),
			new LookupTablesPage(),
			new FamiliesPage(),
			new TemplatesPage(),
			new ProductsPage(),
			new DiagnosticsPage(),
			new ImportsPage(),
		];
	}

	private function build_system_diagnostics( \wpdb $wpdb ): SystemDiagnosticsService {
		return new SystemDiagnosticsService(
			new ProductBindingRepository( $wpdb ),
			new TemplateRepository( $wpdb ),
			new StepRepository( $wpdb ),
			new FieldRepository( $wpdb ),
			new FieldOptionRepository( $wpdb ),
			new LookupTableRepository( $wpdb ),
			new LookupCellRepository( $wpdb ),
			new LibraryRepository( $wpdb ),
			new LibraryItemRepository( $wpdb ),
			new ModuleRepository( $wpdb ),
			new RuleRepository( $wpdb ),
			new LogRepository( $wpdb )
		);
	}

	private function build_rest_router(): Router {
		global $wpdb;
		$module_repo  = new ModuleRepository( $wpdb );
		$library_repo = new LibraryRepository( $wpdb );
		$item_repo    = new LibraryItemRepository( $wpdb );
		$lookup_repo  = new LookupTableRepository( $wpdb );
		$cell_repo    = new LookupCellRepository( $wpdb );

		$router = new Router();
		$router->add( new ModulesController( new ModuleService( $module_repo ) ) );
		$router->add( new LibrariesController( new LibraryService( $library_repo, $module_repo ) ) );
		$router->add( new LibraryItemsController(
			new LibraryItemService( $item_repo, $library_repo, $module_repo ),
			new PricingEngine( new LookupEngine(), $this->build_price_provider() )
		) );
		$router->add( new LookupTablesController( new LookupTableService( $lookup_repo, $cell_repo ) ) );
		$router->add( new LookupCellsController( new LookupCellService( $cell_repo, $lookup_repo ) ) );
		$router->add( new FamiliesController( new FamilyService( new FamilyRepository( $wpdb ) ) ) );
		$template_repo     = new TemplateRepository( $wpdb );
		$step_repo         = new StepRepository( $wpdb );
		$field_repo        = new FieldRepository( $wpdb );
		$field_option_repo = new FieldOptionRepository( $wpdb );
		$router->add( new TemplatesController( new TemplateService( $template_repo ) ) );
		$router->add( new StepsController( new StepService( $step_repo, $template_repo ) ) );
		$router->add( new FieldsController( new FieldService( $field_repo, $step_repo, $template_repo ) ) );
		$router->add( new FieldOptionsController( new FieldOptionService( $field_option_repo, $field_repo ) ) );
		$rule_repo = new RuleRepository( $wpdb );
		$router->add( new RulesController( new RuleService(
			$rule_repo,
			$template_repo,
			$field_repo,
			$step_repo,
			$field_option_repo
		) ) );
		$version_repo = new TemplateVersionRepository( $wpdb );
		$template_validator = new TemplateValidator(
			$template_repo,
			$step_repo,
			$field_repo,
			$field_option_repo,
			$rule_repo
		);
		$version_service = new TemplateVersionService(
			$version_repo,
			$template_repo,
			$step_repo,
			$field_repo,
			$field_option_repo,
			$rule_repo,
			$template_validator
		);
		$router->add( new TemplateVersionsController( $version_service, $template_validator ) );

		$binding_repo        = new ProductBindingRepository( $wpdb );
		$binding_service     = new ProductBindingService( $binding_repo );
		$diagnostics_service = new ProductDiagnosticsService(
			$binding_repo,
			$template_repo,
			$step_repo,
			$field_repo,
			$field_option_repo,
			$lookup_repo,
			$cell_repo,
			$library_repo,
			$item_repo,
			$rule_repo
		);
		$test_default_price_service = new TestDefaultPriceService(
			$binding_repo,
			$field_repo,
			$step_repo,
			$item_repo,
			new PricingEngine( new LookupEngine(), $this->build_price_provider() )
		);
		$router->add( new ProductsController(
			$binding_service,
			$diagnostics_service,
			$template_repo,
			$step_repo,
			$field_repo,
			$test_default_price_service
		) );

		$log_repo = new LogRepository( $wpdb );
		$system_diagnostics = new SystemDiagnosticsService(
			$binding_repo,
			$template_repo,
			$step_repo,
			$field_repo,
			$field_option_repo,
			$lookup_repo,
			$cell_repo,
			$library_repo,
			$item_repo,
			$module_repo,
			$rule_repo,
			$log_repo
		);
		$router->add( new DiagnosticsController( $system_diagnostics ) );

		$import_batches = new ImportBatchRepository( $wpdb );
		$import_rows    = new ImportRowRepository( $wpdb );
		$import_parser  = new ImportParser();
		$import_validator = new ImportValidator( $lookup_repo, $cell_repo );
		$import_runner    = new ImportRunner(
			$wpdb,
			$import_batches,
			$import_rows,
			$cell_repo,
			$lookup_repo,
			$import_parser,
			$import_validator
		);
		$library_item_runner = new LibraryItemRunner(
			$wpdb,
			$import_batches,
			$import_rows,
			$item_repo,
			$library_repo,
			new LibraryItemParser(),
			new LibraryItemValidator( $library_repo, $module_repo, $item_repo, new WooSkuResolverImpl() )
		);
		$router->add( new ImportsController( new ImportService(
			$import_runner,
			$import_batches,
			$import_rows,
			$library_item_runner
		) ) );

		$router->add( new RenderDataController(
			$binding_repo,
			$template_repo,
			$step_repo,
			$field_repo,
			$field_option_repo,
			$rule_repo,
			$library_repo,
			$item_repo,
			$lookup_repo,
			$cell_repo,
			$module_repo
		) );

		$router->add( new AddToCartController(
			$binding_repo,
			$template_repo,
			$step_repo,
			$field_repo
		) );

		$router->add( new WooProductsController( new WooProductSearchProvider() ) );
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
