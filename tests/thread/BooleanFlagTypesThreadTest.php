<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Position;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Boolean-shaped tinyint(1) columns must reach REST clients as real integers.
 */
class BooleanFlagTypesThreadTest extends WP_UnitTestCase {

	private int $admin_id;
	private string $game_slug = 'thread-test-flag-types';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Flag Types Game', 'notifications_enabled' => 0,
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );

		Schema_Block::create( [
			'slug' => 'thread-flag-plain-block', 'name' => 'Plain Block', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [] ], 'is_system' => 0, 'storyteller_only' => 0,
		] );
	}

	private function get( string $path, array $query = [] ) {
		$request = new WP_REST_Request( 'GET', $path );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	public function test_schema_block_flags_are_booleans_over_rest(): void {
		$block = (array) $this->get( '/be/v1/schema-blocks/thread-flag-plain-block' )->get_data();

		$this->assertSame( false, $block["storyteller_only"], "storyteller_only must be boolean false - a JSON boolean cannot be a truthy \"0\"." );
		$this->assertSame( false, $block["is_system"], "is_system must be boolean false, not the truthy string \"0\"." );
	}

	public function test_schema_block_flags_are_booleans_in_list_responses(): void {
		$rows = $this->get( '/be/v1/schema-blocks', [ 'per_page' => 100, 'search' => 'Plain Block' ] )->get_data();
		$row  = null;
		foreach ( $rows as $candidate ) {
			$candidate = (array) $candidate;
			if ( $candidate['slug'] === 'thread-flag-plain-block' ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( false, $row["storyteller_only"] );
		$this->assertSame( false, $row["is_system"] );
	}

	public function test_a_real_storyteller_only_block_still_reads_as_one(): void {
		Schema_Block::update( 'thread-flag-plain-block', [ 'storyteller_only' => 1 ] );
		$block = Schema_Block::find_by_slug( 'thread-flag-plain-block' );

		$this->assertSame( true, $block->storyteller_only );
	}

	public function test_creature_stack_is_system_is_a_boolean(): void {
		$stacks = $this->get( '/be/v1/creature-stacks', [ 'per_page' => 1 ] )->get_data();
		$this->assertNotEmpty( $stacks, 'The test install seeds creature stacks.' );

		$this->assertIsBool( ( (array) $stacks[0] )["is_system"] );
		$this->assertIsBool( Creature_Stack::find_by_slug( ( (array) $stacks[0] )["slug"] )->is_system );
	}

	public function test_game_notifications_enabled_is_a_boolean(): void {
		$game = (array) $this->get( '/be/v1/games/' . $this->game_slug )->get_data();

		$this->assertSame( false, $game["notifications_enabled"], "notifications_enabled must be boolean false so a disabled chronicle does not render as enabled." );
		$this->assertSame( false, Game::find_by_slug( $this->game_slug )->notifications_enabled );
	}

	// -------------------------------------------------------------------------
	// Factions, faction_members and positions carry tinyint(1) columns
	// -------------------------------------------------------------------------

	public function test_faction_created_via_proposal_is_a_boolean(): void {
		$game_id    = (int) Game::create( [ 'slug' => 'thread-flag-faction', 'name' => 'Flag Faction Game' ] );
		$faction_id = (int) Faction::create( [
			'game_id' => $game_id, 'name' => 'A Faction', 'faction_type' => 'other',
			'created_via_proposal' => true, 'created_by' => $this->admin_id,
		] );

		$this->assertSame( true, Faction::find( $faction_id )->created_via_proposal );
	}

	public function test_faction_member_is_leader_is_a_boolean(): void {
		$game_id    = (int) Game::create( [ 'slug' => 'thread-flag-faction-member', 'name' => 'Flag Member Game' ] );
		$faction_id = (int) Faction::create( [ 'game_id' => $game_id, 'name' => 'A Faction', 'faction_type' => 'other', 'created_by' => $this->admin_id ] );
		$player_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $player_id, 'player' );
		$character_id = (int) \BeyondElysium\Models\Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-flag-faction-member', 'wp_user_id' => $player_id, 'created_by' => $this->admin_id,
		] );
		Faction_Member::add( $faction_id, $character_id, $this->admin_id, true );

		$this->assertSame( true, Faction_Member::find_for( $faction_id, $character_id )->is_leader );
	}

	public function test_faction_member_is_public_is_a_boolean(): void {
		$game_id      = (int) Game::create( [ 'slug' => 'thread-flag-faction-public', 'name' => 'Flag Public Game' ] );
		$faction_id   = (int) Faction::create( [ 'game_id' => $game_id, 'name' => 'A Faction', 'faction_type' => 'other', 'created_by' => $this->admin_id ] );
		$character_id = (int) \BeyondElysium\Models\Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-flag-faction-public', 'created_by' => $this->admin_id,
		] );
		Faction_Member::add( $faction_id, $character_id, $this->admin_id, false, null, false );

		$this->assertSame( false, Faction_Member::find_for( $faction_id, $character_id )->is_public );
	}

	public function test_position_holder_public_is_a_boolean(): void {
		$game_id     = (int) Game::create( [ 'slug' => 'thread-flag-position', 'name' => 'Flag Position Game' ] );
		$position_id = (int) Position::create( [
			'game_id' => $game_id, 'title' => 'Prince', 'holder_public' => false, 'created_by' => $this->admin_id,
		] );

		$this->assertSame( false, Position::find( $position_id )->holder_public );
	}

	public function test_secret_reveal_approved_is_a_boolean(): void {
		$game_id      = (int) Game::create( [ 'slug' => 'thread-flag-secret-reveal', 'name' => 'Flag Secret Reveal Game' ] );
		$character_id = (int) \BeyondElysium\Models\Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-flag-secret-reveal', 'created_by' => $this->admin_id,
		] );
		$plot_id   = (int) \BeyondElysium\Models\Plot::create( [ 'game_id' => $game_id, 'title' => 'A Plot', 'created_by' => $this->admin_id ] );
		$secret_id = (int) \BeyondElysium\Models\Secret::create( [
			'game_id' => $game_id, 'entity_type' => 'plot', 'entity_id' => $plot_id,
			'title' => 'A Secret', 'created_by' => $this->admin_id,
		] );
		$reveal_id = (int) \BeyondElysium\Models\Secret_Reveal::create( [
			'secret_id' => $secret_id, 'character_id' => $character_id, 'approved' => false,
			'revealed_by' => $this->admin_id,
		] );

		$this->assertSame( false, \BeyondElysium\Models\Secret_Reveal::find( $reveal_id )->approved );

		\BeyondElysium\Models\Secret_Reveal::update( $reveal_id, [ 'approved' => true ] );
		$this->assertSame( true, \BeyondElysium\Models\Secret_Reveal::find( $reveal_id )->approved );
	}

	public function test_change_auto_approved_is_a_boolean(): void {
		$game_id      = (int) Game::create( [ 'slug' => 'thread-flag-change', 'name' => 'Flag Change Game' ] );
		$character_id = (int) \BeyondElysium\Models\Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-flag-change', 'created_by' => $this->admin_id,
		] );
		$change_id = \BeyondElysium\Models\Change::create( [
			'character_id' => $character_id, 'change_type' => 'xp_earn', 'change_data' => [ 'amount' => 1 ],
			'status' => 'pending', 'submitted_by' => $this->admin_id, 'auto_approved' => false,
		] );

		$this->assertSame( false, \BeyondElysium\Models\Change::find( $change_id )->auto_approved );

		\BeyondElysium\Database\Manager::update( 'character_changes', [ 'auto_approved' => 1 ], [ 'id' => $change_id ] );
		$this->assertSame( true, \BeyondElysium\Models\Change::find( $change_id )->auto_approved );
	}

	public function test_character_profile_show_player_is_a_boolean(): void {
		$character_id = (int) \BeyondElysium\Models\Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'created_by' => $this->admin_id,
		] );

		$character = \BeyondElysium\Models\Character::find( $character_id );
		$this->assertSame( false, $character->profile_show_player );

		\BeyondElysium\Models\Character::update_header( $character_id, [ 'profile_show_player' => 1 ] );
		$this->assertSame( true, \BeyondElysium\Models\Character::find( $character_id )->profile_show_player );
	}

	public function test_transfer_keep_current_flags_are_booleans(): void {
		$game_id      = (int) Game::create( [ 'slug' => 'thread-flag-transfer', 'name' => 'Flag Transfer Game' ] );
		$character_id = (int) \BeyondElysium\Models\Character::create( [
			'name' => 'A Character', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'thread-flag-transfer', 'created_by' => $this->admin_id,
		] );
		$character   = \BeyondElysium\Models\Character::find( $character_id );
		$transfer_id = \BeyondElysium\Models\Transfer::create( [
			'character_uuid' => $character->uuid, 'character_id' => $character_id,
			'direction' => 'outbound', 'state' => 'offered', 'home_slug' => 'thread-flag-transfer',
			'home_site' => home_url(), 'home_chronicle' => 'Flag Transfer Game',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => $this->admin_id,
		] );

		$this->assertSame( false, \BeyondElysium\Models\Transfer::find( $transfer_id )->keep_current );
		$this->assertSame( false, \BeyondElysium\Models\Transfer::find( $transfer_id )->keep_current_accepted );

		$rows = (array) $this->get( '/be/v1/thread-flag-transfer/transfers' )->get_data();
		$this->assertSame( false, ( (array) $rows[0] )['keep_current'], 'keep_current must be boolean false over REST, not the truthy string "0".' );

		\BeyondElysium\Models\Transfer::transition( $transfer_id, 'offered', [ 'keep_current' => 1, 'keep_current_accepted' => 1 ] );
		$this->assertSame( true, \BeyondElysium\Models\Transfer::find( $transfer_id )->keep_current );
		$this->assertSame( true, \BeyondElysium\Models\Transfer::find( $transfer_id )->keep_current_accepted );
	}

	public function test_submission_keep_current_is_a_boolean(): void {
		$game_id = (int) Game::create( [ 'slug' => 'thread-flag-submission', 'name' => 'Flag Submission Game' ] );

		$submission_id = \BeyondElysium\Models\Submission::create( [
			'game_id' => $game_id, 'submitted_by' => $this->admin_id, 'arrival' => 'joining',
			'character_name' => 'A Character', 'stack_slug' => 'vampire', 'source_file' => 'a.gex',
			'format' => 'xml', 'file_hash' => str_repeat( 'a', 64 ), 'parsed' => '{}',
			'keep_current' => true,
		] );

		$this->assertSame( true, \BeyondElysium\Models\Submission::find( $submission_id )->keep_current );
		$this->assertSame( true, \BeyondElysium\Models\Submission::find_with_file( $submission_id )->keep_current );

		$rows = \BeyondElysium\Models\Submission::waiting_for_game( $game_id );
		$this->assertSame( true, $rows[0]->keep_current, 'keep_current must be boolean true, not the truthy string "1".' );
	}
}
