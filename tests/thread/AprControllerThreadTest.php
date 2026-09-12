<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers Apr_Controller's two halves through the real REST server: the
 * settings read-modify-write merge and its validation (§3.6/§5.6), chronicle-
 * scoped permission gating for be_manage_apr (§3.5/§5.7, the same shape
 * ApprovalRulesControllerTest already proved for be_manage_approval_rules),
 * and the ledger routes' ownership visibility (§3.4/§5.8).
 */
class AprControllerThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'apr-controller-test';
	private int $game_id;
	private int $admin_id;
	private int $hst_id;
	private int $narrator_id;
	private int $player_id;
	private int $other_player_id;
	private int $player_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Apr Controller Test',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [
				'extended_health' => true,
				'apr'              => [ 'copy_previous' => true, 'race_rumors' => false ],
			] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id        = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->hst_id          = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->narrator_id     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player_id       = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->other_player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Game_Member::set_role( $this->game_id, $this->hst_id, 'hst' );
		Game_Member::set_role( $this->game_id, $this->narrator_id, 'narrator' );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		Game_Member::set_role( $this->game_id, $this->other_player_id, 'player' );

		Schema_Block::create( [
			'slug' => 'apr-test-stack-backgrounds', 'name' => 'Backgrounds', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [
				[ 'name' => 'Bureaucracy', 'source' => 'Influences' ],
				[ 'name' => 'Resources', 'source' => 'Backgrounds' ],
			] ],
			'is_system' => 0,
		] );

		$this->player_character_id = Character::create( [
			'name' => 'Apr Test Character', 'stack_slug' => 'apr-test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'apr-test-stack-backgrounds' => [ [ 'name' => 'Bureaucracy', 'count' => 2 ] ] ],
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		return rest_get_server()->dispatch( $request );
	}

	private function put_settings( array $apr ) {
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/apr-settings" );
		$request->set_body_params( [ 'apr' => $apr ] );
		return $this->dispatch( $request );
	}

	// --- settings: merge and validation ---

	public function test_a_partial_put_preserves_untouched_apr_keys_and_other_settings(): void {
		wp_set_current_user( $this->admin_id );

		$response = $this->put_settings( [ 'personal_actions' => 5 ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 5, $response->get_data()['personal_actions'] );

		global $wpdb;
		$stored = json_decode( $wpdb->get_var( $wpdb->prepare(
			"SELECT settings FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug
		) ), true );

		$this->assertTrue( $stored['extended_health'], 'a key outside apr entirely must survive' );
		$this->assertTrue( $stored['apr']['copy_previous'], 'an untouched apr key must survive the partial write' );
		$this->assertFalse( $stored['apr']['race_rumors'], 'ditto' );
		$this->assertSame( 5, $stored['apr']['personal_actions'] );
	}

	public function test_personal_actions_out_of_range_is_rejected(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->put_settings( [ 'personal_actions' => 500 ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_an_unknown_background_name_is_rejected_not_silently_stored(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->put_settings( [ 'background_actions' => [ 'Not A Real Background' ] ] );
		$this->assertSame( 400, $response->get_status() );

		global $wpdb;
		$stored = json_decode( $wpdb->get_var( $wpdb->prepare(
			"SELECT settings FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug
		) ), true );
		$this->assertArrayNotHasKey( 'background_actions', $stored['apr'] );
	}

	public function test_a_real_background_name_is_accepted(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->put_settings( [ 'background_actions' => [ 'Resources' ] ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'Resources' ], $response->get_data()['background_actions'] );
	}

	public function test_actions_per_level_rejects_a_level_outside_1_through_20(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->put_settings( [ 'actions_per_level' => [ '21' => 10 ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_get_background_options_returns_the_real_catalog_union(): void {
		wp_set_current_user( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/apr-settings/backgrounds" );
		$response = $this->dispatch( $request );

		$names = array_column( $response->get_data(), 'name' );
		$this->assertContains( 'Bureaucracy', $names );
		$this->assertContains( 'Resources', $names );
	}

	// --- settings: permission gating (same shape as ApprovalRulesControllerTest) ---

	public function test_an_administrator_needs_no_membership_row_for_settings(): void {
		wp_set_current_user( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/apr-settings" );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
	}

	public function test_an_hst_member_is_allowed_to_read_settings(): void {
		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/apr-settings" );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
	}

	public function test_a_narrator_member_is_denied_settings(): void {
		wp_set_current_user( $this->narrator_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/apr-settings" );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_a_player_is_denied_settings(): void {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/apr-settings" );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	// --- ledger: ownership visibility (§3.4/§5.8) ---

	public function test_the_owning_player_can_read_their_own_spendable(): void {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$this->player_character_id}/spendable" );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
	}

	public function test_a_different_player_cannot_read_someone_elses_spendable(): void {
		wp_set_current_user( $this->other_player_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$this->player_character_id}/spendable" );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_an_hst_can_read_any_characters_spendable(): void {
		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$this->player_character_id}/spendable" );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
	}

	// --- ledger: recording a use ---

	private function record_use( int $character_id, string $name ) {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$character_id}/background-uses" );
		$request->set_body_params( [ 'game_date' => '2026-01-01', 'name' => $name ] );
		return $this->dispatch( $request );
	}

	public function test_the_owning_player_can_record_their_own_use(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->record_use( $this->player_character_id, 'Bureaucracy' );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_a_different_player_cannot_record_a_use_for_someone_elses_character(): void {
		wp_set_current_user( $this->other_player_id );
		$response = $this->record_use( $this->player_character_id, 'Bureaucracy' );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_hst_can_record_a_use_for_any_character(): void {
		wp_set_current_user( $this->hst_id );
		$response = $this->record_use( $this->player_character_id, 'Bureaucracy' );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_recording_an_unheld_background_is_rejected(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->record_use( $this->player_character_id, 'Never Held' );
		$this->assertSame( 400, $response->get_status() );
	}
}
