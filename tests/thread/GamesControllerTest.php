<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;

/**
 * Step 3a/3e audit findings, both around slug collisions:
 * - create_item(): an explicit (not name-derived) colliding slug used to fall through
 *   to the database's own UNIQUE constraint and surface as a generic create_failed 500.
 * - update_item(): Game::update()'s boolean return was discarded entirely - a colliding
 *   slug silently failed the UPDATE, and the code went on to look up the *other* game
 *   that already held the requested slug, returning that game's data as if the edit had
 *   succeeded.
 */
class GamesControllerTest extends WP_UnitTestCase {

	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	private function create_game( string $slug ) {
		$request = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request->set_param( 'name', 'Game ' . $slug );
		$request->set_param( 'slug', $slug );
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_explicit_duplicate_slug_is_rejected_on_create(): void {
		$this->assertSame( 201, $this->create_game( 'thread-test-dup-game' )->get_status() );

		$response = $this->create_game( 'thread-test-dup-game' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'duplicate_slug', $response->as_error()->get_error_code() );
	}

	public function test_a_name_derived_slug_still_auto_dedupes_on_create(): void {
		$request = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request->set_param( 'name', 'Auto Dedupe Game Thread Test' );
		$first = rest_get_server()->dispatch( $request )->get_data();

		$request2 = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request2->set_param( 'name', 'Auto Dedupe Game Thread Test' );
		$response2 = rest_get_server()->dispatch( $request2 );

		$this->assertSame( 201, $response2->get_status(), 'No explicit slug was requested, so auto-dedupe (not a 409) is still correct.' );
		$this->assertNotSame( $first->slug, $response2->get_data()->slug );
	}

	public function test_renaming_a_game_to_another_games_slug_is_rejected_not_silently_wrong(): void {
		$this->create_game( 'thread-test-game-a' );
		$this->create_game( 'thread-test-game-b' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-game-a' );
		$request->set_param( 'slug', 'thread-test-game-b' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 409, $response->get_status(), 'A colliding rename must be a real 409, not a 200 carrying the OTHER game\'s data.' );
		$this->assertSame( 'duplicate_slug', $response->as_error()->get_error_code() );

		// Game A must be untouched - still reachable at its original slug, still its own name.
		$still_there = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/games/thread-test-game-a' ) )->get_data();
		$this->assertSame( 'Game thread-test-game-a', $still_there->name );
	}

	/**
	 * This test used to certify the bug as working: a rename that only ever touched
	 * be_games, leaving every character orphaned at the old owner_slug. Now asserts the
	 * cascade actually moved the character - the single most likely way Game::rename()'s
	 * fix could regress is exactly this assertion quietly being lost again.
	 */
	public function test_a_normal_rename_still_succeeds(): void {
		$this->create_game( 'thread-test-rename-source' );
		$character_id = Character::create( [
			'name'       => 'Rename Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => 'thread-test-rename-source',
		] );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-rename-source' );
		$request->set_param( 'slug', 'thread-test-rename-target' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'thread-test-rename-target', $response->get_data()->slug );

		$moved = Character::find( (int) $character_id );
		$this->assertSame( 'thread-test-rename-target', $moved->owner_slug, 'the character must follow the rename, not stay orphaned at the old slug' );
		$this->assertCount( 1, Character::all_for_game( 'thread-test-rename-target' ) );
		$this->assertCount( 0, Character::all_for_game( 'thread-test-rename-source' ) );
	}

	/**
	 * Before 1.0.0 a row-only delete left a chronicle's schema-block forks behind (D42, fixed
	 * by 1.0.0-review F-036), so an install can still hold a fork outliving its game at a slug
	 * a later chronicle then tries to rename into. That must abort with a named error, not
	 * silently merge or hit the forks table's own unique index as a generic 500.
	 */
	public function test_renaming_into_a_slug_with_an_orphaned_schema_block_fork_is_rejected(): void {
		$this->create_game( 'thread-test-fork-source' );
		Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', 'thread-test-fork-target' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-fork-source' );
		$request->set_param( 'slug', 'thread-test-fork-target' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'fork_collision', $response->as_error()->get_error_code() );

		// The orphaned fork itself must be untouched by the aborted rename.
		$fork = Schema_Block::find_for_game( 'vampire-disciplines', 'thread-test-fork-target' );
		$this->assertNotNull( $fork );
		$this->assertSame( 'thread-test-fork-target', $fork->game_slug );
	}

	public function test_update_with_a_malformed_settings_payload_is_rejected(): void {
		$this->create_game( 'thread-test-settings-game' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-settings-game' );
		$request->set_param( 'settings', 'not an object' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), 'The PUT route previously had no args schema at all, so this reached Game::update() unvalidated.' );
	}

	/**
	 * Step 1.5c/1.5e (workflow-0.9.md) - asc_role_path is a D27-class field: present on
	 * neither this controller's own field list nor Game::update()'s allowlist until this
	 * fix, so it would have been silently dropped with no error on either layer.
	 */
	public function test_asc_role_path_can_be_set_and_read_back(): void {
		$this->create_game( 'thread-test-asc-role-path-game' );

		$request = new WP_REST_Request( 'PUT', '/be/v1/games/thread-test-asc-role-path-game' );
		$request->set_param( 'asc_role_path', 'Chronicle/KONY' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Chronicle/KONY', $response->get_data()->asc_role_path );

		$refetched = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/games/thread-test-asc-role-path-game' ) )->get_data();
		$this->assertSame( 'Chronicle/KONY', $refetched->asc_role_path );
	}

	// -------------------------------------------------------------------------
	// GET /my/games (page-consolidation-design.md) - the real chronicle-switcher
	// data source, never the full collection.
	// -------------------------------------------------------------------------

	public function test_my_games_returns_only_real_memberships_with_the_real_role(): void {
		$game_a = $this->create_game( 'thread-test-my-games-a' )->get_data();
		$game_b = $this->create_game( 'thread-test-my-games-b' )->get_data();
		// A third real chronicle the test player holds no membership in at all.
		$this->create_game( 'thread-test-my-games-c' );

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $game_a->id, $player, 'hst' );
		Game_Member::set_role( (int) $game_b->id, $player, 'player' );

		wp_set_current_user( $player );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/games' ) );
		$this->assertSame( 200, $response->get_status() );

		$games = $response->get_data();
		$this->assertCount( 2, $games, 'the third, no-membership chronicle must not appear' );

		$by_slug = [];
		foreach ( $games as $g ) {
			$by_slug[ $g['slug'] ] = $g['role'];
		}
		$this->assertSame( 'hst', $by_slug['thread-test-my-games-a'] );
		$this->assertSame( 'player', $by_slug['thread-test-my-games-b'] );
	}

	public function test_my_games_is_empty_for_a_user_with_no_memberships_not_every_chronicle(): void {
		$this->create_game( 'thread-test-my-games-lonely' );

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/games' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data(), 'must never fall back to the full games collection' );
	}

	public function test_my_games_works_for_a_plain_subscriber_not_just_a_manager(): void {
		$game = $this->create_game( 'thread-test-my-games-subscriber' )->get_data();

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $game->id, $player, 'player' );
		wp_set_current_user( $player );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/games' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// GET /{game_slug}/my/capabilities (page-consolidation-design.md) - the exact
	// scenario a chronicle switcher exists to make safe: an HST in one chronicle,
	// nothing at all in another.
	// -------------------------------------------------------------------------

	public function test_capabilities_reflect_hst_in_one_chronicle_and_nothing_in_another(): void {
		$hst_chronicle     = $this->create_game( 'thread-test-caps-hst-game' )->get_data();
		$stranger_chronicle = $this->create_game( 'thread-test-caps-stranger-game' )->get_data();

		// A real HST needs both layers: a WP role that actually holds the raw
		// be_manage_characters/be_manage_plots capability (Capabilities.php grants those
		// to editor+ only, never subscriber), plus the chronicle-scoped 'hst' membership
		// row that Authorization::check_request() uses to decide WHICH chronicle it
		// applies to. A subscriber can never pass regardless of their game_members role -
		// this is the real two-layer model, not a test bug to work around.
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $hst_chronicle->id, $user, 'hst' );
		wp_set_current_user( $user );

		$hst_response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', "/be/v1/{$hst_chronicle->slug}/my/capabilities" )
		);
		$this->assertSame( 200, $hst_response->get_status() );
		$hst_caps = $hst_response->get_data()['capabilities'];
		$this->assertTrue( $hst_caps['be_manage_characters'] );
		$this->assertTrue( $hst_caps['be_manage_plots'] );

