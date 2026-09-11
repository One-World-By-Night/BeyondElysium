<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * workflow-0.9.md Step 1: every registered `/be/v1` route x four personas, dispatched
 * through the real REST server against raw HTTP responses - not a vibe.
 *
 * `tests/integration/` doesn't exist and was never meant to: `TESTING.md` deliberately
 * defines exactly three layers (unit/thread/workflow), and Step 1h's own file path
 * predates that decision - this lives in `tests/thread/` instead, the layer whose own
 * definition ("one full path through the stack, needs WordPress + MariaDB") already
 * describes exactly what this test does.
 *
 * Personas map onto real WP roles, since `Authorization::check()` reduces to
 * `current_user_can()` in this environment - no controller anywhere passes a
 * `role_path`, so the accessSchema branch is never entered today (Step 1d's own
 * finding, not invented here). The doc's five personas ("anonymous, logged-in with no
 * chronicle role, player, AST, HST") collapse to four *testable* ones here: every WP
 * role from subscriber up already carries `be_view_characters`/`be_edit_own_characters`
 * (Decision 023's own capability table), so "logged in, no chronicle role" and "player"
 * are indistinguishable by capability alone - the real difference between them is
 * object-level ownership, covered separately below, not a capability gate.
 *
 *   anonymous      - not logged in
 *   player         - subscriber, owns exactly one character in the fixture game
 *   st (editor)    - be_manage_characters/plots/world_objects/connections/run_queries,
 *                    NOT be_manage_games/schemas/templates/be_import
 *   admin          - every capability
 *
 * Each route is dispatched with a placeholder/nonexistent id or slug rather than a
 * fully valid payload - `permission_callback` always runs before the route handler, so
 * a denied persona gets 403 regardless, and an allowed persona gets whatever the
 * business logic returns (404/400/200) - never 403. That is the one thing this test
 * asserts: the permission boundary, not full business-logic correctness, which the
 * controller-specific thread tests already cover.
 *
 * @see BE_PROCESS/workflow-0.9.md Step 1
 */
class PermissionMatrixTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-matrix-game';
	private int $game_id;
	private int $player_id;
	private int $st_id;
	private int $admin_id;
	// Step 1.5f's own fifth persona (workflow-0.9.md): "member of this chronicle vs.
	// member of another." Same capability as $st_id, deliberately membered into a
	// DIFFERENT game - proves the permission gate checks THIS chronicle's own membership,
	// not merely "is this user an ST of anything, anywhere."
	private string $other_game_slug = 'thread-test-matrix-foreign-game';
	private int $other_game_id;
	private int $foreign_st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Matrix Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->other_game_slug, 'name' => 'Thread Test Matrix Other Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->other_game_id = (int) $wpdb->insert_id;

		$this->player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->st_id        = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->admin_id     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->foreign_st_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		// Step 1.5, workflow-0.9.md: a game-scoped route now needs BOTH the site-wide WP
		// capability (asserted below via user_can()) AND chronicle membership. `admin_id`
		// needs neither - be_manage_games bypasses membership entirely
		// (Authorization::check_request()'s step 2), matching a real site administrator.
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $this->st_id, 'hst' );
		// Deliberately membered into the OTHER game only, never this one.
		\BeyondElysium\Models\Game_Member::set_role( $this->other_game_id, $this->foreign_st_id, 'hst' );
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

	/**
	 * @return array<string,array{0:string,1:string,2:string|string[],3?:array<string,mixed>}>
	 *         label => [method, route, required capability (or capabilities, for a
	 *         `permission_any` gate), request params]
	 *
	 * A route with a WP-declared *required* arg (`'required' => true` in its own
	 * `get_create_params()`) needs real params here - WordPress validates required
	 * params BEFORE calling `permission_callback` (`WP_REST_Server::dispatch()`'s own
	 * order), so a denied persona hitting one of these with an empty body gets `400`
	 * from that validation layer, never reaching the permission check this test exists
	 * to exercise. Every other route is dispatched with a placeholder/nonexistent id
	 * and no body on purpose - `permission_callback` still runs first there.
	 */
	public function routes(): array {
		$g = $this->game_slug;

		return [
			// GET (list) endpoints - be_view_characters, every logged-in role has it.
			'GET games'           => [ 'GET', '/be/v1/games', 'be_view_characters' ],
			'GET schema-blocks'   => [ 'GET', '/be/v1/schema-blocks', 'be_view_characters' ],
			'GET creature-stacks' => [ 'GET', '/be/v1/creature-stacks', 'be_view_characters' ],
			'GET characters'      => [ 'GET', "/be/v1/{$g}/characters", 'be_view_characters' ],
			'GET character changes (per-character)' => [ 'GET', "/be/v1/{$g}/characters/999999/changes", 'be_view_characters' ],
			'GET templates (global)' => [ 'GET', '/be/v1/templates', 'be_view_characters' ],
			'GET plots'           => [ 'GET', "/be/v1/{$g}/plots", 'be_view_characters' ],
			'GET connections'     => [ 'GET', "/be/v1/{$g}/connections", 'be_view_characters' ],
			'GET world-objects'   => [ 'GET', "/be/v1/{$g}/world-objects", 'be_view_characters' ],
			'GET boons'           => [ 'GET', "/be/v1/{$g}/boons", 'be_view_characters' ],
			'GET query-fields'    => [ 'GET', '/be/v1/query-fields', 'be_view_characters' ],

			// Admin-only management. Required params supplied so validation doesn't mask
			// the permission check for a denied persona.
			'POST games'          => [ 'POST', '/be/v1/games', 'be_manage_games', [ 'name' => 'Matrix Test Game' ] ],
			'DELETE games'        => [ 'DELETE', '/be/v1/games/nonexistent-slug', 'be_manage_games' ],
			'POST schema-blocks'  => [ 'POST', '/be/v1/schema-blocks', 'be_manage_schemas', [ 'slug' => 'matrix-test-block', 'name' => 'Matrix Test Block', 'section_type' => 'trait_list' ] ],
			'DELETE schema-blocks' => [ 'DELETE', '/be/v1/schema-blocks/nonexistent', 'be_manage_schemas' ],
			'POST creature-stacks' => [ 'POST', '/be/v1/creature-stacks', 'be_manage_schemas', [ 'slug' => 'matrix-test-stack', 'name' => 'Matrix Test Stack', 'stack_definition' => [] ] ],
			'POST templates'      => [ 'POST', '/be/v1/templates', 'be_manage_templates' ],
			'DELETE templates'    => [ 'DELETE', '/be/v1/templates/999999', 'be_manage_templates' ],
			'POST import parse'   => [ 'POST', "/be/v1/{$g}/import/parse", 'be_import' ],

			// ST-level management (editor+admin).
			'DELETE characters'   => [ 'DELETE', "/be/v1/{$g}/characters/999999", 'be_manage_characters' ],
			'GET changes queue'   => [ 'GET', "/be/v1/{$g}/changes", 'be_manage_characters' ],
			'PUT changes'         => [ 'PUT', "/be/v1/{$g}/changes/999999", 'be_manage_characters' ],
			'POST batch-approve'  => [ 'POST', "/be/v1/{$g}/changes/batch-approve", 'be_manage_characters' ],
			'POST bulk-award'     => [ 'POST', "/be/v1/{$g}/experience/bulk-award", 'be_manage_characters' ],
			'DELETE plots'        => [ 'DELETE', "/be/v1/{$g}/plots/999999", 'be_manage_plots' ],
			'POST allocate-actions' => [ 'POST', "/be/v1/{$g}/plots/allocate-actions", 'be_manage_plots' ],
			'POST connections'    => [ 'POST', "/be/v1/{$g}/connections", 'be_manage_connections' ],
			'POST world-objects'  => [ 'POST', "/be/v1/{$g}/world-objects", 'be_manage_world_objects' ],
			'POST boons'          => [ 'POST', "/be/v1/{$g}/boons", 'be_manage_world_objects' ],
			'POST query'          => [ 'POST', "/be/v1/{$g}/query", 'be_run_queries' ],
			'POST statistics'     => [ 'POST', "/be/v1/{$g}/statistics", 'be_run_queries' ],

			// Self-service (every logged-in role, including a bare player).
			'POST characters (create own)' => [ 'POST', "/be/v1/{$g}/characters", 'be_edit_own_characters' ],
			'POST changes (submit own)'    => [ 'POST', "/be/v1/{$g}/characters/999999/changes", 'be_edit_own_characters' ],

			// permission_any - a player can reach this via be_submit_actions even
			// though they don't hold be_manage_plots (Decision 016's "everything is a
			// plot" collapse - a player submitting their own action/rumor uses this
			// same endpoint an ST uses to create a plot outright).
			'POST plots' => [ 'POST', "/be/v1/{$g}/plots", [ 'be_submit_actions', 'be_manage_plots' ] ],
		];
	}

	/**
	 * @dataProvider routes
	 * @param string|string[] $capability
	 * @param array<string,mixed> $params
	 */
	public function test_permission_boundary( string $method, string $route, $capability, array $params = [] ): void {
		$capabilities = (array) $capability;
		$personas     = [
			'anonymous'  => null,
			'player'     => $this->player_id,
			'st'         => $this->st_id,
			// Step 1.5f's fifth axis: the SAME capability as 'st', held by a user who is
			// a member of a DIFFERENT chronicle, never this one. A route with no
			// game_slug at all (games/schema-blocks/creature-stacks/templates) is
			// unaffected by chronicle membership - identical to 'st' there. A
			// game-scoped route must deny this persona regardless of capability, which
			// is exactly what distinguishes this axis from 'st' and is the one new thing
			// this loop iteration proves that the other four personas cannot.
			'foreign_st' => $this->foreign_st_id,
			'admin'      => $this->admin_id,
		];
		// Same test both ways of detecting it: a game-scoped route names this fixture's
		// own game_slug in its path.
		$is_game_scoped = ( false !== strpos( $route, "/{$this->game_slug}/" ) );

		foreach ( $personas as $persona => $user_id ) {
			if ( $user_id === null ) {
				wp_set_current_user( 0 );
				$allowed = false;
			} else {
				wp_set_current_user( $user_id );
				$allowed = false;
				foreach ( $capabilities as $cap ) {
					if ( user_can( $user_id, $cap ) ) {
						$allowed = true;
						break;
					}
				}
				// Character creation is deliberately exempt from pre-existing membership
				// (Step 1.5d's $allow_bootstrap - see Characters_Controller::register_routes()'s
				// own comment): a user's first character in a chronicle is what GRANTS
				// their membership, so this one route cannot itself require it, or
				// nobody could ever obtain their first membership row. Every other
				// game-scoped route still must deny a real-capability, wrong-chronicle
				// user - that is the one thing this axis exists to prove.
				if ( 'foreign_st' === $persona && $is_game_scoped && 'POST characters (create own)' !== $this->dataName() ) {
					$allowed = false; // Real capability, real membership - just the wrong chronicle.
				}
			}

			$status = $this->dispatch( $method, $route, $params )->get_status();
			$caps_label = implode( '|', $capabilities );

			if ( $allowed ) {
				$this->assertNotSame(
					403,
					$status,
					"{$method} {$route} ({$caps_label}): {$persona} should be allowed past the permission gate, got 403."
				);
			} else {
				$this->assertSame(
					403,
					$status,
					"{$method} {$route} ({$caps_label}): {$persona} should be denied, got {$status}."
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// 1a/1b: route enumeration - zero real unguarded routes.
	// -------------------------------------------------------------------------

	public function test_every_be_route_has_a_permission_callback_except_the_wp_core_index(): void {
		$server = rest_get_server();
		$open   = [];

		foreach ( $server->get_routes() as $route => $handlers ) {
			if ( strpos( $route, '/be/v1' ) !== 0 ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( empty( $handler['permission_callback'] ) ) {
					$open[] = $route;
				}
			}
		}

		// The one real hit is WordPress core's own auto-generated namespace index
		// (WP_REST_Server::get_namespace_index(), GET /be/v1) - it returns route
		// metadata only (methods, arg schemas), never character/game/plot data, and
		// every REST namespace on every WP site gets one automatically; it is not a
		// route this plugin registers or could usefully guard.
		$this->assertSame( [ '/be/v1' ], $open, 'Every BE-registered route must have a permission_callback.' );
	}

	// -------------------------------------------------------------------------
	// 1g: object-level ownership - capability alone is not enough.
	// -------------------------------------------------------------------------

	public function test_a_player_cannot_read_another_players_character(): void {
		$owner_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$other_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Both are real members of this chronicle - what this test isolates is D33's
		// object-level ownership check specifically, not chronicle membership (Step 1.5).
		// Without this, other_id's expected 403 would be ambiguous between "not a member
		// of this chronicle at all" and "a member, but not this character's owner."
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $owner_id, 'player' );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $other_id, 'player' );

		$character_id = \BeyondElysium\Models\Character::create( [
			'name' => 'Ownership Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $owner_id,
		] );

		wp_set_current_user( $other_id );
		$response = $this->dispatch( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$this->assertSame( 403, $response->get_status(), 'A player must not be able to edit another player\'s character.' );

		wp_set_current_user( $owner_id );
		$response = $this->dispatch( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$this->assertNotSame( 403, $response->get_status(), 'The real owner must be able to read their own character.' );
	}

	public function test_a_player_cannot_reach_another_games_data_by_swapping_the_slug(): void {
		global $wpdb;
		$other_slug = 'thread-test-matrix-other-game';
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $other_slug, 'name' => 'Other Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$owner_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = \BeyondElysium\Models\Character::create( [
			'name' => 'Cross-Game Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $other_slug, 'wp_user_id' => $owner_id,
		] );

		// A real member of $this->game_slug (the URL actually dispatched below) - not of
		// $other_slug, where the character actually lives. This is deliberate: the
		// permission gate (Step 1.5) must pass on the strength of THIS game's membership
		// alone, so the 404 asserted below comes from the handler's owner_slug-mismatch
		// check, not from the new chronicle-membership gate denying them a 403 first.
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $owner_id, 'player' );

		// The same character id, requested through the WRONG game's URL - the
		// controller must resolve ownership against the real owner_slug, not just the
		// numeric id, or a player could read/edit a character by guessing an id and
		// swapping which game's route they hit.
		wp_set_current_user( $owner_id );
		$response = $this->dispatch( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$this->assertSame( 404, $response->get_status(), 'A character must not be reachable through a different game\'s route.' );
	}

	// -------------------------------------------------------------------------
	// 1f: field-level visibility, checked in raw responses.
	// -------------------------------------------------------------------------

	public function test_rp_notes_is_st_only_and_notes_st_markers_are_stripped_for_a_non_manager(): void {
		$owner_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $owner_id, 'player' );
		$character_id = \BeyondElysium\Models\Character::create( [
			'name' => 'Field Visibility Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $owner_id,
			'rp_notes' => 'Secret ST-only roleplay notes.',
			'notes'    => 'Visible background. [ST]Hidden ST aside.[/ST] More visible text.',
		] );

		// The owner (a plain player) must never receive rp_notes at all, and must
		// receive `notes` with the [ST]...[/ST] marker section removed, not the whole
		// field blanked - St_Filter strips markers, Characters_Controller strips the
		// whole rp_notes field, and they are not the same rule.
		wp_set_current_user( $owner_id );
		$data = $this->dispatch( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" )->get_data();
		$this->assertFalse( property_exists( $data, 'rp_notes' ), 'A non-manager must never receive rp_notes at all.' );
		$this->assertStringNotContainsString( 'Hidden ST aside', $data->notes );
		$this->assertStringContainsString( 'Visible background', $data->notes );
		$this->assertStringContainsString( 'More visible text', $data->notes );

		// An ST (be_manage_characters) sees everything, unfiltered.
		wp_set_current_user( $this->st_id );
		$data = $this->dispatch( 'GET', "/be/v1/{$this->game_slug}/characters/{$character_id}" )->get_data();
		$this->assertSame( 'Secret ST-only roleplay notes.', $data->rp_notes );
		$this->assertStringContainsString( 'Hidden ST aside', $data->notes );
	}

	public function test_st_notes_on_plots_is_st_only(): void {
		global $wpdb;
		$game_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}be_games WHERE slug = %s", $this->game_slug
		) );
		$plot_id = \BeyondElysium\Models\Plot::create( [
			'game_id' => $game_id, 'title' => 'Field Visibility Test Plot',
			'st_notes' => 'Secret ST-only plot notes.',
		] );

		wp_set_current_user( $this->player_id );
		$data = $this->dispatch( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" )->get_data();
		$this->assertFalse( property_exists( $data, 'st_notes' ), 'A non-manager must never receive st_notes at all.' );

		wp_set_current_user( $this->st_id );
		$data = $this->dispatch( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" )->get_data();
		$this->assertSame( 'Secret ST-only plot notes.', $data->st_notes );
	}

	public function test_a_player_cannot_approve_their_own_change(): void {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = \BeyondElysium\Models\Character::create( [
			'name' => 'Self-Approve Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $player_id,
		] );
		$change_id = \BeyondElysium\Models\Change::create( [
			'character_id' => $character_id, 'change_type' => 'modify_identity', 'category' => 'vampire-identity',
			'change_data' => [ 'block_slug' => 'vampire-identity', 'fields' => [ 'Title' => 'Whip' ] ],
			'xp_cost' => 0, 'status' => 'pending', 'submitted_by' => $player_id,
		] );

		wp_set_current_user( $player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status(), 'A player must not be able to approve their own change, even by calling the endpoint directly.' );
	}
}
