<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Audience;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The item-cards and location-cards reports filter by audience for a non-Storyteller.
 */
class CardReportAudienceThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-card-report-audience';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Card Report Audience',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function make_player(): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		return $player;
	}

	private function make_character( int $wp_user_id ): int {
		return (int) Character::create( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $wp_user_id,
			'created_by' => $wp_user_id,
		] );
	}

	private function make_item( string $name, string $audience ): int {
		return (int) World_Object::create( [
			'game_id'     => $this->game_id,
			'object_type' => 'item',
			'name'        => $name,
			'audience'    => $audience,
			'created_by'  => 1,
		] );
	}

	private function names_from( \WP_REST_Response $response ): array {
		return array_map( static fn( $card ) => $card[0][1], $response->get_data()['cards'] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_item_cards_hides_a_storytellers_only_item_from_a_player(): void {
		$this->make_item( 'Open Sword', Audience::EVERYONE );
		$this->make_item( 'Secret Relic', Audience::STORYTELLERS );

		wp_set_current_user( $this->make_player() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/reports/item-cards" ) );
		$names    = $this->names_from( $response );

		$this->assertContains( 'Open Sword', $names );
		$this->assertNotContains( 'Secret Relic', $names );
	}

	public function test_print_my_items_shows_a_storytellers_only_item_the_character_holds(): void {
		$player = $this->make_player();
		$character_id = $this->make_character( $player );
		$item_id = $this->make_item( 'Secret Relic', Audience::STORYTELLERS );

		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'character',
			'source_id'   => $character_id,
			'target_type' => 'world_object',
			'target_id'   => $item_id,
			'label'       => 'holds',
			'created_by'  => 1,
		] );

		wp_set_current_user( $player );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/reports/item-cards" );
		$request->set_param( 'character_id', $character_id );
		$names = $this->names_from( $this->dispatch( $request ) );

		$this->assertContains(
			'Secret Relic',
			$names,
			'holding an item is its own authorization for Print My Items, independent of the general audience'
		);
	}
}
