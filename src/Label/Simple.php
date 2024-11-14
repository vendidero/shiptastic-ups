<?php

namespace Vendidero\Shiptastic\UPS\Label;

use Vendidero\Shiptastic\UPS\Package;
use Vendidero\Shiptastic\Labels\Label;

defined( 'ABSPATH' ) || exit;

/**
 * UPS Label class.
 */
class Simple extends Label {

	/**
	 * Stores product data.
	 *
	 * @var array
	 */
	protected $extra_data = array(
		'incoterms'    => '',
		'form_01_path' => '',
	);

	public function get_type() {
		return 'simple';
	}

	public function get_shipping_provider( $context = 'view' ) {
		return 'ups';
	}

	public function get_additional_file_types() {
		$file_types = parent::get_additional_file_types();

		return array_merge( $file_types, array( 'form_01' ) );
	}

	public function get_incoterms( $context = 'view' ) {
		return $this->get_prop( 'incoterms', $context );
	}

	public function get_form_01_path( $context = 'view' ) {
		return $this->get_path( $context, 'form_01' );
	}

	public function set_form_01_path( $path ) {
		$this->set_path( $path, 'form_01' );
	}

	public function set_incoterms( $incoterms ) {
		$this->set_prop( 'incoterms', $incoterms );
	}

	/**
	 * @return \WP_Error|true
	 */
	public function fetch() {
		$result = Package::get_api()->get_label( $this );

		return $result;
	}

	public function delete( $force_delete = false ) {
		Package::get_api()->cancel_label( $this );

		return parent::delete( $force_delete );
	}
}
