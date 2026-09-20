<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Position;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.10 (F2): court/office positions - free-text titles (with a preset picker),
 * optionally under a faction, with `holder_public = 0` hiding WHO holds a visible position
 * from a non-manager without hiding THAT it is held.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.10
 */
class PositionsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-positions';
	private int $game_id;
	private int $storyteller_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Positions' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );
	}

	private function send( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function make_player(): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $this->storyteller_id,
		] );
		return [ $player_id, $character_id ];
	}

	// -------------------------------------------------------------------------
	// holder_public = 0: everyone still sees the title is held, no non-manager
	// sees by whom.
	// -------------------------------------------------------------------------

	public function test_a_non_public_holder_is_hidden_from_a_plain_viewer(): void {
		[ $player_id, $character_id ] = $this->make_player();
		wp_set_current_user( $this->storyteller_id );
		$position_id = $this->send( 'POST', '/positions', [
			'title' => 'Sheriff', 'character_id' => $character_id, 'holder_public' => false,
		] )->get_data()['id'];

		wp_set_current_user( $player_id );
		$data = current( array_filter(
			$this->send( 'GET', '/positions' )->get_data(),
			static fn( $p ) => $p['id'] === $position_id
		) );

		$this->assertTrue( $data['held'] );
		$this->assertNull( $data['character_id'] );
		$this->assertArrayNotHasKey( 'holder_public', $data );
	}

	public function test_a_manager_sees_the_holder_regardless_of_holder_public(): void {
		[ , $character_id ] = $this->make_player();
		wp_set_current_user( $this->storyteller_id );
		$position_id = $this->send( 'POST', '/positions', [
			'title' => 'Sheriff', 'character_id' => $character_id, 'holder_public' => false,
		] )->get_data()['id'];

		$data = current( array_filter(
			$this->send( 'GET', '/positions' )->get_data(),
			static fn( $p ) => $p['id'] === $position_id
		) );

		$this->assertSame( $character_id, $data['character_id'] );
		$this->assertFalse( $data['holder_public'] );
	}

	public function test_a_vacant_position_is_not_held(): void {
		wp_set_current_user( $this->storyteller_id );
		$this->send( 'POST', '/positions', [ 'title' => 'Sheriff' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		Game_Member::set_role( $this->game_id, get_current_user_id(), 'player' );
		$data = $this->send( 'GET', '/positions' )->get_data();

		$this->assertFalse( $data[0]['held'] );
	}

	// -------------------------------------------------------------------------
	// set_holder() records history; changing holders closes the outgoing row
	// and opens a new one, never leaving two open rows at once.
	// -------------------------------------------------------------------------

	public function test_changing_the_holder_closes_the_previous_history_row(): void {
		[ , $first_id ]  = $this->make_player();
		[ , $second_id ] = $this->make_player();
		wp_set_current_user( $this->storyteller_id );
		$position_id = $this->send( 'POST', '/positions', [ 'title' => 'Sheriff', 'character_id' => $first_id ] )
			->get_data()['id'];

		$this->send( 'PUT', "/positions/{$position_id}", [ 'character_id' => $second_id ] );

		$history = $this->send( 'GET', "/positions/{$position_id}/history" )->get_data();
		$this->assertCount( 2, $history );
		$first  = current( array_filter( $history, static fn( $h ) => $h['character_id'] === $first_id ) );
		$second = current( array_filter( $history, static fn( $h ) => $h['character_id'] === $second_id ) );
		$this->assertNotNull( $first['ended'] );
		$this->assertNull( $second['ended'] );
	}

	public function test_vacating_a_position_ends_its_open_history_row_with_no_replacement(): void {
		[ , $character_id ] = $this->make_player();
		wp_set_current_user( $this->storyteller_id );
		$position_id = $this->send( 'POST', '/positions', [ 'title' => 'Sheriff', 'character_id' => $character_id ] )
			->get_data()['id'];

		$this->send( 'PUT', "/positions/{$position_id}", [ 'character_id' => null ] );

		$data = $this->send( 'GET', '/positions' )->get_data();
		$found = current( array_filter( $data, static fn( $p ) => $p['id'] === $position_id ) );
		$this->assertFalse( $found['held'] );

		$history = $this->send( 'GET', "/positions/{$position_id}/history" )->get_data();
		$this->assertNotNull( $history[0]['ended'] );
	}

	// -------------------------------------------------------------------------
	// Deleting a position cascades its history.
	// -------------------------------------------------------------------------

	public function test_deleting_a_position_cascades_its_history(): void {
		[ , $character_id ] = $this->make_player();
		wp_set_current_user( $this->storyteller_id );
		$position_id = $this->send( 'POST', '/positions', [ 'title' => 'Sheriff', 'character_id' => $character_id ] )
			->get_data()['id'];

		$response = $this->send( 'DELETE', "/positions/{$position_id}" );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( [], Position::for_game( $this->game_id ) );
	}

	// -------------------------------------------------------------------------
	// A position can attach to a faction and moves with faction_id.
	// -------------------------------------------------------------------------

	public function test_a_position_can_be_scoped_to_a_faction(): void {
		wp_set_current_user( $this->storyteller_id );
		$faction_id = (int) Faction::create( [ 'game_id' => $this->game_id, 'name' => 'The Camarilla', 'faction_type' => 'sect', 'created_by' => $this->storyteller_id ] );
		$this->send( 'POST', '/positions', [ 'title' => 'Prince', 'faction_id' => $faction_id ] );

		// A "?" embedded straight into the route string never matches any registered
		// route pattern - the param has to be set via WP_REST_Request::set_param().
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/positions" );
		$request->set_param( 'faction_id', $faction_id );
		$scoped   = rest_get_server()->dispatch( $request )->get_data();
		$unscoped = $this->send( 'GET', '/positions' )->get_data();

		$this->assertCount( 1, $scoped );
		$this->assertCount( 1, $unscoped );
	}

	// -------------------------------------------------------------------------
	// The title preset groups are real data, manager-only.
	// -------------------------------------------------------------------------

	public function test_position_presets_lists_the_real_groups(): void {
		wp_set_current_user( $this->storyteller_id );
		$presets = $this->send( 'GET', '/position-presets' )->get_data();

		$this->assertArrayHasKey( 'Camarilla court', $presets );
		$this->assertContains( 'Prince', $presets['Camarilla court'] );
	}

	public function test_a_player_cannot_create_a_position(): void {
		[ $player_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', '/positions', [ 'title' => 'Nope' ] );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// `notes` is now an HtmlEditor field (1.2.5-design-workflow.md §B2) -
	// wp_kses_post(), not sanitize_textarea_field(), which would silently strip
	// every real formatting tag a Storyteller actually types.
	// -------------------------------------------------------------------------

	public function test_notes_keeps_real_formatting_on_create(): void {
		wp_set_current_user( $this->storyteller_id );
		$position_id = $this->send( 'POST', '/positions', [
			'title' => 'Sheriff', 'notes' => '<p>Reports to the <strong>Prince</strong>.</p>',
		] )->get_data()['id'];

		$data = current( array_filter(
			$this->send( 'GET', '/positions' )->get_data(),
			static fn( $p ) => $p['id'] === $position_id
		) );
		$this->assertSame( '<p>Reports to the <strong>Prince</strong>.</p>', $data['notes'] );
	}

	public function test_notes_keeps_real_formatting_on_update(): void {
		wp_set_current_user( $this->storyteller_id );
		$position_id = $this->send( 'POST', '/positions', [ 'title' => 'Sheriff' ] )->get_data()['id'];

		$this->send( 'PUT', "/positions/{$position_id}", [ 'notes' => '<ul><li>First</li></ul>' ] );

		$data = current( array_filter(
			$this->send( 'GET', '/positions' )->get_data(),
			static fn( $p ) => $p['id'] === $position_id
		) );
		$this->assertSame( '<ul><li>First</li></ul>', $data['notes'] );
	}
}
