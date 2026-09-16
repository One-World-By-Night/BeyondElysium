<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-071, F-072, F-073, F-074 (Pass H intake `t1-print-reports`). Four reports
 * printed columns that could never hold anything:
 * - F-071: Experience History and Player Point History showed each change's Earned and Unspent
 *   as 0 for every award (and a spend as its cost under Earned), where Grapevine shows the
 *   totals after each change; an ordinary change's Reason always read "—".
 * - F-072: Vampire Status Report's Date was always blank and its Group and Description were
 *   never mapped; Grapevine's report is Name, Title, Clan, Status, and Boons.
 * - F-073: Character Equipment's Item column was "—" for every character, though the items a
 *   character holds are right there as connections.
 * - F-074: Search Report's Match column was "—" for every row, and its sort was a no-op.
 */
class ReportColumnsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-report-columns';
	private int $game_id;
	private int $storyteller;
	private int $player;
	private int $isolde;
	private int $marcus;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id     = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Report Columns' ] );
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor', 'display_name' => 'Story Teller' ] );
		$this->player      = self::factory()->user->create( [ 'role' => 'subscriber', 'display_name' => 'Pat Player' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller, 'hst' );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->isolde = Character::create( [
			'name' => 'Isolde', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'status' => 'active', 'wp_user_id' => $this->player,
			'sheet_data' => [
				'vampire-identity' => [ 'Clan' => 'Toreador', 'Title' => 'Harpy' ],
				'vampire-statuses' => [ [ 'name' => 'Acknowledged', 'count' => 1 ], [ 'name' => 'Admired', 'count' => 1 ] ],
			],
		] );
		$this->marcus = Character::create( [
			'name' => 'Marcus', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'status' => 'active', 'wp_user_id' => $this->player,
			'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Ventrue' ] ],
		] );
		// Starting XP from an import: no change row carries it.
		Character::update_xp( $this->isolde, 10, 10 );
		Character::update_xp( $this->marcus, 4, 4 );

		wp_set_current_user( $this->storyteller );
	}

	private function approved( int $character_id, array $change ): void {
		$id = Change::create( array_merge( [ 'character_id' => $character_id, 'status' => 'pending', 'submitted_by' => $this->player ], $change ) );
		$this->assertTrue( Change_Engine::approve( $id, $this->storyteller, null ) );
	}

	private function report( string $key, array $query = [] ): array {
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports/{$key}" );
		$request->set_query_params( $query );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$document = $response->get_data();
		return array_map( static fn( array $row ) => array_combine( $document['columns'], $row ), $document['rows'] );
	}

	public function test_experience_history_shows_the_totals_after_each_change_and_its_reason(): void {
		$this->approved( $this->isolde, [ 'change_type' => 'xp_earn', 'change_data' => [ 'amount' => 5, 'reason' => 'Game attendance' ], 'xp_cost' => 0 ] );
		$this->approved( $this->isolde, [
			'change_type' => 'add_trait', 'category' => 'met-abilities', 'xp_cost' => 3, 'notes' => 'Studying the occult',
			'change_data' => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 1 ] ],
		] );

		$rows = array_values( array_filter( $this->report( 'experience-history' ), static fn( $r ) => $r['Name'] === 'Isolde' ) );

		$this->assertSame( [ '15', '15' ], [ $rows[0]['Earned'], $rows[0]['Unspent'] ] );
		$this->assertSame( 'Game attendance', $rows[0]['Reason'] );
		$this->assertSame( [ '15', '12' ], [ $rows[1]['Earned'], $rows[1]['Unspent'] ] );
		$this->assertSame( 'Studying the occult', $rows[1]['Reason'] );
	}

	public function test_player_point_history_runs_the_totals_across_a_players_characters(): void {
		$this->approved( $this->isolde, [ 'change_type' => 'xp_earn', 'change_data' => [ 'amount' => 5, 'reason' => 'Attendance' ], 'xp_cost' => 0 ] );
		$this->approved( $this->marcus, [ 'change_type' => 'xp_earn', 'change_data' => [ 'amount' => 2, 'reason' => 'Costuming' ], 'xp_cost' => 0 ] );

		$rows = array_values( array_filter( $this->report( 'player-point-history' ), static fn( $r ) => $r['Name'] === 'Pat Player' ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( [ '19', '19' ], [ $rows[0]['PP Earned'], $rows[0]['PP Unspent'] ], 'after the first award: 15 + 4' );
		$this->assertSame( [ '21', '21' ], [ $rows[1]['PP Earned'], $rows[1]['PP Unspent'] ], 'the player\'s characters together: 15 + 6' );
	}

	public function test_vampire_status_report_is_grapevines_name_title_clan_status_and_boons(): void {
		$boon = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/boons" );
		$boon->set_param( 'owed_by_character_id', $this->marcus );
		$boon->set_param( 'owed_to_character_id', $this->isolde );
		$boon->set_param( 'boon_level', 'major' );
		$this->assertSame( 201, rest_get_server()->dispatch( $boon )->get_status() );

		$rows = array_column( $this->report( 'vampire-status-report' ), null, 'Name' );

		$this->assertSame( [ 'Name', 'Title', 'Clan', 'Status', 'Boons' ], array_keys( $rows['Isolde'] ) );
		$this->assertSame( 'Harpy', $rows['Isolde']['Title'] );
		$this->assertSame( 'Toreador', $rows['Isolde']['Clan'] );
		$this->assertStringContainsString( 'Acknowledged', $rows['Isolde']['Status'] );
		$this->assertStringContainsString( 'Marcus', $rows['Isolde']['Boons'] );
		$this->assertStringContainsString( 'Isolde', $rows['Marcus']['Boons'] );
	}

	public function test_character_equipment_names_the_items_a_character_holds(): void {
		foreach ( [ 'Silver Knife', 'Old Map' ] as $name ) {
			$item = World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => $name, 'properties' => [], 'created_by' => 1 ] );
			Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $this->isolde, 'target_type' => 'world_object', 'target_id' => $item, 'created_by' => 1 ] );
		}

		$rows = array_column( $this->report( 'character-equipment' ), null, 'Character' );

		$this->assertSame( 'Old Map, Silver Knife', $rows['Isolde']['Item'] );
		$this->assertSame( '—', $rows['Marcus']['Item'] );
	}

	public function test_search_report_says_why_each_character_matched(): void {
		$rows = $this->report( 'search-report', [ 'conditions' => wp_json_encode( [ [ 'field' => 'clan', 'operator' => 'equals', 'find' => 'Toreador', 'value' => 'Toreador' ] ] ) ] );

		$this->assertSame( [ 'Isolde' ], array_column( $rows, 'Name' ) );
		$this->assertStringContainsString( 'Toreador', $rows[0]['Match'] );
	}
}
