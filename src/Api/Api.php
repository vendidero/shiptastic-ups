<?php

namespace Vendidero\Shiptastic\UPS\Api;

use Vendidero\Shiptastic\ImageToPDF;
use Vendidero\Shiptastic\PDFMerger;
use Vendidero\Shiptastic\SecretBox;
use Vendidero\Shiptastic\ShipmentError;
use Vendidero\Shiptastic\UPS\Label\Retoure;
use Vendidero\Shiptastic\UPS\Label\Simple;
use Vendidero\Shiptastic\UPS\Package;
use Vendidero\Shiptastic\Shipment;

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
	 * @param Simple|Retoure $label
	 *
	 * @return \WP_Error|true
	 */
	public function cancel_label( $label ) {
		if ( $label->get_number() ) {
			$response = $this->delete( 'shipments/' . $this->get_api_version() . '/void/' . $label->get_number() );

			if ( is_wp_error( $response ) ) {
				return $response;
			} elseif ( isset( $response['body']['VoidShipmentResponse']['Response']['ResponseStatus']['Code'] ) && 1 === absint( $response['body']['VoidShipmentResponse']['Response']['ResponseStatus']['Code'] ) ) {
				return true;
			}
		}

		return new \WP_Error( 'ups_error', _x( 'There was an error while cancelling the label', 'ups', 'ups-for-shiptastic' ) );
	}

	protected function limit_length( $str, $max_length = -1 ) {
		if ( $max_length > 0 ) {
			if ( function_exists( 'mb_strcut' ) ) {
				/**
				 * mb_substr does not cut to the exact point in case of umlauts etc.
				 * e.g. returns 33 chars instead of 30 in case last latter is ü.
				 */
				$str = mb_strcut( $str, 0, $max_length );
			} else {
				$str = mb_substr( $str, 0, $max_length );
			}
		}

		return $str;
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return string
	 */
	protected function get_dimension_unit( $shipment ) {
		$shipper_country = $shipment->get_sender_country();
		$unit            = 'CM';

		if ( in_array( $shipper_country, array( 'US' ), true ) ) {
			$unit = 'IN';
		}

		return apply_filters( 'shiptastic_ups_shipment_dimension_unit', $unit, $shipment );
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
		$label_unit = $shipment->get_dimension_unit();
		$ups_unit   = strtolower( $this->get_dimension_unit( $shipment ) );

		if ( $label_unit !== $ups_unit ) {
			$dimension = wc_get_dimension( $dimension, $ups_unit, $label_unit );
		}

		return apply_filters( 'shiptastic_ups_shipment_dimension', ceil( $dimension ), $shipment );
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

		return apply_filters( 'shiptastic_ups_shipment_weight_unit', $unit, $shipment );
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

		return apply_filters( 'shiptastic_ups_shipment_dimension', ceil( (float) $weight * 2 ) / 2, $shipment );
	}

	protected function format_phone_number( $phone_number ) {
		$number_plain = preg_replace( '/[^0-9]/', '', $phone_number );

		return $number_plain;
	}

	protected function get_api_version() {
		return 'v2403';
	}

	public function test_connection() {
		$response       = $this->get( 'track/v1/details/12324' );
		$has_connection = false;

		if ( is_wp_error( $response ) && 401 !== $response->get_error_code() ) {
			$has_connection = true;
		}

		return $has_connection;
	}

	/**
	 * @param Simple|Retoure $label
	 *
	 * @return \WP_Error|true
	 */
	public function get_label( $label ) {
		$shipment              = $label->get_shipment();
		$provider              = $shipment->get_shipping_provider_instance();
		$phone_is_required     = ! $shipment->is_shipping_domestic();
		$email_is_required     = false;
		$service_data          = array();
		$shipper_address_lines = array();

		foreach ( $label->get_services() as $service ) {
			$service_name = ucfirst( $service );

			switch ( $service ) {
				case 'Notification':
					$request_services[ $service_name ] = array();
					$notification_codes                = array( 6, 7, 8 );

					foreach ( $notification_codes as $notification_code ) {
						$request_services[ $service_name ][] = array(
							'NotificationCode' => $notification_code,
							'EMail'            => array( 'EMailAddress' => array( $label->get_service_prop( 'customerAlertService', 'email' ) ) ),
						);
					}
					break;
			}
		}

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
				'AddressLine'       => $shipper_address_lines,
				'City'              => $shipment->get_sender_city(),
				'PostalCode'        => $shipment->get_sender_postcode(),
				'StateProvinceCode' => $shipment->get_sender_state(),
				'CountryCode'       => $shipment->get_sender_country(),
			),
		);

		if ( ! empty( $shipment->get_sender_phone() ) ) {
			$shipper['Phone'] = array(
				'Number' => $this->limit_length( $this->format_phone_number( $shipment->get_sender_phone() ), 15 ),
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
				'AddressLine'       => $ship_to_address_lines,
				'City'              => $shipment->get_city(),
				'PostalCode'        => $shipment->get_postcode(),
				'StateProvinceCode' => $shipment->get_state(),
				'CountryCode'       => $shipment->get_country(),
			),
		);

		if ( empty( $shipment->get_company() ) ) {
			$ship_to['ResidentialAddressIndicator'] = 'yes';
		}

		if ( $phone_is_required || ( apply_filters( 'shiptastic_ups_label_api_transmit_customer_phone', false, $label ) && $shipment->get_phone() ) ) {
			$ship_to['Phone'] = array(
				'Number' => $this->limit_length( $this->format_phone_number( $shipment->get_phone() ), 15 ),
			);
		}

		if ( $email_is_required || ( apply_filters( 'shiptastic_ups_force_email_notification', false, $shipment ) && $shipment->get_email() ) ) {
			$ship_to['EMailAddress'] = $shipment->get_email();
		}

		$customs_data = $label->get_customs_data();

		if ( $shipment->is_shipping_international() ) {
			$products = array();

			foreach ( $customs_data['items'] as $item ) {
				$products[] = array(
					'Description'       => $this->limit_length( $item['description'], 35 ),
					'Unit'              => array(
						'Number'            => $item['quantity'],
						'Value'             => wc_format_decimal( $item['single_value'], 3 ),
						'UnitOfMeasurement' => array(
							'Code' => 'PCS',
						),
					),
					'CommodityCode'     => $item['tariff_number'],
					'OriginCountryCode' => $item['origin_code'],
					'ProductWeight'     => array(
						'UnitOfMeasurement' => array(
							'Code' => 'kgs',
						),
						'Weight'            => wc_format_decimal( $item['gross_weight_in_kg'], 1 ),
					),
				);
			}

			$default_terms  = $label->get_incoterms();
			$default_reason = ! empty( $customs_data['export_reason'] ) ? strtoupper( $customs_data['export_reason'] ) : 'SALE';

			$available_reasons_for_export = array(
				'SALE'             => _x( 'Sale', 'ups-reasons-for-export', 'ups-for-shiptastic' ),
				'GIFT'             => _x( 'Gift', 'ups-reasons-for-export', 'ups-for-shiptastic' ),
				'SAMPLE'           => _x( 'Sample', 'ups-reasons-for-export', 'ups-for-shiptastic' ),
				'RETURN'           => _x( 'Return', 'ups-reasons-for-export', 'ups-for-shiptastic' ),
				'REPAIR'           => _x( 'Repair', 'ups-reasons-for-export', 'ups-for-shiptastic' ),
				'INTERCOMPANYDATA' => _x( 'Inter company data', 'ups-reasons-for-export', 'ups-for-shiptastic' ),
			);

			$service_data['InternationalForms'] = array(
				'FormType'            => '01',
				'InvoiceNumber'       => $shipment->get_shipment_number(),
				'InvoiceDate'         => date_i18n( 'Ymd' ),
				'PurchaseOrderNumber' => $shipment->get_order_number(),
				'TermsOfShipment'     => array_key_exists( $default_terms, $provider->get_available_incoterms() ) ? $default_terms : 'DDP',
				'ReasonForExport'     => array_key_exists( $default_reason, $available_reasons_for_export ) ? $default_reason : 'SALE',
				'CurrencyCode'        => $customs_data['currency'],
				'Product'             => $products,
				'FreightCharges'      => array(
					'MonetaryValue' => wc_format_decimal( $customs_data['additional_fee'], 2 ),
				),
				'Contacts'            => array(
					'SoldTo' => $ship_to,
				),
			);
		}

		$locale = '';

		if ( in_array( $shipment->get_country(), array( 'DE', 'AT' ), true ) ) {
			$locale = 'de_DE';
		}

		$available_packaging_types = array(
			'02' => _x( 'Customer Supplied Package', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'03' => _x( 'Tube', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'04' => _x( 'PAK', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'21' => _x( 'UPS Express Box', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'24' => _x( 'UPS 25KG Box', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'25' => _x( 'UPS 10KG Box', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'30' => _x( 'Pallet', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'2a' => _x( 'Small Express Box', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'2b' => _x( 'Medium Express Box', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'2c' => _x( 'Large Express Box', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'56' => _x( 'Flats', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'57' => _x( 'Parcels', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'58' => _x( 'BPM', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'59' => _x( 'First Class', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'60' => _x( 'Priority', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'61' => _x( 'Machineables', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'62' => _x( 'Irregulars', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'63' => _x( 'Parcel Post', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'64' => _x( 'BPM Parcel', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'65' => _x( 'Media Mail', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'66' => _x( 'BPM Flat', 'ups-packaging-type', 'ups-for-shiptastic' ),
			'67' => _x( 'Standard Flat', 'ups-packaging-type', 'ups-for-shiptastic' ),
		);

		$default_packaging_type = '02';

		$api_references = array();
		$references     = array_filter(
			array(
				$provider->get_formatted_label_reference( $label, $label->get_type(), 'ref_1' ),
				$provider->get_formatted_label_reference( $label, $label->get_type(), 'ref_2' ),
			)
		);

		foreach ( $references as $ref ) {
			$api_references[] = array(
				'Value' => $ref,
			);
		}

		$request = array(
			'ShipmentRequest' => array(
				'Request'            => array(
					'RequestOption' => 'novalidate',
				),
				'Shipment'           => array(
					'Locale'                 => $locale,
					'Description'            => $this->limit_length( $customs_data['export_type_description'], 50 ),
					'Shipper'                => $shipper,
					'ShipFrom'               => $ship_from,
					'ShipTo'                 => $ship_to,
					'PaymentInformation'     => array(
						'ShipmentCharge' => array(
							'Type'        => '01',
							'BillShipper' => array(
								'AccountNumber' => Package::get_account_number(),
							),
						),
					),
					'Service'                => array(
						'Code' => str_replace( 'ups_', '', $label->get_product_id() ),
					),
					'NumOfPiecesInShipment'  => $shipment->get_item_count(),
					'ShipmentServiceOptions' => $service_data,
					'ReferenceNumber'        => $api_references,
					'Package'                => array(
						// Detailed item description
						'Description'   => $this->limit_length( $customs_data['export_type_description'], 35 ),
						'Packaging'     => array(
							'Code' => array_key_exists( $default_packaging_type, $available_packaging_types ) ? $default_packaging_type : '02',
						),
						'Dimensions'    => array(
							'UnitOfMeasurement' => array(
								'Code' => $this->get_dimension_unit( $shipment ),
							),
							'Length'            => $this->get_dimension( $label->get_length(), $shipment ),
							'Width'             => $this->get_dimension( $label->get_width(), $shipment ),
							'Height'            => $this->get_dimension( $label->get_height(), $shipment ),
						),
						'PackageWeight' => array(
							'UnitOfMeasurement' => array(
								'Code' => $this->get_weight_unit( $shipment ),
							),
							'Weight'            => $this->get_weight( $label->get_weight(), $shipment ),
						),
					),
				),
				'LabelSpecification' => array(
					'LabelImageFormat' => array(
						'Code' => 'GIF',
					),
				),
			),
		);

		if ( 'return' === $label->get_type() ) {
			$request['ShipmentRequest']['Shipment']['ReturnService'] = array(
				'Code' => $label->get_return_service(),
			);

			if ( 8 === absint( $label->get_return_service() ) ) {
				$request['ShipmentRequest']['Shipment']['ShipmentServiceOptions']['LabelDelivery'] = array(
					'EMail' => array(
						'EMailAddress' => $shipment->get_sender_email(),
					),
				);

				$request['ShipmentRequest']['Shipment']['LabelSpecification'] = array(
					'LabelImageFormat' => array(
						'Code' => '',
					),
				);
			}
		}

		$request  = $this->clean_request( $request );
		$response = $this->post( 'shipments/' . $this->get_api_version() . '/ship', apply_filters( 'shiptastic_ups_label_api_request', $request, $label ) );

		if ( ! is_wp_error( $response ) && isset( $response['body'] ) ) {
			$error       = new ShipmentError();
			$parcel_data = $response['body']['ShipmentResponse'];

			if ( 1 !== absint( $parcel_data['Response']['ResponseStatus']['Code'] ) ) {
				$error->add( 'error', _x( 'There was an unknown error calling the UPS API.', 'ups', 'ups-for-shiptastic' ) );
				return $error;
			}

			$shipment_references = $parcel_data['ShipmentResults'];
			$charges             = wc_clean( $shipment_references['ShipmentCharges']['TotalCharges']['MonetaryValue'] );
			$tracking_id         = wc_clean( $shipment_references['ShipmentIdentificationNumber'] );
			$package_results     = count( $shipment_references['PackageResults'] ) > 0 ? $shipment_references['PackageResults'][0] : array();
			$label_pdf           = '';

			$label->set_number( $tracking_id );

			if ( isset( $package_results['ShippingLabel']['GraphicImage'] ) ) {
				$gif = base64_decode( $package_results['ShippingLabel']['GraphicImage'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

				try {
					$converter = new ImageToPDF( 'landscape' );
					$converter->set_rotation( 90 );
					$converter->import_image( $gif );

					$label_pdf = $converter->Output( 'S' );

					if ( $path = $label->upload_label_file( $label_pdf ) ) {
						$label->set_path( $path );
					} else {
						$error->add( 'upload', _x( 'Error while uploading UPS label.', 'ups', 'ups-for-shiptastic' ) );
					}
				} catch ( \Exception $e ) {
					$error->add( 'upload', sprintf( _x( 'Could not convert GIF to PDF file: %1$s', 'ups', 'ups-for-shiptastic' ), $e->getMessage() ) );
				}
			}

			$label->save();

			if ( ! empty( $shipment_references['Form'] ) ) {
				$international_form = $shipment_references['Form'];
				$form_code          = wc_clean( $international_form['Code'] );

				if ( ! empty( $international_form['Image'] ) && ! empty( $international_form['Image']['GraphicImage'] ) ) {
					$format = $international_form['Image']['ImageFormat']['Code'];

					if ( ! $label->get_plain_path() ) {
						$label->upload_label_file( $label_pdf, 'plain' );
					}

					if ( 'PDF' === $format ) {
						$international_file = base64_decode( $international_form['Image']['GraphicImage'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

						if ( $label->upload_label_file( $international_file, 'form_' . $form_code ) ) {
							// Merge files
							$merger = new PDFMerger();
							$merger->add( $label->get_file() );
							$merger->add( $label->get_file( 'form_' . $form_code ) );

							$filename_label = $label->get_filename();
							$file           = $merger->output( $filename_label, 'S' );

							$label->upload_label_file( $file );
						}
					}
				}

				$label->save();
			}

			if ( wc_stc_shipment_wp_error_has_errors( $error ) ) {
				return $error;
			}
		}

		return is_wp_error( $response ) ? $response : true;
	}

	protected function clean_request( $the_array ) {
		foreach ( $the_array as $k => $v ) {
			if ( is_array( $v ) ) {
				$the_array[ $k ] = $this->clean_request( $v );
			} elseif ( ! is_string( $v ) ) {
				$the_array[ $k ] = wp_json_encode( $v );
			}

			if ( '' === $v ) {
				unset( $the_array[ $k ] );
			}
		}

		return $the_array;
	}

	protected function get_timeout( $request_type = 'GET' ) {
		return 'GET' === $request_type ? 30 : 100;
	}

	protected function get_header() {
		$headers = array(
			'Content-Type'   => 'application/json',
			'Accept'         => 'application/json',
			'User-Agent'     => 'Germanized/' . Package::get_version(),
			'transId'        => uniqid(),
			'transactionSrc' => 'Germanized/' . Package::get_version(),
		);

		return $headers;
	}

	protected function has_auth() {
		return $this->get_access_token() ? true : false;
	}

	protected function get_access_token() {
		$access_token = get_transient( 'shiptastic_ups_access_token' );

		if ( ! empty( $access_token ) ) {
			$decrypted = SecretBox::decrypt( $access_token );

			if ( ! is_wp_error( $decrypted ) ) {
				$access_token = $decrypted;
			}

			return $access_token;
		} else {
			return false;
		}
	}

	protected function auth() {
		$api_url = self::is_sandbox() ? 'https://wwwcie.ups.com/security/v1/oauth/token' : 'https://onlinetools.ups.com/security/v1/oauth/token';

		$response = $this->post(
			$api_url,
			array(
				'grant_type' => 'client_credentials',
			),
			array(
				'x-merchant-id' => Package::get_account_number(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( Package::get_api_username() . ':' . Package::get_api_password() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$access_token = isset( $response['body']['access_token'] ) ? $response['body']['access_token'] : '';
			$expires_in   = absint( isset( $response['body']['expires_in'] ) ? $response['body']['expires_in'] : 3599 );

			if ( ! empty( $access_token ) ) {
				$encrypted = SecretBox::encrypt( $access_token );

				if ( ! is_wp_error( $encrypted ) ) {
					$access_token = $encrypted;
				}

				set_transient( 'shiptastic_ups_access_token', $access_token, $expires_in );

				return true;
			}

			return new \WP_Error( 'auth', 'Error while authenticating with UPS' );
		} else {
			return $response;
		}
	}

	public function disconnect() {
		delete_transient( 'shiptastic_ups_access_token' );
	}

	/**
	 * @param $endpoint
	 * @param $type
	 * @param $body_args
	 *
	 * @return array|\WP_Error
	 */
	protected function get_response( $endpoint, $type = 'GET', $body_args = array(), $header = array() ) {
		$is_auth_request = false;

		if ( strstr( $endpoint, 'oauth' ) ) {
			$is_auth_request = true;
		} elseif ( ! $this->has_auth() ) {
			$auth_response = $this->auth();

			if ( is_wp_error( $auth_response ) ) {
				return $auth_response;
			}
		}

		$url          = wc_is_valid_url( $endpoint ) ? $endpoint : untrailingslashit( trailingslashit( self::is_sandbox() ? 'https://wwwcie.ups.com/api' : 'https://onlinetools.ups.com/api' ) . $endpoint );
		$headers      = array_replace_recursive( $this->get_header(), $header );
		$content_type = isset( $headers['Content-Type'] ) ? $headers['Content-Type'] : 'application/json';
		$code         = 400;

		if ( ! $is_auth_request && $this->has_auth() ) {
			$headers['Authorization'] = 'Bearer ' . $this->get_access_token();
		}

		if ( 'GET' === $type ) {
			$response = wp_remote_get(
				esc_url_raw( $url ),
				array(
					'headers' => $headers,
					'timeout' => $this->get_timeout( $type ),
				)
			);
		} elseif ( 'POST' === $type ) {
			if ( 'application/x-www-form-urlencoded' === $content_type ) {
				$body = http_build_query( $body_args );
			} else {
				$body = wp_json_encode( $body_args, JSON_PRETTY_PRINT );
			}

			$response = wp_remote_post(
				esc_url_raw( $url ),
				array(
					'headers' => $headers,
					'timeout' => $this->get_timeout( $type ),
					'body'    => $body,
				)
			);
		} elseif ( 'DELETE' === $type ) {
			if ( 'application/x-www-form-urlencoded' === $content_type ) {
				$body = http_build_query( $body_args );
			} else {
				$body = wp_json_encode( $body_args, JSON_PRETTY_PRINT );
			}

			$response = wp_remote_request(
				esc_url_raw( $url ),
				array(
					'method'  => 'DELETE',
					'headers' => $headers,
					'timeout' => $this->get_timeout( $type ),
					'body'    => $body,
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
				if ( in_array( (int) $code, array( 401, 403 ), true ) && ! $is_auth_request && ! isset( $body_args['is_retry'] ) ) {
					delete_transient( 'shiptastic_ups_access_token' );
					$body_args['is_retry'] = true;

					return $this->get_response( $endpoint, $type, $body_args, $header );
				}

				return $this->parse_error( $body, $headers, $code );
			}

			return array(
				'code'    => (int) $code,
				'raw'     => $body,
				'headers' => $headers,
				'body'    => json_decode( $body, true ),
			);
		}

		return new \WP_Error( absint( $code ), sprintf( esc_html_x( 'Error while querying UPS endpoint %s', 'ups', 'ups-for-shiptastic' ), esc_url_raw( $endpoint ) ) );
	}

	protected function post( $endpoint, $data = array(), $header = array() ) {
		return $this->get_response( $endpoint, 'POST', $data, $header );
	}

	protected function get( $endpoint, $data = array(), $header = array() ) {
		return $this->get_response( $endpoint, 'GET', $data, $header );
	}

	protected function delete( $endpoint, $data = array(), $header = array() ) {
		return $this->get_response( $endpoint, 'DELETE', $data, $header );
	}

	/**
	 * @param $body
	 * @param $headers
	 * @param $code
	 *
	 * @return \WP_Error
	 */
	protected function parse_error( $body, $headers, $code ) {
		$error = new \WP_Error();
		$body  = is_array( $body ) ? $body : json_decode( $body, true );

		if ( ! empty( $body['response']['errors'] ) ) {
			foreach ( $body['response']['errors'] as $response_error ) {
				$error->add( $code, wp_kses_post( $response_error['code'] . ': ' . $response_error['message'] ) );
			}
		} elseif ( ! empty( $body['response'] ) ) {
			$error->add( $code, wp_kses_post( $body['response']['code'] . ': ' . $body['response']['message'] ) );
		}

		if ( ! wc_stc_shipment_wp_error_has_errors( $error ) ) {
			$error->add( $code, _x( 'There was an unknown error calling the UPS API.', 'ups', 'ups-for-shiptastic' ) );
		}

		return $error;
	}

	/** Disabled Api constructor. Use Api::instance() as singleton */
	protected function __construct() {}
}
