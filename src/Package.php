<?php

namespace Vendidero\Germanized\UPS;

use Vendidero\Germanized\Shipments\ShippingProvider\Helper;
use Vendidero\Germanized\UPS\Api\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Main package class.
 */
class Package {

	/**
	 * Version.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	protected static $api = null;

	/**
	 * Init the package - load the REST API Server class.
	 */
	public static function init() {
		if ( self::has_dependencies() ) {
			// Add shipping provider
			add_filter( 'woocommerce_gzd_shipping_provider_class_names', array( __CLASS__, 'add_shipping_provider_class_name' ), 20, 1 );
		}

		if ( ! did_action( 'woocommerce_gzd_shipments_init' ) ) {
			add_action( 'woocommerce_gzd_shipments_init', array( __CLASS__, 'on_shipments_init' ), 20 );
		} else {
			self::on_shipments_init();
		}
	}

	public static function on_shipments_init() {
		if ( ! self::has_dependencies() ) {
			return;
		}

		self::includes();

		if ( self::is_enabled() ) {
			self::init_hooks();
		}
	}

	public static function has_dependencies() {
		return ( class_exists( 'WooCommerce' ) && class_exists( '\Vendidero\Germanized\Shipments\Package' ) && apply_filters( 'woocommerce_gzd_ups_enabled', true ) );
	}

	public static function is_enabled() {
		return ( self::is_ups_enabled() );
	}

	public static function get_domestic_products( $shipment = false ) {
		$products = array(
			'01' => _x( 'Next Day Air', 'ups', 'woocommerce-germanized-ups' ),
			'02' => _x( '2nd Day Air', 'ups', 'woocommerce-germanized-ups' ),
			// @see https://www.ups.com/us/en/supplychain/freight/ground.page
			// '03' => _x( 'Ground', 'ups', 'woocommerce-germanized-ups' ),
			'07' => _x( 'Express', 'ups', 'woocommerce-germanized-ups' ),
			'08' => _x( 'Expedited', 'ups', 'woocommerce-germanized-ups' ),
			'11' => _x( 'UPS Standard', 'ups', 'woocommerce-germanized-ups' ),
			'12' => _x( '3 Day Select', 'ups', 'woocommerce-germanized-ups' ),
			'14' => _x( 'UPS Next Day Air Early', 'ups', 'woocommerce-germanized-ups' ),
			'17' => _x( 'UPS Worldwide Economy DDU', 'ups', 'woocommerce-germanized-ups' ),
			'54' => _x( 'Express Plus', 'ups', 'woocommerce-germanized-ups' ),
			'59' => _x( '2nd Day Air A.M.', 'ups', 'woocommerce-germanized-ups' ),
			'65' => _x( 'UPS Saver', 'ups', 'woocommerce-germanized-ups' ),
			// @see https://www.ups.com/at/de/shipping/international/services/worldwide-express-freight-midday.page
			// '71' => _x( 'UPS Worldwide Express Freight Midday', 'ups', 'woocommerce-germanized-ups' ),
			'72' => _x( 'UPS Worldwide Economy DDP', 'ups', 'woocommerce-germanized-ups' ),
			'74' => _x( 'UPS Express 12:00', 'ups', 'woocommerce-germanized-ups' ),
			'82' => _x( 'UPS Today Standard', 'ups', 'woocommerce-germanized-ups' ),
			'83' => _x( 'UPS Today Dedicated Courier', 'ups', 'woocommerce-germanized-ups' ),
			'84' => _x( 'UPS Today Intercity', 'ups', 'woocommerce-germanized-ups' ),
			'85' => _x( 'UPS Today Express', 'ups', 'woocommerce-germanized-ups' ),
			'86' => _x( 'UPS Today Express Saver', 'ups', 'woocommerce-germanized-ups' ),
			// @see https://www.ups.com/at/de/shipping/international/services/worldwide-express-freight.page
			// '96' => _x( 'UPS Worldwide Express Freight', 'ups', 'woocommerce-germanized-ups' ),
		);

		return $products;
	}

	public static function get_eu_products( $shipment = false ) {

	}

	public static function get_international_products( $shipment = false ) {

	}

	public static function is_ups_enabled() {
		$is_enabled = false;

		if ( method_exists( '\Vendidero\Germanized\Shipments\ShippingProvider\Helper', 'is_shipping_provider_activated' ) ) {
			$is_enabled = Helper::instance()->is_shipping_provider_activated( 'ups' );
		} else {
			if ( $provider = self::get_ups_shipping_provider() ) {
				$is_enabled = $provider->is_activated();
			}
		}

		return $is_enabled;
	}

