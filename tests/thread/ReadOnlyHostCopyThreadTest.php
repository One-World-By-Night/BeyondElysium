<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Transfer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A host's own copy of a visiting character is read-only for its player: refused while an open, agreed
 * keep-current visit makes home the place to edit, and refused again once the visit has ended, until another
 * one reopens it.
 */
class ReadOnlyHostCopyThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-readonly-home';
	private string $host_slug = 'thread-readonly-host';
	private int $player_id;
	private int $character_id;
	private object $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->home_slug, 'name' => 'Readonly Home' ] );
		Game::create( [ 'slug' => $this->host_slug, 'name' => 'Readonly Host' ] );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->player_id );

		$this->character_id = Character::create( [
			'name' => 'Visiting Copy', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
			'wp_user_id' => $this->player_id,
		] );
		$this->character = Character::find( $this->character_id );
	}

	private function create_inbound_visit( string $state ): int {
		$id = Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'inbound', 'state' => $state, 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Readonly Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Readonly Host',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => 1,
		] );
		if ( $state === 'visiting' ) {
			Transfer::transition( $id, 'visiting', [ 'keep_current' => 1, 'keep_current_accepted' => 1 ] );
		}
		return $id;
	}

	private function submit_change() {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'xp_earn' );
		$request->set_param( 'category', 'experience' );
		$request->set_param( 'change_data', [ 'amount' => 1 ] );
		return rest_get_server()->dispatch( $request );
	}

	private function submit_change_set() {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_param( 'changes', [ [ 'change_type' => 'xp_earn', 'category' => 'experience', 'change_data' => [ 'amount' => 1 ] ] ] );
		return rest_get_server()->dispatch( $request );
	}

	private function update_character() {
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->host_slug}/characters/{$this->character_id}" );
		$request->set_param( 'biography', 'Updated.' );
		return rest_get_server()->dispatch( $request );
	}

	public function test_edits_are_refused_while_kept_current_elsewhere(): void {
		$this->create_inbound_visit( 'visiting' );

		$change_response     = $this->submit_change();
		$character_response  = $this->update_character();

		$this->assertSame( 403, $change_response->get_status() );
		$this->assertSame( 'kept_current_elsewhere', $change_response->as_error()->get_error_code() );
		$this->assertSame( 403, $character_response->get_status() );
		$this->assertSame( 'kept_current_elsewhere', $character_response->as_error()->get_error_code() );

		$set_response = $this->submit_change_set();
		$this->assertSame( 403, $set_response->get_status() );
		$this->assertSame( 'kept_current_elsewhere', $set_response->as_error()->get_error_code() );
	}

	public function test_edits_are_refused_after_the_visit_ends(): void {
		$visit_id = $this->create_inbound_visit( 'visiting' );
		Transfer::transition( $visit_id, 'ended' );

		$change_response    = $this->submit_change();
		$character_response = $this->update_character();

		$this->assertSame( 403, $change_response->get_status() );
		$this->assertSame( 'visit_ended', $change_response->as_error()->get_error_code() );
		$this->assertSame( 403, $character_response->get_status() );
		$this->assertSame( 'visit_ended', $character_response->as_error()->get_error_code() );

		$set_response = $this->submit_change_set();
		$this->assertSame( 403, $set_response->get_status() );
		$this->assertSame( 'visit_ended', $set_response->as_error()->get_error_code() );
	}

	public function test_a_manager_can_still_edit_the_copy(): void {
		$this->create_inbound_visit( 'visiting' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$character_response = $this->update_character();
		$change_response    = $this->submit_change();

		$this->assertSame( 200, $character_response->get_status() );
		$this->assertSame( 201, $change_response->get_status(), 'a manager\'s own change still submits - K6 forwards it on approval, not on submit' );
	}

	public function test_a_new_visit_reopens_editing(): void {
		$first = $this->create_inbound_visit( 'visiting' );
		Transfer::transition( $first, 'ended' );
		$this->create_inbound_visit( 'visiting' );

		$character_response = $this->update_character();

		$this->assertSame( 403, $character_response->get_status(), 'still kept current, not a bare pass' );
		$this->assertSame( 'kept_current_elsewhere', $character_response->as_error()->get_error_code() );
	}

	public function test_a_new_non_kept_current_visit_reopens_editing(): void {
		$first = $this->create_inbound_visit( 'visiting' );
		Transfer::transition( $first, 'ended' );
		Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Readonly Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Readonly Host',
			'payload_hash' => str_repeat( 'b', 64 ), 'initiated_by' => 1,
		] );

		$character_response = $this->update_character();

		$this->assertSame( 200, $character_response->get_status() );
	}
}
