<?php
/**
 * ShippingProvider impl.
 *
 * @package WooCommerce/Blocks
 */
namespace Vendidero\Shiptastic\UPS\ShippingProvider;

use Vendidero\Shiptastic\Labels\ConfigurationSet;
use Vendidero\Shiptastic\UPS\Package;
use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\ShippingProvider\Auto;
use Vendidero\Shiptastic\UPS\ShippingProvider\Services\Notification;

defined( 'ABSPATH' ) || exit;

class UPS extends Auto {

	public function get_title( $context = 'view' ) {
		return _x( 'UPS', 'ups', 'ups-for-shiptastic' );
	}

	public function get_name( $context = 'view' ) {
		return 'ups';
	}

	public function get_description( $context = 'view' ) {
		return _x( 'Create UPS labels and return labels conveniently.', 'ups', 'ups-for-shiptastic' );
	}

	public function get_default_tracking_url_placeholder() {
		return 'https://wwwapps.ups.com/tracking/tracking.cgi?tracknum={tracking_id}&loc=de_DE';
	}

	public function is_sandbox() {
		return true;
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
				'label'      => _x( 'Reference 1', 'ups', 'ups-for-shiptastic' ),
				'default'    => 'return' === $shipment_type ? _x( 'Return #{shipment_number}, order {order_number}', 'ups', 'ups-for-shiptastic' ) : _x( '#{shipment_number}, order {order_number}', 'ups', 'ups-for-shiptastic' ),
				'max_length' => 35,
			),
			'ref_2' => array(
				'label'      => _x( 'Reference 2', 'ups', 'ups-for-shiptastic' ),
				'default'    => '',
				'max_length' => 35,
			),
		);

		return $reference_types;
	}

	public function get_available_incoterms() {
		return array(
			'CFR' => _x( 'Cost and Freight', 'ups', 'ups-for-shiptastic' ),
			'CIF' => _x( 'Cost Insurance and Freight', 'ups', 'ups-for-shiptastic' ),
			'CIP' => _x( 'Carriage and Insurance Paid', 'ups', 'ups-for-shiptastic' ),
			'CPT' => _x( 'Carriage Paid To', 'ups', 'ups-for-shiptastic' ),
			'DAF' => _x( 'Delivered at Frontier', 'ups', 'ups-for-shiptastic' ),
			'DDP' => _x( 'Delivery Duty Paid', 'ups', 'ups-for-shiptastic' ),
			'DDU' => _x( 'Delivery Duty Unpaid', 'ups', 'ups-for-shiptastic' ),
			'DEQ' => _x( 'Delivered Ex Quay', 'ups', 'ups-for-shiptastic' ),
			'DES' => _x( 'Delivered Ex Ship', 'ups', 'ups-for-shiptastic' ),
			'EXW' => _x( 'Ex Works', 'ups', 'ups-for-shiptastic' ),
			'FAS' => _x( 'Free Alongside Ship', 'ups', 'ups-for-shiptastic' ),
			'FCA' => _x( 'Free Carrier', 'ups', 'ups-for-shiptastic' ),
			'FOB' => _x( 'Free On Board', 'ups', 'ups-for-shiptastic' ),
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
						'title'   => _x( 'Return Service', 'ups', 'ups-for-shiptastic' ),
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
							'title'    => _x( 'Default Incoterms', 'ups', 'ups-for-shiptastic' ),
							'type'     => 'select',
							'default'  => 'DDP',
							'id'       => 'label_default_incoterms',
							'value'    => $this->get_setting( 'label_default_incoterms', 'DDP' ),
							'desc'     => _x( 'Please select a default incoterms option.', 'ups', 'ups-for-shiptastic' ),
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
			'ups_96' => _x( 'UPS Worldwide Express Freight', 'ups', 'ups-for-shiptastic' ),
			'ups_71' => _x( 'UPS Worldwide Express Freight Midday', 'ups', 'ups-for-shiptastic' ),
			'ups_17' => _x( 'UPS Worldwide Economy DDU', 'ups', 'ups-for-shiptastic' ),
			'ups_72' => _x( 'UPS Worldwide Economy DDP', 'ups', 'ups-for-shiptastic' ),
		);

		if ( 'US' === $base_country ) {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'ups-for-shiptastic' ),
			);

			$international = array(
				'ups_07' => _x( 'UPS Worldwide Express', 'ups', 'ups-for-shiptastic' ),
				'ups_08' => _x( 'UPS Worldwide Expedited', 'ups', 'ups-for-shiptastic' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'ups-for-shiptastic' ),
				'ups_65' => _x( 'UPS Worldwide Saver', 'ups', 'ups-for-shiptastic' ),
			);

			$domestic = array(
				'ups_02' => _x( 'UPS 2nd Day Air', 'ups', 'ups-for-shiptastic' ),
				'ups_59' => _x( 'UPS 2nd Day Air A.M.', 'ups', 'ups-for-shiptastic' ),
				'ups_12' => _x( 'UPS 3 Day Select', 'ups', 'ups-for-shiptastic' ),
				'ups_03' => _x( 'UPS Ground', 'ups', 'ups-for-shiptastic' ),
				'ups_01' => _x( 'UPS Next Day Air', 'ups', 'ups-for-shiptastic' ),
				'ups_14' => _x( 'UPS Next Day Air Early', 'ups', 'ups-for-shiptastic' ),
				'ups_13' => _x( 'UPS Next Day Air Saver', 'ups', 'ups-for-shiptastic' ),
			);
		} elseif ( 'CA' === $base_country ) {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'ups-for-shiptastic' ),
			);

			$domestic = array(
				'ups_02' => _x( 'UPS Expedited', 'ups', 'ups-for-shiptastic' ),
				'ups_13' => _x( 'UPS Express Saver', 'ups', 'ups-for-shiptastic' ),
				'ups_70' => _x( 'UPS Access Point Economy', 'ups', 'ups-for-shiptastic' ),
				'ups_01' => _x( 'UPS Express', 'ups', 'ups-for-shiptastic' ),
				'ups_14' => _x( 'UPS Express Early', 'ups', 'ups-for-shiptastic' ),
			);

			$international = array(
				'ups_65' => _x( 'UPS Express Saver', 'ups', 'ups-for-shiptastic' ),
				'ups_08' => _x( 'UPS Worldwide Expedited', 'ups', 'ups-for-shiptastic' ),
				'ups_07' => _x( 'UPS Worldwide Express', 'ups', 'ups-for-shiptastic' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'ups-for-shiptastic' ),
			);

			$this->register_product(
				'ups_12',
				array(
					'label'     => _x( 'UPS 3 Day Select', 'ups', 'ups-for-shiptastic' ),
					'countries' => array( 'CA', 'US' ),
				)
			);
		} elseif ( $is_eu ) {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'ups-for-shiptastic' ),
				'ups_08' => _x( 'UPS Expedited', 'ups', 'ups-for-shiptastic' ),
				'ups_07' => _x( 'UPS Express', 'ups', 'ups-for-shiptastic' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'ups-for-shiptastic' ),
				'ups_65' => _x( 'UPS Worldwide Saver', 'ups', 'ups-for-shiptastic' ),
			);

			$domestic = array(
				'ups_70' => _x( 'UPS Access Point Economy', 'ups', 'ups-for-shiptastic' ),
			);

			if ( 'PL' === $base_country ) {
				$general = array(
					'ups_11' => _x( 'UPS Standard', 'ups', 'ups-for-shiptastic' ),
					'ups_08' => _x( 'UPS Expedited', 'ups', 'ups-for-shiptastic' ),
					'ups_07' => _x( 'UPS Express', 'ups', 'ups-for-shiptastic' ),
					'ups_54' => _x( 'UPS Express Plus', 'ups', 'ups-for-shiptastic' ),
					'ups_65' => _x( 'UPS Express Saver', 'ups', 'ups-for-shiptastic' ),
				);

				$domestic = array(
					'ups_70' => _x( 'UPS Access Point Economy', 'ups', 'ups-for-shiptastic' ),
					'ups_83' => _x( 'UPS Today Dedicated Courrier', 'ups', 'ups-for-shiptastic' ),
					'ups_85' => _x( 'UPS Today Express', 'ups', 'ups-for-shiptastic' ),
					'ups_86' => _x( 'UPS Today Express Saver', 'ups', 'ups-for-shiptastic' ),
					'ups_82' => _x( 'UPS Today Standard', 'ups', 'ups-for-shiptastic' ),
				);
			} elseif ( 'DE' === $base_country ) {
				$domestic['ups_74'] = _x( 'UPS Express 12:00', 'ups', 'ups-for-shiptastic' );
			}
		} else {
			$general = array(
				'ups_11' => _x( 'UPS Standard', 'ups', 'ups-for-shiptastic' ),
				'ups_07' => _x( 'UPS Express', 'ups', 'ups-for-shiptastic' ),
				'ups_08' => _x( 'UPS Worldwide Expedited', 'ups', 'ups-for-shiptastic' ),
				'ups_54' => _x( 'UPS Worldwide Express Plus', 'ups', 'ups-for-shiptastic' ),
				'ups_65' => _x( 'UPS Worldwide Saver', 'ups', 'ups-for-shiptastic' ),
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

		foreach ( $domestic as $code => $desc ) {
			$this->register_product(
				$code,
				array(
					'label'          => $desc,
					'shipment_types' => in_array( $code, $non_returnable, true ) ? array( 'simple' ) : array( 'simple', 'return' ),
					'zones'          => array( 'dom' ),
				)
			);
		}

		foreach ( array_merge_recursive( $general, $base_available ) as $code => $desc ) {
			$this->register_product(
				$code,
				array(
					'label'          => $desc,
					'shipment_types' => in_array( $code, $non_returnable, true ) ? array( 'simple' ) : array( 'simple', 'return' ),
				)
			);
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
				'title'             => _x( 'Client ID', 'ups', 'ups-for-shiptastic' ),
				'type'              => 'text',
				'id'                => 'api_username',
				'default'           => '',
				'desc'              => '<div class="wc-shiptastic-additional-desc">' . sprintf( _x( 'You\'ll need to register an app within the UPS Developer Portal to retrieve your Client ID and Secret and connect with the API. <a href="%s">Learn more</a> in our docs.', 'ups', 'ups-for-shiptastic' ), '' ) . '</div>',
				'value'             => $this->get_setting( 'api_username', '' ),
				'custom_attributes' => array(
					'autocomplete' => 'new-password',
				),
			),

			array(
				'title'             => _x( 'Client Secret', 'ups', 'ups-for-shiptastic' ),
				'type'              => 'password',
				'desc'              => '',
				'id'                => 'api_password',
				'value'             => $this->get_setting( 'api_password', '' ),
				'custom_attributes' => array(
					'autocomplete' => 'new-password',
				),
			),

			array(
				'title' => _x( 'Account number', 'ups', 'ups-for-shiptastic' ),
				'type'  => 'text',
				'desc'  => '',
				'id'    => 'api_account_number',
				'value' => $this->get_setting( 'api_account_number', '' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'ups_api_options',
			),
		);

		$settings = array_merge(
			$settings,
			array(
				array(
					'title' => _x( 'Tracking', 'ups', 'ups-for-shiptastic' ),
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
						'label'       => _x( 'Incoterms', 'ups', 'ups-for-shiptastic' ),
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
					'label'       => _x( 'Return Service', 'ups', 'ups-for-shiptastic' ),
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
						'label'       => _x( 'Incoterms', 'ups', 'ups-for-shiptastic' ),
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
		return 'https://vendidero.de/dokument/ups-integration-einrichten';
	}

	public function get_signup_link() {
		return '';
	}

	public function test_connection() {
		$username = Package::get_api_username();

		if ( empty( $username ) ) {
			return null;
		}

		return Package::get_api()->test_connection();
	}
}
