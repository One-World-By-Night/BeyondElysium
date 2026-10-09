<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Attachment_Storage;
use BeyondElysium\Services\Demo_Chronicle;
use WP_UnitTestCase;

/**
 * Resetting a demo chronicle rebuilds it, and its companion, from the declared content.
 */
class DemoChronicleThreadTest extends WP_UnitTestCase {

	private int $storyteller_id;
	private int $player_id;
	private object $game;

	public function setUp(): void {
		parent::setUp();

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player_id      = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$slug = 'thread-demo-chronicle-' . wp_generate_password( 8, false );
		Game::create( [
			'name' => 'Thread Demo Chronicle', 'slug' => $slug,
			'settings' => [ 'demo' => [
				'on' => true, 'reset_hours' => 6,
				'accounts' => [ 'storyteller' => $this->storyteller_id, 'player' => $this->player_id ],
			] ],
		] );
		$this->game = Game::find_by_slug( $slug );
	}

	public function tearDown(): void {
		$companion = Game::find_by_slug( $this->game->slug . '-companion' );
		Game::delete_with_content( $this->game->slug );
		if ( $companion ) {
			Game::delete_with_content( $companion->slug );
		}
		Demo_Chronicle::unschedule( (int) $this->game->id );
		parent::tearDown();
	}

	public function test_no_character_the_demo_player_owns_carries_a_custom_sheet_style(): void {
		$this->assertTrue( Demo_Chronicle::reset( $this->game ) );

		$owned = Character::all_for_game( $this->game->slug, [ 'wp_user_id' => $this->player_id ] );
		$this->assertNotEmpty( $owned, 'the demo player owns at least one character' );
		foreach ( $owned as $character ) {
			$this->assertNull(
				\BeyondElysium\Models\Sheet_Style::for_character( (int) $character->id ),
				"{$character->name} is the player's own and opens with the default sheet"
			);
		}
	}

	public function test_the_seeded_sheet_styles_sit_on_characters_a_storyteller_opens(): void {
		$this->assertTrue( Demo_Chronicle::reset( $this->game ) );

		foreach ( [ 'Radu Bathory', 'Konstantin Drake' ] as $name ) {
			$character = Character::find_by_name_in_game( $name, $this->game->slug );
			$this->assertNotNull( $character, $name );
			$this->assertNotNull( \BeyondElysium\Models\Sheet_Style::for_character( (int) $character->id ), "{$name} carries a seeded style" );
		}
	}

	public function test_every_seeded_sheet_style_uses_a_font_the_editor_can_save(): void {
		$declaration = require BE_PLUGIN_PATH . '/includes/Database/demo-chronicle.php';

		foreach ( $declaration['sheet_styles'] as $style ) {
			$this->assertContains(
				$style['font_family'],
				\BeyondElysium\REST\Sheet_Style_Controller::ALLOWED_FONTS,
				"{$style['character']}'s seeded font is one the style editor offers, so a colour change on it saves"
			);
		}
	}

	public function test_radus_public_profile_is_shown_and_lists_no_faction_to_a_player(): void {
		$this->assertTrue( Demo_Chronicle::reset( $this->game ) );

		$radu = Character::find_by_name_in_game( 'Radu Bathory', $this->game->slug );
		$this->assertNotNull( $radu );
		$this->assertSame( 'everyone', $radu->profile_audience );
		$this->assertNotSame( '', trim( (string) $radu->public_description ) );

		$this->assertNotSame( [], \BeyondElysium\Models\Faction_Member::for_character( (int) $radu->id ), 'he still holds the membership' );
		wp_set_current_user( (int) Demo_Chronicle::account_id( $this->game, 'player' ) );
		$request = new \WP_REST_Request( 'GET', "/be/v1/{$this->game->slug}/npcs/{$radu->id}" );
		$request->set_url_params( [ 'game_slug' => $this->game->slug, 'id' => (string) $radu->id ] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['factions'], 'the hidden membership stays off his profile' );
	}

