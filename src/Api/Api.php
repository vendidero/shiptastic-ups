<?php

namespace Vendidero\Shiptastic\UPS\Api;

use Vendidero\Shiptastic\API\Auth\OAuthGateway;
use Vendidero\Shiptastic\API\Response;
use Vendidero\Shiptastic\API\REST;
use Vendidero\Shiptastic\ImageToPDF;
use Vendidero\Shiptastic\PDFMerger;
use Vendidero\Shiptastic\ShipmentError;
use Vendidero\Shiptastic\UPS\Label\Retoure;
use Vendidero\Shiptastic\UPS\Label\Simple;
use Vendidero\Shiptastic\UPS\Package;
use Vendidero\Shiptastic\Shipment;

defined( 'ABSPATH' ) || exit;

class Api extends REST {

	/**
	 * @param Simple|Retoure $label
	 *
	 * @return ShipmentError|true
	 */
	public function cancel_label( $label ) {
		if ( $label->get_number() ) {
			$response = $this->delete( 'shipments/' . $this->get_api_version() . '/void/' . $label->get_number() );

			if ( $response->is_error() ) {
				return $response->get_error();
			} elseif ( isset( $response->get_body()['VoidShipmentResponse']['Response']['ResponseStatus']['Code'] ) && 1 === absint( $response->get_body()['VoidShipmentResponse']['Response']['ResponseStatus']['Code'] ) ) {
				return true;
			}
		}

		return new ShipmentError( 'ups_error', _x( 'There was an error while cancelling the label', 'ups', 'shiptastic-ups' ) );
	}

	protected function limit_length( $str, $max_length = -1 ) {
		return wc_shiptastic_substring( $str, 0, $max_length );
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
		$has_connection = false;

		if ( $this->get_auth_api()->is_connected() ) {
			$response = $this->get( 'track/v1/details/12324' );

			if ( $response->is_error() && 401 !== $response->get_code() ) {
				$has_connection = true;
			}
		}

		return $has_connection;
	}

	public function find_access_points( $address, $limit = 10 ) {
		$address = wp_parse_args(
			$address,
			array(
				'address_1' => '',
				'city'      => '',
				'postcode'  => '',
				'country'   => '',
				'state'     => '',
			)
		);

		$locale      = \Vendidero\Shiptastic\Package::get_locale_info( $address['country'] );
		$measurement = 'en_US' === $locale['default_locale'] ? 'MI' : 'KM';

		$request = array(
			'LocatorRequest' => array(
				'Request'           => array(
					'RequestAction' => 'Locator',
				),
				'OriginAddress'     => array(
					'AddressKeyFormat' => array(
						'AddressLine'        => $address['address_1'],
						'PoliticalDivision2' => $address['city'],
						'PostcodePrimaryLow' => $address['postcode'],
						'CountryCode'        => $address['country'],
					),
					'MaximumListSize'  => $limit,
				),
				'Translate'         => array(
					'Locale' => $locale['default_locale'],
				),
				'UnitOfMeasurement' => array(
					'Code' => $measurement,
				),
			),
		);

		$response  = $this->post( 'locations/' . $this->get_api_version() . '/search/availabilities/64', $request );
		$locations = array();

		if ( ! $response->is_error() ) {
			$body      = $response->get_body();
			$locations = wc_clean( isset( $body['LocatorResponse']['SearchResults']['DropLocation'] ) ? (array) $body['LocatorResponse']['SearchResults']['DropLocation'] : array() );
		}

		return $locations;
	}

	public function find_access_point_by_id( $id, $address ) {
		$address = wp_parse_args(
			$address,
			array(
				'city'     => Package::is_sandbox_mode() ? 'Atlanta' : '',
				'postcode' => '',
				'country'  => '',
			)
		);
		$locale  = \Vendidero\Shiptastic\Package::get_locale_info();

		$request = array(
			'LocatorRequest' => array(
				'Request'                => array(
					'RequestAction' => 'Locator',
				),
				'OriginAddress'          => array(
					'AddressKeyFormat' => array(
						'PoliticalDivision2' => $address['city'],
						'PostcodePrimaryLow' => $address['postcode'],
						'CountryCode'        => $address['country'],
					),
				),
				'Translate'              => array(
					'Locale' => $locale['default_locale'],
				),
				'LocationSearchCriteria' => array(
					'AccessPointSearch' => array(
						'PublicAccessPointID' => $id,
					),
				),
			),
		);

		$response = $this->post( 'locations/' . $this->get_api_version() . '/search/availabilities/64', $request );
		$location = false;

		if ( ! $response->is_error() ) {
			$body      = $response->get_body();
			$locations = wc_clean( isset( $body['LocatorResponse']['SearchResults']['DropLocation'] ) ? (array) $body['LocatorResponse']['SearchResults']['DropLocation'] : array() );

			if ( ! empty( $locations ) ) {
				$location = $locations[0];
			}
		}

		return $location;
	}

