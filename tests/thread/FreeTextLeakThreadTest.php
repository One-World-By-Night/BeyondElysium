<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Free-text leaks found by the coverage guard, proven closed on the real routes.
 */
class FreeTextLeakThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-free-text';
	private int $game_id;
	private int $plot_id;
	private int $character_id;
	private int $change_id;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Free Text',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->plot_id = (int) Plot::create( [
			'game_id'            => $this->game_id,
			'title'              => 'A Closed Plot',
			'description'        => 'Public.',
			'resolution_details' => 'The ritual failed. [ST]Because the sheriff swapped the reagent.[/ST]',
			'resolution_impact'  => 'The domain is unstable. [ST]Set up the Prince falling next month.[/ST]',
			'created_by'         => 1,
		] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->character_id = (int) Character::create( [
			'game_slug'  => $this->game_slug,
			'owner_slug' => $this->game_slug,
			'name'       => 'Free Text Subject',
			'stack_slug' => 'vampire',
			'wp_user_id' => $this->player,
			'created_by' => 1,
		] );

		$this->change_id = Change::create( [
			'character_id' => $this->character_id,
			'change_type'  => 'trait_add',
			'category'     => 'trait',
			'change_data'  => [ 'block' => 'met-abilities', 'trait' => [ 'name' => 'Occult' ] ],
			'submitted_by' => $this->player,
			'notes'        => 'Bought at game. [ST]Storyteller comped this one.[/ST]',
			'reason'       => 'Needs approval. [ST]Watch this player, they keep overspending.[/ST]',
		] );
		Change::update_status(
			$this->change_id,
			'approved',
			1,
			'Approved. [ST]Do not tell them the cap was waived.[/ST]'
		);
	}

	private function get( string $route ) {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	public function test_a_player_never_sees_st_text_in_a_plot_resolution(): void {
		wp_set_current_user( $this->player );

		$plot = (array) $this->get( "/be/v1/{$this->game_slug}/plots/{$this->plot_id}" )->get_data();

		$this->assertStringNotContainsString( 'swapped the reagent', (string) $plot['resolution_details'] );
		$this->assertStringNotContainsString( '[ST]', (string) $plot['resolution_details'] );
		$this->assertStringContainsString( 'The ritual failed', (string) $plot['resolution_details'] );

		$this->assertStringNotContainsString( 'the Prince falling', (string) $plot['resolution_impact'] );
		$this->assertStringContainsString( 'The domain is unstable', (string) $plot['resolution_impact'] );
	}

	public function test_a_player_never_sees_st_text_in_their_own_change_history(): void {
		wp_set_current_user( $this->player );

		$changes = $this->get(
			"/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes"
		)->get_data();

		$this->assertNotEmpty( $changes );
		$change = (array) $changes[0];

		$this->assertStringNotContainsString( 'comped this one', (string) $change['notes'] );
		$this->assertStringNotContainsString( 'keep overspending', (string) $change['reason'] );
		$this->assertStringNotContainsString( 'the cap was waived', (string) $change['review_notes'] );

		$this->assertStringContainsString( 'Bought at game', (string) $change['notes'] );
		$this->assertStringContainsString( 'Needs approval', (string) $change['reason'] );
		$this->assertStringContainsString( 'Approved', (string) $change['review_notes'] );
	}

	public function test_a_storyteller_still_sees_all_of_it(): void {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		wp_set_current_user( $hst );

		$plot = (array) $this->get( "/be/v1/{$this->game_slug}/plots/{$this->plot_id}" )->get_data();
		$this->assertStringContainsString( 'swapped the reagent', (string) $plot['resolution_details'] );
		$this->assertStringContainsString( 'the Prince falling', (string) $plot['resolution_impact'] );

		$changes = $this->get(
			"/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes"
		)->get_data();
		$change = (array) $changes[0];
		$this->assertStringContainsString( 'the cap was waived', (string) $change['review_notes'] );
		$this->assertStringContainsString( 'keep overspending', (string) $change['reason'] );
	}

	public function test_a_player_never_sees_st_text_in_a_storytellers_answer_note(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_character_submissions', [
			'game_id'      => $this->game_id,
			'submitted_by' => $this->player,
			'source_file'  => 'sheet.gex',
			'format'       => 'gex',
			'file_hash'    => str_repeat( 'a', 64 ),
			'state'        => 'refused',
			'answer_note'  => 'Resubmit with XP totals. [ST]They fabricated the last two sheets.[/ST]',
			'created_at'   => current_time( 'mysql' ),
		] );

		wp_set_current_user( $this->player );
		$rows = $this->get( '/be/v1/my/submissions' )->get_data();

		$this->assertNotEmpty( $rows );
		$note = (string) ( (array) $rows[0] )['answer_note'];

		$this->assertStringNotContainsString( 'fabricated the last two', $note );
		$this->assertStringNotContainsString( '[ST]', $note );
		$this->assertStringContainsString( 'Resubmit with XP totals', $note );
	}
}
