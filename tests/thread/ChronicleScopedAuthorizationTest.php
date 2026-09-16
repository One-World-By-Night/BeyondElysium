<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Step 1.5, workflow-0.9.md - the security gap this whole sub-phase exists to close:
 * `Base_Controller::permission()`'s closure took no arguments and could not see which
 * chronicle a request was for, so a site-wide `be_manage_characters` (or even the
 * broadly-granted `be_view_characters`/`be_edit_own_characters`) reached EVERY chronicle
 * on the site, not just the one the requester actually belongs to.
 *
 * `PermissionMatrixTest` proves every route has SOME gate. This proves the gate is now
 * scoped to the right chronicle - the thing that test's own doc comment explicitly says
 * was out of scope ("no controller anywhere passes a role_path... the accessSchema
 * branch is never entered today").
 *
 * Runs entirely through the capability + `be_game_members` path (accessSchema disabled,
 * the default and the only path any real environment this project has ever run in has
 * exercised) - the four-state accessSchema matrix itself is Step 1.5f, which needs a
 * real accessSchema instance actually installed and active, not simulated here.
 *
 * @see BE_PROCESS/workflow-0.9.md Step 1.5
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

		// Editor is HST of game A only - deliberately given no membership in game B at all.
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

	/**
	 * The user's own explicit role model (2026-09-11): "AST can do everything but delete
	 * and edit game." Two real, previously-untested gaps this closed: `game-roles.php`
	 * excluded `be_import` from `ast` specifically (not part of the stated model - an AST
	 * could not import a Grapevine file into their own chronicle), and `Capabilities::CAPS`
	 * capped `be_import` at the WordPress `administrator` role only, so even an AST whose
	 * `game-roles.php` entry DID grant it would still fail `current_user_can()`'s own
	 * baseline gate (`Authorization::check_request()` requires both). Both fixed together;
	 * this proves the combination actually works end to end, not just one half of it.
	 *
	 * Narrowed further 2026-09-15 (1.0.0-checklist.md item 27) - see
	 * `test_ast_cannot_manage_approval_rules_for_their_own_chronicle()` and its neighbors
	 * below for the newer restrictions. "Everything but delete and edit game" is no longer
	 * the whole model; import and the game-level edit/delete boundary this test checks are
	 * both still exactly as they were.
	 */
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

	/**
	 * Owner ruling, 1.0.0-checklist.md item 27 (2026-09-15): an AST loses Chronicle Setup's
	 * own Approval Rules section for their chronicle. `be_manage_approval_rules` used to be
	 * part of the blanket `array_diff(Capabilities::all(), ['be_manage_games'])` grant every
	 * AST shared with every HST - this proves game-roles.php's own exclusion, not just that
	 * the capability exists.
	 */
	public function test_ast_cannot_manage_approval_rules_for_their_own_chronicle(): void {
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_a_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		$response = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/approval-rules' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Same ruling: an AST loses the chronicle's own catalog customization (forking a
	 * schema block for their chronicle specifically) - `be_manage_schemas`, chronicle-scoped
	 * via the exact `?game_slug=` write path GS-1 built for an HST.
	 */
	public function test_ast_cannot_customize_the_chronicles_own_catalog(): void {
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_a_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		// The route's own required-args validation runs before permission_callback in WP
		// core's own dispatch order, so a bare request 400s before the capability is ever
		// checked - a real create body is needed to actually exercise the permission gate.
		$response = $this->dispatch( 'POST', '/be/v1/' . $this->game_a . '/schema-blocks', [
			'slug'         => 'thread-test-ast-catalog-block',
			'name'         => 'Thread Test AST Catalog Block',
			'section_type' => 'trait_list',
		] );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Same ruling: an AST loses the chronicle's own template customization too - GS-1's
	 * "fork their own chronicle's catalog and templates" was always both halves together for
	 * an HST, and item 27's "the chronicle's own schema blocks and templates" (1.0.0-checklist.md's
	 * own wording) keeps them together for this exclusion as well.
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
	 * Same ruling: an AST loses the ability to permanently delete a character, but keeps
	 * every other character power the model already granted (edit, bulk XP/status/reset -
	 * 1.0.0-checklist.md item 27's own "keeps ... the bulk changes"). `be_delete_characters`
	 * is the new, narrower capability `Characters_Controller`'s DELETE route now checks
	 * instead of the broader `be_manage_characters` an AST still holds for everything else.
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
	 * Positive control for all three narrowings above: an HST (never touched by item 27)
	 * must still be able to do every one of them, in the same chronicle, the same way as
	 * before - proving the AST exclusions in game-roles.php did not accidentally reach hst.
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
	 * The core regression test for this whole sub-phase. Before Step 1.5, this editor's
	 * site-wide `be_manage_characters` alone would have passed `permission_callback` for
	 * ANY game_slug, including one they have never been added to - exactly the
	 * cross-chronicle leak `Base_Controller::permission()`'s zero-argument closure made
	 * possible. Must be 403 now.
	 */
	public function test_editor_is_denied_on_a_chronicle_they_are_not_a_member_of(): void {
		wp_set_current_user( $this->editor_id );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_b . '/changes' );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The same leak, one layer down: `be_view_characters` is granted to every WP role
	 * from subscriber up (`Capabilities::CAPS`), so an ordinary player's ability to reach
	 * ANY chronicle's roster was never actually gated on which chronicle they play in -
	 * only object-level ownership (D33) inside the handler ever stopped them from reading
	 * another PLAYER's sheet, never from reaching another CHRONICLE's roster at all.
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
	 * `be_manage_games` (site administrator) is step 2 of check_request()'s resolution
	 * order, before game membership is ever consulted - reaches every chronicle
	 * regardless of membership, by design (the site owner does not enroll themself in
	 * every chronicle just to administer the plugin).
	 */
	public function test_site_administrator_bypasses_membership_entirely(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/be/v1/' . $this->game_b . '/changes' );
		$this->assertNotSame( 403, $response->get_status() );
	}

	/**
	 * A route with no `game_slug` in its URL (the games list itself) is a deliberately
	 * unchanged path - check_request()'s step 3/4, plain current_user_can(), exactly
	 * pre-1.5 behavior. Confirms the new gate did not accidentally tighten something it
	 * was never meant to touch.
	 */
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
	 * Direct test of Schema::backfill_game_members() itself - the exact bug caught before
	 * shipping (workflow-0.9.md's own account): a manager-only backfill would have 403'd
	 * every existing player on their own character, not just changed who manages what.
	 * Exercises both passes and the INSERT IGNORE non-downgrade guarantee in one go.
	 */
	public function test_backfill_reproduces_both_manager_and_player_access(): void {
		delete_option( 'be_game_members_backfilled' );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'be_game_members WHERE game_id IN (%d,%d)', $this->game_a_id, $this->game_b_id ) );

		// A manager who is ALSO a character owner in the same game - INSERT IGNORE must
		// not let the player-owner pass downgrade their hst role from the manager pass.
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

		// Idempotency: running it again must not error or duplicate rows (UNIQUE key +
		// the option guard both cover this, but the guard is what should actually stop it
		// from even trying a second time).
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
	 * The bootstrap gap found reconciling `feat/0.9a-authz` with `main` (2026-09-11): once
	 * membership is required for every game-scoped capability, a player with no prior
	 * relationship to a chronicle could never obtain their first membership row, because
	 * every route that could grant one already required one. Character creation is the one
	 * route this must not apply to (`Base_Controller::permission( ..., true )` on the POST
	 * route only) - proven here against a user who is a member of NEITHER game, not just
	 * "no member of game B" like the rest of this file's fixtures.
	 *
	 * Since 1.0.0-review F-033 (owner ruling) that first character is a join request: it waits,
	 * pending, and membership follows when a Storyteller sets it active.
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
	 * The other half of the same gap: membership has to follow ownership on every
	 * assignment, not just at character-creation time, or an ST assigning an EXISTING
	 * character to a player leaves that player unable to see the character they were just
	 * given. Exercises `Character::update_header()`'s own call, not `create()`'s.
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

	/**
	 * A real bug found running Step 1.5f live against an actually-installed accessSchema
	 * instance (2026-09-11, see Authorization::normalize_role_path()'s own doc comment for
	 * the full account): `accessSchema_register_path()` slugifies every path segment
	 * through `sanitize_title()` when a role is created, but the real grant-matching
	 * compares with a strict PHP `in_array(..., true)` - not the case-insensitive MySQL
	 * lookup a DIFFERENT accessSchema function happens to use. `$game->asc_role_path` is
	 * stored human-readable ("Chronicle/KONY") and the role suffix gets uppercased for the
	 * same reason - neither matches accessSchema's own lowercase-slugified convention, so
	 * every accessSchema grant would have silently never matched on a real install. No
	 * accessSchema instance exists in this test environment (confirmed, `PLATFORM.md`), so
	 * this stubs `owc_asc_check_access()` to capture the exact role_path it was called
	 * with, rather than asserting a real grant/deny outcome.
	 */
	public function test_the_accessSchema_role_path_is_normalized_to_match_its_own_slug_convention(): void {
		require_once __DIR__ . '/fixtures/fake-owc-asc-check-access.php';
		global $be_test_captured_role_paths;
		$be_test_captured_role_paths = [];

		update_option( 'be_asc_enabled', true );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'asc_role_path' => 'Chronicle/ASC Normalize Test!' ], [ 'id' => $this->game_a_id ] );

		wp_set_current_user( $this->editor_id ); // already hst of game_a (setUp())
		$this->dispatch( 'GET', "/be/v1/{$this->game_a}/characters" );

		// be_view_characters (this route's capability) is granted by more than one role, so
		// check_request()'s own loop tries each in turn when the stub always denies -
		// asserting every attempted path is normalized proves the fix for all of them, not
		// just whichever happened to be tried first.
		$this->assertNotEmpty( $be_test_captured_role_paths, 'owc_asc_check_access() must actually have been called' );
		foreach ( $be_test_captured_role_paths as $attempted ) {
			// "Chronicle/ASC Normalize Test!/{role}" run through sanitize_title() per
			// segment - exactly matching how accessSchema_register_path() itself slugifies
			// a role name - so every attempt must be all-lowercase with no punctuation.
			$this->assertMatchesRegularExpression( '#^chronicle/asc-normalize-test/[a-z]+$#', $attempted );
		}
		$this->assertContains( 'chronicle/asc-normalize-test/hst', $be_test_captured_role_paths );

		update_option( 'be_asc_enabled', false );
	}

	/**
	 * Step 1.5g, workflow-0.9.md - "a request that checks the same capability twice makes
	 * ONE accessSchema call." The doc names `PerformanceTest` as the home for this, but
	 * that class's own `setUp()` unconditionally skips every test in it until a 220-
	 * character fixture has been seeded (`tests/fixtures/seed-0.9-performance-dataset.php`)
	 * - real for its own wall-clock/N+1 assertions, irrelevant and needlessly coupling for
	 * this one, which needs no seeded data at all. Placed here instead, next to the other
	 * accessSchema-stub test this file already has.
	 */
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

		// A second request checking the identical capability, same process (PHP statics
		// live for the process's lifetime, matching a single real request's own lifetime -
		// Step 1.5g's own scope, "for the life of the request").
		$this->dispatch( 'GET', "/be/v1/{$this->game_a}/characters" );
		$this->assertSame(
			$after_first,
			$be_test_asc_call_count,
			'a second check of the same capability must make zero additional accessSchema calls - the memo must be reused.'
		);

		update_option( 'be_asc_enabled', false );
	}

	/**
	 * Step 5d, workflow-0.9.md - the multisite audit's real, narrow finding: `be_game_members`
	 * stores a bare `wp_user_id`, so a deleted account now leaves an orphan row where none
	 * was possible before this table existed. `Game_Member::remove_user_everywhere()` and its
	 * `deleted_user` hook (`Plugin::init()`) were already wired when Step 1.5a-d first landed
	 * - this is the first real test proving the wiring actually works, not just that the
	 * method exists in isolation.
	 */
	public function test_deleting_a_user_removes_every_membership_row_they_held(): void {
		$doomed_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_a_id, $doomed_id, 'player' );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_b_id, $doomed_id, 'narrator' );

		$this->assertCount( 2, \BeyondElysium\Models\Game_Member::for_user( $doomed_id ) );

		wp_delete_user( $doomed_id );

		$this->assertSame( [], \BeyondElysium\Models\Game_Member::for_user( $doomed_id ), 'every membership row for the deleted user must be gone, in every game' );
	}
}
