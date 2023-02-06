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
		$service_data                  = array();

		$shipper_address_lines = array();

		if ( $shipment->get_sender_address_2() ) {
			$shipper_address_lines[] = $shipment->get_sender_address_2();
		}

		$shipper_address_lines[] = $shipment->get_sender_address_1();

		$shipper = array(
			'Name'                    => $this->limit_length( $shipment->get_sender_company() ? $shipment->get_sender_company() : $shipment->get_formatted_sender_full_name(), 35 ),
			'AttentionName'           => $this->limit_length( $shipment->get_formatted_sender_full_name(), 35 ),
			'CompanyDisplayableName'  => $this->limit_length( $shipment->get_sender_company(), 35 ),
			'TaxIdentificationNumber' => $this->limit_length( $shipment->get_sender_customs_reference_number(), 15 ),
			'EMailAddress'            => $this->limit_length( $shipment->get_sender_email(), 50 ),
			'ShipperNumber'           => Package::get_account_number(),
			'Address'                 => array(
				'AddressLine' => $shipper_address_lines,
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

		$ship_from = $shipper;
		unset( $ship_from['ShipperNumber'] );

		$ship_to_address_lines = array();

		if ( $shipment->get_address_2() ) {
			$ship_to_address_lines[] = $shipment->get_address_2();
		}

		$ship_to_address_lines[] = $shipment->get_address_1();

		$ship_to = array(
			'Name'                    => $this->limit_length( $shipment->get_company() ? $shipment->get_company() : $shipment->get_formatted_full_name(), 35 ),
			'AttentionName'           => $this->limit_length( $shipment->get_formatted_full_name(), 35 ),
			'TaxIdentificationNumber' => $this->limit_length( $shipment->get_customs_reference_number(), 15 ),
			'Address'                 => array(
				'AddressLine' => $ship_to_address_lines,
				'City'        => $shipment->get_city(),
				'PostalCode'  => $shipment->get_postcode(),
				'StateProvinceCode' => $shipment->get_state(),
				'CountryCode'       => $shipment->get_country(),
			),
		);

		if ( empty( $shipment->get_company() ) ) {
			$ship_to['ResidentialAddressIndicator'] = 'yes';
		}

		if ( $phone_is_required || ( apply_filters( 'woocommerce_gzd_ups_label_api_transmit_customer_phone', false, $label ) && $shipment->get_phone() ) ) {
			$ship_to['Phone'] = array(
				'Number' => $this->limit_length( $this->format_phone_number( $shipment->get_phone() ), 15 )
			);
		}

		if ( $email_is_required || ( apply_filters( 'woocommerce_gzd_ups_label_api_transmit_customer_email', false, $label ) && $shipment->get_email() ) ) {
			$ship_to['EMailAddress'] = $shipment->get_email();
		}

		$customs_data = $label->get_customs_data();

		if ( $shipment->is_shipping_international() ) {
			$available_international_form_types = array(
				'01' => _x( 'Invoice', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'03' => _x( 'CO', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'04' => _x( 'NAFTA CO', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'05' => _x( 'Partial Invoice', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'06' => _x( 'Packinglist', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'07' => _x( 'Customer Generated Forms', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'08' => _x( 'Air Freight Packing List', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'09' => _x( 'CN22 Form', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'10' => _x( 'UPS Premium Care Form', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
				'11' => _x( 'EEI', 'ups-international-form-types', 'woocommerce-germanized-ups' ),
			);

			$cn22_content = array();
			$products     = array();

			foreach( $customs_data['items'] as $item ) {
				$cn22_content[] = array(
					'CN22ContentQuantity' => $item['quantity'],
					'CN22ContentDescription' => $this->limit_length( $item['description'], 105 ),
					'CN22ContentWeight' => array(
						'UnitOfMeasurement' => array(
							'Code' => 'lbs',
						),
						'Weight' => wc_format_decimal( wc_get_weight( $item['gross_weight_in_kg'], 'lbs', 'kg' ), 2 ),
					),
					'CN22ContentTotalValue' => wc_format_decimal( $item['value'] ),
					'CN22ContentCurrencyCode' => 'USD',
					'CN22ContentCountryOfOrigin' => $item['origin_code'],
					'CN22ContentTariffNumber' => $item['tariff_number'],
				);

				$products[] = array(
					'Description' => $this->limit_length( $item['description'], 35 ),
					'Unit' => array(
						'Number' => $item['quantity'],
						'Value' => $item['single_value'],
						'UnitOfMeasurement' => array(
							'Code' => 'PCS',
						),
					),
					'CommodityCode' => $item['tariff_number'],
					'OriginCountryCode' => $item['origin_code'],
					'ProductWeight' => array(
						'UnitOfMeasurement' => array(
							'Code' => 'kgs'
						),
						'Weight' => wc_format_decimal( $item['gross_weight_in_kg'], 1 ),
					),
					'InvoiceNumber' => '',
					'InvoiceDate' => '',
					'PurchaseOrderNumber' => $shipment->get_order_number(),
				);
			}

			$available_cn22_types = array(
				'1' => _x( 'Gift', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'2' => _x( 'Documents', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'3' => _x( 'Commercial Sample', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'4' => _x( 'Other', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
			);

			$default_cn22_type = '4';

			$cn22 = array(
				'LabelSize' => '1',
				'PrintsPerPage' => '1',
				'LabelPrintType' => 'pdf',
				'CN22Type' => array_key_exists( $default_cn22_type, $available_cn22_types ) ? $default_cn22_type : '4',
				'CN22Content' => $cn22_content,
			);

			if ( '4' === $cn22['CN22Type'] ) {
				$cn22['CN22OtherDescription'] = $this->limit_length( $customs_data['export_type_description'], 20 );
			}

			$available_shipment_terms = array(
				'CFR' => _x( 'Cost and Freight', 'ups', 'woocommerce-germanized-ups' ),
				'CIF' => _x( 'Cost Insurance and Freight', 'ups', 'woocommerce-germanized-ups' ),
				'CIP' => _x( 'Carriage and Insurance Paid', 'ups', 'woocommerce-germanized-ups' ),
				'CPT' => _x( 'Carriage Paid To', 'ups', 'woocommerce-germanized-ups' ),
				'DAF' => _x( 'Delivered at Frontier', 'ups', 'woocommerce-germanized-ups' ),
				'DDP' => _x( 'Delivery Duty Paid', 'ups', 'woocommerce-germanized-ups' ),
				'DDU' => _x( 'Delivery Duty Unpaid', 'ups', 'woocommerce-germanized-ups' ),
				'DEQ' => _x( 'Delivered Ex Quay', 'ups', 'woocommerce-germanized-ups' ),
				'DES' => _x( 'Delivered Ex Ship', 'ups', 'woocommerce-germanized-ups' ),
				'EXW' => _x( 'Ex Works', 'ups', 'woocommerce-germanized-ups' ),
				'FAS' => _x( 'Free Alongside Ship', 'ups', 'woocommerce-germanized-ups' ),
				'FCA' => _x( 'Free Carrier', 'ups', 'woocommerce-germanized-ups' ),
				'FOB' => _x( 'Free On Board', 'ups', 'woocommerce-germanized-ups' ),
			);

			$default_terms = 'DDP';
			$default_reason = 'SALE';

			$available_reasons_for_export = array(
				'SALE' => _x( 'Sale', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'GIFT' => _x( 'Gift', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'SAMPLE' => _x( 'Sample', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'RETURN' => _x( 'Return', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'REPAIR' => _x( 'Repair', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
				'INTERCOMPANYDATA' => _x( 'Inter company data', 'ups-reasons-for-export', 'woocommerce-germanized-ups' ),
			);

			$service_data['InternationalForms'] = array(
				'FormType' => '09',
				'CN22Form' => $cn22,
				'TermsOfShipment' => array_key_exists( $default_terms, $available_shipment_terms ) ? $default_terms : 'DDP',
				'ReasonForExport' => array_key_exists( $default_reason, $available_reasons_for_export ) ? $default_reason : 'SALE',
				'CurrencyCode'    => $customs_data['currency']
			);
		}

		$locale = '';

		if ( in_array( $shipment->get_country(), array( 'DE', 'AT' ), true ) ) {
			$locale = 'de_DE';
		}

		$available_packaging_types = array(
			'02' => _x( 'Customer Supplied Package', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'03' => _x( 'Tube', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'04' => _x( 'PAK', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'21' => _x( 'UPS Express Box', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'24' => _x( 'UPS 25KG Box', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'25' => _x( 'UPS 10KG Box', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'30' => _x( 'Pallet', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'2a' => _x( 'Small Express Box', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'2b' => _x( 'Medium Express Box', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'2c' => _x( 'Large Express Box', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'56' => _x( 'Flats', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'57' => _x( 'Parcels', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'58' => _x( 'BPM', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'59' => _x( 'First Class', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'60' => _x( 'Priority', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'61' => _x( 'Machineables', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'62' => _x( 'Irregulars', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'63' => _x( 'Parcel Post', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'64' => _x( 'BPM Parcel', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'65' => _x( 'Media Mail', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'66' => _x( 'BPM Flat', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
			'67' => _x( 'Standard Flat', 'ups-packaging-type', 'woocommerce-germanized-ups' ),
		);

		$default_packaging_type = '02';

		$request = array(
			'ShipmentRequest' => array(
				'Shipment' => array(
					'Locale' => $locale,
					'Description' => $this->limit_length( $customs_data['export_type_description'], 50 ),
					'Shipper' => $shipper,
					'ShipFrom' => $ship_from,
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
					//'NumOfPiecesInShipment' => $shipment->get_item_count(),
					'ShipmentServiceOptions' => $service_data,
					'Package' => array(
						// Detailed item description
						'Description' => $this->limit_length( $customs_data['export_type_description'], 35 ),
						'Packaging' => array(
							'Code' => array_key_exists( $default_packaging_type, $available_packaging_types ) ? $default_packaging_type : '02',
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
		$url = untrailingslashit( trailingslashit( self::is_sandbox() ? 'https://wwwcie.ups.com/ship/v2205/' : 'https://onlinetools.ups.com/ship/v2205/' ) . $endpoint );

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
