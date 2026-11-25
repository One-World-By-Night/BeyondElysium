<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Staff_Grants;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Per-member staff permissions: an HST gives an AST or a Narrator one of five areas in one chronicle, the grant opens
 * exactly that area there and nowhere else, and every grant and removal is recorded.
 */
class StaffGrantsThreadTest extends WP_UnitTestCase {

	private const CATALOG = 'be_manage_schemas';

	private string $slug = 'thread-test-grants-a';
	private string $other_slug = 'thread-test-grants-b';
	private object $game;
	private object $other;
	private int $hst_id;
	private int $ast_id;
	private int $narrator_id;
	private int $player_id;
	private int $harpy_id;
	private int $stranger_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->forget_memos();

		$this->game  = $this->make_game( $this->slug );
		$this->other = $this->make_game( $this->other_slug );

		$this->hst_id      = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->ast_id      = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->narrator_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player_id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->harpy_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->stranger_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		Game_Member::set_role( (int) $this->game->id, $this->hst_id, 'hst' );
		Game_Member::set_role( (int) $this->game->id, $this->ast_id, 'ast' );
		Game_Member::set_role( (int) $this->game->id, $this->narrator_id, 'narrator' );
		Game_Member::set_role( (int) $this->game->id, $this->player_id, 'player' );
		Game_Member::set_role( (int) $this->game->id, $this->harpy_id, 'boons' );
		Game_Member::set_role( (int) $this->other->id, $this->ast_id, 'ast' );

