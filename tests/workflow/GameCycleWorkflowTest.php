<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Core\Maintenance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Services\Action_Allocator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Trace 3: a Storyteller creates Friday's session with a downtime window Monday to Thursday 11:59pm and a default
 * batch scheduled Friday 5pm.
 */
class GameCycleWorkflowTest extends WP_UnitTestCase {

	private string $slug = 'game-cycle-workflow';
	private array $captured_mail = [];

	public function setUp(): void {
		parent::setUp();
		$this->captured_mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ] );
		parent::tearDown();
	}

	public function capture_mail( $pre_empty, $atts ) {
		$this->captured_mail[] = $atts;
		return true;
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_full_game_cycle_from_session_to_attendance_xp(): void {
		do_action( 'rest_api_init' );

		$game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Game Cycle Workflow' ] );

		$hst_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $hst_id, 'hst' );
		$narrator_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $narrator_id, 'narrator' );

		$player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'The Regular', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'status' => 'active', 'created_by' => $hst_id,
		] );

		$game_date = gmdate( 'Y-m-d', strtotime( '+5 days' ) );

		// A default batch scheduled Friday 5pm.
		wp_set_current_user( $hst_id );
		$batch = $this->dispatch( 'POST', "/be/v1/{$this->slug}/release-batches", [
			'name'       => 'Friday Batch',
			'release_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
		] );
		$batch_id = (int) $batch->get_data()->id;

		$session = $this->dispatch( 'POST', "/be/v1/{$this->slug}/sessions", [
			'game_date'             => $game_date,
			'downtime_opens_at'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			'downtime_deadline_at'  => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			'default_batch_id'      => $batch_id,
		] );
		$this->assertSame( 201, $session->get_status() );
		$session_id = (int) $session->get_data()->id;

		// A player posts an action Tuesday and records a background use.
		$plot_id = (int) Action_Allocator::create_own_plot( Character::find( $character_id ), $game_date );

		wp_set_current_user( $player_id );
		$action = $this->dispatch( 'POST', "/be/v1/{$this->slug}/plots/{$plot_id}/entries", [
			'entry_type' => 'action', 'content' => 'I case the old chantry.',
		] );
		$this->assertSame( 201, $action->get_status() );
		$action_id = (int) $action->get_data()->id;

		$background = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$character_id}/background-uses", [
			'game_date' => $game_date, 'name' => 'Personal', 'text' => 'Ask around town.',
		] );
		$this->assertSame( 201, $background->get_status() );

		// A Storyteller answers Wednesday (held).
		wp_set_current_user( $hst_id );
		$answer = $this->dispatch( 'POST', "/be/v1/{$this->slug}/plots/{$plot_id}/entries", [
			'entry_type' => 'response', 'content' => 'Someone was here before you.',
		] );
		$this->assertSame( 201, $answer->get_status() );
		$this->assertTrue( (bool) $answer->get_data()->held );
		$this->assertSame( $batch_id, (int) $answer->get_data()->release_batch_id );
		$answer_id = (int) $answer->get_data()->id;

		// The player sees no answer.
		wp_set_current_user( $player_id );
		$before_release = $this->dispatch( 'GET', "/be/v1/{$this->slug}/plots/{$plot_id}/entries" )->get_data();
		$this->assertNotContains( $answer_id, array_map( static fn( $e ) => (int) $e->id, (array) $before_release ) );

		// After Thursday's deadline, the player's edit 409s.
		Game_Session::update( $session_id, [ 'downtime_deadline_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ] );

		$edit = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/entries/{$action_id}", [ 'content' => 'A revised plan.' ] );
		$this->assertSame( 409, $edit->get_status() );
		$this->assertSame( 'entry_locked', $edit->get_data()['code'] );

		// One character gets an extension and posts.
		wp_set_current_user( $hst_id );
		$extension = $this->dispatch( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/downtime-extensions", [
			'character_id' => $character_id, 'until' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		] );
		$this->assertSame( 200, $extension->get_status() );

		wp_set_current_user( $player_id );
		$extended_post = $this->dispatch( 'POST', "/be/v1/{$this->slug}/plots/{$plot_id}/entries", [
			'entry_type' => 'action', 'content' => 'One more thing, now that there is time.',
		] );
		$this->assertSame( 201, $extended_post->get_status() );

		// Friday 5:00:01pm, before any cron run: the batch's own time has passed.
		Release_Batch::update( $batch_id, [ 'release_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ] );
		$this->assertSame( 'scheduled', Release_Batch::find( $batch_id )->status );
		$this->assertNull( Release_Batch::find( $batch_id )->notified_at );

		$after_deadline = $this->dispatch( 'GET', "/be/v1/{$this->slug}/plots/{$plot_id}/entries" )->get_data();
		$this->assertContains( $answer_id, array_map( static fn( $e ) => (int) $e->id, (array) $after_deadline ) );

		// The sweep sends each player one email.
		$this->captured_mail = [];
		Maintenance::run_release_sweep();
		$this->assertCount( 1, $this->captured_mail );
		$this->assertSame( 'released', Release_Batch::find( $batch_id )->status );

		Maintenance::run_release_sweep();
		$this->assertCount( 1, $this->captured_mail, 'A second sweep must not send a second round.' );

		// After the game, a Narrator signs in the characters and a visitor.
		wp_set_current_user( $narrator_id );
		$sign_in_character = $this->dispatch( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/attendance", [
			'character_id' => $character_id,
		] );
		$this->assertSame( 201, $sign_in_character->get_status() );

		$sign_in_visitor = $this->dispatch( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/attendance", [
			'visitor_name' => 'A Visiting Player',
		] );
		$this->assertSame( 201, $sign_in_visitor->get_status() );

		// A Narrator holds be_manage_sessions but not be_manage_characters.
		wp_set_current_user( $hst_id );
		$award = $this->dispatch( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/award-attendance-xp" );
		$this->assertSame( 200, $award->get_status() );
		$this->assertSame( 1, $award->get_data()['awarded_count'], 'Only the real character earns XP - the visitor has no character_id.' );

		$second_award = $this->dispatch( 'POST', "/be/v1/{$this->slug}/sessions/{$session_id}/award-attendance-xp" );
		$this->assertSame( 409, $second_award->get_status() );
		$this->assertSame( 'already_awarded', $second_award->get_data()['code'] );
	}
}
