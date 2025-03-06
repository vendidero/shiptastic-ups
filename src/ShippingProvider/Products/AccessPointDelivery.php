<?php

namespace Vendidero\Shiptastic\UPS\ShippingProvider\Services;

use Vendidero\Shiptastic\ShippingProvider\Product;

defined( 'ABSPATH' ) || exit;

class AccessPointDelivery extends Product {

	public function supports_shipment( $shipment ) {
		$supports_shipment = parent::supports_shipment( $shipment );

		if ( ! $supports_shipment ) {
			return $supports_shipment;
		}

		return $shipment->has_pickup_location();
	}
}
