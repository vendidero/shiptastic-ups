<?php

namespace Vendidero\Shiptastic\UPS\Api;

use Vendidero\Shiptastic\API\Auth\OAuth;
use Vendidero\Shiptastic\SecretBox;
use Vendidero\Shiptastic\ShipmentError;
use Vendidero\Shiptastic\UPS\Package;

defined( 'ABSPATH' ) || exit;

class Auth extends OAuth {

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

	public function get_url() {
		return $this->get_api()->is_sandbox() ? 'https://wwwcie.ups.com/security/v1/oauth' : 'https://onlinetools.ups.com/security/v1/oauth';
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
				'Authorization' => 'Basic ' . base64_encode( Package::get_api_username() . ':' . Package::get_api_password() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);

		if ( ! $response->is_error() ) {
			$body         = $response->get_body();
			$access_token = isset( $body['access_token'] ) ? $body['access_token'] : '';
			$expires_in   = absint( isset( $body['expires_in'] ) ? $body['expires_in'] : 3599 );

			if ( ! empty( $access_token ) ) {
				$encrypted = SecretBox::encrypt( $access_token );

				if ( ! is_wp_error( $encrypted ) ) {
					$access_token = $encrypted;
				}

				set_transient( 'shiptastic_ups_access_token', $access_token, $expires_in );

				return true;
			}

			$response->set_error( new ShipmentError( 'auth', 'Error while authenticating with UPS' ) );

			return $response;
		} else {
			delete_transient( 'shiptastic_ups_access_token' );

			return $response;
		}
	}

	public function revoke() {
		delete_transient( 'shiptastic_ups_access_token' );
	}
}
