<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle turns `player_order` on for its own copy of a book block the book itself never flagged
 * (`werewolf-gifts`), independent of every other chronicle, and the choice survives a reseed of the book.
 */
class PlayerOrderChronicleScopeThreadTest extends WP_UnitTestCase {

	private string $slug   = 'thread-player-order-scope';
	private string $slug_b = 'thread-player-order-scope-b';
	private int $game_id;
	private int $game_id_b;
	private int $hst;
	private int $player;
	private int $player_b;
	private int $character;
	private int $character_b;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id   = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Player Order Scope A' ] );
		$this->game_id_b = (int) Game::create( [ 'slug' => $this->slug_b, 'name' => 'Player Order Scope B' ] );

		$this->hst       = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->player_b  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id_b, $this->player_b, 'player' );

		$gifts = [
			[ 'name' => 'Gift One' ],
			[ 'name' => 'Gift Two' ],
			[ 'name' => 'Gift Three' ],
		];

		$this->character = Character::create( [
			'name' => 'Order Garou A', 'stack_slug' => 'werewolf', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'status' => 'active', 'wp_user_id' => $this->player,
			'sheet_data' => [ 'werewolf-gifts' => $gifts ],
		] );

		$this->character_b = Character::create( [
			'name' => 'Order Garou B', 'stack_slug' => 'werewolf', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug_b, 'status' => 'active', 'wp_user_id' => $this->player_b,
			'sheet_data' => [ 'werewolf-gifts' => $gifts ],
		] );
	}

	private function send( string $method, string $route, array $body = [], ?int $as = null ): \WP_REST_Response {
		wp_set_current_user( $as ?? $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Loads a chronicle's own current view of `werewolf-gifts` (its fork if it has one, the book otherwise), flips
	 * `player_order`, and saves the whole definition back through the real game-scoped write route - the same shape
	 * the admin editor's own checkbox save does.
	 */
	private function set_player_order( string $game_slug, bool $on ): \WP_REST_Response {
		$block      = Schema_Block::find_for_game( 'werewolf-gifts', $game_slug );
		$definition = $block->definition;
		$definition->player_order = $on;

		return $this->send(
			'PUT',
			"/be/v1/{$game_slug}/schema-blocks/werewolf-gifts",
			[ 'definition' => $definition ]
		);
	}

	private function put_order( int $user, string $game_slug, int $character_id, array $body ): \WP_REST_Response {
		return $this->send( 'PUT', "/be/v1/{$game_slug}/characters/{$character_id}/order/werewolf-gifts", $body, $user );
	}

	public function test_turning_ordering_on_lets_the_order_route_accept_a_valid_order(): void {
		$toggle = $this->set_player_order( $this->slug, true );
		$this->assertSame( 200, $toggle->get_status(), wp_json_encode( $toggle->get_data() ) );

		$fork = Schema_Block::find_for_game( 'werewolf-gifts', $this->slug );
		$this->assertSame( $this->slug, $fork->game_slug );
		$this->assertTrue( (bool) $fork->definition->player_order );

		$response = $this->put_order( $this->player, $this->slug, $this->character, [
			'order' => [ 2, 0, 1 ],
			'names' => [ 'Gift Three', 'Gift One', 'Gift Two' ],
		] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$character = Character::find( $this->character );
		$this->assertSame(
			[ 'Gift Three', 'Gift One', 'Gift Two' ],
			array_column( $character->sheet_data['werewolf-gifts'], 'name' )
		);
	}

	public function test_another_chronicle_without_the_fork_still_refuses(): void {
		$this->set_player_order( $this->slug, true );

		// The book itself never flagged `werewolf-gifts`, and chronicle B never forked it.
		$response = $this->put_order( $this->player_b, $this->slug_b, $this->character_b, [
			'order' => [ 1, 0, 2 ],
			'names' => [ 'Gift Two', 'Gift One', 'Gift Three' ],
		] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_player_order', $response->as_error()->get_error_code() );

		$character_b = Character::find( $this->character_b );
		$this->assertSame(
			[ 'Gift One', 'Gift Two', 'Gift Three' ],
			array_column( $character_b->sheet_data['werewolf-gifts'], 'name' ),
			'chronicle B is untouched by chronicle A turning ordering on'
		);
	}

	public function test_turning_ordering_off_refuses_again(): void {
		$this->set_player_order( $this->slug, true );
		$on = $this->put_order( $this->player, $this->slug, $this->character, [
			'order' => [ 1, 0, 2 ],
			'names' => [ 'Gift Two', 'Gift One', 'Gift Three' ],
		] );
		$this->assertSame( 200, $on->get_status() );

		$toggle_off = $this->set_player_order( $this->slug, false );
		$this->assertSame( 200, $toggle_off->get_status(), wp_json_encode( $toggle_off->get_data() ) );

		$response = $this->put_order( $this->player, $this->slug, $this->character, [
			'order' => [ 1, 0, 2 ],
			'names' => [ 'Gift One', 'Gift Two', 'Gift Three' ],
		] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_player_order', $response->as_error()->get_error_code() );
	}

	public function test_the_choice_survives_a_book_rebuild(): void {
		$this->set_player_order( $this->slug, true );

		// A book rebuild: the real re-seed, re-merging every chronicle's own fork.
		Seeder::seed_schema_blocks();

		$fork = Schema_Block::find_for_game( 'werewolf-gifts', $this->slug );
		$this->assertTrue( (bool) $fork->definition->player_order );

		$response = $this->put_order( $this->player, $this->slug, $this->character, [
			'order' => [ 2, 1, 0 ],
			'names' => [ 'Gift Three', 'Gift Two', 'Gift One' ],
		] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}
}
