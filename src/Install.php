<?php

namespace Vendidero\Germanized\UPS;

defined( 'ABSPATH' ) || exit;

/**
 * Main package class.
 */
class Install {

	public static function install() {
		$current_version = get_option( 'woocommerce_gzd_ups_version', null );

		if ( ! is_null( $current_version ) ) {
			self::update( $current_version );
		}

		/**
		 * Older versions did not support custom versioning
		 */
		if ( is_null( $current_version ) ) {
			add_option( 'woocommerce_gzd_ups_version', Package::get_version() );
		} else {
			update_option( 'woocommerce_gzd_ups_version', Package::get_version() );
		}
	}

	private static function update( $current_version ) {}
}
