<?php
/**
 * Plugin Name: ConfigKit
 * Description: Dynamic schema-driven WooCommerce product configurator.
 * Version: 0.0.1
 * Author: Ovanap
 * Text Domain: configkit
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Phase 0 — plugin scaffold only. No runtime code yet.
define( 'CONFIGKIT_VERSION', '0.0.1' );
define( 'CONFIGKIT_PLUGIN_FILE', __FILE__ );
define( 'CONFIGKIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
