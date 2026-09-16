<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-047. Every chronicle role, players included, holds `be_view_reports`, and a
 * report for a non-Storyteller hid only NPCs: any player could read every other player's
 * characters and sheet values (Character Roster, Search, Equipment...), everyone's XP history,
 * every plot with its entries, every other player's actions and the Storyteller responses, and
 * could search any sheet field across the chronicle through `conditions`. Each report now names
 * who may run it: character and player reports need `be_manage_characters`, plot, action, and
 * rumor reports need `be_manage_plots`, and only the catalog cards, House Rules, and the calendar
 * stay open to every member.
 */
class ReportAudienceThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-report-audience';
	private int $game_id;
	private int $player;
	private int $narrator;
	private int $hst;
	private int $own_character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Report Audience' ] );
		$this->player   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$other_player   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->narrator = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->hst      = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id, $other_player, 'player' );
		Game_Member::set_role( $this->game_id, $this->narrator, 'narrator' );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->own_character = Character::create( [
			'name' => 'My Own Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
		Character::create( [
			'name' => 'Someone Else Secret Sheet', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $other_player,
		] );
		$plot = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'The Hidden Conspiracy', 'description' => 'Storytellers only' ] );
		Plot_Entry::create( [ 'plot_id' => $plot, 'entry_type' => 'action', 'content' => 'Another player tails the Prince' ] );
	}

	private function get( int $as, string $route, array $query = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'GET', $route );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_cannot_read_other_players_characters_plots_or_actions_through_reports(): void {
		foreach ( [ 'character-roster', 'search-report', 'experience-history', 'statistics-report', 'player-roster', 'character-equipment', 'plot-report', 'master-action-report', 'master-rumor-report' ] as $report ) {
			$response = $this->get( $this->player, "/be/v1/{$this->slug}/reports/{$report}", [ 'conditions' => wp_json_encode( [] ) ] );
			$this->assertSame( 403, $response->get_status(), "{$report}: " . wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_a_player_still_gets_house_rules_and_their_own_item_cards(): void {
		$this->assertSame( 200, $this->get( $this->player, "/be/v1/{$this->slug}/reports/house-rules" )->get_status() );
		$this->assertSame( 200, $this->get( $this->player, "/be/v1/{$this->slug}/reports/item-cards", [ 'character_id' => $this->own_character ] )->get_status() );

		$listed = array_column( $this->get( $this->player, "/be/v1/{$this->slug}/reports" )->get_data(), 'key' );
		sort( $listed );
		$this->assertSame( [ 'game-calendar', 'house-rules', 'item-cards', 'location-cards', 'rote-cards' ], $listed );
	}

	public function test_a_narrator_runs_plot_reports_but_not_character_reports(): void {
		$this->assertSame( 200, $this->get( $this->narrator, "/be/v1/{$this->slug}/reports/plot-report" )->get_status() );
		$this->assertSame( 403, $this->get( $this->narrator, "/be/v1/{$this->slug}/reports/character-roster" )->get_status() );
	}

	public function test_a_storyteller_runs_every_report(): void {
		$roster = $this->get( $this->hst, "/be/v1/{$this->slug}/reports/character-roster" );
		$this->assertSame( 200, $roster->get_status() );
		$this->assertStringContainsString( 'Someone Else Secret Sheet', wp_json_encode( $roster->get_data() ) );
		$this->assertCount( 20, $this->get( $this->hst, "/be/v1/{$this->slug}/reports" )->get_data() );
	}
}
