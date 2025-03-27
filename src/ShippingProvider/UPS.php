<?php
/**
 * ShippingProvider impl.
 *
 * @package WooCommerce/Blocks
 */
namespace Vendidero\Shiptastic\UPS\ShippingProvider;

use Vendidero\Shiptastic\Labels\ConfigurationSet;
use Vendidero\Shiptastic\PickupDelivery;
use Vendidero\Shiptastic\UPS\Package;
use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\ShippingProvider\Auto;
use Vendidero\Shiptastic\UPS\ShippingProvider\Products\AccessPointDelivery;
use Vendidero\Shiptastic\UPS\ShippingProvider\Services\Notification;

defined( 'ABSPATH' ) || exit;

class UPS extends Auto {

	public function get_title( $context = 'view' ) {
		return _x( 'UPS', 'ups', 'shiptastic-integration-for-ups' );
	}

	public function get_name( $context = 'view' ) {
		return 'ups';
	}

	public function get_description( $context = 'view' ) {
		return _x( 'Create UPS labels and return labels conveniently.', 'ups', 'shiptastic-integration-for-ups' );
	}

	public function get_default_tracking_url_placeholder() {
		return 'https://wwwapps.ups.com/tracking/tracking.cgi?tracknum={tracking_id}&loc=de_DE';
	}

	public function is_sandbox() {
		return wc_string_to_bool( $this->get_setting( 'sandbox_mode', 'no' ) );
	}

	public function get_label_classname( $type ) {
		if ( 'return' === $type ) {
			return '\Vendidero\Shiptastic\UPS\Label\Retoure';
		} else {
			return '\Vendidero\Shiptastic\UPS\Label\Simple';
		}
	}

	/**
	 * @param string $label_type
	 * @param false|Shipment $shipment
	 *
	 * @return bool
	 */
	public function supports_labels( $label_type, $shipment = false ) {
		$label_types = array( 'simple', 'return' );

		return in_array( $label_type, $label_types, true );
	}

	public function supports_customer_return_requests() {
		return true;
	}

	public function hide_return_address() {
		return false;
	}
	protected function register_services() {
		$this->register_service( new Notification( $this ) );
	}

	public function get_api_username( $context = 'view' ) {
		return $this->get_meta( 'api_username', true, $context );
	}

	public function set_api_username( $username ) {
		$this->update_meta_data( 'api_username', $username );
	}

	public function get_supported_label_reference_types( $shipment_type = 'simple' ) {
		$reference_types = array(
			'ref_1' => array(
				'label'      => _x( 'Reference 1', 'ups', 'shiptastic-integration-for-ups' ),
				'default'    => 'return' === $shipment_type ? _x( 'Return #{shipment_number}, order {order_number}', 'ups', 'shiptastic-integration-for-ups' ) : _x( '#{shipment_number}, order {order_number}', 'ups', 'shiptastic-integration-for-ups' ),
				'max_length' => 35,
			),
			'ref_2' => array(
				'label'      => _x( 'Reference 2', 'ups', 'shiptastic-integration-for-ups' ),
				'default'    => '',
				'max_length' => 35,
			),
		);

		return $reference_types;
	}

	public function get_available_incoterms() {
		return array(
			'CFR' => _x( 'Cost and Freight', 'ups', 'shiptastic-integration-for-ups' ),
			'CIF' => _x( 'Cost Insurance and Freight', 'ups', 'shiptastic-integration-for-ups' ),
			'CIP' => _x( 'Carriage and Insurance Paid', 'ups', 'shiptastic-integration-for-ups' ),
			'CPT' => _x( 'Carriage Paid To', 'ups', 'shiptastic-integration-for-ups' ),
			'DAF' => _x( 'Delivered at Frontier', 'ups', 'shiptastic-integration-for-ups' ),
			'DDP' => _x( 'Delivery Duty Paid', 'ups', 'shiptastic-integration-for-ups' ),
			'DDU' => _x( 'Delivery Duty Unpaid', 'ups', 'shiptastic-integration-for-ups' ),
			'DEQ' => _x( 'Delivered Ex Quay', 'ups', 'shiptastic-integration-for-ups' ),
			'DES' => _x( 'Delivered Ex Ship', 'ups', 'shiptastic-integration-for-ups' ),
			'EXW' => _x( 'Ex Works', 'ups', 'shiptastic-integration-for-ups' ),
			'FAS' => _x( 'Free Alongside Ship', 'ups', 'shiptastic-integration-for-ups' ),
			'FCA' => _x( 'Free Carrier', 'ups', 'shiptastic-integration-for-ups' ),
			'FOB' => _x( 'Free On Board', 'ups', 'shiptastic-integration-for-ups' ),
		);
	}

