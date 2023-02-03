<?php

namespace Vendidero\Germanized\UPS\Api;

use Vendidero\Germanized\Shipments\ShipmentError;
use Vendidero\Germanized\UPS\Label\Retoure;
use Vendidero\Germanized\UPS\Label\Simple;
use Vendidero\Germanized\UPS\Package;
use Vendidero\Germanized\Shipments\Shipment;

defined( 'ABSPATH' ) || exit;

/**
 * UPS RESTful API
 */
class Api {
	const DEV_ENVIRONMENT  = 0;
	const PROD_ENVIRONMENT = 1;

	/** @var Api */
	private static $instance;

	/** @var int */
	protected static $environment = self::DEV_ENVIRONMENT;

	/**
	 * @return Api
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Set API environment to development version
	 */
	public static function dev() {
		self::$environment = self::DEV_ENVIRONMENT;
	}

	/**
	 * Set API environment to production version
	 */
	public static function prod() {
		self::$environment = self::PROD_ENVIRONMENT;
	}

	public static function is_sandbox() {
		return self::DEV_ENVIRONMENT === self::$environment;
	}

	/**
	 * @param string $ref_text
	 * @param Shipment $shipment
	 * @param int $max_length
	 *
	 * @return string
	 */
	protected function get_reference( $shipment, $max_length = 50, $ref_text = '' ) {
		if ( '' === $ref_text ) {
			$ref_text = apply_filters( 'woocommerce_gzd_gls_label_api_reference', _x( 'Shipment {shipment_id}', 'gls', 'woocommerce-germanized-gls' ), $shipment );
		}

		return mb_strcut( str_replace( array( '{shipment_id}', '{order_id}' ), array( $shipment->get_shipment_number(), $shipment->get_order_number() ), $ref_text ), 0, $max_length );
	}

	/**
	 * @param Simple|Retoure $label
	 *
	 * @return \WP_Error|true
	 */
	public function cancel_label( $label ) {
		if ( $label->get_number() ) {
			$response = $this->post( 'shipments/cancel/' . $label->get_number() );

			if ( is_wp_error( $response ) ) {
				return $response;
			} elseif ( isset( $response['body']['VoidShipmentResponse']['Response']['ResponseStatus']['Code'] ) && 1 === absint( $response['body']['VoidShipmentResponse']['Response']['ResponseStatus']['Code'] ) ) {
				return true;
			}
		}

		return new \WP_Error( 'ups_error', _x( 'There was an error while cancelling the label', 'ups', 'woocommerce-germanized-ups' ) );
	}

