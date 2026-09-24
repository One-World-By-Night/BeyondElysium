<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Page_Provisioner;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The guided chronicle setup behaviors not covered by a dedicated test file: the permission fix, the membership
 * bootstrap on create and page provisioning.
 */
class GuidedChronicleSetupTest extends WP_UnitTestCase {

	private $editor_id;
	private $game_slug = 'guided-setup-test';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );

		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Guided Setup Test', 'created_by' => $this->editor_id ] );
	}

	// -------------------------------------------------------------------------

	public function test_gs1_an_hst_editor_can_fork_their_own_chronicles_schema_block(): void {
		$game = Game::find_by_slug( $this->game_slug );
		Game_Member::set_role( (int) $game->id, $this->editor_id, 'hst' );

		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'PUT', '/be/v1/' . $this->game_slug . '/schema-blocks/vampire-abilities' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug, 'slug' => 'vampire-abilities' ] );
		$request->set_param( 'name', 'Abilities (customized)' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$block = \BeyondElysium\Models\Schema_Block::find_for_game( 'vampire-abilities', $this->game_slug );
		$this->assertSame( 'Abilities (customized)', $block->name );

		// The global block itself must be untouched - this was a fork, never a mutation.
		$global = \BeyondElysium\Models\Schema_Block::find_by_slug( 'vampire-abilities' );
		$this->assertNotSame( 'Abilities (customized)', $global->name );
	}

	public function test_gs1_an_editor_with_no_membership_in_this_chronicle_is_denied(): void {
		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'PUT', '/be/v1/' . $this->game_slug . '/schema-blocks/vampire-abilities' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug, 'slug' => 'vampire-abilities' ] );
		$request->set_param( 'name', 'Should Not Save' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status(), 'be_manage_schemas alone must not be enough without a real chronicle membership row' );
	}

	// -------------------------------------------------------------------------
	// Membership bootstrap on all three real creation paths
	// -------------------------------------------------------------------------

	public function test_gs7_games_controller_create_bootstraps_the_creator_as_hst(): void {
		// be_manage_games stays administrator-only (never touches it).
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$request = new WP_REST_Request( 'POST', '/be/v1/games' );
		$request->set_param( 'name', 'GS-7 REST Create Test' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$slug = $response->get_data()->slug;

		$game   = Game::find_by_slug( $slug );
		$member = Game_Member::find( (int) $game->id, $admin_id );
		$this->assertNotNull( $member, 'Games_Controller::create_item() must bootstrap an hst row for the creator' );
		$this->assertSame( 'hst', $member->role );
	}

	public function test_gs7_chronicle_sync_bootstraps_the_post_author_as_hst(): void {
		if ( ! post_type_exists( 'owbn_chronicle' ) ) {
			register_post_type( 'owbn_chronicle', [ 'public' => false ] );
		}

		$author_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$post_id   = self::factory()->post->create( [ 'post_type' => 'owbn_chronicle', 'post_author' => $author_id, 'post_status' => 'publish' ] );
		update_post_meta( $post_id, 'chronicle_slug', 'gs7-sync-test' );

		\BeyondElysium\Core\Chronicle_Sync::sync( $post_id, get_post( $post_id ), false );

		$game = Game::find_by_slug( 'gs7-sync-test' );
		$this->assertNotNull( $game, 'Chronicle_Sync::sync() must still create the row' );

		$member = Game_Member::find( (int) $game->id, $author_id );
		$this->assertNotNull( $member, 'the post author must be bootstrapped as hst' );
		$this->assertSame( 'hst', $member->role );
	}

	// -------------------------------------------------------------------------
	// Page provisioning is chronicle-independent
	// -------------------------------------------------------------------------

	public function test_a_second_chronicle_never_gets_its_own_duplicate_pages(): void {
		Page_Provisioner::maybe_provision();
		$before = get_posts( [ 'post_type' => 'page', 'name' => Page_Provisioner::PLAYER_SLUG, 'post_status' => 'publish', 'numberposts' => -1 ] );

		Game::create( [ 'name' => 'Second Chronicle', 'slug' => 'guided-setup-second-chronicle' ] );
		Page_Provisioner::maybe_provision();

		$after = get_posts( [ 'post_type' => 'page', 'name' => Page_Provisioner::PLAYER_SLUG, 'post_status' => 'publish', 'numberposts' => -1 ] );
		$this->assertCount( 1, $before );
		$this->assertCount( 1, $after, 'a second chronicle existing must not create a second My Chronicle page' );
		$this->assertNull( get_page_by_path( Page_Provisioner::PLAYER_SLUG . '-guided-setup-second-chronicle', OBJECT, 'page' ), 'no chronicle-qualified slug should ever be created under the new model' );
	}

	// -------------------------------------------------------------------------
	// Cascading delete only when explicitly requested
	// -------------------------------------------------------------------------

	public function test_gs11_delete_with_content_removes_the_chronicles_characters(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Character::create( [ 'name' => 'GS-11 Test Character', 'owner_slug' => $this->game_slug, 'stack_slug' => 'vampire', 'created_by' => $admin_id ] );

		wp_set_current_user( $admin_id );
		$request = new WP_REST_Request( 'DELETE', '/be/v1/games/' . $this->game_slug );
		$request->set_url_params( [ 'slug' => $this->game_slug ] );
		$request->set_param( 'with_content', true );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertEmpty( Character::all_for_game( $this->game_slug ) );
		$this->assertNull( Game::find_by_slug( $this->game_slug ) );
	}

	public function test_gs11_plain_delete_without_with_content_still_works_as_before(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$request = new WP_REST_Request( 'DELETE', '/be/v1/games/' . $this->game_slug );
		$request->set_url_params( [ 'slug' => $this->game_slug ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Game::find_by_slug( $this->game_slug ) );
	}
}
