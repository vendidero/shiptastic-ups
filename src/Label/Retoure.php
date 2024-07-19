<?php

namespace Vendidero\Germanized\UPS\Label;

use Vendidero\Germanized\UPS\Package;
use Vendidero\Germanized\Shipments\Interfaces\ShipmentReturnLabel;

defined( 'ABSPATH' ) || exit;

/**
 * DPD ReturnLabel class.
 */
class Retoure extends Simple implements ShipmentReturnLabel {

	/**
	 * Stores product data.
	 *
	 * @var array
	 */
	protected $extra_data = array(
		'xxx' => '',
	);

	protected function get_hook_prefix() {
		return 'woocommerce_gzd_ups_return_label_get_';
	}

	public function get_type() {
		return 'return';
	}
}
