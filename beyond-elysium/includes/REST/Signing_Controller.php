<?php

namespace BeyondElysium\REST;

use BeyondElysium\Services\Pdf_Signer;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the secure-printing settings screen.
 */
class Signing_Controller extends Base_Controller {

	protected $rest_base = 'signing';

	/**
	 * A generated key is useless without a passphrase of at least this length.
	 */
	private const MIN_PASSPHRASE = 8;

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/status', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/settings', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/certificate', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate_certificate' ],
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			],
		] );
	}

	/**
	 * What the settings screen shows: which of the three constants are defined, whether the two files are readable,
	 * whether the opt-in is on, and whether this host could mint a pair at all.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_status( $request ) {
		$availability = Pdf_Signer::availability();

		return $this->success( [
			'enabled'            => Pdf_Signer::opted_in(),
			'available'          => $availability['ok'],
			'code'               => $availability['code'],
			'signing_now'        => Pdf_Signer::should_sign()['ok'],
			'constants'          => Pdf_Signer::constant_report(),
			'can_generate'       => Pdf_Signer::can_generate(),
			'openssl_extension'  => extension_loaded( 'openssl' ),
		] );
	}

	/**
	 * Switches secure printing on or off, site-wide.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( $request ) {
		$enabled = $request->get_param( 'enabled' );
		if ( ! is_bool( $enabled ) && ! in_array( $enabled, [ '0', '1', 0, 1, 'true', 'false' ], true ) ) {
			return $this->error( 'invalid_param', __( 'enabled must be true or false.', 'beyond-elysium' ), 400 );
		}

		update_option( Pdf_Signer::OPT_IN_OPTION, rest_sanitize_boolean( $enabled ) );

		return $this->success( [ 'enabled' => Pdf_Signer::opted_in() ] );
	}

	/**
	 * Mints a self-signed certificate and private key in memory and returns them once, with the exact `wp-config.php`
	 * constants to paste.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_certificate( $request ) {
		if ( ! Pdf_Signer::can_generate() ) {
			return $this->error(
				'openssl_unavailable',
				__( "This server's PHP has no openssl extension, so it cannot generate a certificate. It also cannot sign a PDF by any other route - prints will be marked UNSIGNED.", 'beyond-elysium' ),
				501
			);
		}

		$passphrase = (string) $request->get_param( 'passphrase' );
		if ( strlen( $passphrase ) < self::MIN_PASSPHRASE ) {
			return $this->error(
				'invalid_param',
				sprintf(
					/* translators: %d: minimum number of characters. */
					__( 'Choose a passphrase of at least %d characters. You will need it again in wp-config.php.', 'beyond-elysium' ),
					self::MIN_PASSPHRASE
				),
				400
			);
		}

		$common_name = sanitize_text_field( (string) ( $request->get_param( 'common_name' ) ?: get_bloginfo( 'name' ) ) );
		$days        = (int) ( $request->get_param( 'days' ) ?: 3650 );
		$days        = max( 30, min( 7300, $days ) );

		$generated = Pdf_Signer::generate( $common_name, $passphrase, $days );
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		// The one copy of this key that will ever exist - never cached anywhere.
		nocache_headers();
		$response = $this->success( $generated );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
