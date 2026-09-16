<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Sheets_Controller`'s two routes, dispatched as real REST requests: `%PDF-`
 * bytes for an owner, `403` for a non-owner, an UNSIGNED-stamped copy when
 * the cert is unreadable, and ST-only values absent from the byte stream for a
 * non-manager. `rest_get_server()->dispatch()` stops short
 * of the real HTTP serve step, so `$response->get_data()['bytes']` is read
 * directly rather than needing the `rest_pre_serve_request` filter to fire
 * (Section 4c's own note on why this route shape stays testable).
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 4c, SP-9
 */
class SheetsControllerThreadTest extends WP_UnitTestCase {

	private $manager_id;
	private $player_id;
	private $other_player_id;
	private $character_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		PdfSigningTestFixture::ensure();
	}

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->manager_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->other_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$game_id = Game::create( [
			'slug'       => 'sheets-controller-test',
			'name'       => 'Sheets Controller Test',
			'created_by' => $this->manager_id,
		] );

		// Real chronicle membership, not just a WP role - Character::create() grants this
		// automatically to a character's own owner, but $other_player_id owns nothing here
		// and needs it granted explicitly to reach the route's permission_callback at all,
		// so the D33 ownership *denial* this test exercises is this controller's own check,
		// not an earlier, different denial for not being a chronicle member.
		\BeyondElysium\Models\Game_Member::ensure_player( (int) $game_id, $this->other_player_id );

		Schema_Block::create( [
			'slug'         => 'sc-identity',
			'name'         => 'Identity',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'text', 'required' => false ] ] ],
			'is_system'    => 0,
		] );
		Schema_Block::create( [
			'slug'             => 'sc-secret',
			'name'             => 'Secret',
			'section_type'     => 'identity_field',
			'definition'       => [ 'fields' => [ [ 'name' => 'Hook', 'field_type' => 'textarea', 'required' => false ] ] ],
			'is_system'        => 0,
			'storyteller_only' => 1,
		] );

		Creature_Stack::create( [
			'slug'             => 'sc-stack',
			'name'             => 'Sheets Controller Test Stack',
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'sc-identity' ],
				[ 'block_slug' => 'sc-secret' ],
			] ],
			'is_system'        => 0,
			'created_by'       => $this->manager_id,
		] );

		Template::create( [
			'stack_slug'    => 'sc-stack',
			'name'          => 'Sheets Controller Test Layout',
			'template_type' => 'sheet_full',
			'layout'        => [
				'version'  => 1,
				'columns'  => 3,
				'sections' => [
					[ 'block_slug' => 'sc-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false ],
					[ 'block_slug' => 'sc-secret', 'column' => 1, 'order' => 2, 'title' => 'Secret', 'display' => null, 'collapsed' => false ],
				],
			],
			'is_system'     => 0,
			'created_by'    => $this->manager_id,
		] );

		$this->character_id = Character::create( [
			'name'       => 'Owned Character',
			'owner_slug' => 'sheets-controller-test',
			'stack_slug' => 'sc-stack',
			'wp_user_id' => $this->player_id,
			'sheet_data' => [
				'sc-identity' => [ 'Clan' => 'Tremere' ],
				'sc-secret'   => [ 'Hook' => 'Secretly working for the Sabbat' ],
			],
			'created_by' => $this->manager_id,
		] );
	}

	private function pdf_request( int $as_user, string $character_ids ): \WP_REST_Response {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'GET', '/be/v1/sheets-controller-test/sheets/pdf' );
		$request->set_url_params( [ 'game_slug' => 'sheets-controller-test' ] );
		$request->set_param( 'character_ids', $character_ids );
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_owning_player_gets_real_pdf_bytes(): void {
		$response = $this->pdf_request( $this->player_id, (string) $this->character_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_a_manager_gets_real_pdf_bytes_for_any_character(): void {
		$response = $this->pdf_request( $this->manager_id, (string) $this->character_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_a_non_owner_is_denied(): void {
		$response = $this->pdf_request( $this->other_player_id, (string) $this->character_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ownership_denied', $response->as_error()->get_error_code() );
	}

	/**
	 * 1.0.0-review F-042, owner ruling 2026-09-14: with no signing certificate a sheet still
	 * prints - clearly marked unsigned, never passed off as a signed copy. It used to refuse with
	 * `503 signing_unavailable`.
	 */
	public function test_with_no_signing_certificate_a_sheet_prints_marked_unsigned(): void {
		// Hides, then always restores, the *shared* cert file (PdfWriterThreadTest's
		// tests run in this same process and need it readable again immediately after).
		$cert_path = BE_PDF_SIGNING_CERT;
		rename( $cert_path, $cert_path . '.hidden' );
		try {
			$response = $this->pdf_request( $this->player_id, (string) $this->character_id );
		} finally {
			rename( $cert_path . '.hidden', $cert_path );
		}

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$bytes = (string) $response->get_data()['bytes'];
		$this->assertStringStartsWith( '%PDF-', $bytes );
		$this->assertStringNotContainsString( '/ByteRange', $bytes, 'no signature dictionary in an unsigned copy' );
		$this->assertStringEndsWith( '-unsigned.pdf', $response->get_data()['filename'] );
		$this->assertStringContainsString( 'UNSIGNED', self::text_of( $bytes ) );
	}

	private static function text_of( string $bytes ): string {
		if ( ! shell_exec( 'command -v pdftotext' ) ) {
			self::markTestSkipped( 'pdftotext (poppler) is not installed.' );
		}
		$path = wp_tempnam( 'be-unsigned' );
		file_put_contents( $path, $bytes );
		$text = (string) shell_exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
		unlink( $path );
		return $text;
	}

	public function test_storyteller_only_values_are_absent_from_the_byte_stream_for_a_non_manager(): void {
		if ( ! shell_exec( 'command -v pdftotext' ) ) {
			$this->markTestSkipped( 'pdftotext (poppler) is not installed.' );
		}

		$player_bytes = $this->pdf_request( $this->player_id, (string) $this->character_id )->get_data()['bytes'];
		$path         = sys_get_temp_dir() . '/be-sheets-controller-test-player.pdf';
		file_put_contents( $path, $player_bytes );
		$player_text = (string) shell_exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
		unlink( $path );

		$this->assertStringNotContainsString( 'Secretly working for the Sabbat', $player_text );
		$this->assertStringContainsString( 'Tremere', $player_text, 'an ordinary field is untouched' );

		$manager_bytes = $this->pdf_request( $this->manager_id, (string) $this->character_id )->get_data()['bytes'];
		file_put_contents( $path, $manager_bytes );
		$manager_text = (string) shell_exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
		unlink( $path );

		$this->assertMatchesRegularExpression(
			'/Secretly working for the\s+Sabbat/',
			$manager_text,
			'a manager keeps every section'
		);
	}

	public function test_availability_route_reports_ok_when_signing_is_configured(): void {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/sheets-controller-test/sheets/availability' );
		$request->set_url_params( [ 'game_slug' => 'sheets-controller-test' ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
	}

	public function test_more_than_fifty_character_ids_is_rejected(): void {
		$ids      = implode( ',', range( 1, 51 ) );
		$response = $this->pdf_request( $this->manager_id, $ids );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'too_many_characters', $response->as_error()->get_error_code() );
	}
}
