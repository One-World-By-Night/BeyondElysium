<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Chronicle-scoped authorization: a site-wide capability such as `be_manage_characters` does not grant access to a
 * chronicle the caller has no role in.
 */
class ChronicleScopedAuthorizationTest extends WP_UnitTestCase {

	private string $game_a = 'thread-test-chronicle-scope-a';
	private string $game_b = 'thread-test-chronicle-scope-b';
	private int $game_a_id;
	private int $game_b_id;
	private int $editor_id;
	private int $admin_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'be_games',
			[
				'slug'       => $this->game_a,
				'name'       => 'Thread Test Chronicle Scope A',
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$this->game_a_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'be_games',
			[
				'slug'       => $this->game_b,
				'name'       => 'Thread Test Chronicle Scope B',
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$this->game_b_id = (int) $wpdb->insert_id;

		$this->editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Editor is HST of game A only.
		Game_Member::set_role( $this->game_a_id, $this->editor_id, 'hst' );
		// Player is a member of game A only, same shape.
		Game_Member::set_role( $this->game_a_id, $this->player_id, 'player' );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function dispatch( string $method, string $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_editor_reaches_the_chronicle_they_are_hst_of(): void {
		wp_set_current_user( $this->editor_id );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_a . '/changes' );
		$this->assertNotSame( 403, $response->get_status() );
	}

	public function test_ast_can_import_but_cannot_delete_or_edit_the_game(): void {
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_a_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		$import = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/import/parse' );
		$this->assertNotSame( 403, $import->get_status(), 'an AST must be able to import into their own chronicle' );

		$edit = $this->dispatch( 'PUT', '/be/v1/games/' . $this->game_a, [ 'name' => 'Renamed' ] );
		$this->assertSame( 403, $edit->get_status(), 'an AST must not be able to edit the game itself' );

		$delete = $this->dispatch( 'DELETE', '/be/v1/games/' . $this->game_a );
		$this->assertSame( 403, $delete->get_status(), 'an AST must not be able to delete the game itself' );
	}

	public function test_ast_cannot_manage_approval_rules_for_their_own_chronicle(): void {
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_a_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		$response = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/approval-rules' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Same ruling: an AST loses the chronicle's own catalog customization (forking a schema block for their chronicle
	 * specifically).
	 */
	public function test_ast_cannot_customize_the_chronicles_own_catalog(): void {
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_a_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		// The route's own required-args validation runs before permission_callback in WP core's own dispatch order.
		$response = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/schema-blocks', [
			'slug'         => 'thread-test-ast-catalog-block',
			'name'         => 'Thread Test AST Catalog Block',
			'section_type' => 'trait_list',
		] );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Same ruling: an AST loses the chronicle's own template customization too.
	 */
	public function test_ast_cannot_customize_the_chronicles_own_templates(): void {
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_a_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		$response = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/templates', [
			'name'          => 'Thread Test AST Template',
			'template_type' => 'sheet_full',
			'stack_slug'    => 'mortal',
		] );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Same ruling: an AST loses the ability to permanently delete a character.
	 */
	public function test_ast_cannot_delete_a_character_but_keeps_bulk_status_changes(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'be_characters',
			[
				'uuid'       => wp_generate_uuid4(),
				'name'       => 'AST Boundary Test Character',
				'stack_slug' => 'mortal',
				'owner_type' => 'chronicle',
				'owner_slug' => $this->game_a,
				'status'     => 'active',
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$character_id = (int) $wpdb->insert_id;

		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_a_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		$delete = $this->dispatch( 'DELETE', '/be/v1/' . $this->game_a . '/characters/' . $character_id );
		$this->assertSame( 403, $delete->get_status(), 'an AST must not be able to permanently delete a character' );

		$bulk = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/characters/bulk-status', [
			'character_ids' => [ $character_id ],
			'status'        => 'inactive',
		] );
		$this->assertNotSame( 403, $bulk->get_status(), 'an AST must keep the bulk status/XP/reset operations item 27 says they keep' );
	}

