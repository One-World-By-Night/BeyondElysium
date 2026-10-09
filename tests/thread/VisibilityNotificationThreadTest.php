<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Core\User_Settings;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notification_Queue;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Emails a connected character's player when a connection, or a widened audience, makes a plot, item or location newly
 * visible to them.
 */
class VisibilityNotificationThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-visible-notify';
	private int $game_id;
	private array $captured = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Visible Notify', 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

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

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function send( string $method, string $route, array $body, int $as ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	private function make_hst(): int {
		return self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	/** @return array{0:int,1:int} [wp_user_id, character_id] */
	private function make_player_character( string $name ): array {
		$player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $player_id, 'status' => 'active', 'created_by' => 1,
		] );
		return [ $player_id, $character_id ];
	}

	private function make_item( string $audience = 'storytellers' ): int {
		return (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'A Tarnished Locket', 'audience' => $audience,
		] );
	}

	private function recipients(): array {
		return array_column( $this->captured, 'to' );
	}

	// --- Connections: items/locations ---------------------------------------------------------

	public function test_connecting_a_character_to_an_item_emails_its_player_once_on_immediate(): void {
		$hst_id                  = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Isolde' );
		$item_id                 = $this->make_item();

		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$response = $this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $hst_id );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( get_userdata( $player_id )->user_email, $this->recipients()[0] );
	}

	public function test_connecting_a_character_to_an_item_queues_one_row_on_daily(): void {
		$hst_id                  = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Isolde' );
		$item_id                 = $this->make_item();

		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'daily' );

		$this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
		$rows = Notification_Queue::for_user( $player_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'visible', $rows[0]->kind );
		$this->assertSame( 'item', $rows[0]->payload['kind'] );
	}

	public function test_connecting_a_character_to_an_item_sends_nothing_on_off(): void {
		$hst_id                  = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Isolde' );
		$item_id                 = $this->make_item();

		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'off' );

		$this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
		$this->assertCount( 0, Notification_Queue::for_user( $player_id ) );
	}

	public function test_connecting_an_already_visible_item_emails_nobody(): void {
		$hst_id                  = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Isolde' );
		$item_id                 = $this->make_item( 'everyone' );

		$this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $hst_id );

		$this->assertCount( 0, $this->captured, 'already visible to everyone - nothing new' );
	}

	// --- Connections: plots ---------------------------------------------------------------------

	public function test_connecting_a_character_to_a_storytellers_only_plot_emails_nobody(): void {
		$hst_id                  = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ , $character_id ] = $this->make_player_character( 'Isolde' );
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'ST Secret Plot', 'created_by' => 1, 'audience' => 'storytellers',
		] );

		$this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $character_id, 'label' => 'plot_member',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
	}

	public function test_connecting_a_character_to_a_rules_audience_plot_emails_its_player(): void {
		$hst_id                  = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Isolde' );
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'A Restricted Plot', 'created_by' => 1, 'audience' => 'restricted',
		] );

		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$response = $this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $character_id, 'label' => 'plot_member',
		], $hst_id );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( get_userdata( $player_id )->user_email, $this->recipients()[0] );
	}

	// --- Audience widened: creating or updating an item/location --------------------------------

	public function test_creating_an_item_with_everyone_audience_emails_every_active_player(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$response = $this->send( 'POST', "/be/v1/{$this->game_slug}/world-objects", [
			'name' => 'A Public Scroll', 'object_type' => 'item', 'audience' => 'everyone',
		], $hst_id );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( get_userdata( $player_id )->user_email, $this->recipients()[0] );
	}

	public function test_creating_an_item_with_storytellers_audience_emails_nobody(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$response = $this->send( 'POST', "/be/v1/{$this->game_slug}/world-objects", [
			'name' => 'A Hidden Scroll', 'object_type' => 'item', 'audience' => 'storytellers',
		], $hst_id );

		$this->assertSame( 201, $response->get_status() );
		$this->assertCount( 0, $this->captured );
	}

	public function test_widening_an_items_audience_emails_each_newly_reached_player_once_not_the_acting_storyteller(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $own_character_id ] = $this->make_player_character( 'The Acting HST\'s Own Character' );
		Character::update_header( $own_character_id, [ 'wp_user_id' => $hst_id ] );
		[ $other_player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $other_player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		update_user_meta( $hst_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$item_id = $this->make_item( 'storytellers' );

		$response = $this->send( 'PUT', "/be/v1/{$this->game_slug}/world-objects/{$item_id}", [
			'audience' => 'everyone',
		], $hst_id );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 1, $this->captured, 'only the other player - never the acting Storyteller, even though their own character is now reached too' );
		$this->assertSame( get_userdata( $other_player_id )->user_email, $this->recipients()[0] );
	}

	public function test_narrowing_an_items_audience_emails_nobody(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$item_id = $this->make_item( 'everyone' );

		$this->send( 'PUT', "/be/v1/{$this->game_slug}/world-objects/{$item_id}", [
			'audience' => 'storytellers',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
	}

	public function test_a_save_that_leaves_the_audience_alone_emails_nobody(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$item_id = $this->make_item( 'everyone' );

		$this->send( 'PUT', "/be/v1/{$this->game_slug}/world-objects/{$item_id}", [
			'name' => 'A Renamed Locket',
		], $hst_id );

		$this->assertCount( 0, $this->captured, 'no audience/audience_rules field touched at all' );
	}

	// --- A model-level create (as the demo reset does) never emails -----------------------------

	public function test_a_model_level_create_emails_nobody(): void {
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Model-Created Locket', 'audience' => 'everyone',
		] );

		$this->assertCount( 0, $this->captured, 'no controller hook ran - the model alone never notifies' );
	}

	// --- add_member: the plot owner adding another player's character ---------------------------

	public function test_a_plot_owner_adding_another_players_character_emails_that_player(): void {
		[ $owner_id, $owner_character_id ] = $this->make_player_character( 'Owner' );
		[ $other_id, $other_character_id ] = $this->make_player_character( 'Other' );
		update_user_meta( $other_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'A Player Plot', 'created_by' => $owner_id, 'audience' => 'restricted',
		] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot_id,
			'target_type' => 'character', 'target_id' => $owner_character_id, 'label' => 'plot_owner', 'created_by' => $owner_id,
		] );

		$response = $this->send( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/members", [
			'character_id' => $other_character_id,
		], $owner_id );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( get_userdata( $other_id )->user_email, $this->recipients()[0] );
	}

	// --- Audience widened: creating or updating a plot -------------------------------------------

	public function test_an_st_creating_a_plot_with_everyone_audience_emails_every_active_player(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$response = $this->send( 'POST', "/be/v1/{$this->game_slug}/plots", [
			'title' => 'An Open Plot', 'audience' => 'everyone',
		], $hst_id );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 1, $this->captured );
	}

	public function test_a_rumor_created_held_emails_nobody_at_create(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );

		$response = $this->send( 'POST', "/be/v1/{$this->game_slug}/plots", [
			'title' => 'A Held Rumor', 'is_rumor' => true, 'audience' => 'everyone',
		], $hst_id );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 0, $this->captured, 'a held plot reaches nobody until its own release batch goes out' );
	}

	public function test_widening_a_plot_from_storytellers_to_everyone_emails_each_player_once(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'Was Hidden', 'created_by' => 1, 'audience' => 'storytellers',
		] );

		$response = $this->send( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}", [
			'audience' => 'everyone',
		], $hst_id );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 1, $this->captured );
	}

	public function test_narrowing_a_plots_audience_emails_nobody(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'Was Open', 'created_by' => 1, 'audience' => 'everyone',
		] );

		$this->send( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}", [
			'audience' => 'storytellers',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
	}

	public function test_a_plot_save_that_leaves_the_audience_alone_emails_nobody(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'Was Open', 'created_by' => 1, 'audience' => 'everyone',
		] );

		$this->send( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}", [
			'title' => 'A New Title',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
	}

	public function test_several_things_made_visible_in_one_request_send_one_email_not_several(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $first_character_id ] = $this->make_player_character( 'First' );
		$second_character_id = (int) Character::create( [
			'name' => 'Second', 'stack_slug' => 'vampire', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $player_id, 'status' => 'active', 'created_by' => 1,
		] );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$plot_id = (int) Plot::create( [
			'game_id' => $this->game_id, 'title' => 'Reaches Both Characters', 'created_by' => 1, 'audience' => 'storytellers',
		] );

		$response = $this->send( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}", [
			'audience' => 'everyone',
		], $hst_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->captured, 'one player, two newly-reached characters, one email' );
	}

	public function test_the_daily_digest_lists_posts_and_new_things_apart(): void {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Notification_Queue::create( $player_id, $this->game_id, 'plot_post', [
			'game_name' => 'Kony', 'plot_title' => 'A Plot', 'posted_by' => 'A Storyteller', 'link' => 'https://example.test/plot',
		] );
		Notification_Queue::create( $player_id, $this->game_id, 'visible', [
			'game_name' => 'Kony', 'kind' => 'item', 'title' => 'A Locket', 'link' => 'https://example.test/sheet',
		] );

		Notifications::send_daily_digests();

		$this->assertCount( 1, $this->captured );
		$body = $this->captured[0]['message'];
		$this->assertStringContainsString( 'New posts:', $body );
		$this->assertStringContainsString( 'New things your characters can see:', $body );
		$this->assertStringContainsString( 'A Plot', $body );
		$this->assertStringContainsString( 'A Locket', $body );
	}

	public function test_a_demo_chronicle_emails_nobody_at_all_for_a_newly_visible_item(): void {
		$demo_hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		$demo_slug = 'thread-visible-notify-demo';
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $demo_slug, 'name' => 'Thread Visible Notify Demo', 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [ 'demo' => [ 'on' => true, 'reset_hours' => 6, 'accounts' => [] ] ] ),
		] );
		$demo_game_id = (int) $wpdb->insert_id;
		Game_Member::set_role( $demo_game_id, $demo_hst, 'hst' );
		$player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $demo_game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'Demo Character', 'stack_slug' => 'vampire', 'owner_slug' => $demo_slug,
			'wp_user_id' => $player_id, 'status' => 'active', 'created_by' => 1,
		] );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$item_id = (int) World_Object::create( [
			'game_id' => $demo_game_id, 'object_type' => 'item', 'name' => 'A Demo Locket', 'audience' => 'storytellers',
		] );

		$this->send( 'POST', "/be/v1/{$demo_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $demo_hst );

		$this->assertCount( 0, $this->captured );
	}

	public function test_the_chronicles_notifications_off_stops_a_visibility_email(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'notifications_enabled' => 0 ], [ 'id' => $this->game_id ] );

		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$item_id = $this->make_item();

		$this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
	}

	public function test_the_account_opt_out_stops_a_visibility_email(): void {
		$hst_id = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		[ $player_id, $character_id ] = $this->make_player_character( 'Isolde' );
		update_user_meta( $player_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		update_user_meta( $player_id, User_Settings::NOTIFICATIONS_OPT_OUT_META, '1' );
		$item_id = $this->make_item();

		$this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $hst_id );

		$this->assertCount( 0, $this->captured );
	}

	public function test_the_player_who_made_the_connection_is_not_emailed(): void {
		$hst_id       = $this->make_hst();
		Game_Member::set_role( $this->game_id, $hst_id, 'hst' );
		$character_id = (int) Character::create( [
			'name' => 'The HST\'s Own Character', 'stack_slug' => 'vampire', 'owner_slug' => $this->game_slug,
			'wp_user_id' => $hst_id, 'status' => 'active', 'created_by' => 1,
		] );
		update_user_meta( $hst_id, User_Settings::PLOT_NOTIFY_META, 'immediate' );
		$item_id = $this->make_item();

		$this->send( 'POST', "/be/v1/{$this->game_slug}/connections", [
			'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds',
		], $hst_id );

		$this->assertCount( 0, $this->captured, 'never emailed about a connection they made themselves' );
	}
}
