<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Pdf_Signer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Secure printing is an opt-in, and the plugin will mint a certificate but never install one.
 */
class SecurePrintingThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-secure-printing';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Secure Printing',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		delete_option( Pdf_Signer::OPT_IN_OPTION );
	}

	public function tearDown(): void {
		delete_option( Pdf_Signer::OPT_IN_OPTION );
		parent::tearDown();
	}

	public function test_secure_printing_is_off_until_someone_turns_it_on(): void {
		$this->assertFalse( Pdf_Signer::opted_in(), 'The default must be off.' );
		$this->assertFalse( Pdf_Signer::should_sign()['ok'] );
		$this->assertSame( 'secure_printing_off', Pdf_Signer::should_sign()['code'] );
	}

	/**
	 * The distinction the settings screen and the character sheet both depend on: "switched off" and "no certificate" are
	 * different states and must not collapse into one message.
	 */
	public function test_switched_off_reports_the_opt_in_not_the_certificate(): void {
		delete_option( Pdf_Signer::OPT_IN_OPTION );

		$this->assertSame(
			'secure_printing_off',
			Pdf_Signer::should_sign()['code'],
			'While switched off, the reason is the switch - never a certificate complaint.'
		);

		update_option( Pdf_Signer::OPT_IN_OPTION, true );

		$this->assertNotSame(
			'secure_printing_off',
			Pdf_Signer::should_sign()['code'],
			'Once switched on, the answer must come from the certificate instead.'
		);
	}

	public function test_the_opt_in_alone_never_decides_whether_a_print_is_signed(): void {
		update_option( Pdf_Signer::OPT_IN_OPTION, true );

		$this->assertSame(
			Pdf_Signer::availability(),
			Pdf_Signer::should_sign(),
			'With the switch on, signing is exactly what the certificate allows - no more.'
		);
	}

	public function test_only_an_administrator_may_ask_for_a_certificate(): void {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		wp_set_current_user( $hst );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', '/be/v1/signing/certificate' )
		);

		$this->assertSame(
			403,
			$response->get_status(),
			'A Storyteller runs a chronicle; a private key is the site operator\'s business.'
		);
	}

	public function test_the_certificate_route_refuses_a_get(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/be/v1/signing/certificate' )
		);

		$this->assertSame(
			404,
			$response->get_status(),
			'POST only - there must be no URL anyone can bookmark or share that mints a key.'
		);
	}

	public function test_a_weak_passphrase_is_refused_before_anything_is_generated(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/be/v1/signing/certificate' );
		$request->set_body_params( [ 'passphrase' => 'short' ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The load-bearing claim of the whole design: generating writes nothing anywhere.
	 */
	public function test_generating_a_certificate_stores_nothing(): void {
		if ( ! Pdf_Signer::can_generate() ) {
			$this->markTestSkipped( 'No openssl extension on this PHP.' );
		}

		global $wpdb;
		$options_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );

		$generated = Pdf_Signer::generate( 'Test Chronicle', 'a-real-passphrase', 365 );

		$this->assertIsArray( $generated );
		$this->assertStringContainsString( 'BEGIN CERTIFICATE', $generated['certificate'] );
		$this->assertStringContainsString( 'ENCRYPTED PRIVATE KEY', $generated['private_key'] );

		$this->assertSame(
			$options_before,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
			'Generating must not write a single option - not the key, not the passphrase.'
		);

		$this->assertFalse(
			openssl_pkey_get_private( $generated['private_key'], 'the-wrong-passphrase' ),
			'The exported key must be encrypted with the passphrase given.'
		);
		$this->assertNotFalse(
			openssl_pkey_get_private( $generated['private_key'], 'a-real-passphrase' )
		);
	}

	public function test_a_generated_pair_actually_matches(): void {
		if ( ! Pdf_Signer::can_generate() ) {
			$this->markTestSkipped( 'No openssl extension on this PHP.' );
		}

		$generated = Pdf_Signer::generate( 'Test Chronicle', 'a-real-passphrase', 365 );
		$key       = openssl_pkey_get_private( $generated['private_key'], 'a-real-passphrase' );

		$this->assertTrue(
			openssl_x509_check_private_key( $generated['certificate'], $key ),
			'A certificate that does not match its key would fail only at signing time.'
		);
	}
}
