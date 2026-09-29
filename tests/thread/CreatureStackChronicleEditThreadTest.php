<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle's own layer over a book creature type, saved and cleared through its own route: creation rules, and a
 * section hidden, shown, added or removed.
 */
class CreatureStackChronicleEditThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-stack-edit';
	private int $game_id;
	private int $hst;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Stack Edit by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	private function send( string $method, string $route, array $body = [], ?int $as = null ): \WP_REST_Response {
		wp_set_current_user( $as ?? $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_hst_saves_creation_rules_for_their_own_chronicle(): void {
		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/creature-stacks/vampire", [
			'creation_rules' => [ 'steps' => [ [ 'kind' => 'prioritized', 'label' => 'Attributes', 'sections' => [ 'met-physical-traits' ], 'amounts' => [ 7 ] ] ] ],
		] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$layer = Creature_Stack::find_for_game( 'vampire', $this->slug );
		$this->assertSame( $this->slug, $layer->game_slug );
		$this->assertCount( 1, $layer->creation_rules->steps );
		$this->assertSame( 'prioritized', $layer->creation_rules->steps[0]->kind );

		$book = Creature_Stack::find_by_slug( 'vampire' );
		$this->assertSame( '', $book->game_slug, "the book's own row is untouched" );
		$this->assertGreaterThan( 1, count( $book->creation_rules->steps ), "the book's own rules are untouched" );
	}

	public function test_a_player_cannot_save_creation_rules(): void {
		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/creature-stacks/vampire", [
			'creation_rules' => [ 'steps' => [] ],
		], $this->player );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_write_with_no_chronicle_in_the_url_is_refused(): void {
		$response = $this->send( 'PUT', '/be/v1/creature-stacks/vampire', [ 'creation_rules' => [ 'steps' => [] ] ], self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'book_read_only', $response->as_error()->get_error_code() );
	}

	public function test_hiding_and_showing_a_book_section(): void {
		$hidden = $this->send( 'PUT', "/be/v1/{$this->slug}/creature-stacks/vampire/sections/vampire-blood-magic", [ 'hidden' => true ] );
		$this->assertSame( 200, $hidden->get_status(), wp_json_encode( $hidden->get_data() ) );
		$this->assertTrue( Creature_Stack::find_closed_entry( Creature_Stack::find_for_game( 'vampire', $this->slug ), [ 'vampire-blood-magic' => [ [ 'name' => 'Anything' ] ] ] ) !== null );

		$shown = $this->send( 'PUT', "/be/v1/{$this->slug}/creature-stacks/vampire/sections/vampire-blood-magic", [ 'hidden' => false ] );
		$this->assertSame( 200, $shown->get_status() );
		$this->assertSame( '', Creature_Stack::find_for_game( 'vampire', $this->slug )->game_slug, 'showing it again leaves no layer, reading as the book' );
	}

	public function test_hiding_an_unknown_section_is_404(): void {
		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/creature-stacks/vampire/sections/not-a-real-block", [ 'hidden' => true ] );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_adding_and_removing_a_chronicles_own_section(): void {
		$added = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks/vampire/sections", [
			'block_slug' => 'werewolf-gifts', 'label' => 'Borrowed Gifts',
		] );
		$this->assertSame( 201, $added->get_status(), wp_json_encode( $added->get_data() ) );

		$again = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks/vampire/sections", [ 'block_slug' => 'werewolf-gifts' ] );
		$this->assertSame( 400, $again->get_status(), 'already on this creature type' );

		$unknown = $this->send( 'POST', "/be/v1/{$this->slug}/creature-stacks/vampire/sections", [ 'block_slug' => 'not-a-real-block' ] );
		$this->assertSame( 400, $unknown->get_status() );

		$removed = $this->send( 'DELETE', "/be/v1/{$this->slug}/creature-stacks/vampire/sections/werewolf-gifts" );
		$this->assertSame( 200, $removed->get_status(), wp_json_encode( $removed->get_data() ) );
	}

	public function test_removing_a_section_never_added_is_404(): void {
		$response = $this->send( 'DELETE', "/be/v1/{$this->slug}/creature-stacks/vampire/sections/not-a-real-block" );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_a_book_section_can_be_hidden_but_not_removed(): void {
		$response = $this->send( 'DELETE', "/be/v1/{$this->slug}/creature-stacks/vampire/sections/vampire-disciplines" );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'book_section', $response->as_error()->get_error_code() );
	}

	public function test_deleting_the_layer_resets_to_the_book(): void {
		Creature_Stack::hide_section( 'vampire', $this->slug, 'vampire-blood-magic' );
		$this->assertSame( $this->slug, Creature_Stack::find_for_game( 'vampire', $this->slug )->game_slug );

		$response = $this->send( 'DELETE', "/be/v1/{$this->slug}/creature-stacks/vampire" );
		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( '', Creature_Stack::find_for_game( 'vampire', $this->slug )->game_slug );
	}
}