		Schema_Block::create( [
			'slug'         => 'thread-test-grants-block',
			'name'         => 'Thread Test Grants Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [] ],
		] );
	}

	public function tearDown(): void {
		update_option( 'be_asc_enabled', false );
		unset( $GLOBALS['be_test_asc_paths_held'] );
		parent::tearDown();
	}

	private function make_game( string $slug ): object {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $slug,
			'name'       => 'Grants ' . $slug,
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );
		return Game::find_by_slug( $slug );
	}

	private function forget_memos(): void {
		foreach ( [ [ Authorization::class, 'asc_memo' ], [ Staff_Grants::class, 'memo' ] ] as [ $class, $property ] ) {
			$reflection = new \ReflectionProperty( $class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( null, [] );
		}
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function dispatch( string $method, string $route, array $params = [] ) {
		$this->forget_memos();
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function save_catalog_block( string $slug ): int {
		return $this->dispatch( 'PUT', "/be/v1/{$slug}/schema-blocks/thread-test-grants-block", [ 'name' => 'Renamed Block' ] )->get_status();
	}

	private function grant( int $user_id, array $areas, ?int $by = null ): ?string {
		return Staff_Grants::set( $this->game, $user_id, $areas, $by ?? $this->hst_id );
	}

	public function test_a_grant_opens_the_catalog_to_an_ast_in_that_chronicle_and_no_other(): void {
		wp_set_current_user( $this->ast_id );
		$this->assertSame( 403, $this->save_catalog_block( $this->slug ) );

		$this->assertNull( $this->grant( $this->ast_id, [ self::CATALOG ] ) );

		$this->assertSame( 200, $this->save_catalog_block( $this->slug ) );
		$this->assertSame( 403, $this->save_catalog_block( $this->other_slug ), 'a grant in one chronicle opens nothing in another' );
	}

	public function test_a_narrator_can_be_given_the_catalog_but_not_deleting_characters(): void {
		$this->assertNull( $this->grant( $this->narrator_id, [ self::CATALOG ] ) );
		wp_set_current_user( $this->narrator_id );
		$this->assertSame( 200, $this->save_catalog_block( $this->slug ) );

		$this->assertSame( 'delete_needs_ast', $this->grant( $this->narrator_id, [ self::CATALOG, 'be_delete_characters' ] ) );
		$this->assertSame( [ self::CATALOG ], Staff_Grants::held( (int) $this->game->id, $this->narrator_id ) );
	}

	public function test_a_player_a_harpy_and_an_account_with_no_role_here_cannot_be_given_anything(): void {
		foreach ( [ $this->player_id, $this->harpy_id, $this->stranger_id ] as $user_id ) {
			$this->assertSame( 'not_eligible', $this->grant( $user_id, [ self::CATALOG ] ) );
			$this->assertSame( [], Staff_Grants::held( (int) $this->game->id, $user_id ) );
		}
	}

	public function test_the_two_capabilities_that_can_never_be_granted_are_refused_as_areas(): void {
		$this->assertSame( 'invalid_area', $this->grant( $this->ast_id, [ 'be_manage_games' ] ) );
		$this->assertSame( 'invalid_area', $this->grant( $this->ast_id, [ 'be_grant_staff_access' ] ) );
		$this->assertSame( 'invalid_area', $this->grant( $this->ast_id, [ 'be_manage_characters' ] ) );
		$this->assertSame( [], Staff_Grants::held( (int) $this->game->id, $this->ast_id ) );
	}

	public function test_the_five_areas_are_exactly_the_five_an_ast_lacks(): void {
		$this->assertSame(
			[ 'be_manage_schemas', 'be_manage_approval_rules', 'be_manage_templates', 'be_manage_chronicle_setup', 'be_delete_characters' ],
			Staff_Grants::AREAS
		);
		$ast = Authorization::role_grants( 'ast' );
		foreach ( Staff_Grants::AREAS as $area ) {
			$this->assertNotContains( $area, $ast );
		}
		$this->assertNotContains( 'be_grant_staff_access', $ast );
		$this->assertContains( 'be_grant_staff_access', Authorization::role_grants( 'hst' ) );
		$this->assertNotContains( 'be_grant_staff_access', Authorization::role_grants( 'narrator' ) );
	}

	public function test_taking_a_grant_away_closes_it_on_the_next_request(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		wp_set_current_user( $this->ast_id );
		$this->assertSame( 200, $this->save_catalog_block( $this->slug ) );

		$this->assertNull( $this->grant( $this->ast_id, [] ) );
		$this->assertSame( 403, $this->save_catalog_block( $this->slug ) );
	}

	public function test_an_ast_demoted_to_player_keeps_the_row_and_loses_the_access(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		Game_Member::set_role( (int) $this->game->id, $this->ast_id, 'player' );

		wp_set_current_user( $this->ast_id );
		$this->assertSame( 403, $this->save_catalog_block( $this->slug ) );
		$this->assertSame( [ self::CATALOG ], Staff_Grants::held( (int) $this->game->id, $this->ast_id ) );

		Game_Member::set_role( (int) $this->game->id, $this->ast_id, 'ast' );
		$this->assertSame( 200, $this->save_catalog_block( $this->slug ), 'the same row works again once they are an AST again' );
	}

	public function test_a_demoted_person_can_still_have_their_grants_taken_away(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		Game_Member::set_role( (int) $this->game->id, $this->ast_id, 'player' );

		$this->assertNull( $this->grant( $this->ast_id, [] ) );
		$this->assertSame( [], Staff_Grants::held( (int) $this->game->id, $this->ast_id ) );
	}

	public function test_a_site_wide_route_ignores_grants(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		wp_set_current_user( $this->ast_id );

		$response = $this->dispatch( 'PUT', '/be/v1/schema-blocks/thread-test-grants-block', [ 'name' => 'Renamed Globally' ] );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_each_of_the_five_areas_opens_its_own_route_and_no_other(): void {
		$routes = [
			'be_manage_approval_rules'  => [ 'GET', "/be/v1/{$this->slug}/approval-rules" ],
			'be_manage_templates'       => [ 'GET', "/be/v1/{$this->slug}/templates" ],
			'be_manage_chronicle_setup' => [ 'PUT', "/be/v1/{$this->slug}/chronicle-setup" ],
		];
		wp_set_current_user( $this->ast_id );
		foreach ( $routes as $area => [ $method, $route ] ) {
			$this->assertSame( 403, $this->dispatch( $method, $route )->get_status(), "$area is closed to an AST without it" );
		}
		$this->grant( $this->ast_id, [ 'be_manage_templates' ] );
		foreach ( $routes as $area => [ $method, $route ] ) {
			$status = $this->dispatch( $method, $route )->get_status();
			if ( 'be_manage_templates' === $area ) {
				$this->assertNotSame( 403, $status, 'the granted area opens' );
			} else {
				$this->assertSame( 403, $status, "$area stays closed" );
			}
		}
	}

	public function test_an_ast_granted_deleting_characters_can_delete_one(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_characters', [
			'uuid'       => wp_generate_uuid4(),
			'name'       => 'Grant Delete Test',
			'stack_slug' => 'mortal',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'status'     => 'active',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );
		$character_id = (int) $wpdb->insert_id;

		wp_set_current_user( $this->ast_id );
		$this->assertSame( 403, $this->dispatch( 'DELETE', "/be/v1/{$this->slug}/characters/{$character_id}" )->get_status() );

		$this->grant( $this->ast_id, [ 'be_delete_characters' ] );
		$this->assertNotSame( 403, $this->dispatch( 'DELETE', "/be/v1/{$this->slug}/characters/{$character_id}" )->get_status() );
	}

	public function test_the_capabilities_map_reflects_a_grant(): void {
		wp_set_current_user( $this->ast_id );
		$before = $this->dispatch( 'GET', "/be/v1/{$this->slug}/my/capabilities" )->get_data()['capabilities'];
		$this->assertFalse( $before[ self::CATALOG ] );
		$this->assertFalse( $before['be_delete_characters'] );
		$this->assertFalse( $before['be_grant_staff_access'] );

		$this->grant( $this->ast_id, [ self::CATALOG ] );
		$after = $this->dispatch( 'GET', "/be/v1/{$this->slug}/my/capabilities" )->get_data()['capabilities'];
		$this->assertTrue( $after[ self::CATALOG ] );
		$this->assertFalse( $after['be_delete_characters'] );
		$this->assertFalse( $after['be_grant_staff_access'], 'a grant never carries the right to grant' );

		wp_set_current_user( $this->hst_id );
		$hst = $this->dispatch( 'GET', "/be/v1/{$this->slug}/my/capabilities" )->get_data()['capabilities'];
		$this->assertTrue( $hst['be_grant_staff_access'] );
	}

	public function test_every_grant_and_removal_records_who_and_when(): void {
		$this->grant( $this->ast_id, [ self::CATALOG, 'be_manage_templates' ] );
		$this->grant( $this->ast_id, [ 'be_manage_templates' ] );

		$log = Staff_Grants::log( $this->game );
		$this->assertCount( 3, $log );

		$actions = array_map( static fn( $row ) => $row['action'] . ':' . $row['area'], $log );
		sort( $actions );
		$this->assertSame( [ 'granted:be_manage_schemas', 'granted:be_manage_templates', 'revoked:be_manage_schemas' ], $actions );
		foreach ( $log as $row ) {
			$this->assertSame( $this->ast_id, $row['wp_user_id'] );
			$this->assertSame( $this->hst_id, $row['by'] );
			$this->assertNotSame( '', $row['at'] );
		}
	}

	public function test_setting_the_same_areas_again_writes_nothing(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		$this->assertCount( 1, Staff_Grants::log( $this->game ) );
	}

	public function test_deleting_a_chronicle_or_an_account_deletes_its_grants(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		Staff_Grants::set( $this->other, $this->ast_id, [ 'be_manage_templates' ], $this->hst_id );

		Game::delete_with_content( $this->other_slug );
		$this->assertSame( [], Staff_Grants::held( (int) $this->other->id, $this->ast_id ) );
		$this->assertSame( [ self::CATALOG ], Staff_Grants::held( (int) $this->game->id, $this->ast_id ) );

		wp_delete_user( $this->ast_id );
		$this->assertSame( [], Staff_Grants::held( (int) $this->game->id, $this->ast_id ) );
	}

	/**
	 * Links a chronicle to accessSchema and makes an account hold the given role paths there.
	 *
	 * @param string[] $roles
	 */
	private function link_chronicle_with( int $user_id, array $roles ): void {
		require_once __DIR__ . '/fixtures/fake-owc-asc-check-access.php';
		require_once __DIR__ . '/fixtures/fake-owc-asc-roles.php';
		global $wpdb;
		update_option( 'be_asc_enabled', true );
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'asc_role_path' => 'chronicle/' . $this->slug ], [ 'id' => (int) $this->game->id ] );
		$this->game = Game::find_by_slug( $this->slug );

		$email = get_userdata( $user_id )->user_email;
		$GLOBALS['be_test_asc_paths_held'][ $email ] = array_map( fn( $role ) => 'chronicle/' . $this->slug . '/' . $role, $roles );
		$GLOBALS['be_test_asc_user_roles'][ $email ] = $GLOBALS['be_test_asc_paths_held'][ $email ];
	}

	public function test_on_a_linked_chronicle_a_grant_opens_the_catalog_to_someone_holding_the_ast_role(): void {
		$linked_ast = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->link_chronicle_with( $linked_ast, [ 'ast' ] );
		Game_Member::remove( (int) $this->game->id, $linked_ast );

		wp_set_current_user( $linked_ast );
		$this->assertSame( 403, $this->save_catalog_block( $this->slug ) );

		$this->assertNull( $this->grant( $linked_ast, [ self::CATALOG ] ) );
		$this->assertSame( 200, $this->save_catalog_block( $this->slug ) );
	}

	public function test_on_a_linked_chronicle_a_narrator_is_eligible_and_a_player_is_not(): void {
		$narrator = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$player   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->link_chronicle_with( $narrator, [ 'narrator' ] );
		$this->link_chronicle_with( $player, [ 'player' ] );

		$this->assertNull( $this->grant( $narrator, [ self::CATALOG ] ) );
		$this->assertSame( 'not_eligible', $this->grant( $player, [ self::CATALOG ] ) );
	}

	public function test_on_a_linked_chronicle_losing_the_ast_role_loses_the_access_and_keeps_the_row(): void {
		$linked_ast = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->link_chronicle_with( $linked_ast, [ 'ast' ] );
		$this->assertNull( $this->grant( $linked_ast, [ self::CATALOG ] ) );

		wp_set_current_user( $linked_ast );
		$this->assertSame( 200, $this->save_catalog_block( $this->slug ) );

		$this->link_chronicle_with( $linked_ast, [ 'player' ] );
		$this->assertSame( 403, $this->save_catalog_block( $this->slug ) );
		$this->assertSame( [ self::CATALOG ], Staff_Grants::held( (int) $this->game->id, $linked_ast ) );
	}

	public function test_a_request_with_no_grant_asks_accessSchema_nothing_about_the_ast_or_narrator_role(): void {
		$linked_ast = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->link_chronicle_with( $linked_ast, [ 'ast' ] );
		$GLOBALS['be_test_captured_role_paths'] = [];

		wp_set_current_user( $linked_ast );
		$this->assertSame( 403, $this->save_catalog_block( $this->slug ) );

		foreach ( $GLOBALS['be_test_captured_role_paths'] as $path ) {
			$this->assertStringEndsNotWith( '/ast', $path );
			$this->assertStringEndsNotWith( '/narrator', $path );
		}
		$this->assertNotEmpty( $GLOBALS['be_test_captured_role_paths'], 'the HST path is still asked' );
	}

	private function put_areas( int $user_id, array $areas ) {
		return $this->dispatch( 'PUT', "/be/v1/{$this->slug}/staff-grants/{$user_id}", [ 'areas' => $areas ] );
	}

	public function test_the_hst_gives_and_lists_with_who_and_when(): void {
		wp_set_current_user( $this->hst_id );

		$put = $this->put_areas( $this->ast_id, [ self::CATALOG, 'be_manage_templates' ] );
		$this->assertSame( 200, $put->get_status() );
		$this->assertSame( [ self::CATALOG, 'be_manage_templates' ], array_column( $put->get_data()['person']['areas'], 'area' ) );

		$list = $this->dispatch( 'GET', "/be/v1/{$this->slug}/staff-grants" );
		$this->assertSame( 200, $list->get_status() );
		$data = $list->get_data();
		$this->assertCount( 1, $data['people'] );
		$person = $data['people'][0];
		$this->assertSame( $this->ast_id, $person['wp_user_id'] );
		$this->assertSame( 'ast', $person['role'] );
		$this->assertTrue( $person['eligible'] );
		$this->assertSame( get_userdata( $this->hst_id )->display_name, $person['areas'][0]['by_name'] );
		$this->assertNotSame( '', $person['areas'][0]['at'] );
		$this->assertCount( 2, $data['log'] );
		$this->assertSame( 'granted', $data['log'][0]['action'] );
		$this->assertSame( get_userdata( $this->ast_id )->display_name, $data['log'][0]['user_name'] );
		$this->assertSame( Staff_Grants::AREAS, $data['areas'] );
	}

	public function test_only_someone_holding_the_right_to_grant_can_read_or_change_grants(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		foreach ( [ $this->ast_id, $this->narrator_id, $this->player_id, $this->stranger_id ] as $user_id ) {
			wp_set_current_user( $user_id );
			$this->assertSame( 403, $this->dispatch( 'GET', "/be/v1/{$this->slug}/staff-grants" )->get_status() );
			$this->assertSame( 403, $this->put_areas( $this->narrator_id, [ self::CATALOG ] )->get_status() );
		}
		$this->assertSame( [], Staff_Grants::held( (int) $this->game->id, $this->narrator_id ) );
	}

	public function test_a_grant_in_another_chronicle_does_not_let_the_hst_here_change_it(): void {
		wp_set_current_user( $this->hst_id );
		$put = $this->dispatch( 'PUT', "/be/v1/{$this->other_slug}/staff-grants/{$this->ast_id}", [ 'areas' => [ self::CATALOG ] ] );
		$this->assertSame( 403, $put->get_status() );
	}

	public function test_the_refusals_carry_their_codes(): void {
		wp_set_current_user( $this->hst_id );

		$invalid = $this->put_areas( $this->ast_id, [ 'be_manage_games' ] );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( 'invalid_area', $invalid->as_error()->get_error_code() );

		$player = $this->put_areas( $this->player_id, [ self::CATALOG ] );
		$this->assertSame( 400, $player->get_status() );
		$this->assertSame( 'not_eligible', $player->as_error()->get_error_code() );
		$this->assertSame( 'Give them the AST or Narrator role first.', $player->as_error()->get_error_message() );

		$delete = $this->put_areas( $this->narrator_id, [ 'be_delete_characters' ] );
		$this->assertSame( 400, $delete->get_status() );
		$this->assertSame( 'delete_needs_ast', $delete->as_error()->get_error_code() );

		$missing = $this->put_areas( 999999, [ self::CATALOG ] );
		$this->assertSame( 404, $missing->get_status() );
	}

	public function test_someone_no_longer_an_ast_is_listed_so_their_grants_can_be_removed(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		Game_Member::set_role( (int) $this->game->id, $this->ast_id, 'player' );

		wp_set_current_user( $this->hst_id );
		$person = $this->dispatch( 'GET', "/be/v1/{$this->slug}/staff-grants" )->get_data()['people'][0];
		$this->assertSame( 'player', $person['role'] );
		$this->assertFalse( $person['eligible'] );

		$this->assertSame( 200, $this->put_areas( $this->ast_id, [] )->get_status() );
		$this->assertSame( [], $this->dispatch( 'GET', "/be/v1/{$this->slug}/staff-grants" )->get_data()['people'] );
	}

	public function test_a_grant_never_widens_a_request_for_anything_outside_the_five_areas(): void {
		$this->grant( $this->ast_id, [ self::CATALOG ] );
		wp_set_current_user( $this->ast_id );
		$this->assertSame( 403, $this->dispatch( 'PUT', "/be/v1/games/{$this->slug}", [ 'name' => 'Renamed' ] )->get_status() );
	}
}