	public function test_reset_seeds_the_mail_log_with_emails_the_demo_would_have_sent(): void {
		$this->assertTrue( Demo_Chronicle::reset( $this->game ) );

		$rows = \BeyondElysium\Models\Mail_Log::for_game( (int) $this->game->id, [], 100, 0 );

		$this->assertCount( 5, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'skipped', $row->result );
			$this->assertSame( 'demo', $row->reason );
			$this->assertNotSame( '', $row->recipient_email );
		}
		$plot_rows = array_values( array_filter( $rows, static fn( $row ) => $row->entity_type === 'plot' ) );
		$this->assertNotEmpty( $plot_rows );
		$this->assertGreaterThan( 0, $plot_rows[0]->entity_id );

		$this->assertTrue( Demo_Chronicle::reset( $this->game ) );
		$this->assertCount( 5, \BeyondElysium\Models\Mail_Log::for_game( (int) $this->game->id, [], 100, 0 ), 'a second reset clears the first one\'s rows' );
	}

	public function test_reset_builds_the_declared_content(): void {
		$this->assertTrue( Demo_Chronicle::reset( $this->game ) );

		$this->assertSame( 25, Character::count_for_game( $this->game->slug ), '23 fixtures plus Radu and the Dockside Hunger, both NPCs' );

		$companion = Game::find_by_slug( $this->game->slug . '-companion' );
		$this->assertNotNull( $companion, 'the companion chronicle must exist after a reset' );
		$this->assertSame( 2, Character::count_for_game( $companion->slug ) );
		$this->assertTrue( Demo_Chronicle::is_demo( $companion ), 'the companion is itself flagged as a demo' );

		$locket = World_Object::find_by_name_in_game( (int) $this->game->id, 'item', 'A Tarnished Silver Locket' );
		$this->assertNotNull( $locket );
		$this->assertSame( 2, (int) $locket->properties['uses_left'] );

		global $wpdb;
		$plots = $wpdb->get_results( $wpdb->prepare(
			"SELECT assigned_to FROM {$wpdb->prefix}be_plots WHERE game_id = %d AND assigned_to IS NOT NULL",
			$this->game->id
		) );
		$this->assertCount( 2, $plots, 'the two downtime actions, each assigned to a different staff member' );
		$assigned = array_map( static fn( $row ) => (int) $row->assigned_to, $plots );
		$this->assertContains( $this->storyteller_id, $assigned );
		$this->assertContains( $this->player_id, $assigned );
	}

	public function test_reset_seeds_a_various_npc_holding_sections_from_other_creature_types(): void {
		$this->assertTrue( Demo_Chronicle::reset( $this->game ) );

		$npc = Character::find_by_name_in_game( 'The Dockside Hunger', $this->game->slug );
		$this->assertNotNull( $npc );
		$this->assertSame( 'various', $npc->stack_slug );
		$this->assertTrue( (bool) $npc->is_npc );
		foreach ( [ 'various-powers', 'vampire-disciplines', 'werewolf-gifts' ] as $block ) {
			$this->assertNotEmpty( $npc->sheet_data[ $block ] ?? [], $block );
		}

		$documents = \BeyondElysium\Services\Sheet_Document::for_characters( [ (int) $npc->id ], $this->game->slug, [ 'can_manage' => true ] );
		$sections  = array_column( $documents[0]['sections'], 'block_slug' );
		$this->assertContains( 'vampire-disciplines', $sections, 'a Various sheet prints the sections it holds from other creature types' );
		$this->assertContains( 'werewolf-gifts', $sections );
	}

	public function test_reset_seeds_character_connections_and_a_session_recap(): void {
		Demo_Chronicle::reset( $this->game );

		$characters = Character::all_for_game( $this->game->slug );
		$by_name    = [];
		foreach ( $characters as $character ) {
			$by_name[ $character->name ] = $character;
		}

		$connections = Connection::for_entity( 'character', (int) $by_name['Isolde Marchetti']->id );
		$this->assertNotEmpty( $connections, 'Isolde is declared with at least one connection' );

		$to_konstantin = null;
		foreach ( $connections as $connection ) {
			if ( (int) $connection->target_id === (int) $by_name['Konstantin Drake']->id
				|| (int) $connection->source_id === (int) $by_name['Konstantin Drake']->id ) {
				$to_konstantin = $connection;
			}
		}
		$this->assertNotNull( $to_konstantin, 'Isolde and Konstantin are declared as connected' );
		$this->assertSame( 'Primogen Council', $to_konstantin->label );

		$radu_connections = Connection::for_entity( 'character', (int) $by_name['Radu Bathory']->id );
		$this->assertNotEmpty( $radu_connections, 'the visiting NPC is connected to a PC too, not just cast to a session' );

		$session = Game_Session::find_by_date( (int) $this->game->id, gmdate( 'Y-m-d', strtotime( '-3 days' ) ) );
		$this->assertNotNull( $session, 'the past-2 session exists' );
		$this->assertNotNull( $session->recap, 'past-2 is declared with a recap' );
		$this->assertStringContainsString( 'Sheriff', $session->recap['key_events'] );
		$this->assertSame( 'Radu Bathory', $session->recap['npcs_involved'][0]['name'] );
	}

	public function test_reset_corrects_a_drifted_demo_account_password_to_its_declared_value(): void {
		wp_set_password( 'something-else-entirely', $this->storyteller_id );

		$settings           = (array) $this->game->settings;
		$settings['demo']   = array_merge( (array) $settings['demo'], [
			'accounts_password' => [
				'storyteller' => 'Correct-Horse-1!',
				'player'      => 'Battery-Staple-1!',
			],
		] );
		Game::update( $this->game->slug, [ 'settings' => $settings ] );
		$game = Game::find_by_slug( $this->game->slug );

		$this->assertTrue( Demo_Chronicle::reset( $game ) );

		$storyteller = get_userdata( $this->storyteller_id );
		$this->assertTrue(
			wp_check_password( 'Correct-Horse-1!', $storyteller->user_pass, $this->storyteller_id ),
			'a drifted storyteller password is corrected back to the declared value'
		);

		$player = get_userdata( $this->player_id );
		$this->assertTrue(
			wp_check_password( 'Battery-Staple-1!', $player->user_pass, $this->player_id ),
			'the player account is corrected the same way'
		);
	}

	public function test_reset_leaves_an_already_correct_demo_account_password_alone(): void {
		wp_set_password( 'Correct-Horse-1!', $this->storyteller_id );

		$settings         = (array) $this->game->settings;
		$settings['demo'] = array_merge( (array) $settings['demo'], [
			'accounts_password' => [ 'storyteller' => 'Correct-Horse-1!' ],
		] );
		Game::update( $this->game->slug, [ 'settings' => $settings ] );
		$game = Game::find_by_slug( $this->game->slug );

		$this->assertTrue( Demo_Chronicle::reset( $game ) );

		$storyteller = get_userdata( $this->storyteller_id );
		$this->assertTrue(
			wp_check_password( 'Correct-Horse-1!', $storyteller->user_pass, $this->storyteller_id )
		);
	}

	public function test_reset_sets_the_declared_whos_who_profiles(): void {
		Demo_Chronicle::reset( $this->game );

		$characters = Character::all_for_game( $this->game->slug );
		$by_name    = [];
		foreach ( $characters as $character ) {
			$by_name[ $character->name ] = $character;
		}

		$this->assertSame( 'everyone', $by_name['Isolde Marchetti']->profile_audience );
		$this->assertTrue( $by_name['Isolde Marchetti']->profile_show_player );

		$this->assertSame( 'everyone', $by_name['Ezra Stormcrow']->profile_audience );
		$this->assertFalse( $by_name['Ezra Stormcrow']->profile_show_player );

		$this->assertSame( 'everyone', $by_name['Dr. Adrian Voss']->profile_audience );
		$this->assertFalse( $by_name['Dr. Adrian Voss']->profile_show_player );

		$this->assertSame( 'storytellers', $by_name['Detective Rosa Alvarez']->profile_audience, 'left on the default, so hidden from Who\'s Who' );
	}

	public function test_reset_seeds_secret_passing_and_a_tellable_secret(): void {
		Demo_Chronicle::reset( $this->game );

		$this->assertSame( 'approval', Game::find_by_slug( $this->game->slug )->settings->secret_passing );

		$isolde = null;
		foreach ( Character::all_for_game( $this->game->slug ) as $character ) {
			if ( $character->name === 'Isolde Marchetti' ) {
				$isolde = $character;
			}
		}
		$this->assertNotNull( $isolde );

		$reveals = \BeyondElysium\Models\Secret_Reveal::for_character( (int) $isolde->id );
		$told    = array_filter( $reveals, static fn( $r ) => \BeyondElysium\Models\Secret::find( (int) $r->secret_id )->title === 'Why the wards never fail' );
		$reveal  = array_values( $told )[0] ?? null;

		$this->assertNotNull( $reveal );
		$this->assertTrue( $reveal->approved );
		$secret = \BeyondElysium\Models\Secret::find( (int) $reveal->secret_id );
		$this->assertSame( 'restricted', $secret->audience );
	}

	public function test_reset_forks_disciplines_with_player_order_on(): void {
		Demo_Chronicle::reset( $this->game );

		$fork = \BeyondElysium\Models\Schema_Block::find_for_game( 'vampire-disciplines', $this->game->slug );
		$this->assertSame( $this->game->slug, $fork->game_slug );
		$this->assertTrue( (bool) $fork->definition->player_order );

		// The book itself is untouched - this is the chronicle's own fork, not a catalog change.
		$this->assertEmpty( \BeyondElysium\Models\Schema_Block::find_by_slug( 'vampire-disciplines' )->definition->player_order ?? null );
	}

	public function test_a_second_reset_leaves_the_flag_on(): void {
		// Every reset clears the chronicle's own content, forks included, and rebuilds it; the flag survives that
		// cycle.
		Demo_Chronicle::reset( $this->game );
		Demo_Chronicle::reset( $this->game );

		$fork = \BeyondElysium\Models\Schema_Block::find_for_game( 'vampire-disciplines', $this->game->slug );
		$this->assertSame( $this->game->slug, $fork->game_slug );
		$this->assertTrue( (bool) $fork->definition->player_order );
	}

	public function test_the_storyteller_account_holds_the_hst_role_on_both_chronicles(): void {
		Demo_Chronicle::reset( $this->game );

		$this->assertSame( 'hst', (Game_Member::find( (int) $this->game->id, $this->storyteller_id  )?->role) );

		$companion = Game::find_by_slug( $this->game->slug . '-companion' );
		$this->assertSame( 'hst', (Game_Member::find( (int) $companion->id, $this->storyteller_id  )?->role) );
	}

	public function test_the_player_account_is_not_a_member_of_the_companion_chronicle(): void {
		Demo_Chronicle::reset( $this->game );

		$companion = Game::find_by_slug( $this->game->slug . '-companion' );
		$this->assertNull( (Game_Member::find( (int) $companion->id, $this->player_id  )?->role) );
	}

	public function test_the_companion_chronicle_takes_join_requests_and_the_player_can_find_it(): void {
		Demo_Chronicle::reset( $this->game );

		$companion = Game::find_by_slug( $this->game->slug . '-companion' );
		$this->assertSame( 'Elsewhere', $companion->name );
		$this->assertTrue( \BeyondElysium\Models\Join_Request::open_on( $companion ) );

		wp_set_current_user( $this->player_id );
		$request = new \WP_REST_Request( 'GET', '/be/v1/joinable' );
		$slugs   = array_column( rest_get_server()->dispatch( $request )->get_data(), 'slug' );
		$this->assertContains( $companion->slug, $slugs );
	}

	public function test_a_second_reset_rebuilds_cleanly_without_doubling_content(): void {
		Demo_Chronicle::reset( $this->game );
		Demo_Chronicle::reset( $this->game );

		$this->assertSame( 25, Character::count_for_game( $this->game->slug ) );

		$game = Game::find_by_slug( $this->game->slug );
		$this->assertSame( (int) $this->game->id, (int) $game->id, 'the game row, and its id, must survive a reset' );
	}

	public function test_a_member_added_between_resets_is_gone_after_the_next_one(): void {
		Demo_Chronicle::reset( $this->game );

		$visitor = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $this->game->id, $visitor, 'player' );
		$this->assertSame( 'player', (Game_Member::find( (int) $this->game->id, $visitor  )?->role) );

		Demo_Chronicle::reset( $this->game );

		$this->assertNull( (Game_Member::find( (int) $this->game->id, $visitor  )?->role) );
	}

	public function test_a_held_change_submitted_between_resets_is_gone_after_the_next_one(): void {
		Demo_Chronicle::reset( $this->game );

		$character = Character::find_by_name_in_game( 'Isolde Marchetti', $this->game->slug );
		\BeyondElysium\Services\Change_Engine::submit( (int) $character->id, [
			'change_type' => 'modify_trait', 'category' => 'test',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Academics', 'count' => 9 ] ],
		], $this->player_id );

		Demo_Chronicle::reset( $this->game );

		$rebuilt = Character::find_by_name_in_game( 'Isolde Marchetti', $this->game->slug );
		$this->assertNotSame( (int) $character->id, (int) $rebuilt->id, 'a reset is a fresh row, not an edit of the old one' );
	}

	public function test_an_attachment_uploaded_before_a_reset_is_gone_after_it_file_included(): void {
		Demo_Chronicle::reset( $this->game );

		$item_id = World_Object::create( [
			'game_id' => (int) $this->game->id, 'object_type' => 'item', 'name' => 'A visitor-added item',
		] );
		$path  = tempnam( sys_get_temp_dir(), 'be-demo-chronicle-test-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$image = imagecreate( 2, 2 );
		imagecolorallocate( $image, 10, 10, 10 );
		imagepng( $image, $path );
		$stored = Attachment_Storage::store( [
			'tmp_name' => $path, 'name' => 'visitor.png', 'error' => 0, 'size' => filesize( $path ), 'type' => 'image/png',
		] );
		$attachment_id = Attachment::create( [
			'game_id' => (int) $this->game->id, 'entity_type' => 'item', 'entity_id' => $item_id,
			'original_name' => $stored['original_name'], 'stored_name' => $stored['stored_name'],
			'mime' => $stored['mime'], 'bytes' => $stored['bytes'],
		] );
		$file_path = Attachment_Storage::path_for( $stored['stored_name'], $stored['original_name'] );
		$this->assertFileExists( $file_path, 'fixture sanity check' );

		Demo_Chronicle::reset( $this->game );

		$this->assertNull( Attachment::find( (int) $attachment_id ), 'the row must be gone' );
		$this->assertFileDoesNotExist( $file_path, 'the file must be gone too' );
	}

	public function test_reset_refuses_without_a_storyteller_account(): void {
		Game::update( $this->game->slug, [ 'settings' => [ 'demo' => [ 'on' => true, 'accounts' => [] ] ] ] );
		$game = Game::find_by_slug( $this->game->slug );

		$this->assertFalse( Demo_Chronicle::reset( $game ) );
	}

	public function test_schedule_and_unschedule(): void {
		Demo_Chronicle::schedule( $this->game );
		$this->assertNotFalse( wp_next_scheduled( Demo_Chronicle::RESET_HOOK, [ (int) $this->game->id ] ) );

		Demo_Chronicle::unschedule( (int) $this->game->id );
		$this->assertFalse( wp_next_scheduled( Demo_Chronicle::RESET_HOOK, [ (int) $this->game->id ] ) );
	}

	public function test_handle_cron_does_nothing_once_the_flag_is_turned_off(): void {
		Game::update( $this->game->slug, [ 'settings' => [ 'demo' => [ 'on' => false ] ] ] );

		Demo_Chronicle::handle_cron( (int) $this->game->id );

		$this->assertSame( 0, Character::count_for_game( $this->game->slug ) );
		$this->assertFalse( wp_next_scheduled( Demo_Chronicle::RESET_HOOK, [ (int) $this->game->id ] ), 'a flag turned off must not reschedule itself' );
	}
}
