<?php
/**
 * ShippingProvider impl.
 *
 * @package WooCommerce/Blocks
 */
namespace Vendidero\Germanized\UPS\ShippingProvider;

use Vendidero\Germanized\UPS\Package;
use Vendidero\Germanized\Shipments\Shipment;
use Vendidero\Germanized\Shipments\ShippingProvider\Auto;

defined( 'ABSPATH' ) || exit;

class UPS extends Auto {

	protected function get_default_label_minimum_shipment_weight() {
		return 0.01;
	}

	protected function get_default_label_default_shipment_weight() {
		return 0.5;
	}

	public function get_title( $context = 'view' ) {
		return _x( 'UPS', 'ups', 'woocommerce-germanized-ups' );
	}

	public function get_name( $context = 'view' ) {
		return 'ups';
	}

	public function get_description( $context = 'view' ) {
		return _x( 'Create UPS labels and return labels conveniently.', 'ups', 'woocommerce-germanized-ups' );
	}

	public function get_default_tracking_url_placeholder() {
		return 'https://wwwapps.ups.com/tracking/tracking.cgi?tracknum={tracking_id}&loc=de_DE';
	}

	public function is_sandbox() {
		return true;
	}

	public function get_label_classname( $type ) {
		if ( 'return' === $type ) {
			return '\Vendidero\Germanized\UPS\Label\Retoure';
		} else {
			return '\Vendidero\Germanized\UPS\Label\Simple';
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

	public function get_api_username( $context = 'view' ) {
		return $this->get_meta( 'api_username', true, $context );
	}

	public function set_api_username( $username ) {
		$this->update_meta_data( 'api_username', $username );
	}

	public function get_setting_sections() {
		$sections = parent::get_setting_sections();

		return $sections;
	}

	/**
	 * @param \Vendidero\Germanized\Shipments\Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_return_label_fields( $shipment ) {
		$default_args = $this->get_default_label_props( $shipment );
		$default      = $this->get_default_label_product( $shipment );
		$available    = $this->get_available_label_products( $shipment );

		$settings = array(
			array(
				'id'          => 'product_id',
				'label'       => sprintf( _x( '%s Product', 'shipments', 'woocommerce-germanized-shipments' ), $this->get_title() ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'description' => '',
				'options'     => $this->get_available_label_products( $shipment ),
				'value'       => $default && array_key_exists( $default, $available ) ? $default : '',
				'type'        => 'select',
			),
		);

		return $settings;
	}

	/**
	 * @param \Vendidero\Germanized\Shipments\Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_simple_label_fields( $shipment ) {
		$settings     = parent::get_simple_label_fields( $shipment );
		$default_args = $this->get_default_label_props( $shipment );
		$services     = array();

		if ( ! empty( $services ) ) {
			$settings[] = array(
				'type'         => 'services_start',
				'id'           => '',
				'hide_default' => ! empty( $default_args['services'] ) ? false : true,
			);

			$settings = array_merge( $settings, $services );
		}

		return $settings;
	}

	/**
	 * @param Shipment $shipment
	 * @param $props
	 *
	 * @return \WP_Error|mixed
	 */
	protected function validate_label_request( $shipment, $args = array() ) {
		if ( 'return' === $shipment->get_type() ) {
			$args = $this->validate_return_label_args( $shipment, $args );
		} else {
			$args = $this->validate_simple_label_args( $shipment, $args );
		}

		return $args;
	}

	/**
	 * @param Shipment $shipment
	 * @param $args
	 *
	 * @return \WP_Error|mixed
	 */
	protected function validate_return_label_args( $shipment, $args = array() ) {
		return $args;
	}

	/**
	 * @param Shipment $shipment
	 * @param $args
	 *
	 * @return \WP_Error|mixed
	 */
	protected function validate_simple_label_args( $shipment, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id'    => '',
				'services'      => array(),
			)
		);

		$error = new \WP_Error();

		// Do only allow valid services
		if ( ! empty( $args['services'] ) ) {
			$args['services'] = array_intersect( $args['services'], $this->get_available_label_services( $shipment ) );
			$args['services'] = array_values( $args['services'] );
		}

		if ( wc_gzd_shipment_wp_error_has_errors( $error ) ) {
			return $error;
		}

		return $args;
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_default_label_props( $shipment ) {
		if ( 'return' === $shipment->get_type() ) {
			$gls_defaults = $this->get_default_return_label_props( $shipment );
		} else {
			$gls_defaults = $this->get_default_simple_label_props( $shipment );
		}

		$defaults = parent::get_default_label_props( $shipment );
		$defaults = array_replace_recursive( $defaults, $gls_defaults );

		return $defaults;
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_default_return_label_props( $shipment ) {
		$product_id = $this->get_default_label_product( $shipment );

		$defaults = array(
			'services' => array(),
		);

		return $defaults;
	}

	/**
	 * @param \Vendidero\Germanized\Shipments\Shipment $shipment
	 */
	public function get_default_label_product( $shipment ) {
		return '';
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return array
	 */
	protected function get_default_simple_label_props( $shipment ) {
		$product_id = $this->get_default_label_product( $shipment );

		$defaults = array(
			'services'      => array(),
			'shipping_date' => '',
		);

		return $defaults;
	}

	/**
	 * @param \Vendidero\Germanized\Shipments\Shipment $shipment
	 */
	public function get_available_label_products( $shipment ) {
		$is_return = $shipment->get_type() === 'return';

		return array();
	}

	/**
	 * @param \Vendidero\Germanized\Shipments\Shipment $shipment
	 */
	public function get_available_label_services( $shipment ) {
		return array();
	}

	protected function get_general_settings( $for_shipping_method = false ) {
		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'ups_api_options',
			),

			array(
				'title'   => _x( 'Access Key', 'ups', 'woocommerce-germanized-ups' ),
				'type'    => 'password',
				'desc'    => '<div class="wc-gzd-additional-desc">' . sprintf( _x( 'To access the UPS API you\'ll need to <a href="%1$s">apply for an access key</a>.', 'gls', 'woocommerce-germanized-gls' ), '' ) . '</div>',
				'id'      => 'api_access_password',
				'default' => '',
				'value'   => $this->get_setting( 'api_access_password', '' ),
				'custom_attributes' => array(
					'autocomplete' => 'new-password',
				),
			),

			array(
				'title'             => _x( 'Username', 'ups', 'woocommerce-germanized-ups' ),
				'type'              => 'text',
				'id'                => 'api_username',
				'default'           => '',
				'value'             => $this->get_setting( 'api_username', '' ),
				'custom_attributes' => array(
					'autocomplete' => 'new-password',
				),
			),

			array(
				'title'             => _x( 'Password', 'ups', 'woocommerce-germanized-ups' ),
				'type'              => 'password',
				'desc'              => '',
				'id'                => 'api_password',
				'value'             => $this->get_setting( 'api_password', '' ),
				'custom_attributes' => array(
					'autocomplete' => 'new-password',
				),
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
					'title' => _x( 'Tracking', 'ups', 'woocommerce-germanized-ups' ),
					'type'  => 'title',
					'id'    => 'tracking_options',
				),
			)
		);

		$general_settings = parent::get_general_settings( $for_shipping_method );

		return array_merge( $settings, $general_settings );
	}

	protected function get_label_settings( $for_shipping_method = false ) {
		return array();
	}

	public function get_help_link() {
		return 'https://vendidero.de/dokument/ups-integration-einrichten';
	}

	public function get_signup_link() {
		return '';
	}
}
