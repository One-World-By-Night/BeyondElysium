<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `[ST]...[/ST]` must be stripped everywhere a Storyteller can write prose.
 */
class StFilterCoverageThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-st-coverage';
	private int $game_id;
	private int $plot_id;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'        => $this->game_slug,
			'name'        => 'Thread ST Coverage',
			'description' => 'Public blurb. [ST]Only staff read this.[/ST] More public.',
			'created_by'  => 1,
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
			'settings'    => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->plot_id = (int) Plot::create( [
			'game_id'     => $this->game_id,
			'title'       => 'A Plot',
			'description' => 'Players see this. [ST]They must not see this.[/ST] And this.',
			'cliffhanger' => 'Open. [ST]The answer is the sheriff.[/ST]',
			'st_notes'    => 'Storyteller eyes only.',
			'created_by'  => 1,
		] );

		Plot_Entry::create( [
			'plot_id'    => $this->plot_id,
			'entry_type' => 'response',
			'content'    => 'It happened. [ST]Because the sheriff lied.[/ST]',
			'created_by' => 1,
		] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	private function get( string $route, array $query = [] ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_never_sees_st_text_in_a_plot_description_or_cliffhanger(): void {
		wp_set_current_user( $this->player );

		$plot = (array) $this->get( "/be/v1/{$this->game_slug}/plots/{$this->plot_id}" )->get_data();

		$this->assertStringNotContainsString( 'They must not see this', (string) $plot['description'] );
		$this->assertStringNotContainsString( '[ST]', (string) $plot['description'] );
		$this->assertStringContainsString( 'Players see this', (string) $plot['description'] );

		$this->assertStringNotContainsString( 'the sheriff', (string) $plot['cliffhanger'] );
	}

	public function test_a_player_never_sees_st_text_in_a_plot_entry(): void {
		wp_set_current_user( $this->player );

		$entries = $this->get( "/be/v1/{$this->game_slug}/plots/{$this->plot_id}/entries" )->get_data();

		$this->assertNotEmpty( $entries );
		$content = (string) ( (array) $entries[0] )['content'];
		$this->assertStringNotContainsString( 'Because the sheriff lied', $content );
		$this->assertStringNotContainsString( '[ST]', $content );
		$this->assertStringContainsString( 'It happened', $content );
	}

	public function test_a_player_never_sees_st_text_in_a_chronicle_description(): void {
		wp_set_current_user( $this->player );

		$game = (array) $this->get( "/be/v1/games/{$this->game_slug}" )->get_data();

		$this->assertStringNotContainsString( 'Only staff read this', (string) $game['description'] );
		$this->assertStringNotContainsString( '[ST]', (string) $game['description'] );
		$this->assertStringContainsString( 'Public blurb', (string) $game['description'] );
	}

	public function test_a_storyteller_still_sees_all_of_it(): void {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		wp_set_current_user( $hst );

		$plot = (array) $this->get( "/be/v1/{$this->game_slug}/plots/{$this->plot_id}" )->get_data();

		$this->assertStringContainsString( 'They must not see this', (string) $plot['description'] );
		$this->assertStringContainsString( 'the sheriff', (string) $plot['cliffhanger'] );
	}

	public function test_st_notes_is_removed_for_a_player_not_merely_stripped(): void {
		wp_set_current_user( $this->player );

		$plot = (array) $this->get( "/be/v1/{$this->game_slug}/plots/{$this->plot_id}" )->get_data();

		$this->assertArrayNotHasKey( 'st_notes', $plot );
	}
}
