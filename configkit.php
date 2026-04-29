<?php
/**
 * Plugin Name: ConfigKit
 * Description: Dynamic schema-driven WooCommerce product configurator.
 * Version: 0.1.0
 * Author: Ovanap
 * Text Domain: configkit
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONFIGKIT_VERSION', '0.1.0' );
define( 'CONFIGKIT_PLUGIN_FILE', __FILE__ );
define( 'CONFIGKIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$configkit_composer_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $configkit_composer_autoload ) ) {
	require_once $configkit_composer_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'ConfigKit\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	);
}
unset( $configkit_composer_autoload );

( new \ConfigKit\Plugin( __FILE__ ) )->boot();