	protected function limit_length( $string, $max_length = -1 ) {
		if ( $max_length > 0 ) {
			if ( function_exists( 'mb_strcut' ) ) {
				/**
				 * mb_substr does not cut to the exact point in case of umlauts etc.
				 * e.g. returns 33 chars instead of 30 in case last latter is ü.
				 */
				$string = mb_strcut( $string, 0, $max_length );
			} else {
				$string = mb_substr( $string, 0, $max_length );
			}
		}

		return $string;
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return string
	 */
	protected function get_dimension_unit( $shipment ) {
		$shipper_country = $shipment->get_sender_country();
		$unit            = 'CM';

		if ( in_array( $shipper_country, array( 'US', ), true ) ) {
			$unit = 'IN';
		}

		return apply_filters( 'woocommerce_gzd_ups_shipment_dimension_unit', $unit, $shipment );
	}

	/**
	 * Round dimensions up.
	 *
	 * @param string $dimension
	 * @param Shipment $shipment
	 *
	 * @return string
	 */
	protected function get_dimension( $dimension, $shipment ) {
		$label_unit = 'cm';
		$ups_unit   = strtolower( $this->get_dimension_unit( $shipment ) );

		if ( $label_unit !== $ups_unit ) {
			$dimension = wc_get_dimension( $dimension, $ups_unit, $label_unit );
		}

		return apply_filters( 'woocommerce_gzd_ups_shipment_dimension', ceil( $dimension ), $shipment );
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return string
	 */
	protected function get_weight_unit( $shipment ) {
		$shipper_country = $shipment->get_sender_country();
		$unit            = 'KGS';

		if ( in_array( $shipper_country, array( 'US' ), true ) ) {
			$unit = 'LBS';
		}

		return apply_filters( 'woocommerce_gzd_ups_shipment_weight_unit', $unit, $shipment );
	}

	/**
	 * Round weight up to .5.
	 *
	 * @param string $weight
	 * @param Shipment $shipment
	 *
	 * @return string
	 */
	protected function get_weight( $weight, $shipment ) {
		$label_unit = 'kg';
		$ups_unit   = strtolower( $this->get_weight_unit( $shipment ) );

		if ( $label_unit !== $ups_unit ) {
			$weight = wc_get_weight( $weight, $ups_unit, $label_unit );
		}

		return apply_filters( 'woocommerce_gzd_ups_shipment_dimension', ceil((float) $weight * 2 ) / 2, $shipment );
	}

	protected function format_phone_number( $phone_number ) {
		$number_plain = preg_replace( '/[^0-9]/', '', $phone_number );

		return $number_plain;
	}

	/**
	 * @param Simple|Retoure $label
	 *
	 * @return \WP_Error|true
	 */
	public function get_label( $label ) {
		$shipment                      = $label->get_shipment();
		$provider                      = $shipment->get_shipping_provider_instance();
		$is_return                     = 'return' === $label->get_type();
		$services                      = $label->get_services();
		$phone_is_required             = ! $shipment->is_shipping_domestic();
		$email_is_required             = false;

		$shipper = array(
			'Name'                    => $this->limit_length( $shipment->get_sender_company() ? $shipment->get_sender_company() : $shipment->get_formatted_sender_full_name(), 35 ),
			'AttentionName'           => $this->limit_length( $shipment->get_formatted_sender_full_name(), 35 ),
			'TaxIdentificationNumber' => $this->limit_length( $shipment->get_sender_customs_reference_number(), 15 ),
			'EMailAddress'            => $this->limit_length( $shipment->get_sender_email(), 50 ),
			'ShipperNumber'           => Package::get_account_number(),
			'Address'                 => array(
				'AddressLine' => $shipment->get_sender_address_1() . ( $shipment->get_sender_address_2() ? "\n" . $shipment->get_sender_address_2() : "" ),
				'City'        => $shipment->get_sender_city(),
				'PostalCode'  => $shipment->get_sender_postcode(),
				'StateProvinceCode' => $shipment->get_sender_state(),
				'CountryCode'       => $shipment->get_sender_country(),
			),
		);

		if ( ! empty( $shipment->get_sender_phone() ) ) {
			$shipper['Phone'] = array(
				'Number' => $this->limit_length( $this->format_phone_number( $shipment->get_sender_phone() ), 15 )
			);
		}

		$ship_to = array(
			'Name'                    => $this->limit_length( $shipment->get_company() ? $shipment->get_company() : $shipment->get_formatted_full_name(), 35 ),
			'AttentionName'           => $this->limit_length( $shipment->get_formatted_full_name(), 35 ),
			'TaxIdentificationNumber' => $this->limit_length( $shipment->get_customs_reference_number(), 15 ),
			'Address'                 => array(
				'AddressLine' => $shipment->get_address_1() . ( $shipment->get_address_2() ? "\n" . $shipment->get_address_2() : "" ),
				'City'        => $shipment->get_city(),
				'PostalCode'  => $shipment->get_postcode(),
				'StateProvinceCode' => $shipment->get_state(),
				'CountryCode'       => $shipment->get_country(),
			),
		);

		if ( empty( $shipment->get_company() ) ) {
			$ship_to['ResidentialAddressIndicator'] = 'yes';
		}

		if ( $phone_is_required || ( apply_filters( 'woocommerce_gzd_ups_label_api_transmit_customer_phone', false ) && $shipment->get_phone() ) ) {
			$ship_to['Phone'] = array(
				'Number' => $this->limit_length( $this->format_phone_number( $shipment->get_phone() ), 15 )
			);
		}

		if ( $email_is_required || ( apply_filters( 'woocommerce_gzd_ups_label_api_transmit_customer_email', false ) && $shipment->get_email() ) ) {
			$ship_to['EMailAddress'] = $shipment->get_email();
		}

		$request = array(
			'ShipmentRequest' => array(
				'Shipment' => array(
					'Description' => ' ',
					'Shipper' => $shipper,
					'ShipTo' => $ship_to,
					'PaymentInformation' => array(
						'ShipmentCharge' => array(
							'Type' => '01',
							'BillShipper' => array(
								'AccountNumber' => Package::get_account_number()
							),
						)
					),
					'Service' => array(
						'Code' => '11',
						'Description' => 'UPS Standard'
					),
					'Package' => array(
						// Detailed item description
						'Description' => ' ',
						'Packaging' => array(
							'Code' => '02',
						),
						'Dimensions' => array(
							'UnitOfMeasurement' => array(
								'Code' => $this->get_dimension_unit( $shipment ),
							),
							'Length' => $this->get_dimension( $label->get_length(), $shipment ),
							'Width'  => $this->get_dimension( $label->get_width(), $shipment ),
							'Height' => $this->get_dimension( $label->get_height(), $shipment )
						),
						'PackageWeight' => array(
							'UnitOfMeasurement' => array(
								'Code' => $this->get_weight_unit( $shipment ),
							),
							'Weight' => $this->get_weight( $label->get_weight(), $shipment ),
						)
					),
					'LabelSpecification' => array(
						'LabelImageFormat' => array(
							'Code' => 'GIF',
						)
					),
					'HTTPUserAgent' => 'Mozilla/4.5'
				),
			),
		);

		$request  = $this->clean_request( $request );
		$response = $this->post( 'shipments', apply_filters( 'woocommerce_gzd_ups_label_api_request', $request, $label ) );

		if ( ! is_wp_error( $response ) ) {
			$error         = new ShipmentError();
			$parcel_data   = $response['body']['ShipmentResponse'];

			if ( 1 !== absint( $parcel_data['Response']['ResponseStatus']['Code'] ) ) {
				$error->add( 'error', _x( 'There was an unkown error calling the UPS API.', 'ups', 'woocommerce-germanized-ups' ) );
				return $error;
			}

			$shipment_references = $parcel_data['ShipmentResults'];
			$charges             = wc_clean( $shipment_references['ShipmentCharges']['TotalCharges']['MonetaryValue'] );
			$tracking_id         = wc_clean( $shipment_references['ShipmentIdentificationNumber'] );
			$package_results     = $shipment_references['PackageResults'];

			$label->set_number( $tracking_id );

			if ( isset( $package_results['ShippingLabel']['GraphicImage'] ) ) {
				$gif = $package_results['ShippingLabel']['GraphicImage']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

				/**
				 * For px to mm conversion.
				 * @see https://stackoverflow.com/questions/10040309/how-to-maintain-image-quality-with-fpdf-and-php
				 */
				$scale_factor = 2.83;

				$image  = 'data://text/plain;base64,' . $gif;
				$info   = wp_getimagesize( $image );
				$width  = $info[0] / $scale_factor;
				$height = $info[1] / $scale_factor;

				try {
					$pdf = new \FPDF( 'landscape', 'mm', array( $width, $height ) );

					$pdf->AddPage();
					$pdf->Image( $image, 0, 0, $width, $height, 'gif' );

					$stream = $pdf->Output( 'label.pdf', 'S' );

					if ( $path = $label->upload_label_file( $stream ) ) {
						$label->set_path( $path );
					} else {
						$error->add( 'upload', _x( 'Error while uploading UPS label.', 'ups', 'woocommerce-germanized-ups' ) );
					}
				} catch ( \Exception $e ) {
					$error->add( 'upload', sprintf( _x( 'Error while uploading UPS label: %1$s', 'ups', 'woocommerce-germanized-ups' ), $e->getMessage() ) );
				}
			}

			$label->save();

			if ( wc_gzd_shipment_wp_error_has_errors( $error ) ) {
				return $error;
			}
		}

		return is_wp_error( $response ) ? $response : true;
	}

	protected function clean_request( $array ) {
		foreach ( $array as $k => $v ) {
			if ( is_array( $v ) ) {
				$array[ $k ] = $this->clean_request( $v );
			} elseif ( ! is_string( $v ) ) {
				$array[ $k ] = json_encode( $v );
			}

			if ( '' === $v ) {
				unset( $array[ $k ] );
			}
		}

		return $array;
	}

	protected function get_api_base_url() {
		return trailingslashit( Package::get_api_url() ) . 'backend/rs/shipments';
	}

	protected function get_timeout( $request_type = 'GET' ) {
		return 'GET' === $request_type ? 30 : 100;
	}

	protected function get_header() {
		$headers = array(
			'Content-Type'        => 'application/json',
			'Accept'              => 'application/json',
			'User-Agent'          => 'Germanized/' . Package::get_version(),
			'transId'             => uniqid(),
			'transactionSrc'      => 'Germanized/' . Package::get_version(),
			'Username'            => Package::get_api_username(),
			'Password'            => Package::get_api_password(),
			'AccessLicenseNumber' => Package::get_api_access_key(),
		);

		return $headers;
	}

	/**
	 * @param $endpoint
	 * @param $type
	 * @param $body_args
	 *
	 * @return array|\WP_Error
	 */
	protected function get_response( $endpoint, $type = 'GET', $body_args = array() ) {
		$url = untrailingslashit( trailingslashit( self::is_sandbox() ? 'https://wwwcie.ups.com/ship/v1/' : 'https://onlinetools.ups.com/ship/v1/' ) . $endpoint );

		if ( 'GET' === $type ) {
			$response = wp_remote_get(
				esc_url_raw( $url ),
				array(
					'headers' => $this->get_header(),
					'timeout' => $this->get_timeout( $type ),
				)
			);
		} elseif ( 'POST' === $type ) {
			$response = wp_remote_post(
				esc_url_raw( $url ),
				array(
					'headers' => $this->get_header(),
					'timeout' => $this->get_timeout( $type ),
					'body'    => wp_json_encode( $body_args, JSON_PRETTY_PRINT ),
				)
			);
		}

		if ( false !== $response ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$headers = wp_remote_retrieve_headers( $response );

			if ( (int) $code >= 300 ) {
				return $this->parse_error( $code, $body, $headers );
			}

			return array(
				'code'    => (int) $code,
				'raw'     => $body,
				'headers' => $headers,
				'body'    => json_decode( $body, true ),
			);
		}

		return new \WP_Error( 'error', sprintf( esc_html_x( 'Error while querying UPS endpoint %s', 'ups', 'woocommerce-germanized-ups' ), esc_url_raw( $endpoint ) ) );
	}

	protected function post( $endpoint, $data = array() ) {
		return $this->get_response( $endpoint, 'POST', $data );
	}

	/**
	 * @param $code
	 * @param $body
	 * @param $headers
	 *
	 * @return \WP_Error
	 */
	protected function parse_error( $code, $body, $headers ) {
		$error = new \WP_Error();

		if ( is_string( $body ) ) {
			$response = json_decode( $body, true );

			if ( ! empty( $response['response']['errors'] ) ) {
				foreach( $response['response']['errors'] as $response_error ) {
					$error->add( 'error', wp_kses_post( $response_error['code'] . ': ' . $response_error['message'] ) );
				}
			}

			if ( ! wc_gzd_shipment_wp_error_has_errors( $error ) ) {
				$error->add( 'error', _x( 'There was an unkown error calling the UPS API.', 'ups', 'woocommerce-germanized-ups' ) );
			}
		} else {
			$error->add( 'error', _x( 'There was an unkown error calling the UPS API.', 'ups', 'woocommerce-germanized-ups' ) );
		}

		return $error;
	}

	/** Disabled Api constructor. Use Api::instance() as singleton */
	protected function __construct() {}
}
