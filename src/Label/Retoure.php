<?php

namespace Vendidero\Shiptastic\UPS\Label;

use Vendidero\Shiptastic\UPS\Package;
use Vendidero\Shiptastic\Interfaces\ShipmentReturnLabel;

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
		'return_service' => '',
		'incoterms'      => '',
		'form_01_path'   => '',
	);

	protected function get_hook_prefix() {
		return 'shiptastic_ups_return_label_get_';
	}

	public function get_type() {
		return 'return';
	}

	public function get_return_service( $context = 'view' ) {
		return $this->get_prop( 'return_service', $context );
	}

	public function set_return_service( $service ) {
		$this->set_prop( 'return_service', $service );
	}
}
