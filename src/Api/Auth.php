<?php

namespace Vendidero\Shiptastic\UPS\Api;

use Vendidero\Shiptastic\API\Auth\OAuth;
use Vendidero\Shiptastic\SecretBox;
use Vendidero\Shiptastic\ShipmentError;
use Vendidero\Shiptastic\UPS\Package;

defined( 'ABSPATH' ) || exit;

class Auth extends OAuth {

	public function get_url() {
		return $this->get_api()->is_sandbox() ? 'https://wwwcie.ups.com/security/v1/oauth' : 'https://onlinetools.ups.com/security/v1/oauth';
	}

	public function is_connected() {
		$username = Package::get_api_client_id();

		if ( empty( $username ) ) {
			return false;
		}

		return true;
	}

	public function auth() {
		$response = $this->get_api()->post(
			$this->get_request_url( 'token' ),
			array(
				'grant_type' => 'client_credentials',
			),
			array(
				'x-merchant-id' => Package::get_account_number(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( Package::get_api_client_id() . ':' . Package::get_api_client_secret() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);

		if ( ! $response->is_error() ) {
			$body = $response->get_body();

			if ( ! empty( $body['access_token'] ) ) {
				$this->update_access_and_refresh_token( $body );
			} else {
				$response->set_error( new ShipmentError( 'auth', 'Error while authenticating with UPS' ) );
			}
		}

		return $response;
	}
}
