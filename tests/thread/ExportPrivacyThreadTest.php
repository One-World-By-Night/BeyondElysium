<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player's export never carries Storyteller text: Storyteller-only blocks and a boon's Storyteller text stay out
 * whatever the request asks, a player cannot mint a transfer from the export route, a Storyteller starts a transfer
 * from the transfer route and still exports everything unless they ask for a player's copy, and a player's verified
 * export still verifies against the live character.
 */
class ExportPrivacyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-export-privacy';
	private int $game_id;
	private int $player;
	private int $storyteller;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Export Privacy', 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [ 'st_comment_start' => '[ST]', 'st_comment_end' => '[/ST]' ] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id, $this->storyteller, 'hst' );

		$this->character = Character::create( [
			'name' => 'Privacy Vampire', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'status' => 'active', 'wp_user_id' => $this->player,
			'biography' => 'Came to the city in 1990. [ST]Secretly a Sabbat mole.[/ST]',
			'sheet_data' => [
				'vampire-identity' => [ 'Clan' => 'Toreador', 'Sect' => 'Camarilla' ],
				'vampire-merits'       => [ [ 'name' => 'Common Sense', 'count' => 1 ] ],
			],
		] );

		$creditor = Character::create( [ 'name' => 'Privacy Creditor', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active' ] );
		$boon     = World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'boon', 'name' => 'A favor',
			'properties' => [ 'boon_level' => '2', 'terms' => 'Carried a message. [ST]The message was a forgery.[/ST]' ], 'created_by' => 1,
		] );
		foreach ( [ 'owed_by' => $this->character, 'owed_to' => $creditor ] as $label => $party ) {
			Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'world_object', 'source_id' => $boon, 'target_type' => 'character', 'target_id' => $party, 'label' => $label, 'created_by' => 1 ] );
		}
	}

	private function export_as( int $user, array $body ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/export" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function flag_merits_storyteller_only(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_schema_blocks', [ 'storyteller_only' => 1 ], [ 'slug' => 'vampire-merits' ] );
	}

	public function test_a_player_never_gets_storyteller_text_back_whatever_the_request_asks(): void {
		foreach ( [ [], [ 'hide_st' => false ] ] as $body ) {
			$response = $this->export_as( $this->player, $body );

			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertStringContainsString( 'Came to the city in 1990.', $response->get_data()['xml'] );
			$this->assertStringNotContainsString( 'Sabbat mole', $response->get_data()['xml'], wp_json_encode( $body ) );
		}
	}

	public function test_a_player_cannot_mint_a_transfer_from_the_export_route(): void {
		$response = $this->export_as( $this->player, [ 'as_transfer' => true ] );

		$this->assertSame( 400, $response->get_status() );
		global $wpdb;
		$kinds = $wpdb->get_col( $wpdb->prepare( "SELECT kind FROM {$wpdb->prefix}be_character_attestations WHERE character_id = %d", $this->character ) );
		$this->assertNotContains( 'transfer', $kinds );
	}

	public function test_a_storyteller_starts_a_transfer_from_the_transfer_route_not_this_one(): void {
		$this->assertSame( 400, $this->export_as( $this->storyteller, [ 'as_transfer' => true ] )->get_status() );
	}

	public function test_a_storyteller_only_block_never_reaches_a_players_export(): void {
		$this->flag_merits_storyteller_only();

		$this->assertStringNotContainsString( 'Common Sense', $this->export_as( $this->player, [ 'hide_st' => true ] )->get_data()['xml'] );
		$this->assertStringContainsString( 'Common Sense', $this->export_as( $this->storyteller, [] )->get_data()['xml'] );
	}

	public function test_a_boons_storyteller_text_is_stripped_for_a_player(): void {
		$xml = $this->export_as( $this->player, [ 'hide_st' => true ] )->get_data()['xml'];

		$this->assertStringContainsString( 'Carried a message.', $xml );
		$this->assertStringNotContainsString( 'forgery', $xml );
	}

	public function test_a_storyteller_still_exports_everything_unless_they_ask_for_a_players_copy(): void {
		$full = $this->export_as( $this->storyteller, [] )->get_data()['xml'];
		$this->assertStringContainsString( 'Sabbat mole', $full );
		$this->assertStringContainsString( 'forgery', $full );

		$redacted = $this->export_as( $this->storyteller, [ 'hide_st' => true ] )->get_data()['xml'];
		$this->assertStringNotContainsString( 'Sabbat mole', $redacted );
		$this->assertStringNotContainsString( 'forgery', $redacted );
	}

	public function test_a_players_verified_export_still_verifies_against_the_live_character(): void {
		$response = $this->export_as( $this->player, [ 'verify' => true ] );
		$code     = $response->get_data()['short_code'];

		wp_set_current_user( 0 );
		$verified = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/verify/{$code}" ) )->get_data();

		$this->assertTrue( $verified['valid'] );
		$this->assertNotContains( false, (array) $verified['still_matches'], wp_json_encode( $verified['still_matches'] ) );
	}
}
