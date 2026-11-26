<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Temporary pool spend/regain, wounds by type, the night's losses, and a session's close - all logged, none of
 * them waiting for a Storyteller.
 */
class TemporaryStateThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-temporary-state';
	private int $game_id;
	private int $player;
	private int $hst;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->slug,
			'name'       => 'Thread Temporary State',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->character_id = (int) Character::create( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
			'sheet_data' => [
				'vampire-resources' => [
					'Blood'     => [ 'permanent' => 10, 'temporary' => 6 ],
					'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ],
				],
				'vampire-abilities' => [
					[ 'name' => 'Brawl', 'count' => 3 ],
					[ 'name' => 'Occult', 'count' => 2 ],
				],
			],
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function pool_request( int $as, int $delta, string $pool_name = 'Blood' ): WP_REST_Request {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/temporary/pool" );
		$request->set_param( 'block_slug', 'vampire-resources' );
		$request->set_param( 'pool_name', $pool_name );
		$request->set_param( 'delta', $delta );
		$request->set_param( 'reason', 'Lost a Physical challenge against Corwin Ashe' );
		return $request;
	}

	/**
	 * A character of the given creature type holding the given sheet data, owned by the fixture player.
	 *
	 * @param array<string,mixed> $sheet_data
	 */
	private function character_of( string $stack, array $sheet_data ): int {
		return (int) Character::create( [
			'name'       => 'Fixture ' . $stack,
			'stack_slug' => $stack,
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
			'sheet_data' => $sheet_data,
		] );
	}

	private function pool_request_on( int $character_id, string $block_slug, string $pool_name, int $delta ): WP_REST_Request {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$character_id}/temporary/pool" );
		$request->set_param( 'block_slug', $block_slug );
		$request->set_param( 'pool_name', $pool_name );
		$request->set_param( 'delta', $delta );
		return $request;
	}

	// -------------------------------------------------------------------------
	// Pool spend/regain
	// -------------------------------------------------------------------------

	public function test_a_player_can_spend_their_own_pool(): void {
		$response = $this->dispatch( $this->pool_request( $this->player, -2 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 4, $response->get_data()['temporary'] );
		$this->assertSame( 10, $response->get_data()['permanent'] );

		$character = Character::find( $this->character_id );
		$this->assertSame( 4, (int) $character->sheet_data['vampire-resources']['Blood']['temporary'] );
	}

	public function test_a_player_can_regain_their_own_pool_clamped_to_permanent(): void {
		$response = $this->dispatch( $this->pool_request( $this->player, 100 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 10, $response->get_data()['temporary'] );
	}

	public function test_spending_clamps_at_zero_rather_than_going_negative(): void {
		$response = $this->dispatch( $this->pool_request( $this->player, -100 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['temporary'] );
	}

	public function test_a_storyteller_can_spend_any_characters_pool(): void {
		$response = $this->dispatch( $this->pool_request( $this->hst, -1 ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_another_player_cannot_spend_someone_elses_pool(): void {
		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other_player, 'player' );

		$response = $this->dispatch( $this->pool_request( $other_player, -1 ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_spending_an_unheld_pool_is_refused(): void {
		$response = $this->dispatch( $this->pool_request( $this->player, -1, 'Rage' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pool_not_found', $response->as_error()->get_error_code() );
	}

	public function test_the_spend_is_logged_as_an_already_approved_change(): void {
		$this->dispatch( $this->pool_request( $this->player, -2 ) );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'temporary_pool_spend' ORDER BY id DESC LIMIT 1",
			$this->character_id
		) );
		$this->assertNotNull( $row );
		$this->assertSame( 'approved', $row->status );
		$this->assertSame( 'Lost a Physical challenge against Corwin Ashe', $row->notes );
	}

	// -------------------------------------------------------------------------
	// Wounds
	// -------------------------------------------------------------------------

	public function test_a_player_can_take_a_wound_of_their_own_character(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/wound" );
		$request->set_param( 'type', 'lethal' );
		$request->set_param( 'amount', 2 );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'bashing' => 0, 'lethal' => 2, 'aggravated' => 0 ], $response->get_data()['wounds'] );
	}

	public function test_armor_is_folded_into_the_logged_reason_not_subtracted(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/wound" );
		$request->set_param( 'type', 'bashing' );
		$request->set_param( 'amount', 3 );
		$request->set_param( 'armor', 8 );
		$response = $this->dispatch( $request );

		$this->assertSame( 3, $response->get_data()['wounds']['bashing'] );

		global $wpdb;
		$notes = $wpdb->get_var( $wpdb->prepare(
			"SELECT notes FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'wound_taken' ORDER BY id DESC LIMIT 1",
			$this->character_id
		) );
		$this->assertStringContainsString( '8 to armor', $notes );
	}

	public function test_healing_reduces_a_wound_type_floored_at_zero(): void {
		Character::set_wound( $this->character_id, 'aggravated', 5 );

		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/heal" );
		$request->set_param( 'type', 'aggravated' );
		$request->set_param( 'amount', 100 );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['wounds']['aggravated'] );
	}

	public function test_an_invalid_wound_type_is_refused(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/wound" );
		$request->set_param( 'type', 'fire' );
		$request->set_param( 'amount', 1 );
		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_wound_state_appears_in_the_sheet_document(): void {
		Character::set_wound( $this->character_id, 'lethal', 2 );

		wp_set_current_user( $this->hst );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/sheet-document" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'bashing' => 0, 'lethal' => 2, 'aggravated' => 0 ], $response->get_data()['wounds'] );
	}

	public function test_a_spent_pool_shows_its_temporary_rating_in_the_sheet_document(): void {
		Character::set_pool_temporary( $this->character_id, 'vampire-resources', 'Blood', 4 );

		wp_set_current_user( $this->hst );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/sheet-document" );
		$response = $this->dispatch( $request );
		$pair     = $this->header_pair( $response->get_data(), 'Blood' );

		$this->assertSame( 'x10 (4 temporary)', $pair[1] );
		$this->assertSame( 10, $pair[2] );
	}

	public function test_an_unspent_pool_shows_no_temporary_marker(): void {
		wp_set_current_user( $this->hst );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/sheet-document" );
		$response = $this->dispatch( $request );
		$pair     = $this->header_pair( $response->get_data(), 'Willpower' );

		$this->assertSame( 'x5', $pair[1] );
	}

	/**
	 * The one header tuple naming the given label, or an empty array when none does.
	 *
	 * @param array<string,mixed> $document
	 * @param string              $label
	 * @return array<int,mixed>
	 */
	private function header_pair( array $document, string $label ): array {
		foreach ( $document['header'] as $pair ) {
			if ( $pair[0] === $label ) {
				return $pair;
			}
		}
		return [];
	}

	public function test_a_lost_trait_is_marked_in_the_sheet_document(): void {
		Character::append_night_loss( $this->character_id, 'trait', [ 'block_slug' => 'vampire-abilities', 'name' => 'Brawl' ] );

		wp_set_current_user( $this->hst );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/sheet-document" );
		$response = $this->dispatch( $request );
		$lines    = $this->section_lines( $response->get_data(), 'vampire-abilities' );

		$this->assertStringContainsString( 'lost tonight', $this->row_naming( $lines, 'Brawl' ) );
		$this->assertStringNotContainsString( 'lost tonight', $this->row_naming( $lines, 'Occult' ) );
	}

	public function test_an_expended_ability_is_marked_with_its_level_count(): void {
		Character::append_night_loss( $this->character_id, 'ability', [ 'block_slug' => 'vampire-abilities', 'name' => 'Occult', 'levels' => 2 ] );

		wp_set_current_user( $this->hst );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/sheet-document" );
		$response = $this->dispatch( $request );
		$lines    = $this->section_lines( $response->get_data(), 'vampire-abilities' );

		$this->assertStringContainsString( '2 expended tonight', $this->row_naming( $lines, 'Occult' ) );
	}

	/**
	 * Every row of a named section flattened to plain text, across its groups when it has them - the same walk the
	 * bot's own renderer does.
	 *
	 * @param array<string,mixed> $document
	 * @param string              $block_slug
	 * @return string[]
	 */
	private function section_lines( array $document, string $block_slug ): array {
		foreach ( $document['sections'] as $section ) {
			if ( $section['block_slug'] !== $block_slug ) {
				continue;
			}
			$rows = [];
			foreach ( $section['groups'] ?? [ [ 'rows' => $section['rows'] ?? [] ] ] as $group ) {
				foreach ( $group['rows'] as $row ) {
					$rows[] = is_array( $row ) ? $row['text'] : $row;
				}
			}
			return $rows;
		}
		return [];
	}

	/**
	 * The one line among a section's rows that names the given trait, or an empty string when none does.
	 *
	 * @param string[] $lines
	 * @param string   $name
	 * @return string
	 */
	private function row_naming( array $lines, string $name ): string {
		foreach ( $lines as $line ) {
			if ( str_starts_with( $line, $name ) ) {
				return $line;
			}
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Night losses
	// -------------------------------------------------------------------------

	public function test_a_player_can_record_their_own_lost_trait(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses" );
		$request->set_param( 'kind', 'trait' );
		$request->set_param( 'block_slug', 'vampire-abilities' );
		$request->set_param( 'name', 'Brawl' );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['lost_traits'] );
		$this->assertSame( 'Brawl', $response->get_data()['lost_traits'][0]['name'] );
	}

	public function test_an_expended_ability_records_its_level_count(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses" );
		$request->set_param( 'kind', 'ability' );
		$request->set_param( 'block_slug', 'vampire-abilities' );
		$request->set_param( 'name', 'Occult' );
		$request->set_param( 'levels', 2 );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['expended_abilities'][0]['levels'] );
	}

	public function test_a_lost_trait_is_logged_as_an_already_approved_change_with_its_reason(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses" );
		$request->set_param( 'kind', 'trait' );
		$request->set_param( 'block_slug', 'vampire-abilities' );
		$request->set_param( 'name', 'Brawl' );
		$request->set_param( 'reason', 'Lost in a Physical challenge against Corwin Ashe' );
		$this->dispatch( $request );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'night_loss_trait' ORDER BY id DESC LIMIT 1",
			$this->character_id
		) );
		$this->assertNotNull( $row );
		$this->assertSame( 'approved', $row->status );
		$this->assertSame( 'Lost in a Physical challenge against Corwin Ashe', $row->notes );
	}

	public function test_clearing_night_losses_empties_both_lists(): void {
		Character::append_night_loss( $this->character_id, 'trait', [ 'block_slug' => 'vampire-abilities', 'name' => 'Brawl' ] );

		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses" );
		$request->set_param( 'reason', 'Night closed early' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $response->get_data()['lost_traits'] );
	}

	public function test_clearing_night_losses_is_logged_with_its_reason(): void {
		Character::append_night_loss( $this->character_id, 'trait', [ 'block_slug' => 'vampire-abilities', 'name' => 'Brawl' ] );

		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses" );
		$request->set_param( 'reason', 'Night closed early' );
		$this->dispatch( $request );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'night_losses_cleared' ORDER BY id DESC LIMIT 1",
			$this->character_id
		) );
		$this->assertNotNull( $row );
		$this->assertSame( 'approved', $row->status );
		$this->assertSame( 'Night closed early', $row->notes );
	}

	public function test_clearing_an_already_empty_night_loses_no_change_logged(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/characters/{$this->character_id}/night-losses" );
		$request->set_param( 'reason', 'Night closed early' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'night_losses_cleared'",
			$this->character_id
		) );
		$this->assertSame( 0, $count );
	}

	// -------------------------------------------------------------------------
	// Pools the books give no temporary points, and pools whose temporary points build up
	// -------------------------------------------------------------------------

	public function test_a_rating_only_pool_refuses_both_spend_and_regain(): void {
		$id = $this->character_of( 'vampire', [ 'vampire-resources' => [ 'Morality' => [ 'permanent' => 7, 'temporary' => 7 ] ] ] );

		foreach ( [ -1, 1 ] as $delta ) {
			$response = $this->dispatch( $this->pool_request_on( $id, 'vampire-resources', 'Morality', $delta ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'rating_only', $response->as_error()->get_error_code() );
			$this->assertSame( 'Morality has no temporary points.', $response->as_error()->get_error_message() );
		}

		$this->assertSame( [ 'permanent' => 7, 'temporary' => 7 ], Character::find( $id )->sheet_data['vampire-resources']['Morality'] );

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'temporary_pool_spend'",
			$id
		) ) );
	}

	public function test_a_build_up_pool_gains_past_its_rating_up_to_its_maximum_and_loses_down_to_zero(): void {
		$id = $this->character_of( 'changeling', [ 'changeling-resources' => [ 'Banality' => [ 'permanent' => 2, 'temporary' => 2 ] ] ] );

		$response = $this->dispatch( $this->pool_request_on( $id, 'changeling-resources', 'Banality', 5 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, $response->get_data()['temporary'], 'past the rating of 2' );
		$this->assertSame( 2, $response->get_data()['permanent'] );

		$response = $this->dispatch( $this->pool_request_on( $id, 'changeling-resources', 'Banality', 100 ) );
		$this->assertSame( 10, $response->get_data()['temporary'], "stops at the pool's own maximum" );

		$response = $this->dispatch( $this->pool_request_on( $id, 'changeling-resources', 'Banality', -100 ) );
		$this->assertSame( 0, $response->get_data()['temporary'] );
		$this->assertSame( 0, Character::find( $id )->sheet_data['changeling-resources']['Banality']['temporary'] );
	}

	public function test_a_build_up_pool_with_no_maximum_has_no_ceiling_and_keeps_its_fraction(): void {
		$id = $this->character_of( 'werewolf', [ 'werewolf-renown' => [ 'Honor' => [ 'permanent' => 4.5, 'temporary' => 3.5 ] ] ] );

		$response = $this->dispatch( $this->pool_request_on( $id, 'werewolf-renown', 'Honor', 1 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 4.5, (float) $response->get_data()['temporary'] );

		$response = $this->dispatch( $this->pool_request_on( $id, 'werewolf-renown', 'Honor', 50 ) );
		$this->assertSame( 54.5, (float) $response->get_data()['temporary'] );
		$this->assertSame( 4.5, (float) $response->get_data()['permanent'] );
	}

	public function test_an_ordinary_pool_is_still_held_to_its_rating(): void {
		$response = $this->dispatch( $this->pool_request( $this->player, 100 ) );
		$this->assertSame( 10, $response->get_data()['temporary'] );

		$id       = $this->character_of( 'changeling', [ 'changeling-resources' => [ 'Glamour' => [ 'permanent' => 4, 'temporary' => 1 ] ] ] );
		$response = $this->dispatch( $this->pool_request_on( $id, 'changeling-resources', 'Glamour', 100 ) );
		$this->assertSame( 4, $response->get_data()['temporary'] );
	}

	public function test_a_rating_only_pool_shows_its_rating_alone_in_the_sheet_document(): void {
		$id = $this->character_of( 'vampire', [ 'vampire-resources' => [
			'Blood'    => [ 'permanent' => 10, 'temporary' => 6 ],
			'Morality' => [ 'permanent' => 7, 'temporary' => 4 ],
		] ] );

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$id}/sheet-document" ) );
		$data     = $response->get_data();

		$this->assertSame( 'x7', $this->header_pair( $data, 'Morality' )[1] );
		$this->assertSame( 'x10 (6 temporary)', $this->header_pair( $data, 'Blood' )[1], 'an ordinary pool still shows what is spent' );
	}

	public function test_a_build_up_pool_shows_its_temporary_count_in_the_sheet_document(): void {
		$id = $this->character_of( 'changeling', [ 'changeling-resources' => [ 'Banality' => [ 'permanent' => 2, 'temporary' => 7 ] ] ] );

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$id}/sheet-document" ) );

		$this->assertSame( 'x2 (7 temporary)', $this->header_pair( $response->get_data(), 'Banality' )[1] );
	}

	// -------------------------------------------------------------------------
	// Session close
	// -------------------------------------------------------------------------

	private function make_session(): int {
		return (int) Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => '2026-10-14', 'created_by' => $this->hst ] );
	}

	public function test_closing_a_session_resets_attendees_pools_and_clears_losses(): void {
		$session_id = $this->make_session();
		Attendance::record( $session_id, $this->game_id, [ 'character_id' => $this->character_id, 'recorded_by' => $this->hst ] );
		Character::set_pool_temporary( $this->character_id, 'vampire-resources', 'Blood', 2 );
		Character::append_night_loss( $this->character_id, 'trait', [ 'block_slug' => 'vampire-abilities', 'name' => 'Brawl' ] );

		wp_set_current_user( $this->hst );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close" );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ $this->character_id ], $response->get_data()['character_ids'] );

		$character = Character::find( $this->character_id );
		$this->assertSame( 10, (int) $character->sheet_data['vampire-resources']['Blood']['temporary'] );
		$this->assertCount( 0, Character::night_losses( $this->character_id )['lost_traits'] );

		$session = Game_Session::find( $session_id );
		$this->assertNotEmpty( $session->closed_at );
	}

	public function test_closing_a_session_resets_ordinary_pools_and_leaves_build_up_and_rating_only_pools_alone(): void {
		$changeling = $this->character_of( 'changeling', [ 'changeling-resources' => [
			'Glamour'  => [ 'permanent' => 5, 'temporary' => 1 ],
			'Banality' => [ 'permanent' => 2, 'temporary' => 7 ],
		] ] );
		$vampire    = $this->character_of( 'vampire', [ 'vampire-resources' => [
			'Blood'    => [ 'permanent' => 10, 'temporary' => 3 ],
			'Morality' => [ 'permanent' => 7, 'temporary' => 4 ],
		] ] );

		$session_id = $this->make_session();
		Attendance::record( $session_id, $this->game_id, [ 'character_id' => $changeling, 'recorded_by' => $this->hst ] );
		Attendance::record( $session_id, $this->game_id, [ 'character_id' => $vampire, 'recorded_by' => $this->hst ] );

		wp_set_current_user( $this->hst );
		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close" ) );
		$this->assertSame( 200, $response->get_status() );

		$sheet = Character::find( $changeling )->sheet_data['changeling-resources'];
		$this->assertSame( 5, $sheet['Glamour']['temporary'], 'an ordinary pool goes back to its rating' );
		$this->assertSame( 7, $sheet['Banality']['temporary'], 'what built up stays built up' );

		$sheet = Character::find( $vampire )->sheet_data['vampire-resources'];
		$this->assertSame( 10, $sheet['Blood']['temporary'] );
		$this->assertSame( [ 'permanent' => 7, 'temporary' => 4 ], $sheet['Morality'], 'a rating-only pool is not touched' );
	}

	public function test_closing_a_session_never_touches_a_character_who_did_not_attend(): void {
		$session_id = $this->make_session();
		// No attendance recorded for $this->character_id.
		Character::set_pool_temporary( $this->character_id, 'vampire-resources', 'Blood', 2 );

		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close" );
		$this->dispatch( $request );

		$character = Character::find( $this->character_id );
		$this->assertSame( 2, (int) $character->sheet_data['vampire-resources']['Blood']['temporary'] );
	}

	public function test_closing_an_already_closed_session_409s(): void {
		$session_id = $this->make_session();
		wp_set_current_user( $this->hst );
		$this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close" ) );

		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close" ) );
		$this->assertSame( 409, $response->get_status() );
	}

	public function test_a_player_cannot_close_a_session(): void {
		$session_id = $this->make_session();

		wp_set_current_user( $this->player );
		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close" ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_closing_logs_one_approved_change_per_attendee(): void {
		$session_id = $this->make_session();
		Attendance::record( $session_id, $this->game_id, [ 'character_id' => $this->character_id, 'recorded_by' => $this->hst ] );

		wp_set_current_user( $this->hst );
		$this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/close" ) );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'session_closed' ORDER BY id DESC LIMIT 1",
			$this->character_id
		) );
		$this->assertNotNull( $row );
		$this->assertSame( 'approved', $row->status );
	}
}
