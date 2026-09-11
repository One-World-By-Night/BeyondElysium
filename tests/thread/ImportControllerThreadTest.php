<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The REST layer around `Import_Controller::parse()`/`get_job()`: real `.gex` fixture
 * uploads dispatched through the actual REST server, not a direct `GEX_Parser` call, so
 * the file-sniffing, permission boundary, and job-storage/retrieval round trip are all
 * exercised for real (workflow-0.8.md Step 6).
 *
 * @see BE_PROCESS/workflow-0.8.md Step 6
 */
class ImportControllerThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-import-game';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Import Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function path( string $relative ): string {
		return BE_PLUGIN_ROOT . '/' . $relative;
	}

	private function upload_request( string $file_path ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/import/parse" );
		$request->set_file_params( [
			'file' => [
				'tmp_name' => $file_path,
				'name'     => basename( $file_path ),
				'error'    => 0,
				'size'     => filesize( $file_path ),
				'type'     => 'application/octet-stream',
			],
		] );
		return $request;
	}

	public function test_parsing_a_real_gex_file_returns_a_job_id_and_correct_counts(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->upload_request( $this->path( 'GV301Source/Code/New Game Items.gex' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data['job_id'] );
		$this->assertSame( 'GVBE', $data['format'] );
		$this->assertEqualsWithDelta( 2.396, $data['version'], 0.0001 );
		$this->assertSame( 46, $data['counts']['items'] );
		$this->assertSame( 0, $data['counts']['characters'] );
	}

	public function test_a_2397_file_exposes_the_extra_sections_a_2396_file_does_not(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->upload_request( $this->path( 'GV301Source/Code/Fetishes and Talens.gex' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertEqualsWithDelta( 2.397, $data['version'], 0.0001 );
		$this->assertSame( 43, $data['counts']['items'] );
	}

	public function test_fetching_a_parsed_job_returns_the_same_preview(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$parse    = $this->dispatch( $this->upload_request( $this->path( 'GV301Source/Code/New Game Items.gex' ) ) );
		$job_id   = $parse->get_data()['job_id'];

		$get   = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/import/{$job_id}" );
		$fetch = $this->dispatch( $get );

		$this->assertSame( 200, $fetch->get_status() );
		$this->assertSame( 46, $fetch->get_data()['counts']['items'] );
	}

	public function test_fetching_an_unknown_job_id_is_404(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/import/does-not-exist" );
		$this->assertSame( 404, $this->dispatch( $get )->get_status() );
	}

	public function test_a_non_grapevine_file_is_rejected(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$tmp = tempnam( sys_get_temp_dir(), 'be-import-test' );
		file_put_contents( $tmp, 'not a grapevine file at all' );

		$response = $this->dispatch( $this->upload_request( $tmp ) );
		unlink( $tmp );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_gvbg_game_file_is_explicitly_refused_not_silently_parsed(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$tmp = tempnam( sys_get_temp_dir(), 'be-import-test' );
		// Length-prefixed "GVBG" header, matching how a real header is written (Root.bas
		// PutStrB) - a fixed 4-byte offset sniff would miss this the same way a real
		// GVBG file's header is laid out.
		file_put_contents( $tmp, "\x04\x00GVBG" );

		$response = $this->dispatch( $this->upload_request( $tmp ) );
		unlink( $tmp );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unsupported_format', $response->get_data()['code'] );
	}

	// -------------------------------------------------------------------------
	// XML exchange files (workflow-0.8.md Step 9) - real samples, dispatched through the
	// actual REST server exactly like the binary tests above, not a direct
	// GEX_Xml_Parser call - proving the format-sniff branch and the "identical shape" it
	// depends on actually hold end to end, not just at the parser layer.
	// -------------------------------------------------------------------------

	public function test_parsing_a_real_xml_gex_file_returns_the_correct_format_and_counts(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->upload_request( $this->path( 'GV301Source/Code/Rotes.gex' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertNotEmpty( $data['job_id'] );
		$this->assertSame( 'XML', $data['format'] );
		$this->assertEqualsWithDelta( 2.396, $data['version'], 0.0001 );
		$this->assertSame( 201, $data['counts']['rotes'] );
		$this->assertSame( 0, $data['counts']['items'] );
	}

	public function test_committing_a_real_xml_import_creates_world_objects(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$parse  = $this->dispatch( $this->upload_request( $this->path( 'GV301Source/Code/Artifacts and Devices.gex' ) ) );
		$job_id = $parse->get_data()['job_id'];

		$before   = \BeyondElysium\Models\World_Object::count_for_game( $this->game_id );
		$commit   = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/import/{$job_id}/commit" );
		$response = $this->dispatch( $commit );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertCount( 34, $data['items'] );
		$this->assertSame( $before + 34, \BeyondElysium\Models\World_Object::count_for_game( $this->game_id ) );

		$claws = current( array_filter(
			\BeyondElysium\Models\World_Object::for_game( $this->game_id, [ 'search' => 'Claws' ] ),
			static fn( $o ) => $o->name === 'Claws'
		) );
		$this->assertNotFalse( $claws, 'an XML-sourced item must be findable by name like any other' );
		$this->assertSame( 'Lethal', $claws->properties['damage_type'] );
	}

	/**
	 * No `.gex` XML sample in this repo (real or otherwise) contains a character
	 * element - refusing loudly is the point of `GEX_Xml_Parser`'s own design
	 * (workflow-0.8.md Step 9c): a silent skip would let a character-bearing file
	 * "succeed" as a clean, empty import instead of surfacing as a parse failure.
	 */
	public function test_an_xml_file_with_an_unrecognized_element_is_refused_not_silently_emptied(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$tmp = tempnam( sys_get_temp_dir(), 'be-import-xml-test' );
		file_put_contents(
			$tmp,
			'<?xml version="1.0"?><grapevine version="2.396"><character name="Test"/></grapevine>'
		);

		$response = $this->dispatch( $this->upload_request( $tmp ) );
		unlink( $tmp );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'parse_failed', $response->get_data()['code'] );
	}

	public function test_a_player_role_user_gets_403(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$response = $this->dispatch( $this->upload_request( $this->path( 'GV301Source/Code/New Game Items.gex' ) ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Neither real sample `.gex` file in this repo carries a player record (confirmed
	 * while writing this test: both parse to 0 players), so player matching (Step 6d) has
	 * no real fixture to exercise it against. A hand-built minimal GVBE buffer - real
	 * bytes in the real wire format, not a mock of `match_players()` - is the fallback,
	 * same reasoning `GexParserTest` already used for the character-record dispatch path.
	 *
	 * @param array<int,array{name:string,email:string}> $players
	 * @return string
	 */
	private function build_gex_with_players( array $players ): string {
		$put_str = static function ( string $s ): string {
			return pack( 'v', strlen( $s ) ) . $s;
		};
		$put_bool = static function ( bool $b ): string {
			return pack( 'v', $b ? -1 : 0 );
		};

		$out  = $put_str( 'GVBE' );
		$out .= pack( 'e', 2.397 ); // version
		$out .= pack( 'v', 0 ); // calendar count (>=2.395)
		$out .= pack( 'v', 0 ); // apr count (>=2.397)
		$out .= pack( 'v', 0 ); // xp award count
		$out .= pack( 'v', 0 ); // template count
		$out .= pack( 'v', count( $players ) ); // player count

		foreach ( $players as $player ) {
			$out .= $put_str( $player['name'] );      // Name
			$out .= $put_str( '' );                    // ID
			$out .= $put_str( $player['email'] );      // EMail
			$out .= $put_str( '' );                    // Phone
			$out .= $put_str( '' );                    // Position
			$out .= $put_str( 'Active' );               // Status (>=2.397)
			$out .= pack( 'e', 0.0 );                  // LastModified
			$out .= pack( 'g', 0.0 );                  // Experience.Unspent (single)
			$out .= pack( 'g', 0.0 );                  // Experience.Earned (single)
			$out .= pack( 'v', 0 );                    // Experience history count
			$out .= $put_str( '' );                    // Address
			$out .= $put_str( '' );                    // Notes
		}

		$out .= pack( 'v', 0 ); // character count
		$out .= pack( 'v', 0 ); // query count
		$out .= pack( 'v', 0 ); // item count
		$out .= pack( 'v', 0 ); // rote count
		$out .= pack( 'v', 0 ); // location count
		$out .= pack( 'v', 0 ); // action count
		$out .= pack( 'v', 0 ); // plot count
		$out .= pack( 'v', 0 ); // rumor count

		return $out;
	}

	public function test_a_player_matched_by_email_is_not_flagged(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		self::factory()->user->create( [ 'user_email' => 'jane@example.test', 'display_name' => 'Jane Doe' ] );

		$tmp = tempnam( sys_get_temp_dir(), 'be-import-players' );
		file_put_contents( $tmp, $this->build_gex_with_players( [
			[ 'name' => 'Jane Doe', 'email' => 'jane@example.test' ],
		] ) );

		$response = $this->dispatch( $this->upload_request( $tmp ) );
		unlink( $tmp );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['players_needing_match'] );
	}

	public function test_an_unmatched_player_is_flagged_with_a_display_name_suggestion(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		self::factory()->user->create( [ 'user_email' => 'someone-else@example.test', 'display_name' => 'Marcus Vitel' ] );

		$tmp = tempnam( sys_get_temp_dir(), 'be-import-players' );
		file_put_contents( $tmp, $this->build_gex_with_players( [
			[ 'name' => 'Marcus Vitel', 'email' => 'not-in-wp-at-all@example.test' ],
		] ) );

		$response = $this->dispatch( $this->upload_request( $tmp ) );
		unlink( $tmp );

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['players_needing_match'] );
		$this->assertSame( 'Marcus Vitel', $data['players_needing_match'][0]['gv_name'] );
		$this->assertNotEmpty( $data['players_needing_match'][0]['suggestions'] );
		$this->assertSame( 'Marcus Vitel', $data['players_needing_match'][0]['suggestions'][0]['display_name'] );
	}
}