	protected function get_language_details( $country ) {
		$locale_info    = \Vendidero\Shiptastic\Package::get_locale_info( $country );
		$default_locale = strtolower( $locale_info['default_locale'] );
		$language_code  = 'eng';

		$locale_parts = explode( '_', $default_locale );
		$locale_map   = array(
			'en' => 'eng',
			'es' => 'spa',
			'it' => 'ita',
			'fr' => 'fra',
			'de' => 'deu',
			'pt' => 'por',
			'nl' => 'nld',
			'da' => 'dan',
			'fi' => 'fin',
			'sv' => 'swe',
			'nb' => 'nor',
			'hu' => 'hun',
			'cs' => 'ces',
			'ro' => 'ron',
			'tr' => 'tur',
			'ru' => 'rus',
			'cn' => 'zho',
		);

		if ( array_key_exists( $locale_parts[0], $locale_map ) ) {
			$language_code = $locale_map[ $locale_parts[0] ];
		}

		if ( 'eng' === $language_code ) {
			$dialect_code = 'gb';

			if ( in_array( $locale_parts[1], array( 'us', 'ca' ), true ) ) {
				$dialect_code = $locale_parts[1];
			}
		} elseif ( 'zho' === $language_code ) {
			$dialect_code = 'tw';
		} else {
			$dialect_code = '97';
		}

		return array(
			'language' => $language_code,
			'dialect'  => $dialect_code,
		);
	}

	protected function get_locale( $country ) {
		$locale_info       = \Vendidero\Shiptastic\Package::get_locale_info( $country );
		$locale            = strtolower( $locale_info['default_locale'] );
		$supported_locales = array(
			'bg_BG',
			'cs_CZ',
			'da_DK',
			'de_DE',
			'el_GR',
			'en_CA',
			'en_GB',
			'en_US',
			'es_AR',
			'es_ES',
			'es_MX',
			'es_PR',
			'et_EE',
			'fi_FI',
			'fr_CA',
			'fr_FR',
			'he_IL',
			'hu_HU',
			'it_IT',
			'ja_JP',
			'ko_KR',
			'lt_LT',
			'lv_LV',
			'nl_NL',
			'no_NO',
			'pl_PL',
			'pt_BR',
			'pt_PT',
			'ro_RO',
			'ru_RU',
			'sk_SK',
			'sv_SE',
			'th_TH',
			'tr_TR',
			'vi_VN',
			'zh_CN',
			'zh_HK',
			'zh_TW',
		);

		if ( ! in_array( $locale, $supported_locales, true ) ) {
			$parts        = explode( '_', $locale );
			$plain_locale = $parts[0] . '_' . $parts[0];

			if ( in_array( $plain_locale, $supported_locales, true ) ) {
				$locale = $plain_locale;
			} else {
				$locale = 'en_GB';
			}
		}

		return $locale;
	}

