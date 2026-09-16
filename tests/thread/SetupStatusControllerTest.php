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

	public function test_front_end_pages_row_is_attention_before_provisioning(): void {
		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'front_end_pages' );

		$this->assertSame( 'attention', $row['status'] );
	}

	/**
	 * page-consolidation-design.md: the four fixed pages are chronicle-independent -
	 * provisioning them once turns this row green for every chronicle on the install,
	 * not just the one that happened to trigger it.
	 */
	public function test_provisioning_the_fixed_pages_turns_the_front_end_pages_row_green_for_every_chronicle(): void {
		\BeyondElysium\Core\Page_Provisioner::maybe_provision();

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'front_end_pages' );

		$this->assertSame( 'ok', $row['status'] );
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

	/**
	 * Owner ruling, 1.0.0-checklist.md item 18 (2026-09-15) - superseded this test's own
	 * prior name and premise (`git log` has the original "actionable_false" version, back
	 * when both rows were be_manage_games-only): an HST is a WordPress editor with a real
	 * be_game_members row in their own chronicle, and now genuinely gets actionable:true on
	 * the two rows this ruling names, not just visibility.
	 */
	public function test_an_hst_sees_actionable_true_on_creature_types_and_new_character_approval(): void {
		$game = Game::find_by_slug( $this->game_slug );
		Game_Member::set_role( (int) $game->id, $this->editor_id, 'hst' );

		$response = $this->dispatch( $this->editor_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'be_view_characters is granted broadly - the editor must reach the route at all' );
		$this->assertSame( 'attention', $this->row( $data['items'], 'enabled_stacks' )['status'], 'status is unrelated to actionable' );
		$this->assertTrue( $this->row( $data['items'], 'enabled_stacks' )['actionable'] );
		$this->assertTrue( $this->row( $data['items'], 'require_new_character_approval' )['actionable'] );
		// Unrelated to item 18 - assigning Storytellers (Chronicle Access) stays administrator-only.
		$this->assertFalse( $this->row( $data['items'], 'storytellers' )['actionable'] );
	}

	/**
	 * The still-true negative case item 18 does not touch: a real chronicle member who
	 * simply isn't an HST (a player here) reaches the route (be_view_characters) but gets
	 * actionable:false on both rows, same as before this ruling.
	 */
	public function test_a_player_member_sees_the_same_status_but_actionable_false(): void {
		$game       = Game::find_by_slug( $this->game_slug );
		$player_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $game->id, $player_id, 'player' );

		$response = $this->dispatch( $player_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->row( $data['items'], 'enabled_stacks' )['actionable'] );
		$this->assertFalse( $this->row( $data['items'], 'require_new_character_approval' )['actionable'] );
	}

	/**
	 * The AST half of the same ruling (item 27): an AST does not hold
	 * be_manage_chronicle_setup either, even though they hold almost everything else an
	 * HST does.
	 */
	public function test_an_ast_sees_actionable_false_on_creature_types_and_new_character_approval(): void {
		$game   = Game::find_by_slug( $this->game_slug );
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $game->id, $ast_id, 'ast' );

		$response = $this->dispatch( $ast_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->row( $data['items'], 'enabled_stacks' )['actionable'] );
		$this->assertFalse( $this->row( $data['items'], 'require_new_character_approval' )['actionable'] );
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
