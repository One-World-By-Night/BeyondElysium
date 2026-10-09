<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Ai_Assist;
use BeyondElysium\Services\Rumor_Generator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Storyteller drafting tools: the three structured AI draft routes (NPC roleplaying notes, a plot from a
 * premise, a game-night recap), the NPC Hooks route, a character-to-character relationship, and the recap
 * column on a game session.
 */
class StorytellerDraftingToolsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-drafting-tools';
	private int $game_id;
	private int $hst_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		delete_option( Ai_Assist::SITE_OPENAI_KEY_OPTION );
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-test' ) );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Drafting Tools Test Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->hst_id    = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->hst_id, 'hst' );
		Game::update( $this->game_slug, [ 'settings' => [ 'ai_assist_enabled' => true ] ] );
	}

	public function tearDown(): void {
		delete_option( Ai_Assist::SITE_OPENAI_KEY_OPTION );
		parent::tearDown();
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function npc( string $name = 'Test NPC' ): object {
		$id = Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'is_npc' => 1,
		] );
		return Character::find( $id );
	}

	private function pc( string $name = 'Test PC' ): object {
		$id = Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'is_npc' => 0,
		] );
		return Character::find( $id );
	}

	private function mock_openai_json( array $data ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $data ) {
			if ( strpos( $url, 'api.openai.com' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => wp_json_encode( $data ) ] ] ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );
	}

	private function mock_openai_capturing_body( array $data, array &$captured ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $data, &$captured ) {
			if ( strpos( $url, 'api.openai.com' ) === false ) {
				return $preempt;
			}
			$captured = json_decode( (string) $args['body'], true );
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => wp_json_encode( $data ) ] ] ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );
	}

	private function npc_draft_reply(): array {
		return [ 'Wants' => 'To be left alone.', 'Knows' => '', 'Will Do If Unopposed' => '', 'Voice & Tone' => '', 'Emotional Range' => '', 'Posture & Movement' => '', 'Public Behavior' => '', 'Private Behavior' => '', 'Combat Style' => '', 'Philosophy & Beliefs' => '', 'Theme Statement' => '', 'Motivations' => '', 'Appearance' => '' ];
	}

	// --- npc-draft ---

	public function test_npc_draft_sends_only_this_npcs_own_data(): void {
		$target = $this->npc( 'Isolde' );
		$other  = $this->pc( 'Someone Else' );
		Character::update_header( $other->id, [ 'biography' => 'ANOTHER-CHARACTERS-SECRET-TEXT' ] );

		$captured = [];
		$this->mock_openai_capturing_body( $this->npc_draft_reply(), $captured );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/npc-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'character_id' => $target->id ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$sent = wp_json_encode( $captured );
		$this->assertStringContainsString( 'Isolde', $sent );
		$this->assertStringNotContainsString( 'ANOTHER-CHARACTERS-SECRET-TEXT', $sent );
	}

	public function test_npc_draft_returns_the_drafted_fields(): void {
		$target = $this->npc();
		$this->mock_openai_json( $this->npc_draft_reply() );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/npc-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'character_id' => $target->id ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'To be left alone.', $response->get_data()['data']['Wants'] );
	}

	public function test_npc_draft_is_refused_for_a_player(): void {
		$target = $this->npc();
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/npc-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'character_id' => $target->id ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_npc_draft_is_refused_on_a_demo_chronicle(): void {
		$target = $this->npc();
		Game::update( $this->game_slug, [ 'settings' => [ 'demo' => [ 'on' => true ] ] ] );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/npc-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'character_id' => $target->id ] );
		$response = $this->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'demo_locked', $response->as_error()->get_error_code() );
	}

	// --- plot-draft ---

	private function plot_draft_reply(): array {
		return [
			'title' => 'The Shattered Compact', 'description' => 'A rival faction tests an old peace.',
			'st_notes' => 'Build toward a confrontation in three sessions.', 'cliffhanger' => 'Who struck first?',
			'notes' => [ 'An envoy arrives.', 'A relic goes missing.', 'Blame spreads fast.' ],
			'rumors' => [ 'They say the envoy was poisoned.', 'Someone saw the relic leave the city.' ],
		];
	}

	public function test_plot_draft_creates_a_storytellers_only_plot_with_entries_and_held_rumors(): void {
		$this->mock_openai_json( $this->plot_draft_reply() );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/plot-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'premise' => 'A rival faction tests an old peace.' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$plot = Plot::find( (int) $response->get_data()['plot_id'] );
		$this->assertSame( 'storytellers', $plot->audience );
		$this->assertSame( 'The Shattered Compact', $plot->title );

		$entries = Plot_Entry::for_plot( $plot->id, [ 'entry_type' => 'note' ] );
		$this->assertCount( 3, $entries );
		foreach ( $entries as $entry ) {
			$this->assertSame( 'storytellers', $entry->audience );
		}

		$rumor_count = 0;
		foreach ( $this->plot_draft_reply()['rumors'] as $rumor_text ) {
			global $wpdb;
			$rumor_plot = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'be_plots WHERE game_id = %d AND description = %s',
				$this->game_id,
				$rumor_text
			) );
			$this->assertNotNull( $rumor_plot, "rumor \"{$rumor_text}\" was not created" );
			$this->assertSame( 1, (int) $rumor_plot->held );
			$this->assertTrue( Rumor_Generator::is_rumor( (int) $rumor_plot->id ) );
			++$rumor_count;
		}
		$this->assertSame( 2, $rumor_count );
	}

	public function test_a_drafted_plot_is_cleaned_like_any_other_plot_write(): void {
		$script = '<script>alert(1)</script>';
		$this->mock_openai_json( [
			'title'       => 'The <b>Compact</b>' . $script,
			'description' => '<p>An old peace.</p>' . $script,
			'st_notes'    => 'Three sessions.' . $script,
			'cliffhanger' => '<img src=x onerror=alert(1)>Who struck first?',
			'notes'       => [ 'An envoy arrives.' . $script, 'A relic goes missing.' ],
			'rumors'      => [ 'They say the envoy was poisoned.' . $script ],
		] );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/plot-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'premise' => 'x' ] );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$plot = Plot::find( (int) $response->get_data()['plot_id'] );
		foreach ( [ 'title', 'description', 'st_notes', 'cliffhanger' ] as $field ) {
			$this->assertStringNotContainsString( '<script', (string) $plot->$field, $field );
			$this->assertStringNotContainsString( 'onerror', (string) $plot->$field, $field );
		}
		$this->assertSame( '<p>An old peace.</p>alert(1)', $plot->description );
		$this->assertStringNotContainsString( '<b>', $plot->title );
		foreach ( Plot_Entry::for_plot( $plot->id, [ 'entry_type' => 'note' ] ) as $entry ) {
			$this->assertStringNotContainsString( '<script', (string) $entry->content );
		}
		global $wpdb;
		$rumor = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT description FROM ' . $wpdb->prefix . 'be_plots WHERE game_id = %d AND id <> %d ORDER BY id DESC LIMIT 1',
			$this->game_id,
			$plot->id
		) );
		$this->assertStringStartsWith( 'They say the envoy was poisoned.', $rumor );
		$this->assertStringNotContainsString( '<script', $rumor );
	}

	public function test_an_invalid_plot_draft_reply_creates_nothing(): void {
		$this->mock_openai_json( [ 'title' => 'Missing everything else' ] );

		global $wpdb;
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'be_plots WHERE game_id = %d', $this->game_id ) );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/plot-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'premise' => 'x' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 502, $response->get_status() );
		$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'be_plots WHERE game_id = %d', $this->game_id ) );
		$this->assertSame( $before, $after, 'an invalid reply must create nothing at all' );
	}

	public function test_plot_draft_is_refused_for_a_player(): void {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/plot-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'premise' => 'x' ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_plot_draft_is_refused_on_a_demo_chronicle(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'demo' => [ 'on' => true ] ] ] );
		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/plot-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'premise' => 'x' ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	// --- recap-draft ---

	public function test_recap_draft_reads_that_nights_reports(): void {
		$character = $this->pc( 'Reporter' );
		$session_id = Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => '2026-10-04' ] );
		Attendance::record( $session_id, $this->game_id, [ 'character_id' => $character->id ] );
		After_Game_Report::create( [
			'game_id' => $this->game_id, 'session_id' => $session_id, 'character_id' => $character->id,
			'wp_user_id' => $this->hst_id, 'did' => 'Confronted the envoy.', 'wants' => 'Answers.', 'to_staff' => '',
		] );

		$captured = [];
		$this->mock_openai_capturing_body( [
			'key_events' => 'The envoy was confronted.', 'player_decisions' => 'Reporter pressed for answers.',
			'npcs_involved' => [ [ 'name' => 'The Envoy', 'status' => 'alive' ] ],
			'cliffhanger' => 'The envoy vanished overnight.', 'prep' => 'Decide where the envoy resurfaces.',
		], $captured );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/recap-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'session_id' => $session_id ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Confronted the envoy.', wp_json_encode( $captured ) );
		$this->assertSame( 'The envoy was confronted.', $response->get_data()['data']['key_events'] );
	}

	public function test_recap_draft_is_refused_for_a_player(): void {
		$session_id = Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => '2026-10-05' ] );
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/recap-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'session_id' => $session_id ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_recap_draft_is_refused_on_a_demo_chronicle(): void {
		$session_id = Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => '2026-10-06' ] );
		Game::update( $this->game_slug, [ 'settings' => [ 'demo' => [ 'on' => true ] ] ] );
		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/ai-assist/recap-draft" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'session_id' => $session_id ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	// --- recap persistence through the existing session update route ---

	public function test_a_saved_recap_round_trips_and_is_hidden_from_a_non_manager(): void {
		$session_id = Game_Session::create( [ 'game_id' => $this->game_id, 'game_date' => '2026-10-07' ] );
		$recap = [ 'key_events' => 'E', 'player_decisions' => 'D', 'npcs_involved' => [ [ 'name' => 'N', 'status' => 'injured' ] ], 'cliffhanger' => 'C', 'prep' => 'P' ];

		wp_set_current_user( $this->hst_id );
		$put = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/sessions/{$session_id}" );
		$put->set_url_params( [ 'game_slug' => $this->game_slug, 'id' => $session_id ] );
		$put->set_body_params( [ 'recap' => $recap ] );
		$this->assertSame( 200, $this->dispatch( $put )->get_status() );

		$saved = Game_Session::find( $session_id );
		$this->assertSame( 'E', $saved->recap['key_events'] );
		$this->assertSame( 'injured', $saved->recap['npcs_involved'][0]['status'] );

		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		wp_set_current_user( $this->player_id );
		$get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/sessions/{$session_id}" );
		$get->set_url_params( [ 'game_slug' => $this->game_slug, 'id' => $session_id ] );
		$response = $this->dispatch( $get );
		$this->assertArrayNotHasKey( 'recap', $response->get_data(), 'recap is Storyteller-only and must never reach a non-manager' );
	}

	// --- Hooks ---

	public function test_the_hooks_route_lists_open_and_resolved_plots_correctly(): void {
		$npc = $this->npc();

		$open_plot_id = Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Open Plot', 'status' => 'active' ] );
		Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $open_plot_id, 'target_type' => 'character', 'target_id' => $npc->id ] );
		Plot_Entry::create( [ 'plot_id' => $open_plot_id, 'entry_type' => 'note', 'content' => 'first', 'event_date' => '2026-10-01' ] );
		Plot_Entry::create( [ 'plot_id' => $open_plot_id, 'entry_type' => 'note', 'content' => 'latest', 'event_date' => '2026-10-03' ] );

		$resolved_plot_id = Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Resolved Plot', 'status' => 'resolved' ] );
		Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $resolved_plot_id, 'target_type' => 'character', 'target_id' => $npc->id ] );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/characters/{$npc->id}/hooks" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug, 'id' => $npc->id ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['open'] );
		$this->assertSame( 'Open Plot', $data['open'][0]['title'] );
		$this->assertSame( '2026-10-03', $data['open'][0]['latest_entry_date'] );
		$this->assertCount( 1, $data['resolved'] );
		$this->assertSame( 'Resolved Plot', $data['resolved'][0]['title'] );
	}

	// --- Relationships ---

	public function test_a_character_to_character_relationship_saves_and_lists_on_both_characters(): void {
		$a = $this->pc( 'Ally A' );
		$b = $this->pc( 'Ally B' );

		$id = Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $a->id, 'target_type' => 'character', 'target_id' => $b->id, 'label' => 'ally' ] );
		$this->assertNotFalse( $id );

		// Both characters also carry their own home-plot connection (Character::ensure_plot()'s own
		// bookkeeping) - a real relationship is the one where BOTH ends are a character.
		$character_to_character = static fn( $rows ) => array_values( array_filter(
			$rows,
			static fn( $row ) => $row->source_type === 'character' && $row->target_type === 'character'
		) );

		$on_a = $character_to_character( Connection::for_entity( 'character', $a->id ) );
		$on_b = $character_to_character( Connection::for_entity( 'character', $b->id ) );
		$this->assertCount( 1, $on_a );
		$this->assertCount( 1, $on_b );
		$this->assertSame( 'ally', $on_a[0]->label );
		$this->assertSame( (int) $id, (int) $on_b[0]->id );
	}
}
