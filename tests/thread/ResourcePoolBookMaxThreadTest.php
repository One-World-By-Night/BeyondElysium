<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Bylaws;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Mummy Balance: the pool reaches 10, but a raise past the book's 5 waits for a Storyteller, on any chronicle, and
 * OWbN's own Balance clauses name who answers. A player starting a new Mummy stops at the book's maximum.
 */
class ResourcePoolBookMaxThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-book-max';
	private int $game_id;
	private int $player;
	private int $hst;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		Bylaws::reset_cache();

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Book Max', 'settings' => [ 'auto_approve' => true ] ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->character_id = (int) Character::create( [
			'name' => 'Balanced Mummy', 'stack_slug' => 'mummy', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player, 'status' => 'active',
			'sheet_data' => [ 'mummy-resources' => [ 'Balance' => [ 'permanent' => 4, 'temporary' => 4 ] ] ],
		] );
		Character::update_xp( $this->character_id, 100, 100 );
	}

	public function tearDown(): void {
		delete_option( Bylaws::OVERRIDE_OPTION );
		Bylaws::reset_cache();
		parent::tearDown();
	}

	private function raise_balance( int $user, int $to ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [
			'change_type' => 'modify_resource',
			'category'    => 'mummy-resources',
			'change_data' => [ 'block_slug' => 'mummy-resources', 'values' => [ 'Balance' => [ 'permanent' => $to, 'temporary' => $to ] ] ],
		] ) );
		return rest_get_server()->dispatch( $request );
	}

	/** @return array<string,mixed> */
	private function change_of( \WP_REST_Response $response ): array {
		$data = $response->get_data();
		return (array) ( is_object( $data ) ? get_object_vars( $data ) : $data );
	}

	private function balance(): int {
		return (int) Character::find( $this->character_id )->sheet_data['mummy-resources']['Balance']['permanent'];
	}

	public function test_a_raise_inside_the_book_maximum_applies_on_an_auto_approve_chronicle(): void {
		$response = $this->raise_balance( $this->player, 5 );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 5, $this->balance() );
	}

	public function test_a_raise_past_the_book_maximum_waits_for_a_storyteller_with_the_reason(): void {
		$this->raise_balance( $this->player, 5 );

		$response = $this->raise_balance( $this->player, 6 );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 5, $this->balance(), 'the raise has not been applied' );
		$change = $this->change_of( $response );
		$this->assertSame( 'pending', $change['status'] );
		$this->assertStringContainsString( "book's maximum of 5", (string) $change['reason'] );
		$this->assertStringContainsString( 'Balance', (string) $change['reason'] );
	}

	public function test_a_storytellers_raise_past_the_book_maximum_is_held_like_anyones_and_approving_it_applies_it(): void {
		$this->raise_balance( $this->player, 5 );

		$response = $this->raise_balance( $this->hst, 7 );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$change = $this->change_of( $response );
		$this->assertSame( 'pending', $change['status'] );
		$this->assertSame( 5, $this->balance() );

		$this->assertTrue( Change_Engine::approve( (int) $change['id'], $this->hst, null ) );
		$this->assertSame( 7, $this->balance() );
	}

	public function test_a_player_may_still_ask_for_a_rating_up_to_the_pools_maximum(): void {
		$this->assertSame( 201, $this->raise_balance( $this->player, 5 )->get_status() );
		$this->assertSame( 201, $this->raise_balance( $this->player, 6 )->get_status() );
		$refused = $this->raise_balance( $this->player, 11 );
		$this->assertSame( 400, $refused->get_status(), 'past the pool\'s own maximum of 10' );
		$this->assertSame( 'pool_above_maximum', $refused->get_data()['code'] );
	}

	/** A ruleset standing in for the shipped one, so the clause wording is the test's own. */
	private function use_balance_clauses( bool $bylaws_on = true ): void {
		update_option( Bylaws::OVERRIDE_OPTION, [
			'rules'       => [
				[ 'clause_id' => 23472, 'path' => '10.k.iii.1', 'subject' => 'Balance, Direction or Quest 6, 7, 8', 'pc' => 'Coordinator Approval', 'npc' => 'Coordinator Approval', 'coordinators' => [ 'Mummy' ] ],
				[ 'clause_id' => 23473, 'path' => '10.k.iii.2', 'subject' => 'Balance or Quest 9-10', 'pc' => 'Disallowed', 'npc' => 'Coordinator Approval', 'coordinators' => [ 'Mummy' ] ],
			],
			'attachments' => [
				[ 'clause_id' => 23472, 'family' => 'resources', 'name' => 'Balance', 'count_range' => [ 'from' => 6, 'to' => 8 ] ],
				[ 'clause_id' => 23473, 'family' => 'resources', 'name' => 'Balance', 'count_range' => [ 'from' => 9, 'to' => 10 ] ],
			],
		] );
		Bylaws::reset_cache();
		Game::update( $this->slug, [ 'settings' => [ 'auto_approve' => true, 'owbn_bylaws' => $bylaws_on ] ] );
	}

	public function test_with_the_bylaws_on_a_balance_of_six_cites_the_coordinator_clause(): void {
		$this->use_balance_clauses();
		$this->raise_balance( $this->player, 5 );

		$change = $this->change_of( $this->raise_balance( $this->player, 6 ) );

		$this->assertSame( 'pending', $change['status'] );
		$this->assertStringContainsString( '10.k.iii.1', (string) $change['reason'] );
		$this->assertStringNotContainsString( '10.k.iii.2', (string) $change['reason'] );
	}

	public function test_with_the_bylaws_on_a_balance_of_nine_cites_the_disallowed_clause_for_a_player_character(): void {
		$this->use_balance_clauses();
		$this->raise_balance( $this->player, 5 );

		$change = $this->change_of( $this->raise_balance( $this->player, 9 ) );

		$this->assertSame( 'pending', $change['status'] );
		$this->assertStringContainsString( '10.k.iii.2', (string) $change['reason'] );
		$this->assertStringContainsString( 'Disallowed', (string) $change['reason'] );
	}

	public function test_with_the_bylaws_off_the_clauses_are_not_cited(): void {
		$this->use_balance_clauses( false );
		$this->raise_balance( $this->player, 5 );

		$change = $this->change_of( $this->raise_balance( $this->player, 6 ) );

		$this->assertStringNotContainsString( '10.k.iii', (string) $change['reason'] );
		$this->assertStringContainsString( "book's maximum", (string) $change['reason'] );
	}

	public function test_the_shipped_ruleset_attaches_the_two_balance_clauses_to_their_ratings(): void {
		$clause_ids = static fn( int $balance ): array => array_map(
			static fn( array $rule ): int => (int) $rule['clause_id'],
			Bylaws::rules_for( 'resources', 'Balance', null, false, $balance )
		);

		$this->assertSame( [], $clause_ids( 5 ) );
		$this->assertSame( [ 23472 ], $clause_ids( 6 ) );
		$this->assertSame( [ 23472 ], $clause_ids( 8 ) );
		$this->assertSame( [ 23473 ], $clause_ids( 9 ) );
		$this->assertSame( [ 23473 ], $clause_ids( 10 ) );
		$this->assertSame( [], $clause_ids( 11 ) );
	}

	public function test_a_player_starting_a_mummy_stops_at_the_books_maximum(): void {
		$create = function ( int $user, int $balance ): \WP_REST_Response {
			wp_set_current_user( $user );
			$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters" );
			$request->set_param( 'name', 'New Mummy ' . $balance );
			$request->set_param( 'stack_slug', 'mummy' );
			$request->set_param( 'sheet_data', [ 'mummy-resources' => [ 'Balance' => [ 'permanent' => $balance, 'temporary' => $balance ] ] ] );
			return rest_get_server()->dispatch( $request );
		};

		$this->assertSame( 201, $create( $this->player, 5 )->get_status() );
		$refused = $create( $this->player, 6 );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'creation_limit', $refused->get_data()['code'] );
		$this->assertStringContainsString( 'Balance', $refused->get_data()['message'] );
		$this->assertSame( 201, $create( $this->hst, 7 )->get_status(), 'a Storyteller sets any rating' );
	}
}
