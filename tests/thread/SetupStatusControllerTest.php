<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Setup_Status_Controller`'s row computation (GS-4,
 * guided-chronicle-setup-design.md §6.3-6.4) - each row's status derived
 * from real rows, never stored, plus `actionable` for an administrator, an
 * editor, and a subscriber.
 *
 * @see BE_PROCESS/guided-chronicle-setup-design.md §6.3
 */
class SetupStatusControllerTest extends WP_UnitTestCase {

	private $admin_id;
	private $editor_id;
	private $subscriber_id;
	private $game_slug = 'setup-status-controller-test';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->editor_id     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Setup Status Controller Test', 'created_by' => $this->admin_id ] );
	}

	private function dispatch( int $as_user ): \WP_REST_Response {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/setup-status' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		return rest_get_server()->dispatch( $request );
	}

	private function row( array $items, string $id ): ?array {
		foreach ( $items as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	public function test_a_freshly_created_chronicle_shows_four_attention_rows(): void {
		$response = $this->dispatch( $this->admin_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'attention', $this->row( $data['items'], 'enabled_stacks' )['status'] );
		$this->assertSame( 'attention', $this->row( $data['items'], 'storytellers' )['status'] );
		$this->assertSame( 'attention', $this->row( $data['items'], 'require_new_character_approval' )['status'] );
		$this->assertSame( 'attention', $this->row( $data['items'], 'characters' )['status'] );
	}

	public function test_setting_enabled_stacks_turns_the_row_green(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_stacks' => [ 'vampire' ] ] ] );

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'enabled_stacks' );

		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_assigning_an_hst_turns_the_storytellers_row_green(): void {
		$game = Game::find_by_slug( $this->game_slug );
		Game_Member::set_role( (int) $game->id, $this->editor_id, 'hst' );

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'storytellers' );

		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_a_provisioned_page_turns_the_front_end_pages_row_green(): void {
		\BeyondElysium\Core\Page_Provisioner::provision_for_game( $this->game_slug );

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'front_end_pages' );

		$this->assertSame( 'ok', $row['status'], 'the real stored post_content is HTML-entity-escaped, not literal quotes - this is the regression guard for that' );
	}

	public function test_a_character_turns_the_characters_row_green(): void {
		Character::create( [
			'name'       => 'Setup Status Test Character',
			'owner_slug' => $this->game_slug,
			'stack_slug' => 'vampire',
			'created_by' => $this->admin_id,
		] );

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'characters' );

		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_a_read_only_editor_sees_the_same_status_but_actionable_false(): void {
		// The realistic scenario (§0): an HST is a WordPress editor with a real
		// be_game_members row in their own chronicle - Authorization's second layer
		// requires that membership even though be_view_characters is granted site-wide.
		$game = Game::find_by_slug( $this->game_slug );
		Game_Member::set_role( (int) $game->id, $this->editor_id, 'hst' );

		$response = $this->dispatch( $this->editor_id );
		$row      = $this->row( $response->get_data()['items'], 'enabled_stacks' );

		$this->assertSame( 200, $response->get_status(), 'be_view_characters is granted broadly - the editor must reach the route at all' );
		$this->assertSame( 'attention', $row['status'], 'status is visible even when not actionable' );
		$this->assertFalse( $row['actionable'], 'be_manage_games is administrator-only' );
	}

	public function test_a_subscriber_with_no_membership_is_denied_outright(): void {
		$response = $this->dispatch( $this->subscriber_id );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_administrator_sees_actionable_true_on_the_administrator_only_rows(): void {
		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'enabled_stacks' );

		$this->assertTrue( $row['actionable'] );
	}

	public function test_the_demo_chronicle_gets_its_own_row_and_others_do_not(): void {
		$response_demo = $this->dispatch_for_game( $this->admin_id, 'be-demo' );
		$this->assertNotNull( $this->row( $response_demo->get_data()['items'], 'demo_chronicle' ) );

		$response_real = $this->dispatch( $this->admin_id );
		$this->assertNull( $this->row( $response_real->get_data()['items'], 'demo_chronicle' ) );
	}

	private function dispatch_for_game( int $as_user, string $game_slug ): \WP_REST_Response {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $game_slug . '/setup-status' );
		$request->set_url_params( [ 'game_slug' => $game_slug ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_status_is_never_cached_across_requests(): void {
		$before = $this->row( $this->dispatch( $this->admin_id )->get_data()['items'], 'enabled_stacks' );
		$this->assertSame( 'attention', $before['status'] );

		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_stacks' => [ 'mage' ] ] ] );

		$after = $this->row( $this->dispatch( $this->admin_id )->get_data()['items'], 'enabled_stacks' );
		$this->assertSame( 'ok', $after['status'], 'a cached response here would still read attention' );
	}
}
