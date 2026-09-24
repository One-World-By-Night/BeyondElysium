<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller-authored secret attached to a plot, item, location, or NPC.
 */
class SecretsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-secrets';
	private int $game_id;
	private int $storyteller_id;
	private int $plot_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Secrets' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'A Plot', 'created_by' => $this->storyteller_id, 'audience' => 'everyone',
		] );
	}

	private function send( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * GET with query params set via WP_REST_Request::set_param().
	 */
	private function get_secrets_for( string $entity_type, int $entity_id ) {
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/secrets" );
		$request->set_param( 'entity_type', $entity_type );
		$request->set_param( 'entity_id', $entity_id );
		return rest_get_server()->dispatch( $request );
	}

	private function make_player( string $stack_slug = 'vampire' ): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'A Character', 'stack_slug' => $stack_slug, 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $this->storyteller_id,
		] );
		return [ $player_id, $character_id ];
	}

	private function make_secret( array $overrides = [] ): int {
		wp_set_current_user( $this->storyteller_id );
		return (int) Secret::create( array_merge( [
			'game_id' => $this->game_id, 'entity_type' => 'plot', 'entity_id' => $this->plot_id,
			'title' => 'A Secret', 'content' => 'Only staff know this.', 'created_by' => $this->storyteller_id,
		], $overrides ) );
	}

	// -------------------------------------------------------------------------
	// storytellers-only hidden (the default), and no connected-character exception.
	// -------------------------------------------------------------------------

	public function test_a_secret_defaults_to_storytellers_only(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$secret_id = $this->make_secret();
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $character_id, 'revealed_by' => $this->storyteller_id ] );

		wp_set_current_user( $player_id );
		$response = $this->get_secrets_for( 'plot', $this->plot_id );

		$this->assertSame( [], $response->get_data() );
	}

	public function test_a_manager_sees_a_storytellers_only_secret(): void {
		$this->make_secret();

		wp_set_current_user( $this->storyteller_id );
		$response = $this->get_secrets_for( 'plot', $this->plot_id );

		$this->assertCount( 1, $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// A reveal shows it to that character's player only, once the secret is restricted.
	// -------------------------------------------------------------------------

	public function test_a_reveal_shows_the_secret_to_that_character_only(): void {
		[ , $revealed_id ]   = $this->make_player();
		[ $other_id, $other_character_id ] = $this->make_player();
		$secret_id = $this->make_secret( [ 'audience' => 'restricted' ] );
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $revealed_id, 'revealed_by' => $this->storyteller_id ] );

		wp_set_current_user( $other_id );
		$this->assertSame( [], $this->get_secrets_for( 'plot', $this->plot_id )->get_data() );

		// The revealed character's own player, via My Secrets.
		$player_of_revealed = Character::find( $revealed_id )->wp_user_id;
		wp_set_current_user( $player_of_revealed );
		$mine = $this->send( 'GET', '/my/secrets' )->get_data();
		$this->assertCount( 1, $mine );
		$this->assertSame( 'A Secret', $mine[0]['title'] );
	}

	// -------------------------------------------------------------------------
	// A held reveal is hidden until its batch is out.
	// -------------------------------------------------------------------------

	public function test_a_held_reveal_is_hidden_until_its_batch_is_out(): void {
		[ , $character_id ] = $this->make_player();
		$secret_id = $this->make_secret( [ 'audience' => 'restricted' ] );
		$batch_id  = (int) Release_Batch::create( [ 'game_id' => $this->game_id, 'name' => 'Batch', 'created_by' => $this->storyteller_id ] );
		Secret_Reveal::create( [
			'secret_id' => $secret_id, 'character_id' => $character_id, 'held' => true,
			'release_batch_id' => $batch_id, 'revealed_by' => $this->storyteller_id,
		] );

		$wp_user_id = Character::find( $character_id )->wp_user_id;
		wp_set_current_user( $wp_user_id );
		$this->assertSame( [], $this->send( 'GET', '/my/secrets' )->get_data() );

		$now = current_time( 'mysql' );
		Release_Batch::mark_released( $batch_id, $now, $now );

		$mine = $this->send( 'GET', '/my/secrets' )->get_data();
		$this->assertCount( 1, $mine );
	}

	// -------------------------------------------------------------------------
	// A rule reveals it (audience_rules), same OR-composition as every other Audience entity.
	// -------------------------------------------------------------------------

	public function test_a_matching_rule_reveals_a_secret_with_no_explicit_reveal_needed(): void {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		Character::create( [
			'name' => 'A Tremere', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $this->storyteller_id,
			'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Tremere' ] ],
		] );

		wp_set_current_user( $this->storyteller_id );
		$secret_id = $this->make_secret( [
			'audience'       => 'restricted',
			'audience_rules' => [
				'logic'      => 'AND',
				'conditions' => [ [ 'field' => 'clan', 'operator' => 'equals', 'find' => 'Tremere' ] ],
			],
		] );

		wp_set_current_user( $player_id );
		$response = $this->get_secrets_for( 'plot', $this->plot_id );

		$this->assertCount( 1, $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// A hidden entity's name never leaks through my/secrets.
	// -------------------------------------------------------------------------

	public function test_my_secrets_never_names_an_entity_the_viewer_cannot_see(): void {
		[ , $character_id ] = $this->make_player();

		wp_set_current_user( $this->storyteller_id );
		Plot::update( $this->plot_id, [ 'audience' => 'storytellers' ] );
		$secret_id = $this->make_secret( [ 'audience' => 'restricted' ] );
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $character_id, 'revealed_by' => $this->storyteller_id ] );

		$wp_user_id = Character::find( $character_id )->wp_user_id;
		wp_set_current_user( $wp_user_id );
		$mine = $this->send( 'GET', '/my/secrets' )->get_data();

		$this->assertCount( 1, $mine );
		$this->assertNull( $mine[0]['entity_name'] );
	}

	public function test_get_items_404s_when_the_viewer_cannot_see_the_parent_entity(): void {
		[ $player_id ] = $this->make_player();
		Plot::update( $this->plot_id, [ 'audience' => 'storytellers' ] );
		$this->make_secret();

		wp_set_current_user( $player_id );
		$response = $this->get_secrets_for( 'plot', $this->plot_id );

		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// A player can't write or reveal.
	// -------------------------------------------------------------------------

	public function test_a_player_cannot_create_a_secret(): void {
		[ $player_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', '/secrets', [
			'entity_type' => 'plot', 'entity_id' => $this->plot_id, 'title' => 'Nope',
		] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_player_cannot_reveal_a_secret(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$secret_id = $this->make_secret();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/reveals", [ 'character_id' => $character_id ] );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// St_Filter applies to secret content.
	// -------------------------------------------------------------------------

	public function test_st_marked_text_is_stripped_from_content_for_a_non_manager(): void {
		[ , $character_id ] = $this->make_player();
		$secret_id = $this->make_secret( [
			'audience' => 'restricted',
			'content'  => 'The Prince did it. [ST]he is lying about the alibi[/ST]',
		] );
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $character_id, 'revealed_by' => $this->storyteller_id ] );

		$wp_user_id = Character::find( $character_id )->wp_user_id;
		wp_set_current_user( $wp_user_id );
		$mine = $this->send( 'GET', '/my/secrets' )->get_data();

		$this->assertStringNotContainsString( 'lying about the alibi', $mine[0]['content'] );
		$this->assertStringContainsString( 'The Prince did it.', $mine[0]['content'] );
	}

	// -------------------------------------------------------------------------
	// CRUD, cascade delete, and duplicate-reveal 409.
	// -------------------------------------------------------------------------

	public function test_deleting_a_secret_cascades_its_reveals(): void {
		[ , $character_id ] = $this->make_player();
		$secret_id = $this->make_secret();
		$reveal_id = (int) Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $character_id, 'revealed_by' => $this->storyteller_id ] );

		wp_set_current_user( $this->storyteller_id );
		$response = $this->send( 'DELETE', "/secrets/{$secret_id}" );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Secret_Reveal::find( $reveal_id ) );
	}

	public function test_revealing_the_same_character_twice_is_refused(): void {
		[ , $character_id ] = $this->make_player();
		$secret_id = $this->make_secret();

		wp_set_current_user( $this->storyteller_id );
		$this->send( 'POST', "/secrets/{$secret_id}/reveals", [ 'character_id' => $character_id ] );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/reveals", [ 'character_id' => $character_id ] );

		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Release batch integration: a reveal can be held and later assigned to a batch.
	// -------------------------------------------------------------------------

	public function test_a_reveal_can_be_added_to_a_release_batch_and_listed_there(): void {
		[ , $character_id ] = $this->make_player();
		$secret_id = $this->make_secret();

		wp_set_current_user( $this->storyteller_id );
		$reveal = $this->send( 'POST', "/secrets/{$secret_id}/reveals", [ 'character_id' => $character_id, 'held' => true ] )->get_data();
		$batch_id = $this->send( 'POST', '/release-batches', [ 'name' => 'Batch' ] )->get_data()->id;

		$added = $this->send( 'POST', "/release-batches/{$batch_id}/items", [ 'type' => 'reveal', 'id' => $reveal->id ] );
		$this->assertSame( 204, $added->get_status() );

		$items = $this->send( 'GET', "/release-batches/{$batch_id}/items" )->get_data();
		$this->assertCount( 1, $items['reveals'] );
		$this->assertSame( 'A Secret', $items['reveals'][0]['secret_title'] );
	}
}
