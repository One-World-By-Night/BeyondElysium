<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller undoes an approved change: its sheet changes and its XP go back, only while everything it touched is as
 * it left it, and the change reads undone.
 */
class ChangeUndoThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-change-undo';
	private int $game_id;
	private int $character_id;
	private int $player_id;
	private int $st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Thread Change Undo',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->character_id = Character::create( [
			'name' => 'Undo Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [
				'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Brawl', 'count' => 2 ] ],
				'vampire-merits'    => [],
				'vampire-resources' => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ],
			],
		] );
		Character::update_xp( $this->character_id, 20, 20 );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function sheet(): array {
		return Character::find( $this->character_id )->sheet_data;
	}

	private function unspent(): int {
		return (int) Character::find( $this->character_id )->xp_unspent;
	}

	/**
	 * Submits one change as the player and has the Storyteller approve it; returns its id.
	 *
	 * @param array<string,mixed> $change
	 */
	private function approved( array $change ): int {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" );
		foreach ( $change as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$created = $this->dispatch( $request );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$id = (int) $created->get_data()->id;

		if ( $created->get_data()->status === 'pending' ) {
			wp_set_current_user( $this->st_id );
			$review = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$id}" );
			$review->set_param( 'status', 'approved' );
			$this->assertSame( 200, $this->dispatch( $review )->get_status() );
		}
		return $id;
	}

	private function occult( int $count, int $was ): array {
		return [
			'change_type' => 'modify_trait',
			'category'    => 'vampire-abilities',
			'change_data' => [
				'block_slug' => 'vampire-abilities',
				'trait'      => [ 'name' => 'Occult', 'count' => $count ],
				'previous'   => [ 'name' => 'Occult', 'count' => $was ],
			],
		];
	}

	private function undo( int $id, int $as = 0, ?string $note = null ) {
		wp_set_current_user( $as ?: $this->st_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/changes/{$id}/undo" );
		if ( $note !== null ) {
			$request->set_param( 'note', $note );
		}
		return $this->dispatch( $request );
	}

	private function history(): array {
		wp_set_current_user( $this->st_id );
		return $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" ) )->get_data();
	}

	// --- Each kind of change ---------------------------------------------------------------------------------

	public function test_undoing_a_raised_trait_puts_it_back_and_returns_the_xp(): void {
		$before = $this->sheet();
		$xp     = $this->unspent();
		$id     = $this->approved( $this->occult( 4, 3 ) );
		$this->assertLessThan( $xp, $this->unspent(), 'the raise cost XP' );

		$response = $this->undo( $id );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'undone', $response->get_data()->status );
		$this->assertSame( $before, $this->sheet() );
		$this->assertSame( $xp, $this->unspent() );
	}

	public function test_undoing_an_added_trait_takes_it_off_and_returns_the_xp(): void {
		$before = $this->sheet();
		$id     = $this->approved( [
			'change_type' => 'add_trait',
			'category'    => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Daredevil' ] ],
		] );
		$this->assertSame( 17, $this->unspent() );

		$this->assertSame( 200, $this->undo( $id )->get_status() );

		$this->assertSame( $before, $this->sheet() );
		$this->assertSame( 20, $this->unspent() );
	}

	public function test_undoing_a_removal_brings_the_trait_back_where_it_was(): void {
		$before = $this->sheet();
		$id     = $this->approved( [
			'change_type' => 'remove_trait',
			'category'    => 'vampire-abilities',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult' ] ],
		] );
		$this->assertCount( 1, $this->sheet()['vampire-abilities'] );

		$this->assertSame( 200, $this->undo( $id )->get_status() );

		$this->assertSame( $before, $this->sheet() );
	}

	public function test_undoing_a_pool_raise_puts_the_rating_back_and_the_temporary_rating_comes_down_with_it(): void {
		$id = $this->approved( [
			'change_type' => 'modify_resource',
			'category'    => 'vampire-resources',
			'change_data' => [ 'block_slug' => 'vampire-resources', 'values' => [ 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ] ] ],
		] );
		$this->assertSame( 6, $this->sheet()['vampire-resources']['Willpower']['permanent'] );
		Character::set_pool_temporary( $this->character_id, 'vampire-resources', 'Willpower', 2 );

		$this->assertSame( 200, $this->undo( $id )->get_status(), 'what was spent at the table never stops it' );

		$this->assertSame( [ 'permanent' => 5, 'temporary' => 1 ], $this->sheet()['vampire-resources']['Willpower'] );
	}

	public function test_undoing_a_permanent_spend_gives_the_point_back(): void {
		wp_set_current_user( $this->st_id );
		$spend = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/permanent/pool" );
		$spend->set_param( 'block_slug', 'vampire-resources' );
		$spend->set_param( 'pool_name', 'Willpower' );
		$this->assertSame( 200, $this->dispatch( $spend )->get_status() );
		$this->assertSame( 4, $this->sheet()['vampire-resources']['Willpower']['permanent'] );

		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'pool_permanent_spent' ORDER BY id DESC LIMIT 1",
			$this->character_id
		) );

		$this->assertSame( 200, $this->undo( $id )->get_status() );

		$this->assertSame( 5, $this->sheet()['vampire-resources']['Willpower']['permanent'] );
	}

	public function test_undoing_an_xp_award_takes_it_back_off(): void {
		Change_Engine::apply_xp( $this->character_id, 5, 'Game night', $this->st_id );
		$this->assertSame( 25, $this->unspent() );
		$id = (int) Change::for_character( $this->character_id, [ 'change_type' => 'xp_earn', 'per_page' => 1 ] )[0]->id;

		$this->assertSame( 200, $this->undo( $id )->get_status() );

		$this->assertSame( 20, $this->unspent() );
		$this->assertSame( 20, (int) Character::find( $this->character_id )->xp_earned );
	}

	public function test_undoing_a_take_back_of_xp_gives_it_to_the_character_again(): void {
		Change_Engine::apply_xp( $this->character_id, -4, 'Correction', $this->st_id );
		$this->assertSame( 16, $this->unspent() );
		$id = (int) Change::for_character( $this->character_id, [ 'change_type' => 'xp_adjust', 'per_page' => 1 ] )[0]->id;

		$this->assertSame( 200, $this->undo( $id )->get_status() );

		$this->assertSame( 20, $this->unspent() );
	}

	public function test_undoing_an_identity_change_puts_the_field_back(): void {
		Character::update_sheet_data( $this->character_id, $this->sheet() + [ 'vampire-identity' => [ 'Clan' => 'Tremere' ] ] );
		$id = $this->approved( [
			'change_type' => 'modify_identity',
			'category'    => 'vampire-identity',
			'change_data' => [ 'block_slug' => 'vampire-identity', 'fields' => [ 'Clan' => 'Ventrue' ] ],
		] );
		$this->assertSame( 'Ventrue', $this->sheet()['vampire-identity']['Clan'] );

		$this->assertSame( 200, $this->undo( $id )->get_status() );

		$this->assertSame( 'Tremere', $this->sheet()['vampire-identity']['Clan'] );
	}

	// --- What stops an undo ----------------------------------------------------------------------------------

	public function test_a_later_change_to_the_same_row_stops_it_and_undoing_that_one_first_lets_it_through(): void {
		$first  = $this->approved( $this->occult( 4, 3 ) );
		$second = $this->approved( $this->occult( 5, 4 ) );

		$blocked = $this->undo( $first );

		$this->assertSame( 409, $blocked->get_status() );
		$this->assertSame( 'changed_since', $blocked->as_error()->get_error_code() );
		$this->assertStringContainsString( 'Occult is x5 now.', $blocked->as_error()->get_error_message() );
		$this->assertStringContainsString( 'Undo the later change first', $blocked->as_error()->get_error_message() );
		$this->assertSame( 'approved', Change::find( $first )->status );

		$this->assertSame( 200, $this->undo( $second )->get_status() );
		$this->assertSame( 200, $this->undo( $first )->get_status() );
		$this->assertSame( 3, $this->sheet()['vampire-abilities'][0]['count'] );
	}

	public function test_a_change_to_another_row_of_the_same_list_does_not_stop_it(): void {
		$first = $this->approved( $this->occult( 4, 3 ) );
		$this->approved( [
			'change_type' => 'modify_trait',
			'category'    => 'vampire-abilities',
			'change_data' => [
				'block_slug' => 'vampire-abilities',
				'trait'      => [ 'name' => 'Brawl', 'count' => 3 ],
				'previous'   => [ 'name' => 'Brawl', 'count' => 2 ],
			],
		] );

		$this->assertSame( 200, $this->undo( $first )->get_status() );

		$rows = array_column( $this->sheet()['vampire-abilities'], 'count', 'name' );
		$this->assertSame( [ 'Occult' => 3, 'Brawl' => 3 ], $rows );
	}

	public function test_an_xp_award_whose_xp_was_spent_cannot_be_undone(): void {
		Change_Engine::apply_xp( $this->character_id, 5, 'Game night', $this->st_id );
		$id = (int) Change::for_character( $this->character_id, [ 'change_type' => 'xp_earn', 'per_page' => 1 ] )[0]->id;
		Character::update_xp( $this->character_id, 0, -22 );
		$this->assertSame( 3, $this->unspent() );

		$response = $this->undo( $id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'xp_spent', $response->as_error()->get_error_code() );
		$this->assertSame( 'That XP has been spent. Undo what it bought first.', $response->as_error()->get_error_message() );
		$this->assertSame( 3, $this->unspent() );
	}

	public function test_a_player_may_not_undo_a_change(): void {
		$id = $this->approved( $this->occult( 4, 3 ) );

		$response = $this->undo( $id, $this->player_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 4, $this->sheet()['vampire-abilities'][0]['count'] );
	}

	public function test_a_change_approved_before_records_were_kept_cannot_be_undone(): void {
		$id = (int) Change::create( [
			'character_id' => $this->character_id, 'change_type' => 'modify_trait', 'category' => 'vampire-abilities',
			'change_data'  => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 4 ] ],
			'xp_cost'      => 1, 'status' => 'approved', 'submitted_by' => $this->player_id,
		] );

		$response = $this->undo( $id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'not_undoable', $response->as_error()->get_error_code() );
		$row = array_values( array_filter( $this->history(), static fn( $c ) => (int) $c->id === $id ) )[0];
		$this->assertFalse( $row->can_undo );
	}

	public function test_a_change_that_is_not_approved_cannot_be_undone(): void {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" );
		foreach ( $this->occult( 4, 3 ) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$id = (int) $this->dispatch( $request )->get_data()->id;

		$this->assertSame( 'not_undoable', $this->undo( $id )->as_error()->get_error_code() );
	}

	public function test_a_sheet_another_chronicle_keeps_current_is_refused(): void {
		$id = $this->approved( $this->occult( 4, 3 ) );
		$character = Character::find( $this->character_id );
		$visit = Transfer::create( [
			'character_uuid' => $character->uuid, 'character_id' => $this->character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => 'elsewhere',
			'home_site' => home_url(), 'home_chronicle' => 'Elsewhere',
			'host_slug' => $this->slug, 'host_site' => home_url(), 'host_chronicle' => 'Undo',
			'payload_hash' => str_repeat( 'c', 64 ), 'initiated_by' => 1, 'keep_current' => true,
		] );
		Transfer::transition( $visit, 'visiting', [ 'keep_current_accepted' => 1 ] );

		$response = $this->undo( $id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'kept_current', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( 'Change the kept-current copy there too', $response->as_error()->get_error_message() );
	}

	// --- What an undo leaves behind --------------------------------------------------------------------------

	public function test_an_undo_takes_a_snapshot_first_and_records_who_when_and_why(): void {
		$id    = $this->approved( $this->occult( 4, 3 ) );
		$count = count( Snapshot::for_character( $this->character_id ) );

		$this->undo( $id, 0, 'Entered by mistake.' );

		$this->assertSame( $count + 1, count( Snapshot::for_character( $this->character_id ) ) );
		$row = array_values( array_filter( $this->history(), static fn( $c ) => (int) $c->id === $id ) )[0];
		$this->assertSame( 'undone', $row->status );
		$this->assertSame( $this->st_id, (int) $row->undone_by );
		$this->assertSame( 'Entered by mistake.', $row->undone_note );
		$this->assertNotEmpty( $row->undone_at );
		$this->assertNotEmpty( $row->undone_by_name );
	}

	public function test_the_record_of_what_a_change_did_is_never_returned(): void {
		$this->approved( $this->occult( 4, 3 ) );

		foreach ( $this->history() as $row ) {
			$this->assertObjectNotHasProperty( 'undo_state', $row );
		}
		$row = $this->history()[0];
		$this->assertTrue( $row->can_undo );
	}

	public function test_an_undone_change_cannot_be_undone_again(): void {
		$id = $this->approved( $this->occult( 4, 3 ) );
		$this->undo( $id );

		$this->assertSame( 'not_undoable', $this->undo( $id )->as_error()->get_error_code() );
	}

	public function test_an_undone_change_reads_as_undone_in_a_players_changes_across_chronicles(): void {
		$id = $this->approved( $this->occult( 4, 3 ) );
		$this->undo( $id );

		wp_set_current_user( $this->player_id );
		$rows = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/changes' ) )->get_data();

		$mine = array_values( array_filter( $rows, static fn( $r ) => (int) $r->id === $id ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( 'undone', $mine[0]->display_status );
	}

	public function test_the_player_is_told_in_the_outcome_email(): void {
		$id = $this->approved( $this->occult( 4, 3 ) );
		reset_phpmailer_instance();

		$this->undo( $id );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = array_filter( $mailer->mock_sent, static fn( $mail ): bool => stripos( (string) $mail['body'], 'UNDONE' ) !== false );
		$this->assertNotEmpty( $sent );
	}

	// --- A whole set -----------------------------------------------------------------------------------------

	/** @return array{0:string,1:array<int,object>} */
	private function approved_set(): array {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'changes' => [
			$this->occult( 4, 3 ),
			[
				'change_type' => 'add_trait',
				'category'    => 'vampire-merits',
				'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Daredevil' ] ],
			],
		] ] ) );
		$data = $this->dispatch( $request )->get_data();
		wp_set_current_user( $this->st_id );
		$approve = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/changes/batch-approve" );
		$approve->set_param( 'change_ids', array_map( static fn( $c ) => (int) $c->id, $data['changes'] ) );
		$this->dispatch( $approve );
		return [ $data['submission_id'], $data['changes'] ];
	}

	public function test_a_set_is_undone_whole(): void {
		$before = $this->sheet();
		[ $submission_id, $changes ] = $this->approved_set();
		$this->assertLessThan( 20, $this->unspent() );

		wp_set_current_user( $this->st_id );
		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/submissions/{$submission_id}/undo" ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 2, $response->get_data()['undone'] );
		$this->assertSame( $before, $this->sheet() );
		$this->assertSame( 20, $this->unspent() );
		foreach ( $changes as $change ) {
			$this->assertSame( 'undone', Change::find( (int) $change->id )->status );
		}
	}

	public function test_a_set_is_undone_all_or_not_at_all(): void {
		[ $submission_id, $changes ] = $this->approved_set();
		$this->approved( $this->occult( 5, 4 ) );

		wp_set_current_user( $this->st_id );
		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/submissions/{$submission_id}/undo" ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'changed_since', $response->as_error()->get_error_code() );
		foreach ( $changes as $change ) {
			$this->assertSame( 'approved', Change::find( (int) $change->id )->status, 'nothing in a refused set is undone' );
		}
		$this->assertSame( 5, $this->sheet()['vampire-abilities'][0]['count'] );
	}

	public function test_a_set_with_nothing_undoable_is_refused(): void {
		wp_set_current_user( $this->st_id );
		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/submissions/" . wp_generate_uuid4() . '/undo' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'not_undoable', $response->as_error()->get_error_code() );
	}
}
