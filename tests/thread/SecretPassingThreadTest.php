<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player logging what their own character learned, and telling another character a secret already known.
 */
class SecretPassingThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-secret-passing';
	private int $game_id;
	private int $storyteller_id;
	private int $plot_id;
	private array $captured = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Secret Passing' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'A Plot', 'created_by' => $this->storyteller_id, 'audience' => 'everyone',
		] );

		$this->captured = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ] );
		parent::tearDown();
	}

	public function capture_mail( $pre_empty, $atts ) {
		$this->captured[] = $atts;
		return true;
	}

	private function send( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function make_player( string $name = 'A Character' ): array {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $this->storyteller_id,
		] );
		return [ $player_id, $character_id ];
	}

	private function make_secret( array $overrides = [] ): int {
		wp_set_current_user( $this->storyteller_id );
		return (int) Secret::create( array_merge( [
			'game_id' => $this->game_id, 'entity_type' => 'plot', 'entity_id' => $this->plot_id,
			'title' => 'A Secret', 'content' => 'Only staff know this.', 'audience' => 'restricted',
			'created_by' => $this->storyteller_id,
		], $overrides ) );
	}

	private function set_secret_passing( string $mode ): void {
		Game::update( $this->slug, [ 'settings' => [ 'secret_passing' => $mode ] ] );
	}

	private function approve( int $change_id, array $extra = [] ) {
		wp_set_current_user( $this->storyteller_id );
		return $this->send( 'PUT', "/changes/{$change_id}", array_merge( [ 'status' => 'approved' ], $extra ) );
	}

	private function reject( int $change_id ) {
		wp_set_current_user( $this->storyteller_id );
		return $this->send( 'PUT', "/changes/{$change_id}", [ 'status' => 'rejected' ] );
	}

	// -------------------------------------------------------------------------
	// Logging what a character learned
	// -------------------------------------------------------------------------

	public function test_a_player_can_log_what_their_own_character_learned(): void {
		[ $player_id, $character_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id,
			'title'        => 'The locket is cursed',
			'details'      => 'Heard it from a Nosferatu contact.',
			'how'          => 'rumor',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()->status );
		$this->assertSame( 'log_knowledge', $response->get_data()->change_type );
	}

	public function test_a_player_cannot_log_for_someone_elses_character(): void {
		[ , $character_id ] = $this->make_player();
		[ $other_player_id ] = $this->make_player( 'Someone Else' );

		wp_set_current_user( $other_player_id );
		$response = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_logging_is_refused_when_secret_passing_is_off(): void {
		$this->set_secret_passing( 'off' );
		[ $player_id, $character_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'secret_passing_off', $response->as_error()->get_error_code() );
	}

	public function test_approving_a_log_ties_it_to_an_existing_secret_and_records_a_reveal(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$secret_id = $this->make_secret();

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'The locket', 'details' => 'It is cursed.', 'how' => 'rumor',
		] )->get_data();

		$response = $this->approve( (int) $log->id, [ 'secret_id' => $secret_id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'approved', $response->get_data()->status );
		$reveal = Secret_Reveal::find_for( $secret_id, $character_id );
		$this->assertNotNull( $reveal );
		$this->assertTrue( $reveal->approved );
		$this->assertSame( 'rumor', $reveal->how );
	}

	public function test_approving_a_log_creates_a_new_secret_and_records_a_reveal(): void {
		[ $player_id, $character_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'The real plot', 'details' => 'It is bigger than it looks.',
		] )->get_data();

		$response = $this->approve( (int) $log->id, [
			'entity_type' => 'plot', 'entity_id' => $this->plot_id, 'title' => 'The real plot', 'content' => 'It is bigger than it looks.',
		] );

		$this->assertSame( 200, $response->get_status() );
		$secrets = Secret::for_entity( $this->game_id, 'plot', $this->plot_id );
		$this->assertCount( 1, $secrets );
		$this->assertTrue( Secret_Reveal::already_revealed( (int) $secrets[0]->id, $character_id ) );
	}

	public function test_approving_a_log_creates_an_unattached_secret_and_records_a_reveal(): void {
		[ $player_id, $character_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'An old grudge', 'details' => 'Not about anything on the sheet yet.',
		] )->get_data();

		$response = $this->approve( (int) $log->id, [ 'title' => 'An old grudge' ] );

		$this->assertSame( 200, $response->get_status() );
		$all = Secret::for_game( $this->game_id );
		$new = null;
		foreach ( $all as $secret ) {
			if ( $secret->title === 'An old grudge' ) {
				$new = $secret;
			}
		}
		$this->assertNotNull( $new );
		$this->assertNull( $new->entity_type );
		$this->assertTrue( Secret_Reveal::already_revealed( (int) $new->id, $character_id ) );
	}

	public function test_approving_a_log_refuses_a_secret_id_from_another_game(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$other_game_id = (int) Game::create( [ 'slug' => 'thread-secret-passing-other', 'name' => 'Another Game' ] );
		$other_plot_id = (int) Plot::create( [ 'game_id' => $other_game_id, 'title' => 'Another Plot', 'created_by' => $this->storyteller_id, 'audience' => 'everyone' ] );
		$foreign_secret_id = (int) Secret::create( [
			'game_id' => $other_game_id, 'entity_type' => 'plot', 'entity_id' => $other_plot_id,
			'title' => 'Not yours', 'created_by' => $this->storyteller_id,
		] );

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] )->get_data();

		$response = $this->approve( (int) $log->id, [ 'secret_id' => $foreign_secret_id ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( Secret_Reveal::already_revealed( $foreign_secret_id, $character_id ) );
	}

	public function test_approving_a_log_refuses_an_entity_id_from_another_game(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$other_game_id = (int) Game::create( [ 'slug' => 'thread-secret-passing-other2', 'name' => 'Another Game 2' ] );
		$other_plot_id = (int) Plot::create( [ 'game_id' => $other_game_id, 'title' => 'Another Plot', 'created_by' => $this->storyteller_id, 'audience' => 'everyone' ] );

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] )->get_data();

		$response = $this->approve( (int) $log->id, [ 'entity_type' => 'plot', 'entity_id' => $other_plot_id, 'title' => 'x' ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_approving_a_log_with_no_secret_choice_is_refused(): void {
		[ $player_id, $character_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] )->get_data();

		$response = $this->approve( (int) $log->id );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_refused_log_records_nothing(): void {
		[ $player_id, $character_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] )->get_data();

		$response = $this->reject( (int) $log->id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, Secret::for_game( $this->game_id ) ? count( Secret::for_game( $this->game_id ) ) : 0 );
	}

	public function test_log_and_pass_always_wait_even_on_auto_approve(): void {
		Game::update( $this->slug, [ 'settings' => [ 'auto_approve' => true ] ] );
		[ $player_id, $character_id ] = $this->make_player();

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] )->get_data();

		$this->assertSame( 'pending', Change::find( (int) $log->id )->status );
	}

	// -------------------------------------------------------------------------
	// Passing: the eight refusals, in order
	// -------------------------------------------------------------------------

	public function test_passing_is_refused_when_the_switch_is_off(): void {
		$this->set_secret_passing( 'off' );
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => 1 ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'secret_passing_off', $response->as_error()->get_error_code() );
	}

	public function test_passing_refuses_a_teller_that_is_not_the_callers_own_character(): void {
		[ , $from_id ] = $this->make_player();
		[ $other_player_id ] = $this->make_player( 'Someone Else' );
		$secret_id = $this->make_secret();

		wp_set_current_user( $other_player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => 1 ] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_passing_with_someone_elses_teller_answers_the_same_for_a_secret_that_does_not_exist(): void {
		[ , $from_id ] = $this->make_player();
		[ $other_player_id ] = $this->make_player( 'Someone Else' );

		wp_set_current_user( $other_player_id );
		$response = $this->send( 'POST', '/secrets/987654321/pass', [ 'from_character_id' => $from_id, 'to_character_id' => 1 ] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_passing_refuses_when_the_teller_has_not_learned_the_secret(): void {
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => 1 ] );

		$this->assertSame( 404, $response->get_status(), 'never confirms the secret exists' );
	}

	public function test_passing_refuses_an_unreleased_held_reveal(): void {
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret();
		$batch_id  = (int) Release_Batch::create( [ 'game_id' => $this->game_id, 'name' => 'Batch', 'created_by' => $this->storyteller_id ] );
		Secret_Reveal::create( [
			'secret_id' => $secret_id, 'character_id' => $from_id, 'held' => true, 'release_batch_id' => $batch_id,
			'revealed_by' => $this->storyteller_id,
		] );

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => 1 ] );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_passing_refuses_unapproved_knowledge(): void {
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret();
		Secret_Reveal::create( [
			'secret_id' => $secret_id, 'character_id' => $from_id, 'revealed_by' => $this->storyteller_id, 'approved' => false,
		] );
		[ , $to_id ] = $this->make_player( 'Recipient' );

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'knowledge_not_approved', $response->as_error()->get_error_code() );
	}

	public function test_passing_refuses_an_already_public_secret(): void {
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret( [ 'audience' => 'everyone' ] );
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $from_id, 'revealed_by' => $this->storyteller_id ] );
		[ , $to_id ] = $this->make_player( 'Recipient' );

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'secret_already_public', $response->as_error()->get_error_code() );
	}

	public function test_passing_refuses_a_recipient_outside_whos_who(): void {
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret();
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $from_id, 'revealed_by' => $this->storyteller_id ] );
		[ , $to_id ] = $this->make_player( 'Recipient' ); // Never shown its own Who's Who profile.

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_passing_refuses_telling_yourself(): void {
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret();
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $from_id, 'revealed_by' => $this->storyteller_id ] );
		Character::update_header( $from_id, [ 'profile_audience' => 'everyone' ] );

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $from_id ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_passing_refuses_a_recipient_who_already_knows(): void {
		[ $player_id, $from_id ] = $this->make_player();
		$secret_id = $this->make_secret();
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $from_id, 'revealed_by' => $this->storyteller_id ] );
		[ , $to_id ] = $this->make_player( 'Recipient' );
		Character::update_header( $to_id, [ 'profile_audience' => 'everyone' ] );
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $to_id, 'revealed_by' => $this->storyteller_id ] );

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Needs a Storyteller mode (the default)
	// -------------------------------------------------------------------------

	private function make_passable_pair(): array {
		[ $player_id, $from_id ] = $this->make_player( 'Teller' );
		$secret_id = $this->make_secret();
		Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $from_id, 'revealed_by' => $this->storyteller_id ] );
		[ , $to_id ] = $this->make_player( 'Recipient' );
		Character::update_header( $to_id, [ 'profile_audience' => 'everyone' ] );
		return [ $player_id, $from_id, $to_id, $secret_id ];
	}

	public function test_needs_a_storyteller_mode_queues_a_pending_pass_change(): void {
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()->status );
		$this->assertFalse( Secret_Reveal::already_revealed( $secret_id, $to_id ), 'nothing reaches the recipient until approval' );
	}

	public function test_approving_a_pass_in_needs_a_storyteller_mode_creates_an_approved_told_reveal(): void {
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();

		wp_set_current_user( $player_id );
		$change = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] )->get_data();

		$response = $this->approve( (int) $change->id );

		$this->assertSame( 200, $response->get_status() );
		$reveal = Secret_Reveal::find_for( $secret_id, $to_id );
		$this->assertNotNull( $reveal );
		$this->assertTrue( $reveal->approved );
		$this->assertSame( 'told', $reveal->how );
		$this->assertSame( $from_id, (int) $reveal->from_character_id );
	}

	public function test_refusing_a_pass_in_needs_a_storyteller_mode_records_nothing(): void {
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();

		wp_set_current_user( $player_id );
		$change = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] )->get_data();

		$response = $this->reject( (int) $change->id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( Secret_Reveal::already_revealed( $secret_id, $to_id ) );
	}

	// -------------------------------------------------------------------------
	// Immediate mode
	// -------------------------------------------------------------------------

	public function test_immediate_mode_writes_an_unapproved_reveal_right_away(): void {
		$this->set_secret_passing( 'immediate' );
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();

		wp_set_current_user( $player_id );
		$this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$reveal = Secret_Reveal::find_for( $secret_id, $to_id );
		$this->assertNotNull( $reveal );
		$this->assertFalse( $reveal->approved );
	}

	public function test_immediate_mode_emails_the_recipient_and_the_staff(): void {
		$this->set_secret_passing( 'immediate' );
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();
		$to_player_id = Character::find( $to_id )->wp_user_id;
		update_user_meta( $to_player_id, \BeyondElysium\Core\User_Settings::PLOT_NOTIFY_META, 'immediate' );

		wp_set_current_user( $player_id );
		$this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$recipients = array_column( $this->captured, 'to' );
		$this->assertContains( get_userdata( $to_player_id )->user_email, $recipients, 'the recipient is told their character now knows it' );
		$this->assertContains( get_userdata( $this->storyteller_id )->user_email, $recipients, 'staff are told so they can review it' );
	}

	public function test_a_needs_a_storyteller_pass_emails_nobody_about_visibility(): void {
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();
		$to_player_id = Character::find( $to_id )->wp_user_id;
		update_user_meta( $to_player_id, \BeyondElysium\Core\User_Settings::PLOT_NOTIFY_META, 'immediate' );

		wp_set_current_user( $player_id );
		$this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		$recipients = array_column( $this->captured, 'to' );
		$this->assertNotContains( get_userdata( $to_player_id )->user_email, $recipients, 'nothing is visible yet - the recipient only knows once a Storyteller approves' );
	}

	public function test_the_recipient_can_read_an_unapproved_immediate_reveal_but_cannot_pass_it_on(): void {
		$this->set_secret_passing( 'immediate' );
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();
		$to_player_id = Character::find( $to_id )->wp_user_id;

		wp_set_current_user( $player_id );
		$this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] );

		wp_set_current_user( $to_player_id );
		$mine = $this->send( 'GET', '/my/secrets' )->get_data()['known'];
		$this->assertCount( 1, $mine );
		$this->assertFalse( $mine[0]['approved'] );
		$this->assertFalse( $mine[0]['can_pass'] );
	}

	public function test_approving_an_immediate_pass_marks_the_existing_reveal_approved(): void {
		$this->set_secret_passing( 'immediate' );
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();

		wp_set_current_user( $player_id );
		$change = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] )->get_data();

		$this->approve( (int) $change->id );

		$reveal = Secret_Reveal::find_for( $secret_id, $to_id );
		$this->assertTrue( $reveal->approved );
	}

	public function test_refusing_an_immediate_pass_removes_the_unapproved_reveal(): void {
		$this->set_secret_passing( 'immediate' );
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();

		wp_set_current_user( $player_id );
		$change = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] )->get_data();

		$this->reject( (int) $change->id );

		$this->assertFalse( Secret_Reveal::already_revealed( $secret_id, $to_id ) );
	}

	// -------------------------------------------------------------------------
	// Chains and NPCs
	// -------------------------------------------------------------------------

	public function test_a_told_approved_character_can_pass_it_on(): void {
		[ $player_id, $from_id, $to_id, $secret_id ] = $this->make_passable_pair();
		wp_set_current_user( $player_id );
		$change = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $to_id ] )->get_data();
		$this->approve( (int) $change->id );

		[ , $third_id ] = $this->make_player( 'Third' );
		Character::update_header( $third_id, [ 'profile_audience' => 'everyone' ] );
		$to_player_id = Character::find( $to_id )->wp_user_id;

		wp_set_current_user( $to_player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $to_id, 'to_character_id' => $third_id ] );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_telling_an_npc_records_the_reveal_and_emails_no_player(): void {
		$this->set_secret_passing( 'immediate' );
		[ $player_id, $from_id, , $secret_id ] = $this->make_passable_pair();
		$npc_id = (int) Character::create( [
			'name' => 'An NPC', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'is_npc' => 1, 'created_by' => $this->storyteller_id,
		] );
		Character::update_header( $npc_id, [ 'profile_audience' => 'everyone' ] );

		wp_set_current_user( $player_id );
		$response = $this->send( 'POST', "/secrets/{$secret_id}/pass", [ 'from_character_id' => $from_id, 'to_character_id' => $npc_id ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( Secret_Reveal::already_revealed( $secret_id, $npc_id ) );
	}

	// -------------------------------------------------------------------------
	// Capability: approving still needs secret-management rights
	// -------------------------------------------------------------------------

	public function test_approving_a_log_or_pass_needs_manage_plots(): void {
		[ $player_id, $character_id ] = $this->make_player();
		$manager_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $manager_id, 'hst' );
		$user = get_userdata( $manager_id );
		$user->add_cap( 'be_manage_plots', false );

		wp_set_current_user( $player_id );
		$log = $this->send( 'POST', '/my/secrets/log', [
			'character_id' => $character_id, 'title' => 'x', 'details' => 'y',
		] )->get_data();

		wp_set_current_user( $manager_id );
		$response = $this->send( 'PUT', "/changes/{$log->id}", [ 'status' => 'approved', 'secret_id' => 1 ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'secret_capability_denied', $response->as_error()->get_error_code() );
	}
}