	/**
	 * @param Simple|Retoure $label
	 *
	 * @return ShipmentError|true
	 */
	public function get_label( $label ) {
		$shipment              = $label->get_shipment();
		$provider              = $shipment->get_shipping_provider_instance();
		$phone_is_required     = ! $shipment->is_shipping_domestic();
		$email_is_required     = false;
		$service_data          = array();
		$shipper_address_lines = array();
		$locale                = $this->get_locale( $shipment->get_country() );
		$language_details      = $this->get_language_details( $shipment->get_country() );

		foreach ( $label->get_services() as $service ) {
			$service_name = ucfirst( $service );

			switch ( $service ) {
				case 'Notification':
					$service_data[ $service_name ] = array();
					$notification_codes            = array( 6, 7, 8 );

					foreach ( $notification_codes as $notification_code ) {
						$service_data[ $service_name ][] = array(
							'NotificationCode' => $notification_code,
							'EMail'            => array( 'EMailAddress' => array( $label->get_service_prop( 'customerAlertService', 'email' ) ) ),
							'Locale'           => array(
								'Language' => strtoupper( $language_details['language'] ),
								'Dialect'  => strtoupper( $language_details['dialect'] ),
							),
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
				'SALE'             => _x( 'Sale', 'ups-reasons-for-export', 'shiptastic-ups' ),
				'GIFT'             => _x( 'Gift', 'ups-reasons-for-export', 'shiptastic-ups' ),
				'SAMPLE'           => _x( 'Sample', 'ups-reasons-for-export', 'shiptastic-ups' ),
				'RETURN'           => _x( 'Return', 'ups-reasons-for-export', 'shiptastic-ups' ),
				'REPAIR'           => _x( 'Repair', 'ups-reasons-for-export', 'shiptastic-ups' ),
				'INTERCOMPANYDATA' => _x( 'Inter company data', 'ups-reasons-for-export', 'shiptastic-ups' ),
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

		$available_packaging_types = array(
			'02' => _x( 'Customer Supplied Package', 'ups-packaging-type', 'shiptastic-ups' ),
			'03' => _x( 'Tube', 'ups-packaging-type', 'shiptastic-ups' ),
			'04' => _x( 'PAK', 'ups-packaging-type', 'shiptastic-ups' ),
			'21' => _x( 'UPS Express Box', 'ups-packaging-type', 'shiptastic-ups' ),
			'24' => _x( 'UPS 25KG Box', 'ups-packaging-type', 'shiptastic-ups' ),
			'25' => _x( 'UPS 10KG Box', 'ups-packaging-type', 'shiptastic-ups' ),
			'30' => _x( 'Pallet', 'ups-packaging-type', 'shiptastic-ups' ),
			'2a' => _x( 'Small Express Box', 'ups-packaging-type', 'shiptastic-ups' ),
			'2b' => _x( 'Medium Express Box', 'ups-packaging-type', 'shiptastic-ups' ),
			'2c' => _x( 'Large Express Box', 'ups-packaging-type', 'shiptastic-ups' ),
			'56' => _x( 'Flats', 'ups-packaging-type', 'shiptastic-ups' ),
			'57' => _x( 'Parcels', 'ups-packaging-type', 'shiptastic-ups' ),
			'58' => _x( 'BPM', 'ups-packaging-type', 'shiptastic-ups' ),
			'59' => _x( 'First Class', 'ups-packaging-type', 'shiptastic-ups' ),
			'60' => _x( 'Priority', 'ups-packaging-type', 'shiptastic-ups' ),
			'61' => _x( 'Machineables', 'ups-packaging-type', 'shiptastic-ups' ),
			'62' => _x( 'Irregulars', 'ups-packaging-type', 'shiptastic-ups' ),
			'63' => _x( 'Parcel Post', 'ups-packaging-type', 'shiptastic-ups' ),
			'64' => _x( 'BPM Parcel', 'ups-packaging-type', 'shiptastic-ups' ),
			'65' => _x( 'Media Mail', 'ups-packaging-type', 'shiptastic-ups' ),
			'66' => _x( 'BPM Flat', 'ups-packaging-type', 'shiptastic-ups' ),
			'67' => _x( 'Standard Flat', 'ups-packaging-type', 'shiptastic-ups' ),
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

		if ( $shipment->has_pickup_location() ) {
			if ( $location = $shipment->get_pickup_location() ) {
				$notifications = isset( $request['ShipmentRequest']['Shipment']['ShipmentServiceOptions']['Notification'] ) ? $request['ShipmentRequest']['Shipment']['ShipmentServiceOptions']['Notification'] : array();

				$notifications[] = array(
					'NotificationCode' => '012',
					'EMail'            => array(
						'EMailAddress' => $shipment->get_email(),
					),
					'Locale'           => array(
						'Language' => strtoupper( $language_details['language'] ),
						'Dialect'  => strtoupper( $language_details['dialect'] ),
					),
				);

				$notifications[] = array(
					'NotificationCode' => '013',
					'EMail'            => array(
						'EMailAddress' => $shipment->get_email(),
					),
					'Locale'           => array(
						'Language' => strtoupper( $language_details['language'] ),
						'Dialect'  => strtoupper( $language_details['dialect'] ),
					),
				);

				$request['ShipmentRequest']['Shipment']['ShipmentServiceOptions']['Notification'] = $notifications;

				$request['ShipmentRequest']['Shipment']['ShipmentIndicationType'] = array(
					'Code' => '02',
				);

				$request['ShipmentRequest']['Shipment']['AlternateDeliveryAddress'] = array(
					'Name'             => $location->get_label(),
					'AttentionName'    => $request['ShipmentRequest']['Shipment']['ShipTo']['AttentionName'],
					'UPSAccessPointID' => $location->get_code( 'edit' ),
					'Address'          => array(
						'AddressLine'       => $location->get_address_1(),
						'City'              => $location->get_city(),
						'PostalCode'        => $location->get_postcode(),
						'CountryCode'       => $location->get_country(),
						'StateProvinceCode' => in_array( $location->get_country(), array( 'US', 'CA' ), true ) ? $location->get_state() : '',
					),
				);
			}
		}

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

		if ( ! $response->is_error() ) {
			$error       = new ShipmentError();
			$body        = $response->get_body();
			$parcel_data = $body['ShipmentResponse'];

			if ( 1 !== absint( $parcel_data['Response']['ResponseStatus']['Code'] ) ) {
				$error->add( 'error', _x( 'There was an unknown error calling the UPS API.', 'ups', 'shiptastic-ups' ) );

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
						$error->add( 'upload', _x( 'Error while uploading UPS label.', 'ups', 'shiptastic-ups' ) );
					}
				} catch ( \Exception $e ) {
					$error->add( 'upload', sprintf( _x( 'Could not convert GIF to PDF file: %1$s', 'ups', 'shiptastic-ups' ), $e->getMessage() ) );
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

			if ( $error->has_errors() ) {
				return $error;
			}

			return true;
		} else {
			return $response->get_error();
		}
	}

	protected function get_timeout( $request_type = 'GET' ) {
		return 'GET' === $request_type ? 30 : 100;
	}

	protected function get_headers( $headers = array() ) {
		$headers = wp_parse_args(
			$headers,
			array(
				'Content-Type'   => $this->get_content_type(),
				'Accept'         => 'application/json',
				'User-Agent'     => 'Shiptastic/' . \Vendidero\Shiptastic\Package::get_version(),
				'transId'        => uniqid(),
				'transactionSrc' => 'Shiptastic/' . Package::get_version(),
			)
		);

		$headers = array_replace_recursive( $headers, $this->get_auth_api()->get_headers() );

		return $headers;
	}

	/**
	 * @param Response $response
	 *
	 * @return Response
	 */
	protected function parse_error( $response ) {
		$error = new ShipmentError();
		$body  = $response->get_body();
		$code  = $response->get_code();

		if ( ! empty( $body['response']['errors'] ) ) {
			foreach ( $body['response']['errors'] as $response_error ) {
				$error->add( $code, wp_kses_post( $response_error['code'] . ': ' . $response_error['message'] ) );
			}
		} elseif ( ! empty( $body['response'] ) ) {
			$error->add( $code, wp_kses_post( $body['response']['code'] . ': ' . $body['response']['message'] ) );
		}

		if ( ! wc_stc_shipment_wp_error_has_errors( $error ) ) {
			$error->add( $code, _x( 'There was an unknown error calling the UPS API.', 'ups', 'shiptastic-ups' ) );
		}

		$response->set_error( $error );

		return $response;
	}

	public function get_url() {
		return $this->is_sandbox() ? 'https://wwwcie.ups.com/api' : 'https://onlinetools.ups.com/api';
	}

	protected function get_auth_instance() {
		if ( Package::use_custom_api() ) {
			return new Auth( $this );
		} else {
			return new OAuthGateway( $this );
		}
	}

	public function get_name() {
		return 'ups';
	}

	public function get_title() {
		return _x( 'UPS', 'shiptastic', 'shiptastic-ups' );
	}
}