		$stranger_response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', "/be/v1/{$stranger_chronicle->slug}/my/capabilities" )
		);
		$this->assertSame( 200, $stranger_response->get_status() );
		$stranger_caps = $stranger_response->get_data()['capabilities'];
		$this->assertFalse( $stranger_caps['be_manage_characters'], 'an HST in one chronicle must not be treated as one in another' );
		$this->assertFalse( $stranger_caps['be_manage_plots'] );
	}

	public function test_capabilities_for_a_boons_only_role_are_narrow(): void {
		$game = $this->create_game( 'thread-test-caps-boons-game' )->get_data();

		$harpy = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $game->id, $harpy, 'boons' );
		wp_set_current_user( $harpy );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$game->slug}/my/capabilities" ) );
		$caps     = $response->get_data()['capabilities'];

		$this->assertTrue( $caps['be_manage_boons'] );
		$this->assertFalse( $caps['be_manage_characters'] );
		$this->assertFalse( $caps['be_manage_plots'] );
	}

	public function test_capabilities_for_an_unresolvable_game_slug_are_all_false_not_an_error(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $player );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/not-a-real-chronicle/my/capabilities' ) );
		$this->assertSame( 200, $response->get_status() );

		foreach ( $response->get_data()['capabilities'] as $can ) {
			$this->assertFalse( $can );
		}
	}

	public function test_capabilities_route_denies_a_logged_out_visitor_outright(): void {
		// The route's own gate is a bare is_user_logged_in(), not a specific capability -
		// its whole job is to determine capabilities, so it can't be gated by one of them.
		// A fully anonymous visitor is denied at that gate before Authorization::
		// check_request() ever runs, distinct from the "resolvable route, no real
		// relationship to this chronicle" case above, which always answers 200 with every
		// flag false.
		$game = $this->create_game( 'thread-test-caps-logged-out-game' )->get_data();
		$slug = $game->slug;

		wp_set_current_user( 0 );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$slug}/my/capabilities" ) );
		$this->assertSame( 401, $response->get_status(), 'is_user_logged_in() gate on the route itself denies an anonymous visitor' );
	}

	/**
	 * 1.0.0-review checklist item 22 (Chronicle Setup's new "Plot Features" switch).
	 * `settings.plots` is a new, one-level-deeper key than `enabled_stacks`/`auto_approve` -
	 * confirms the same merge (`Games_Controller::update_item()`) round-trips a nested
	 * object just as cleanly, and that saving it doesn't erase an unrelated sibling key
	 * already in `settings`, exactly as `enabled_stacks` never erases `auto_approve`.
	 */
	public function test_saving_expanded_plots_via_rest_round_trips_and_does_not_clobber_a_sibling_key(): void {
		$slug = $this->create_game( 'thread-test-expanded-plots-game' )->get_data()->slug;

		$request1 = new WP_REST_Request( 'PUT', "/be/v1/games/{$slug}" );
		$request1->set_url_params( [ 'slug' => $slug ] );
		$request1->set_param( 'settings', [ 'auto_approve' => true ] );
		rest_get_server()->dispatch( $request1 );

		$request2 = new WP_REST_Request( 'PUT', "/be/v1/games/{$slug}" );
		$request2->set_url_params( [ 'slug' => $slug ] );
		$request2->set_param( 'settings', [ 'plots' => [ 'expanded_enabled' => true ] ] );
		$response = rest_get_server()->dispatch( $request2 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()->settings->plots->expanded_enabled );
		$this->assertTrue( $response->get_data()->settings->auto_approve, "the first PUT's key must survive the second PUT" );

		$game = \BeyondElysium\Models\Game::find_by_slug( $slug );
		$this->assertTrue( $game->settings->plots->expanded_enabled );
		$this->assertTrue( $game->settings->auto_approve );
	}

	/**
	 * Owner ruling, 1.0.0-checklist.md item 18 (2026-09-15): an HST saves their own
	 * chronicle's creature types, sub-faction restrictions, and new-character approval -
	 * previously be_manage_games only, unreachable by anyone but a site administrator. The
	 * new /chronicle-setup route (be_manage_chronicle_setup) carries exactly these three
	 * fields, merged into the shared settings object the same way update_item() does.
	 */
	public function test_an_hst_can_save_their_own_chronicles_setup_settings(): void {
		$slug    = $this->create_game( 'thread-test-hst-chronicle-setup-game' )->get_data()->slug;
		$game_id = \BeyondElysium\Models\Game::find_by_slug( $slug )->id;

		// A sibling settings key, written as the site administrator, must survive the HST's
		// own write below - the same "no clobber" guarantee update_item()'s own merge gives.
		$seed = new WP_REST_Request( 'PUT', "/be/v1/games/{$slug}" );
		$seed->set_url_params( [ 'slug' => $slug ] );
		$seed->set_param( 'settings', [ 'auto_approve' => true ] );
		rest_get_server()->dispatch( $seed );

		$hst_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $game_id, $hst_id, 'hst' );
		wp_set_current_user( $hst_id );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$slug}/chronicle-setup" );
		$request->set_url_params( [ 'game_slug' => $slug ] );
		$request->set_param( 'enabled_stacks', [ 'vampire', 'werewolf' ] );
		$request->set_param( 'require_new_character_approval', true );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'vampire', 'werewolf' ], $response->get_data()->settings->enabled_stacks );
		$this->assertTrue( $response->get_data()->settings->require_new_character_approval );
		$this->assertTrue( $response->get_data()->settings->auto_approve, 'a sibling settings key must survive the HST-only write' );

		$game = \BeyondElysium\Models\Game::find_by_slug( $slug );
		$this->assertSame( [ 'vampire', 'werewolf' ], $game->settings->enabled_stacks );
	}

	/**
	 * The AST half of the same ruling (item 27): an AST does not hold
	 * be_manage_chronicle_setup, so the same route 403s for them even in their own
	 * chronicle - "an AST loses Chronicle Setup."
	 */
	public function test_an_ast_cannot_save_the_chronicles_setup_settings(): void {
		$slug    = $this->create_game( 'thread-test-ast-chronicle-setup-game' )->get_data()->slug;
		$game_id = \BeyondElysium\Models\Game::find_by_slug( $slug )->id;

		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $game_id, $ast_id, 'ast' );
		wp_set_current_user( $ast_id );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$slug}/chronicle-setup" );
		$request->set_url_params( [ 'game_slug' => $slug ] );
		$request->set_param( 'enabled_stacks', [ 'vampire' ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The new route only ever reads enabled_stacks/enabled_factions/
	 * require_new_character_approval - a stray name/description/asc_role_path in the same
	 * request body is silently ignored, never applied, so an HST cannot use this narrow
	 * route to smuggle through a change still gated on the full be_manage_games elsewhere.
	 */
	public function test_the_chronicle_setup_route_ignores_fields_it_does_not_own(): void {
		$slug    = $this->create_game( 'thread-test-chronicle-setup-scope-game' )->get_data()->slug;
		$game_id = \BeyondElysium\Models\Game::find_by_slug( $slug )->id;

		$hst_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $game_id, $hst_id, 'hst' );
		wp_set_current_user( $hst_id );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$slug}/chronicle-setup" );
		$request->set_url_params( [ 'game_slug' => $slug ] );
		$request->set_param( 'enabled_stacks', [ 'mage' ] );
		$request->set_param( 'name', 'Renamed by an HST through the narrow route' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$game = \BeyondElysium\Models\Game::find_by_slug( $slug );
		$this->assertNotSame( 'Renamed by an HST through the narrow route', $game->name );
	}
}
