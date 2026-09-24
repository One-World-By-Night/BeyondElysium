<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Release_Batch;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Rumor levels: a non-manager sees a level's text only when one of their own characters rates at least that level in
 * the rumor's `rumor_level_key` and `rumor_level_match` trait; a manager always sees every level.
 */
class RumorLevelsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-rumor-levels';
	private int $game_id;
	private int $manager_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Rumor Levels',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->manager_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->manager_id, 'hst' );
	}

	/** @return int wp_user_id, with a character rated $count in Occult (0 means no Occult entry at all). */
	private function make_character( string $name, int $count ): int {
		$player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name'       => $name,
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $player_id,
			'status'     => 'active',
			'created_by' => 1,
		] );
		if ( $count > 0 ) {
			Character::update_sheet_data( $character_id, [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => $count ] ] ] );
		}
		return $player_id;
	}

	/**
	 * A rumor plot rated by Occult, audience=everyone (isolating the level gate from the audience gate).
	 */
	private function make_rumor_plot( bool $released ): int {
		$plot_id = (int) Plot::create( [
			'game_id'           => $this->game_id,
			'title'             => 'A Rated Rumor',
			'created_by'        => 1,
			'audience'          => 'everyone',
			'rumor_level_key'   => 'abilities',
			'rumor_level_match' => 'Occult',
		] );
		Plot::update( $plot_id, [ 'held' => true ] );
		if ( $released ) {
			$batch_id = (int) Release_Batch::create( [ 'game_id' => $this->game_id, 'name' => 'Rumor Levels Batch', 'created_by' => 1 ] );
			$now      = current_time( 'mysql' );
			Release_Batch::mark_released( $batch_id, $now, $now );
			Plot::update( $plot_id, [ 'release_batch_id' => $batch_id ] );
		}
		return $plot_id;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function set_levels( int $plot_id, array $levels ): \WP_REST_Response {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}/rumor-levels" );
		$request->set_param( 'levels', $levels );
		return $this->dispatch( $request );
	}

	/** @return int[] The level numbers a viewer's GET .../plots/{id} response shows. */
	private function visible_levels_as( int $wp_user_id, int $plot_id ): array {
		wp_set_current_user( $wp_user_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) );
		if ( $response->get_status() !== 200 ) {
			return [];
		}
		$entries = array_filter( (array) $response->get_data()->entries, static fn( $e ) => $e->entry_type === 'rumor_level' );
		return array_values( array_map( static fn( $e ) => (int) $e->level, $entries ) );
	}

	public function test_a_rating_of_three_reads_levels_one_through_three_and_not_four(): void {
		$player_id = $this->make_character( 'Occult Three', 3 );
		$plot_id   = $this->make_rumor_plot( true );
		$this->set_levels( $plot_id, [ '1' => 'Level one text', '2' => 'Level two text', '3' => 'Level three text', '4' => 'Level four text' ] );

		$this->assertSame( [ 1, 2, 3 ], $this->visible_levels_as( $player_id, $plot_id ) );
	}

	public function test_a_rating_of_one_reads_only_level_one(): void {
		$player_id = $this->make_character( 'Occult One', 1 );
		$plot_id   = $this->make_rumor_plot( true );
		$this->set_levels( $plot_id, [ '1' => 'Level one text', '2' => 'Level two text' ] );

		$this->assertSame( [ 1 ], $this->visible_levels_as( $player_id, $plot_id ) );
	}

	public function test_no_rating_at_all_reads_nothing(): void {
		$player_id = $this->make_character( 'No Occult', 0 );
		$plot_id   = $this->make_rumor_plot( true );
		$this->set_levels( $plot_id, [ '1' => 'Level one text' ] );

		$this->assertSame( [], $this->visible_levels_as( $player_id, $plot_id ) );
	}

	public function test_a_manager_reads_every_level_regardless_of_rating(): void {
		$plot_id = $this->make_rumor_plot( true );
		$this->set_levels( $plot_id, [ '1' => 'Level one text', '2' => 'Level two text', '5' => 'Level five text' ] );

		$this->assertSame( [ 1, 2, 5 ], $this->visible_levels_as( $this->manager_id, $plot_id ) );
	}

	public function test_levels_follow_the_plots_own_release_state_not_their_own(): void {
		$player_id = $this->make_character( 'Occult Three', 3 );
		$plot_id   = $this->make_rumor_plot( false ); // draft: held, no batch
		$this->set_levels( $plot_id, [ '1' => 'Level one text' ] );

		wp_set_current_user( $player_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) );
		$this->assertSame( 404, $response->get_status(), 'a draft rumor is invisible whole, levels included - they carry no release state of their own' );
	}

	public function test_the_route_upserts_and_deletes_a_level_sent_empty(): void {
		$plot_id = $this->make_rumor_plot( true );

		$this->set_levels( $plot_id, [ '1' => 'Original text', '2' => 'Keep me', '3' => 'Delete me next' ] );
		$entries = Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'rumor_level' ] );
		$this->assertCount( 3, $entries );

		// A second call: level 1 rewritten, level 2 untouched (not sent), level 3 emptied (deleted).
		$this->set_levels( $plot_id, [ '1' => 'Rewritten text', '3' => '' ] );

		$by_level = [];
		foreach ( Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'rumor_level' ] ) as $entry ) {
			$by_level[ (int) $entry->level ] = $entry->content;
		}
		$this->assertSame( [ 1 => 'Rewritten text', 2 => 'Keep me' ], $by_level, 'level 3 is deleted, not left as an empty row' );
	}

	public function test_a_player_cannot_set_rumor_levels(): void {
		$player_id = $this->make_character( 'Just A Player', 0 );
		$plot_id   = $this->make_rumor_plot( true );

		wp_set_current_user( $player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}/rumor-levels" );
		$request->set_param( 'levels', [ '1' => 'Text' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