	/**
	 * @param ConfigurationSet $configuration_set
	 *
	 * @return mixed
	 */
	protected function get_label_settings_by_zone( $configuration_set ) {
		$settings = parent::get_label_settings_by_zone( $configuration_set );

		if ( 'return' === $configuration_set->get_shipment_type() ) {
			$settings = array_merge(
				$settings,
				array(
					array(
						'title'   => _x( 'Return Service', 'ups', 'shiptastic-integration-for-ups' ),
						'type'    => 'select',
						'default' => '9',
						'value'   => $configuration_set->get_setting( 'return_service', '9', 'additional' ),
						'id'      => $configuration_set->get_setting_id( 'return_service', 'additional' ),
						'options' => Package::get_return_services(),
						'class'   => 'wc-enhanced-select',
					),
				)
			);
		}

		if ( 'shipping_provider' === $configuration_set->get_setting_type() ) {
			if ( 'int' === $configuration_set->get_zone() ) {
				$settings = array_merge(
					$settings,
					array(
						array(
							'title'    => _x( 'Default Incoterms', 'ups', 'shiptastic-integration-for-ups' ),
							'type'     => 'select',
							'default'  => 'DDP',
							'id'       => 'label_default_incoterms',
							'value'    => $this->get_setting( 'label_default_incoterms', 'DDP' ),
							'desc'     => _x( 'Please select a default incoterms option.', 'ups', 'shiptastic-integration-for-ups' ),
							'desc_tip' => true,
							'options'  => $this->get_available_incoterms(),
							'class'    => 'wc-enhanced-select',
						),
					)
				);
			}
		}

		return $settings;
	}

	/**
	 * @see https://developer.ups.com/api/reference/rating/appendix?loc=en_NL#tabs_1_tabPane_7
	 *
	 * @return void
	 */
	protected function register_products() {
		$base_country  = \Vendidero\Shiptastic\Package::get_base_country();
		$is_eu         = \Vendidero\Shiptastic\Package::country_belongs_to_eu_customs_area( $base_country );
		$general       = array();
		$international = array();
		$domestic      = array();

		$base_available = array(
			'ups_96' => _x( 'UPS Worldwide Express Freight', 'ups', 'shiptastic-integration-for-ups' ),
			'ups_71' => _x( 'UPS Worldwide Express Freight Midday', 'ups', 'shiptastic-integration-for-ups' ),
			'ups_17' => _x( 'UPS Worldwide Economy DDU', 'ups', 'shiptastic-integration-for-ups' ),
			'ups_72' => _x( 'UPS Worldwide Economy DDP', 'ups', 'shiptastic-integration-for-ups' ),
		);

		if ( 'US' === $base_country ) {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'shiptastic-integration-for-ups' ),
			);

