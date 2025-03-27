<?php

namespace Vendidero\Shiptastic\UPS\ShippingProvider\Services;

use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\ShippingProvider\Service;

defined( 'ABSPATH' ) || exit;

class Notification extends Service {

	public function __construct( $shipping_provider, $args = array() ) {
		$args = array(
			'id'    => 'Notification',
			'label' => _x( 'Customer Notification', 'ups', 'shiptastic-integration-for-ups' ),
		);

		parent::__construct( $shipping_provider, $args );
	}

	public function get_default_value( $suffix = '' ) {
		$default_value = parent::get_default_value( $suffix );

		if ( 'email' === $suffix ) {
			$default_value = '';
		}

		return $default_value;
	}

	public function book_as_default( $shipment ) {
		$book_as_default = parent::book_as_default( $shipment );

		if ( false === $book_as_default ) {
			if ( $order = $shipment->get_order() ) {
				$supports_email_notification = wc_stc_get_shipment_order( $order )->supports_third_party_email_transmission();
			}

			$supports_email_notification = $supports_email_notification || apply_filters( 'shiptastic_ups_force_email_notification', false, $shipment );

			if ( $supports_email_notification ) {
				$book_as_default = true;
			}
		}

		return $book_as_default;
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return array[]
	 */
	protected function get_additional_label_fields( $shipment ) {
		$label_fields = parent::get_additional_label_fields( $shipment );
		$value        = $shipment->get_email();

		$label_fields = array_merge(
			$label_fields,
			array(
				array(
					'id'                => $this->get_label_field_id( 'email' ),
					'data_type'         => 'email',
					'label'             => _x( 'E-Mail', 'ups', 'shiptastic-integration-for-ups' ),
					'placeholder'       => '',
					'description'       => '',
					'value'             => $value,
					'type'              => 'text',
					'custom_attributes' => array( 'data-show-if-service_Notification' => '' ),
					'is_required'       => true,
				),
			)
		);

		return $label_fields;
	}
}
