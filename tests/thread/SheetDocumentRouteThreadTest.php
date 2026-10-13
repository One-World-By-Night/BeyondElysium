<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A character's sheet as data: `GET /{game_slug}/characters/{id}/sheet-document`.
 */
class SheetDocumentRouteThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-sheet-document';
	private int $game_id;
	private int $owner;
	private int $manager;
	private int $other_player;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Sheet Document by Night' ] );
		$this->owner        = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->manager       = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->owner, 'player' );
		Game_Member::set_role( $this->game_id, $this->other_player, 'player' );
		Game_Member::set_role( $this->game_id, $this->manager, 'hst' );

		$this->character_id = (int) Character::create( [
			'name' => 'Documented One', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->owner,
		] );
	}

	private function dispatch( int $as, string $route ) {
		wp_set_current_user( $as );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
		wp_set_current_user( 0 );
		return $response;
	}

	private function route( int $character_id ): string {
		return "/be/v1/{$this->slug}/characters/{$character_id}/sheet-document";
	}

	public function test_the_owner_can_read_it(): void {
		$response = $this->dispatch( $this->owner, $this->route( $this->character_id ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_manager_can_read_it(): void {
		$response = $this->dispatch( $this->manager, $this->route( $this->character_id ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_another_player_is_refused(): void {
		$response = $this->dispatch( $this->other_player, $this->route( $this->character_id ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ownership_denied', $response->as_error()->get_error_code() );
	}

	public function test_a_missing_creature_type_gives_404(): void {
		$ghost_id = (int) Character::create( [
			'name' => 'Ghost Stack', 'stack_slug' => 'thread-sheet-document-nonexistent', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->owner,
		] );
		$response = $this->dispatch( $this->owner, $this->route( $ghost_id ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'creature_stack_not_found', $response->as_error()->get_error_code() );
	}

	public function test_a_character_not_in_this_game_gives_404(): void {
		Game::create( [ 'slug' => 'thread-sheet-document-other', 'name' => 'Other' ] );
		$elsewhere_id = (int) Character::create( [
			'name' => 'Elsewhere', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-sheet-document-other', 'wp_user_id' => $this->owner,
		] );
		$response = $this->dispatch( $this->owner, $this->route( $elsewhere_id ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_storyteller_only_blocks_are_filtered_exactly_as_the_signed_pdf_filters_them(): void {
		\BeyondElysium\Models\Schema_Block::find_or_create_fork_for_game( 'vampire-virtues', $this->slug );
		\BeyondElysium\Models\Schema_Block::update( 'vampire-virtues', [ 'storyteller_only' => 1 ], $this->slug );

		$as_owner   = $this->dispatch( $this->owner, $this->route( $this->character_id ) );
		$as_manager = $this->dispatch( $this->manager, $this->route( $this->character_id ) );

		$owner_json   = wp_json_encode( $as_owner->get_data() );
		$manager_json = wp_json_encode( $as_manager->get_data() );

		$this->assertStringNotContainsString( 'Virtues', $owner_json );
		$this->assertStringContainsString( 'Virtues', $manager_json );
	}

	public function test_the_route_matches_the_service_directly_for_both_viewers(): void {
		$as_owner   = $this->dispatch( $this->owner, $this->route( $this->character_id ) );
		$as_manager = $this->dispatch( $this->manager, $this->route( $this->character_id ) );

		$as_non_manager_document = \BeyondElysium\Services\Sheet_Document::for_characters( [ $this->character_id ], $this->slug, [ 'can_manage' => false ] )[0];
		$as_manager_document     = \BeyondElysium\Services\Sheet_Document::for_characters( [ $this->character_id ], $this->slug, [ 'can_manage' => true ] )[0];

		$this->assertEquals( $as_non_manager_document, $as_owner->get_data() );
		$this->assertEquals( $as_manager_document, $as_manager->get_data() );
	}
}