			$international = array(
				'ups_07' => _x( 'UPS Worldwide Express', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_08' => _x( 'UPS Worldwide Expedited', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_65' => _x( 'UPS Worldwide Saver', 'ups', 'shiptastic-integration-for-ups' ),
			);

			$domestic = array(
				'ups_02' => _x( 'UPS 2nd Day Air', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_59' => _x( 'UPS 2nd Day Air A.M.', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_12' => _x( 'UPS 3 Day Select', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_03' => _x( 'UPS Ground', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_01' => _x( 'UPS Next Day Air', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_14' => _x( 'UPS Next Day Air Early', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_13' => _x( 'UPS Next Day Air Saver', 'ups', 'shiptastic-integration-for-ups' ),
			);
		} elseif ( 'CA' === $base_country ) {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'shiptastic-integration-for-ups' ),
			);

			$domestic = array(
				'ups_02' => _x( 'UPS Expedited', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_13' => _x( 'UPS Express Saver', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_70' => _x( 'UPS Access Point Economy', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_01' => _x( 'UPS Express', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_14' => _x( 'UPS Express Early', 'ups', 'shiptastic-integration-for-ups' ),
			);

			$international = array(
				'ups_65' => _x( 'UPS Express Saver', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_08' => _x( 'UPS Worldwide Expedited', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_07' => _x( 'UPS Worldwide Express', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'shiptastic-integration-for-ups' ),
			);

			$this->register_product(
				'ups_12',
				array(
					'label'     => _x( 'UPS 3 Day Select', 'ups', 'shiptastic-integration-for-ups' ),
					'countries' => array( 'CA', 'US' ),
				)
			);
		} elseif ( $is_eu ) {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_08' => _x( 'UPS Expedited', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_07' => _x( 'UPS Express', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_65' => _x( 'UPS Worldwide Saver', 'ups', 'shiptastic-integration-for-ups' ),
			);

			$domestic = array(
				'ups_70' => _x( 'UPS Access Point Economy', 'ups', 'shiptastic-integration-for-ups' ),
			);

			if ( 'PL' === $base_country ) {
				$general = array(
					'ups_11' => _x( 'UPS Standard', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_08' => _x( 'UPS Expedited', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_07' => _x( 'UPS Express', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_54' => _x( 'UPS Express Plus', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_65' => _x( 'UPS Express Saver', 'ups', 'shiptastic-integration-for-ups' ),
				);

				$domestic = array(
					'ups_70' => _x( 'UPS Access Point Economy', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_83' => _x( 'UPS Today Dedicated Courrier', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_85' => _x( 'UPS Today Express', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_86' => _x( 'UPS Today Express Saver', 'ups', 'shiptastic-integration-for-ups' ),
					'ups_82' => _x( 'UPS Today Standard', 'ups', 'shiptastic-integration-for-ups' ),
				);
			} elseif ( 'DE' === $base_country ) {
				$domestic['ups_74'] = _x( 'UPS Express 12:00', 'ups', 'shiptastic-integration-for-ups' );
			}
		} else {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_07' => _x( 'UPS Express', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_08' => _x( 'UPS Worldwide Expedited', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'shiptastic-integration-for-ups' ),
				'ups_65' => _x( 'UPS Worldwide Saver', 'ups', 'shiptastic-integration-for-ups' ),
			);
		}

		$non_returnable = array(
			'ups_13',
			'ups_59',
			'ups_82',
			'ups_83',
			'ups_84',
			'ups_85',
			'ups_86',
		);

		foreach ( array_merge_recursive( $general, $base_available ) as $code => $desc ) {
			$this->register_product(
				$code,
				array(
					'label'          => $desc,
					'shipment_types' => in_array( $code, $non_returnable, true ) ? array( 'simple' ) : array( 'simple', 'return' ),
				)
			);
		}

		foreach ( $domestic as $code => $desc ) {
			if ( 'ups_70' === $code ) {
				$product = new AccessPointDelivery(
					$this,
					array(
						'id'             => $code,
						'label'          => $desc,
						'shipment_types' => in_array( $code, $non_returnable, true ) ? array( 'simple' ) : array( 'simple', 'return' ),
						'zones'          => array( 'dom' ),
					)
				);

				$this->register_product( $product );
			} else {
				$this->register_product(
					$code,
					array(
						'label'          => $desc,
						'shipment_types' => in_array( $code, $non_returnable, true ) ? array( 'simple' ) : array( 'simple', 'return' ),
						'zones'          => array( 'dom' ),
					)
				);
			}
		}

		foreach ( $international as $code => $desc ) {
			$this->register_product(
				$code,
				array(
					'label'          => $desc,
					'shipment_types' => in_array( $code, $non_returnable, true ) ? array( 'simple' ) : array( 'simple', 'return' ),
					'zones'          => array( 'int' ),
				)
			);
		}
	}

	protected function get_general_settings() {
		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'ups_api_options',
			),

			array(
				'title' => _x( 'Account number', 'ups', 'shiptastic-integration-for-ups' ),
				'type'  => 'text',
				'desc'  => '',
				'id'    => 'api_account_number',
				'value' => $this->get_setting( 'api_account_number', '' ),
			),

			array(
				'title' => _x( 'Sandbox mode', 'ups', 'shiptastic-integration-for-ups' ),
				'desc'  => _x( 'Activate Sandbox mode for testing purposes.', 'ups', 'shiptastic-integration-for-ups' ),
				'id'    => 'sandbox_mode',
				'value' => wc_bool_to_string( $this->get_setting( 'sandbox_mode', 'no' ) ),
				'type'  => 'shiptastic_toggle',
			),
		);

		if ( ! Package::use_custom_api() ) {
			$settings = array_merge(
				$settings,
				array(
					array(
						'title'    => _x( 'OAuth', 'ups', 'shiptastic-integration-for-ups' ),
						'type'     => 'shiptastic_oauth',
						'desc'     => '',
						'api_type' => 'ups',
					),
				)
			);
		} else {
			$settings = array_merge(
				$settings,
				array(
					array(
						'title'             => _x( 'Client ID', 'ups', 'shiptastic-integration-for-ups' ),
						'type'              => 'text',
						'id'                => 'api_username',
						'default'           => '',
						'desc'              => '<div class="wc-shiptastic-additional-desc">' . sprintf( _x( 'You\'ll need to <a href="%s">register an app</a> within the UPS Developer Portal to retrieve your Client ID and Secret and connect with the API.', 'ups', 'shiptastic-integration-for-ups' ), 'https://developer.ups.com/' ) . '</div>',
						'value'             => $this->get_setting( 'api_username', '' ),
						'custom_attributes' => array(
							'autocomplete' => 'new-password',
						),
					),

					array(
						'title'             => _x( 'Client Secret', 'ups', 'shiptastic-integration-for-ups' ),
						'type'              => 'password',
						'desc'              => '',
						'id'                => 'api_password',
						'value'             => $this->get_setting( 'api_password', '' ),
						'custom_attributes' => array(
							'autocomplete' => 'new-password',
						),
					),
				)
			);
		}

		$settings = array_merge(
			$settings,
			array(
				array(
					'type' => 'sectionend',
					'id'   => 'ups_api_options',
				),
			)
		);

		$settings = array_merge(
			$settings,
			array(
				array(
					'title' => _x( 'Tracking', 'ups', 'shiptastic-integration-for-ups' ),
					'type'  => 'title',
					'id'    => 'tracking_options',
				),
			)
		);

		$general_settings = parent::get_general_settings();

		return array_merge( $settings, $general_settings );
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return string
	 */
	protected function get_incoterms( $shipment ) {
		$incoterms = $this->get_setting( 'label_default_incoterms', 'DDP' );

		if ( ! empty( $shipment->get_incoterms() ) ) {
			if ( in_array( $shipment->get_incoterms(), array_keys( $this->get_available_incoterms() ), true ) ) {
				$incoterms = $shipment->get_incoterms();
			}
		}

		return $incoterms;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 */
	public function get_default_return_label_service( $shipment ) {
		$service   = '9';
		$available = Package::get_return_services();

		if ( $config_set = $shipment->get_label_configuration_set() ) {
			$service = $config_set->get_setting( 'return_service', $service, 'additional' );
		}

		if ( ! array_key_exists( $service, $available ) && ! empty( $available ) ) {
			$service = '9';
		}

		return $service;
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_default_label_props( $shipment ) {
		$defaults = parent::get_default_label_props( $shipment );
		$defaults = wp_parse_args(
			$defaults,
			array(
				'product_id' => '',
				'services'   => array(),
			)
		);

		if ( $shipment->is_shipping_international() ) {
			$defaults['incoterms'] = $this->get_incoterms( $shipment );
		}

		if ( 'return' === $shipment->get_type() ) {
			$defaults['return_service'] = $this->get_default_return_label_service( $shipment );
		}

		return $defaults;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_simple_label_fields( $shipment ) {
		$settings     = parent::get_simple_label_fields( $shipment );
		$default_args = $this->get_default_label_props( $shipment );

		if ( $shipment->is_shipping_international() ) {
			$settings = array_merge(
				$settings,
				array(
					array(
						'id'          => 'incoterms',
						'label'       => _x( 'Incoterms', 'ups', 'shiptastic-integration-for-ups' ),
						'description' => '',
						'value'       => isset( $default_args['incoterms'] ) ? $default_args['incoterms'] : '',
						'options'     => $this->get_available_incoterms(),
						'type'        => 'select',
					),
				)
			);
		}

		return $settings;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_return_label_fields( $shipment ) {
		$settings     = parent::get_return_label_fields( $shipment );
		$default_args = $this->get_default_label_props( $shipment );

		$settings = array_merge(
			$settings,
			array(
				array(
					'id'          => 'return_service',
					'label'       => _x( 'Return Service', 'ups', 'shiptastic-integration-for-ups' ),
					'description' => '',
					'value'       => isset( $default_args['return_service'] ) ? $default_args['return_service'] : '',
					'options'     => Package::get_return_services(),
					'type'        => 'select',
				),
			)
		);

		if ( $shipment->is_shipping_international() ) {
			$settings = array_merge(
				$settings,
				array(
					array(
						'id'          => 'incoterms',
						'label'       => _x( 'Incoterms', 'ups', 'shiptastic-integration-for-ups' ),
						'description' => '',
						'value'       => isset( $default_args['incoterms'] ) ? $default_args['incoterms'] : '',
						'options'     => $this->get_available_incoterms(),
						'type'        => 'select',
					),
				)
			);
		}

		return $settings;
	}

	public function get_help_link() {
		return 'https://vendidero.de/doc/woocommerce-germanized/ups-integration-einrichten';
	}

	public function get_signup_link() {
		return '';
	}

	public function test_connection() {
		return Package::get_api()->test_connection();
	}

	public function supports_pickup_locations() {
		return true;
	}

	public function supports_pickup_location_delivery( $address, $query_args = array() ) {
		if ( ! $this->enable_pickup_location_delivery() ) {
			return false;
		}

		$query_args = $this->parse_pickup_location_query_args( $query_args );
		$address    = $this->parse_pickup_location_address_args( $address );
		$excluded   = PickupDelivery::get_excluded_gateways();
		$max_weight = wc_get_weight( 20, wc_stc_get_packaging_dimension_unit(), 'kg' );

		$supports = ! in_array( $query_args['payment_gateway'], $excluded, true ) && $query_args['max_weight'] <= $max_weight;

		return $supports;
	}

	public function replace_shipping_address_by_pickup_location() {
		return false;
	}

	protected function fetch_single_pickup_location( $location_code, $address = array() ) {
		$address       = $this->get_address_by_pickup_location_code( $location_code, $address );
		$location_code = $this->parse_pickup_location_code( $location_code );

		if ( empty( $location_code ) ) {
			return false;
		}

		try {
			$result          = Package::get_api()->find_access_point_by_id( $location_code, $address );
			$pickup_location = $this->get_pickup_location_from_api_response( $result );
		} catch ( \Exception $e ) {
			$pickup_location = null;

			if ( 404 === $e->getCode() ) {
				$pickup_location = false;
			}
		}

		return $pickup_location;
	}

	protected function parse_pickup_location_code( $location_code ) {
		$location_code = parent::parse_pickup_location_code( $location_code );
		$keyword_id    = preg_replace( '/[^a-zA-Z0-9]/', '', $location_code );

		return $keyword_id;
	}

	protected function get_pickup_location_from_api_response( $location ) {
		$address = wp_parse_args(
			$location['AddressKeyFormat'],
			array(
				'ConsigneeName'      => '',
				'CountryCode'        => '',
				'PostcodePrimaryLow' => '',
				'AddressLine'        => '',
				'PoliticalDivision2' => '',
				'PoliticalDivision1' => '',
			)
		);

		return $this->get_pickup_location_instance(
			array(
				'code'                     => isset( $location['AccessPointInformation'] ) ? $location['AccessPointInformation']['PublicAccessPointID'] : $location['LocationID'],
				'label'                    => $location['AddressKeyFormat']['ConsigneeName'],
				'latitude'                 => $location['Geocode']['Latitude'],
				'longitude'                => $location['Geocode']['Longitude'],
				'supports_customer_number' => false,
				'address'                  => array(
					'company'   => $address['ConsigneeName'],
					'address_1' => $address['AddressLine'],
					'postcode'  => $address['PostcodePrimaryLow'],
					'city'      => $address['PoliticalDivision2'],
					'state'     => $address['PoliticalDivision1'],
					'country'   => $address['CountryCode'],
				),
				'address_replacement_map'  => array(),
			)
		);
	}

	protected function fetch_pickup_locations( $address, $query_args = array() ) {
		$locations     = array();
		$location_data = Package::get_api()->find_access_points( $address, $query_args['limit'] );

		foreach ( $location_data as $location ) {
			if ( $pickup_location = $this->get_pickup_location_from_api_response( $location ) ) {
				$locations[] = $pickup_location;
			}
		}

		return $locations;
	}
}
