<?php
/**
 * Plugin Name: UPS for Shiptastic
 * Plugin URI: https://vendidero.de/shiptastic
 * Description: UPS integration for Shiptastic
 * Author: vendidero
 * Author URI: https://vendidero.de
 * Version: 1.0.0
 * Requires PHP: 5.6
 * License: GPLv3
 * Requires Plugins: shiptastic-for-woocommerce
 */
defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) {
	return;
}

$autoloader = __DIR__ . '/vendor/autoload_packages.php';

if ( is_readable( $autoloader ) ) {
	require $autoloader;
} else {
	return;
}

register_activation_hook( __FILE__, array( '\Vendidero\Shiptastic\UPS\Package', 'install' ) );
add_action( 'plugins_loaded', array( '\Vendidero\Shiptastic\UPS\Package', 'init' ) );
