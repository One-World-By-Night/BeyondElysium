<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Action_Allocator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Owner requirement, 2026-09-15: "All characters must have an auto-created <Character> [id] Plot
 * associated with them - PC and NPC alike - at creation. We would need to seed one for any
 * existing characters." Its player and the chronicle's Storytellers see it, no other player does,
 * and the character's action rounds sit under it (owner answers, same day).
 */
class CharacterPlotThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-character-plot';
	private int $game_id;
	private int $storyteller;
	private int $alice;
	private int $bob;
	private string $broken_table = '';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id     = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Character Plot' ] );
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->alice       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->bob         = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller, 'hst' );
		Game_Member::set_role( $this->game_id, $this->alice, 'player' );
		Game_Member::set_role( $this->game_id, $this->bob, 'player' );
		wp_set_current_user( $this->storyteller );
	}

	public function tear_down(): void {
		remove_filter( 'query', [ $this, 'break_inserts' ] );
		parent::tear_down();
	}

	public function break_inserts( string $query ): string {
		global $wpdb;
		return $this->broken_table !== '' && preg_match( "/^\s*INSERT INTO `?{$wpdb->prefix}be_{$this->broken_table}`?/i", $query )
			? 'INSERT INTO be_no_such_table VALUES (1)'
			: $query;
	}

	private function character( string $name, array $extra = [] ): int {
		return Character::create( array_merge( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active',
		], $extra ) );
	}

	private function request( int $user, string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body && $method === 'GET' ) {
			$request->set_query_params( $body );
		} elseif ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return int[] The ids of the character's action rounds' parents, by round.
	 */
	private function round_parent( int $round_id ): ?int {
		$round = Plot::find( $round_id );
		return $round && $round->parent_plot_id !== null ? (int) $round->parent_plot_id : null;
	}

	public function test_a_character_made_on_the_character_screen_gets_its_own_plot(): void {
		$response = $this->request( $this->storyteller, 'POST', '/characters', [ 'name' => 'Isolde Plotted', 'stack_slug' => 'vampire', 'wp_user_id' => $this->alice ] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id = (int) $response->get_data()->id;

		$plot_id = Character::plot_id( $id );
		$this->assertNotNull( $plot_id, 'the character has its plot' );
		$plot = Plot::find( $plot_id );
		$this->assertSame( "Isolde Plotted [{$id}] Plot", $plot->title );
		$this->assertSame( $this->game_id, (int) $plot->game_id );
		$this->assertNull( $plot->game_date );
		$this->assertSame( $id, Action_Allocator::actor_character_id( $plot_id ) );
	}

	public function test_an_npc_gets_one_too_that_no_player_sees(): void {
		$npc     = $this->character( 'Prince of Shadows', [ 'is_npc' => 1 ] );
		$plot_id = Character::plot_id( $npc );

		$this->assertNotNull( $plot_id );
		$this->assertSame( 404, $this->request( $this->alice, 'GET', "/plots/{$plot_id}" )->get_status() );
		$this->assertSame( 200, $this->request( $this->storyteller, 'GET', "/plots/{$plot_id}" )->get_status() );
	}

	public function test_its_player_and_the_storytellers_see_it_and_no_other_player_does(): void {
		$plot_id = Character::plot_id( $this->character( 'Alice Kindred', [ 'wp_user_id' => $this->alice ] ) );

		$this->assertSame( 200, $this->request( $this->alice, 'GET', "/plots/{$plot_id}" )->get_status() );
		$this->assertSame( 200, $this->request( $this->storyteller, 'GET', "/plots/{$plot_id}" )->get_status() );
		$this->assertSame( 404, $this->request( $this->bob, 'GET', "/plots/{$plot_id}" )->get_status() );

		$listed = static fn( \WP_REST_Response $response ) => array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );
		$this->assertNotContains( $plot_id, $listed( $this->request( $this->bob, 'GET', '/plots' ) ) );
		$this->assertContains( $plot_id, $listed( $this->request( $this->storyteller, 'GET', '/plots' ) ) );
	}

	public function test_each_action_round_sits_under_the_character_plot_unless_a_storyteller_chose_another(): void {
		$character = $this->character( 'Round Robin', [ 'wp_user_id' => $this->alice ] );
		$chosen    = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'The Siege', 'initiated_by' => 'st' ] );

		$this->assertSame( Character::plot_id( $character ), $this->round_parent( Action_Allocator::persist( $character, '2026-10-01' ) ) );
		$this->assertSame( $chosen, $this->round_parent( Action_Allocator::persist( $character, '2026-11-01', $chosen ) ) );
	}

	public function test_the_plot_follows_a_rename_unless_a_storyteller_renamed_it(): void {
		$character = $this->character( 'Old Name' );
		$plot_id   = Character::plot_id( $character );

		Character::update_header( $character, [ 'name' => 'New Name' ] );
		$this->assertSame( "New Name [{$character}] Plot", Plot::find( $plot_id )->title );

		Plot::update( $plot_id, [ 'title' => 'The Blood Feud' ] );
		Character::update_header( $character, [ 'name' => 'Newer Name' ] );
		$this->assertSame( 'The Blood Feud', Plot::find( $plot_id )->title );
	}

	public function test_a_character_plot_goes_only_with_its_character(): void {
		$character = $this->character( 'Short Lived' );
		$plot_id   = Character::plot_id( $character );

		$response = $this->request( $this->storyteller, 'DELETE', "/plots/{$plot_id}" );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'character_plot', $response->get_data()['code'] );
		$this->assertNotNull( Plot::find( $plot_id ) );

		Character::delete( $character );
		$this->assertNull( Plot::find( $plot_id ) );
	}

	public function test_a_character_whose_plot_cannot_be_written_is_not_made(): void {
		global $wpdb;
		$this->broken_table = 'plots';
		add_filter( 'query', [ $this, 'break_inserts' ] );
		$quiet = $wpdb->suppress_errors( true );
		$id    = $this->character( 'Never Was' );
		$wpdb->suppress_errors( $quiet );
		remove_filter( 'query', [ $this, 'break_inserts' ] );

		$this->assertSame( 0, $id );
		$this->assertNull( Character::find_by_name_in_game( 'Never Was', $this->slug ) );
	}

	public function test_existing_characters_get_their_plots_and_their_rounds_move_under_them(): void {
		global $wpdb;
		$character = $this->character( 'Before The Update', [ 'wp_user_id' => $this->alice ] );
		// As an install before this release left it: no character plot, rounds with no parent.
		Plot::delete( (int) Character::plot_id( $character ) );
		$loose    = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => '2026-09-01 Before The Update', 'initiated_by' => 'player', 'game_date' => '2026-09-01' ] );
		$chosen   = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'The Siege', 'initiated_by' => 'st' ] );
		$nested   = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => '2026-09-15 Before The Update', 'initiated_by' => 'player', 'game_date' => '2026-09-15', 'parent_plot_id' => $chosen ] );
		foreach ( [ $loose, $nested ] as $round ) {
			Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $round, 'target_type' => 'character', 'target_id' => $character, 'label' => Action_Allocator::ACTOR_LABEL ] );
		}
		$this->assertNull( Character::plot_id( $character ) );
		delete_option( 'be_character_plots_seeded' );

		Schema::seed_character_plots();

		$plot_id = Character::plot_id( $character );
		$this->assertNotNull( $plot_id );
		$this->assertSame( "Before The Update [{$character}] Plot", Plot::find( $plot_id )->title );
		$this->assertSame( $plot_id, $this->round_parent( $loose ), 'a round with no parent moves under the character plot' );
		$this->assertSame( $chosen, $this->round_parent( $nested ), 'a round a Storyteller nested stays where it is' );

		$count = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_plots" );
		$before = $count();
		delete_option( 'be_character_plots_seeded' );
		Schema::seed_character_plots();
		$this->assertSame( $before, $count(), 'running it again makes nothing new' );
		$this->assertNotEmpty( get_option( 'be_character_plots_seeded' ) );
	}

	/**
	 * @return int[] The ids a plot list response holds, in ascending order.
	 */
	private function listed_ids( \WP_REST_Response $response ): array {
		$ids = array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Owner, 2026-09-15: "I can't see or edit player plots or filter to show only player or only
	 * not player." A character's plots - its own plot and every action round - on their own, or
	 * everything else, with a total that counts the same plots.
	 */
	public function test_the_plot_list_shows_only_character_plots_or_leaves_them_out(): void {
		$alices = $this->character( 'Filter Alice', [ 'wp_user_id' => $this->alice ] );
		$npc    = $this->character( 'Filter Prince', [ 'is_npc' => 1 ] );
		$round  = Action_Allocator::persist( $alices, '2026-10-01' );
		$story  = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'The Siege', 'initiated_by' => 'st' ] );
		$tied   = [ (int) Character::plot_id( $alices ), (int) Character::plot_id( $npc ), $round ];
		sort( $tied );

		$only = $this->request( $this->storyteller, 'GET', '/plots', [ 'character_plots' => 'only' ] );
		$this->assertSame( 200, $only->get_status(), wp_json_encode( $only->get_data() ) );
		$this->assertSame( $tied, $this->listed_ids( $only ) );
		$this->assertSame( '3', $only->get_headers()['X-WP-Total'] );

		$rest = $this->request( $this->storyteller, 'GET', '/plots', [ 'character_plots' => 'exclude' ] );
		$this->assertSame( [ $story ], $this->listed_ids( $rest ) );
		$this->assertSame( '1', $rest->get_headers()['X-WP-Total'] );

		$everything = $this->request( $this->storyteller, 'GET', '/plots' );
		$this->assertSame( '4', $everything->get_headers()['X-WP-Total'] );

		// A player still sees only their own character's plots.
		$mine = [ (int) Character::plot_id( $alices ), $round ];
		sort( $mine );
		$this->assertSame( $mine, $this->listed_ids( $this->request( $this->alice, 'GET', '/plots', [ 'character_plots' => 'only' ] ) ) );

		$this->assertSame( 400, $this->request( $this->storyteller, 'GET', '/plots', [ 'character_plots' => 'players' ] )->get_status() );
	}

	public function test_a_character_outside_any_chronicle_is_still_made(): void {
		$id = Character::create( [ 'name' => 'Lost Soul', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'thread-no-such-chronicle' ] );

		$this->assertGreaterThan( 0, $id );
		$this->assertNull( Character::plot_id( $id ) );
	}
}