	public static function get_api_username() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_UPS_API_USERNAME' ) ) {
			return WC_GZD_UPS_API_USERNAME;
		} else {
			return self::get_ups_shipping_provider()->get_api_username();
		}
	}

	public static function get_api_password() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_UPS_API_PASSWORD' ) ) {
			return WC_GZD_UPS_API_PASSWORD;
		} else {
			return self::get_ups_shipping_provider()->get_setting( 'api_password' );
		}
	}

	public static function get_api_access_key() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_UPS_API_ACCESS_KEY' ) ) {
			return WC_GZD_UPS_API_ACCESS_KEY;
		} else {
			return self::get_ups_shipping_provider()->get_setting( 'api_access_password' );
		}
	}

	public static function get_account_number() {
		if ( self::is_debug_mode() && defined( 'WC_GZD_UPS_API_ACCOUNT_NUMBER' ) ) {
			return WC_GZD_UPS_API_ACCOUNT_NUMBER;
		} else {
			return self::get_ups_shipping_provider()->get_setting( 'api_account_number' );
		}
	}

	/**
	 * @return Api
	 */
	public static function get_api() {
		$api = \Vendidero\Germanized\UPS\Api\Api::instance();

		if ( self::is_debug_mode() ) {
			$api::dev();
		} else {
			$api::prod();
		}

		return $api;
	}

	private static function includes() {

	}

	public static function init_hooks() {
		// Filter templates
		add_filter( 'woocommerce_gzd_default_plugin_template', array( __CLASS__, 'filter_templates' ), 10, 3 );
	}

	public static function filter_templates( $path, $template_name ) {
		if ( file_exists( self::get_path() . '/templates/' . $template_name ) ) {
			$path = self::get_path() . '/templates/' . $template_name;
		}

		return $path;
	}

	/**
	 * @return false
	 */
	public static function get_ups_shipping_provider() {
		$provider = wc_gzd_get_shipping_provider( 'ups' );

		if ( ! is_a( $provider, '\Vendidero\Germanized\UPS\ShippingProvider\UPS' ) ) {
			return false;
		}

		return $provider;
	}

	public static function add_shipping_provider_class_name( $class_names ) {
		$class_names['ups'] = '\Vendidero\Germanized\UPS\ShippingProvider\UPS';

		return $class_names;
	}

	public static function install() {
		self::on_shipments_init();
		//Install::install();
	}

	public static function install_integration() {
		self::install();
	}

	public static function is_integration() {
		return class_exists( 'WooCommerce_Germanized' ) ? true : false;
	}

	/**
	 * Return the version of the package.
	 *
	 * @return string
	 */
	public static function get_version() {
		return self::VERSION;
	}

	/**
	 * Return the path to the package.
	 *
	 * @return string
	 */
	public static function get_path() {
		return dirname( __DIR__ );
	}

	public static function get_template_path() {
		return 'woocommerce-germanized/';
	}

	/**
	 * Return the path to the package.
	 *
	 * @return string
	 */
	public static function get_url() {
		return plugins_url( '', __DIR__ );
	}

	public static function get_assets_url() {
		return self::get_url() . '/assets';
	}

	public static function is_debug_mode() {
		$is_debug_mode = ( defined( 'WC_GZD_UPS_DEBUG' ) && WC_GZD_UPS_DEBUG );

		return $is_debug_mode;
	}

	public static function enable_logging() {
		return ( defined( 'WC_GZD_UPS_LOG_ENABLE' ) && WC_GZD_UPS_LOG_ENABLE ) || self::is_debug_mode();
	}

	private static function define_constant( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	public static function log( $message, $type = 'info' ) {
		$logger         = wc_get_logger();
		$enable_logging = self::enable_logging() ? true : false;

		if ( ! $logger ) {
			return false;
		}

		/**
		 * Filter that allows adjusting whether to enable or disable
		 * logging for the DPD package (e.g. API requests).
		 *
		 * @param boolean $enable_logging True if logging should be enabled. False otherwise.
		 *
		 * @package Vendidero/Germanized/DPD
		 */
		if ( ! apply_filters( 'woocommerce_gzd_ups_enable_logging', $enable_logging ) ) {
			return false;
		}

		if ( ! is_callable( array( $logger, $type ) ) ) {
			$type = 'info';
		}

		$logger->{$type}( $message, array( 'source' => 'woocommerce-germanized-ups' ) );

		return true;
	}
}
