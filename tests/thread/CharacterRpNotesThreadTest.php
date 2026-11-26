<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A character's RP notes are a Storyteller's own: a manager saves and reads them, and a player neither saves nor
 * receives them.
 */
class CharacterRpNotesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-rp-notes';
	private int $game_id;
	private int $player;
	private int $hst;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'RP Notes by Night' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->character_id = (int) Character::create( [
			'name'       => 'Noted Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
			'rp_notes'   => 'Keeps a ledger of debts.',
			'sheet_data' => [],
		] );
	}

	private function put( int $as, array $body ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/characters/{$this->character_id}" );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function read( int $as ) {
		wp_set_current_user( $as );
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}" ) );
	}

	private function stored(): ?string {
		return Character::find( $this->character_id )->rp_notes;
	}

	public function test_a_storyteller_saves_the_rp_notes(): void {
		$response = $this->put( $this->hst, [ 'rp_notes' => 'Owes Marcus a favour.' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Owes Marcus a favour.', $this->stored() );
		$this->assertSame( 'Owes Marcus a favour.', $response->get_data()->rp_notes );
	}

	public function test_a_storyteller_reads_them_and_the_player_never_does(): void {
		$this->assertSame( 'Keeps a ledger of debts.', $this->read( $this->hst )->get_data()->rp_notes );

		$as_player = $this->read( $this->player )->get_data();
		$this->assertObjectNotHasProperty( 'rp_notes', $as_player );
		$this->assertStringNotContainsString( 'ledger of debts', (string) wp_json_encode( $as_player ) );
	}

	public function test_a_player_cannot_save_them_but_can_save_what_is_theirs(): void {
		$response = $this->put( $this->player, [ 'rp_notes' => 'Nothing to see here.', 'biography' => '<p>Born in 1923.</p>' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Keeps a ledger of debts.', $this->stored(), 'the notes are untouched' );
		$this->assertSame( '<p>Born in 1923.</p>', Character::find( $this->character_id )->biography );
		$this->assertObjectNotHasProperty( 'rp_notes', $response->get_data() );
	}

	public function test_a_storyteller_can_clear_them(): void {
		$this->assertSame( 200, $this->put( $this->hst, [ 'rp_notes' => '' ] )->get_status() );
		$this->assertContains( $this->stored(), [ null, '' ] );
	}
}
