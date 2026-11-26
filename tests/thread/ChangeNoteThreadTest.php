<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The note a player leaves for the Storyteller travels with every change sent together, as plain text of at most a
 * thousand characters.
 */
class ChangeNoteThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-change-note';
	private int $character_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Thread Change Note',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player_id, 'player' );

		$this->character_id = Character::create( [
			'name' => 'Note Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-merits' => [] ],
		] );
		Character::update_xp( $this->character_id, 10, 10 );
	}

	/** @return array<int,object> */
	private function submit_set( ?string $note ): array {
		wp_set_current_user( $this->player_id );
		$changes = [];
		foreach ( [ 'Daredevil', 'Lucky' ] as $name ) {
			$changes[] = [
				'change_type' => 'add_trait',
				'category'    => 'vampire-merits',
				'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name ] ],
				'notes'       => $note,
			];
		}
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'changes' => $changes ] ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['changes'];
	}

	public function test_the_note_is_kept_on_every_change_of_the_set(): void {
		$changes = $this->submit_set( 'Bought at the last game.' );

		foreach ( $changes as $change ) {
			$this->assertSame( 'Bought at the last game.', Change::find( (int) $change->id )->notes );
		}
	}

	public function test_the_note_is_plain_text_and_trimmed(): void {
		$changes = $this->submit_set( "  <b>Bold</b> <script>alert(1)</script> words  \n" );

		$this->assertSame( 'Bold  words', Change::find( (int) $changes[0]->id )->notes, 'tags and the script go, the words stay' );
	}

	public function test_a_note_stops_at_a_thousand_characters(): void {
		$changes = $this->submit_set( str_repeat( 'é', 1500 ) );

		$this->assertSame( 1000, mb_strlen( (string) Change::find( (int) $changes[0]->id )->notes ) );
	}

	public function test_no_note_is_stored_as_none(): void {
		foreach ( [ null, '', "   \n  " ] as $blank ) {
			$changes = $this->submit_set( $blank );
			$this->assertNull( Change::find( (int) $changes[0]->id )->notes );
			foreach ( $changes as $change ) {
				Change::update_status( (int) $change->id, 'rejected', 0, null );
			}
		}
	}
}