	/**
	 * Positive control for all three narrowings above: an HST must still be able to do every one of them, in the same
	 * chronicle, the same way as before.
	 */
	public function test_hst_still_manages_approval_rules_catalog_and_character_deletion(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'be_characters',
			[
				'uuid'       => wp_generate_uuid4(),
				'name'       => 'HST Control Test Character',
				'stack_slug' => 'mortal',
				'owner_type' => 'chronicle',
				'owner_slug' => $this->game_a,
				'status'     => 'active',
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$character_id = (int) $wpdb->insert_id;

		// game_a's editor is already hst (setUp()).
		wp_set_current_user( $this->editor_id );

		$rules = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/approval-rules' );
		$this->assertNotSame( 403, $rules->get_status() );

		$catalog = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/schema-blocks' );
		$this->assertNotSame( 403, $catalog->get_status() );

		$delete = $this->dispatch( 'DELETE', '/be/v1/' . $this->game_a . '/characters/' . $character_id );
		$this->assertNotSame( 403, $delete->get_status() );
	}

	/**
	 * A site editor is denied on a chronicle they are not a member of.
	 */
	public function test_editor_is_denied_on_a_chronicle_they_are_not_a_member_of(): void {
		wp_set_current_user( $this->editor_id );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_b . '/changes' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The same leak, one layer down: `be_view_characters` is granted to every WP role from subscriber up
	 * (`Capabilities::CAPS`).
	 */
	public function test_player_reaches_their_own_chronicle(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_a . '/characters' );
		$this->assertNotSame( 403, $response->get_status() );
	}

	public function test_player_is_denied_on_a_chronicle_they_are_not_a_member_of(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_b . '/characters' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * `be_manage_games` (site administrator) is step 2 of check_request()'s resolution order, before game membership is
	 * ever consulted.
	 */
	public function test_site_administrator_bypasses_membership_entirely(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_b . '/changes' );
		$this->assertNotSame( 403, $response->get_status() );
	}

	public function test_non_game_scoped_route_is_unaffected_by_chronicle_membership(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( 'GET', '/be/v1/games' );
		$this->assertNotSame( 403, $response->get_status() );
	}

	public function test_anonymous_is_denied_regardless_of_game(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_a . '/changes' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Direct test of Schema::backfill_game_members() itself.
	 */
	public function test_backfill_reproduces_both_manager_and_player_access(): void {
		delete_option( 'be_game_members_backfilled' );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'be_game_members WHERE game_id IN (%d,%d)', $this->game_a_id, $this->game_b_id ) );

		$dual_role_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		$wpdb->insert(
			$wpdb->prefix . 'be_characters',
			[
				'uuid'         => wp_generate_uuid4(),
				'name'         => 'Backfill Test Character',
				'stack_slug'   => 'mortal',
				'owner_type'   => 'chronicle',
				'owner_slug'   => $this->game_a,
				'wp_user_id'   => $dual_role_id,
				'status'       => 'active',
				'created_by'   => 1,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			]
		);

		Schema::backfill_game_members();

		$member = Game_Member::find( $this->game_a_id, $dual_role_id );
		$this->assertNotNull( $member );
		$this->assertSame( 'hst', $member->role, 'a manager who also owns a character keeps hst, not downgraded to player' );

		$this->assertTrue( (bool) get_option( 'be_game_members_backfilled' ) );

		Schema::backfill_game_members();
		$rows = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'be_game_members WHERE game_id = %d AND wp_user_id = %d',
				$this->game_a_id,
				$dual_role_id
			)
		);
		$this->assertSame( '1', (string) $rows );
	}

	/**
	 * The bootstrap gap found reconciling `feat/0.9a-authz` with `main`: once membership is required for every
	 * game-scoped capability, a player with no prior relationship to a chronicle could never obtain their first
	 * membership row.
	 */
	public function test_a_brand_new_player_can_ask_to_join_and_a_storyteller_makes_them_a_member(): void {
		\BeyondElysium\Models\Creature_Stack::create( [
			'slug'             => 'thread-test-bootstrap-stack',
			'name'             => 'Thread Test Bootstrap Stack',
			'stack_definition' => [ 'sections' => [] ],
		] );

		$brand_new_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertNull( Game_Member::find( $this->game_a_id, $brand_new_id ), 'precondition: genuinely no membership anywhere' );

		wp_set_current_user( $brand_new_id );
		$response = $this->dispatch( 'POST', "/be/v1/{$this->game_a}/characters", [
			'name'       => 'Bootstrap Test Character',
			'stack_slug' => 'thread-test-bootstrap-stack',
		] );

		$this->assertSame( 201, $response->get_status(), 'a player with zero prior membership must still be able to start their first character' );
		$this->assertSame( 'pending', $response->get_data()->status );
		$this->assertNull( Game_Member::find( $this->game_a_id, $brand_new_id ), 'no membership until a Storyteller approves' );

		\BeyondElysium\Models\Character::update_header( (int) $response->get_data()->id, [ 'status' => 'active' ] );

		$member = Game_Member::find( $this->game_a_id, $brand_new_id );
		$this->assertNotNull( $member, 'approving the character grants real player membership' );
		$this->assertSame( 'player', $member->role );

		// The grant is real: an ordinary game-scoped request (no bootstrap flag) now succeeds on its own.
		$list = $this->dispatch( 'GET', "/be/v1/{$this->game_a}/characters" );
		$this->assertSame( 200, $list->get_status() );
	}

	/**
	 * The other half of the same gap: membership has to follow ownership on every assignment.
	 */
	public function test_assigning_an_existing_character_grants_membership_to_the_new_owner(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'be_characters',
			[
				'uuid'       => wp_generate_uuid4(),
				'name'       => 'Reassignment Test Character',
				'stack_slug' => 'mortal',
				'owner_type' => 'chronicle',
				'owner_slug' => $this->game_a,
				'wp_user_id' => null,
				'status'     => 'active',
				'created_by' => 1,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$character_id = (int) $wpdb->insert_id;

		$new_owner_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertNull( Game_Member::find( $this->game_a_id, $new_owner_id ) );

		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'PUT', "/be/v1/{$this->game_a}/characters/{$character_id}", [
			'wp_user_id' => $new_owner_id,
		] );
		$this->assertSame( 200, $response->get_status() );

		$member = Game_Member::find( $this->game_a_id, $new_owner_id );
		$this->assertNotNull( $member, 'assigning an existing character must grant the new owner membership, not just update the row' );
		$this->assertSame( 'player', $member->role );

		wp_set_current_user( $new_owner_id );
		$read = $this->dispatch( 'GET', "/be/v1/{$this->game_a}/characters/{$character_id}" );
		$this->assertSame( 200, $read->get_status(), 'the newly-assigned player must be able to read their own character immediately' );
	}

	public function test_the_accessSchema_role_path_is_normalized_to_match_its_own_slug_convention(): void {
		require_once __DIR__ . '/fixtures/fake-owc-asc-check-access.php';
		global $be_test_captured_role_paths;
		$be_test_captured_role_paths = [];

		update_option( 'be_asc_enabled', true );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'asc_role_path' => 'Chronicle/ASC Normalize Test!' ], [ 'id' => $this->game_a_id ] );

		wp_set_current_user( $this->editor_id ); // already hst of game_a (setUp())
		$this->dispatch( 'GET', "/be/v1/{$this->game_a}/characters" );

		// be_view_characters (this route's capability) is granted by more than one role.
		$this->assertNotEmpty( $be_test_captured_role_paths, 'owc_asc_check_access() must actually have been called' );
		foreach ( $be_test_captured_role_paths as $attempted ) {
			// "Chronicle/ASC Normalize Test!/{role}" run through sanitize_title() per segment.
			$this->assertMatchesRegularExpression( '#^chronicle/asc-normalize-test/[a-z]+$#', $attempted );
		}
		$this->assertContains( 'chronicle/asc-normalize-test/hst', $be_test_captured_role_paths );

		update_option( 'be_asc_enabled', false );
	}

	public function test_the_same_capability_checked_twice_makes_one_accessSchema_call(): void {
		require_once __DIR__ . '/fixtures/fake-owc-asc-check-access.php';
		global $be_test_asc_call_count;
		$be_test_asc_call_count = 0;

		update_option( 'be_asc_enabled', true );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'asc_role_path' => 'Chronicle/Memo Test' ], [ 'id' => $this->game_a_id ] );

		wp_set_current_user( $this->editor_id ); // hst of game_a (setUp())
		$this->dispatch( 'GET', "/be/v1/{$this->game_a}/characters" );
		$after_first = $be_test_asc_call_count;
		$this->assertGreaterThan( 0, $after_first, 'the accessSchema stub must actually have been called at least once' );

		$this->dispatch( 'GET', "/be/v1/{$this->game_a}/characters" );
		$this->assertSame(
			$after_first,
			$be_test_asc_call_count,
			'a second check of the same capability must make zero additional accessSchema calls - the memo must be reused.'
		);

		update_option( 'be_asc_enabled', false );
	}

	public function test_deleting_a_user_removes_every_membership_row_they_held(): void {
		$doomed_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_a_id, $doomed_id, 'player' );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_b_id, $doomed_id, 'narrator' );

		$this->assertCount( 2, \BeyondElysium\Models\Game_Member::for_user( $doomed_id ) );

		wp_delete_user( $doomed_id );

		$this->assertSame( [], \BeyondElysium\Models\Game_Member::for_user( $doomed_id ), 'every membership row for the deleted user must be gone, in every game' );
	}
}
