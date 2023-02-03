<?php

namespace Vendidero\Germanized\UPS\Label;

use Vendidero\Germanized\UPS\Package;
use Vendidero\Germanized\Shipments\Labels\Label;

defined( 'ABSPATH' ) || exit;

/**
 * UPS Label class.
 */
class Simple extends Label {

	/**
	 * Stores product data.
	 *
	 * @var array
	 */
	protected $extra_data = array(
		'xx'  => '',
	);

	public function get_type() {
		return 'simple';
	}

	public function get_shipping_provider( $context = 'view' ) {
		return 'ups';
	}

	/**
	 * @return \WP_Error|true
	 */
	public function fetch() {
		$result = Package::get_api()->get_label( $this );

		return $result;
	}

	public function delete( $force_delete = false ) {
		Package::get_api()->cancel_label( $this );

		return parent::delete( $force_delete );
	}
}
