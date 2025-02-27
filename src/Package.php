<?php

namespace Vendidero\Shiptastic\UPS;

use Vendidero\Shiptastic\ShippingProvider\Helper;
use Vendidero\Shiptastic\UPS\Api\Api;

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
			add_filter( 'woocommerce_shiptastic_shipping_provider_class_names', array( __CLASS__, 'add_shipping_provider_class_name' ), 20, 1 );
		}

		if ( ! did_action( 'woocommerce_shiptastic_init' ) ) {
			add_action( 'woocommerce_shiptastic_init', array( __CLASS__, 'on_init' ), 20 );
		} else {
			self::on_init();
		}
	}

	public static function on_init() {
		if ( ! self::has_dependencies() ) {
			return;
		}

		self::includes();

		if ( self::is_enabled() ) {
			self::init_hooks();
		}
	}

	public static function has_dependencies() {
		return ( class_exists( 'WooCommerce' ) && class_exists( '\Vendidero\Shiptastic\Package' ) && apply_filters( 'woocommerce_shiptastic_ups_enabled', true ) );
	}

	public static function is_enabled() {
		return ( self::is_ups_enabled() );
	}

	/**
	 * @see https://www.ups.com/assets/resources/webcontent/en_GB/ReturnServicesShipment.pdf
	 *
	 * @return array
	 */
	public static function get_return_services() {
		$services = array(
			'2'  => _x( 'UPS Print and Mail (PNM)', 'ups', 'ups-for-shiptastic' ),
			'3'  => _x( 'UPS Return Service 1-Attempt (RS1)', 'ups', 'ups-for-shiptastic' ),
			'5'  => _x( 'UPS Return Service 3-Attempt (RS3)', 'ups', 'ups-for-shiptastic' ),
			'8'  => _x( 'UPS Electronic Return Label (ERL)', 'ups', 'ups-for-shiptastic' ),
			'9'  => _x( 'UPS Print Return Label (PRL)', 'ups', 'ups-for-shiptastic' ),
			'10' => _x( 'UPS Exchange Print Return Label', 'ups', 'ups-for-shiptastic' ),
		);

		return $services;
	}

	public static function is_ups_enabled() {
		return Helper::instance()->is_shipping_provider_activated( 'ups' );
	}

	public static function use_custom_api() {
		return defined( 'WC_STC_UPS_ENABLE_CUSTOM_API' ) ? WC_STC_UPS_ENABLE_CUSTOM_API : false;
	}

	public static function get_api_client_id() {
		$client_id = self::get_ups_shipping_provider()->get_setting( 'api_username' );

		if ( defined( 'WC_STC_UPS_API_CLIENT_ID' ) ) {
			$client_id = WC_STC_UPS_API_CLIENT_ID;
		}

		return $client_id;
	}

	public static function get_api_client_secret() {
		$client_secret = self::get_ups_shipping_provider()->get_setting( 'api_password' );

		if ( defined( 'WC_STC_UPS_API_CLIENT_SECRET' ) ) {
			$client_secret = WC_STC_UPS_API_CLIENT_SECRET;
		}

		return $client_secret;
	}

	public static function get_account_number() {
		if ( self::is_sandbox_mode() && defined( 'WC_STC_UPS_API_ACCOUNT_NUMBER' ) ) {
			return WC_STC_UPS_API_ACCOUNT_NUMBER;
		} else {
			return self::get_ups_shipping_provider()->get_setting( 'api_account_number' );
		}
	}

	/**
	 * @return false|\Vendidero\Shiptastic\Interfaces\Api|Api
	 */
	public static function get_api() {
		return \Vendidero\Shiptastic\API\Helper::get_api( 'ups', self::is_sandbox_mode() );
	}

	private static function includes() {
	}

	public static function init_hooks() {
		// Filter templates
		add_filter( 'shiptastic_default_template_path', array( __CLASS__, 'filter_templates' ), 10, 2 );
		add_filter(
			'shiptastic_register_api_instance_ups',
			function () {
				return new Api();
			}
		);
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
		$provider = wc_stc_get_shipping_provider( 'ups' );

		if ( ! is_a( $provider, '\Vendidero\Shiptastic\UPS\ShippingProvider\UPS' ) ) {
			return false;
		}

		return $provider;
	}

	public static function add_shipping_provider_class_name( $class_names ) {
		$class_names['ups'] = '\Vendidero\Shiptastic\UPS\ShippingProvider\UPS';

		return $class_names;
	}

	public static function install() {
		self::on_init();

		if ( ! self::has_dependencies() ) {
			return;
		}

		Install::install();
	}

	public static function install_integration() {
		self::install();
	}

	public static function is_integration() {
		return \Vendidero\Shiptastic\Package::is_integration() ? true : false;
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

	public static function is_sandbox_mode() {
		$is_sandbox = ( defined( 'WC_STC_UPS_ENABLE_SANDBOX' ) && WC_STC_UPS_ENABLE_SANDBOX );

		if ( ! $is_sandbox && ( $provider = self::get_ups_shipping_provider() ) ) {
			$is_sandbox = $provider->is_sandbox();
		}

		return $is_sandbox;
	}

	public static function enable_logging() {
		return ( defined( 'WC_STC_UPS_LOG_ENABLE' ) && WC_STC_UPS_LOG_ENABLE ) || self::is_sandbox_mode();
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
		if ( ! apply_filters( 'shiptastic_ups_enable_logging', $enable_logging ) ) {
			return false;
		}

		if ( ! is_callable( array( $logger, $type ) ) ) {
			$type = 'info';
		}

		$logger->{$type}( $message, array( 'source' => 'ups-for-shiptastic' ) );

		return true;
	}
}
