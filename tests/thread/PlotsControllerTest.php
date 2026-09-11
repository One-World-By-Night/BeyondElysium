<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `st_notes` and `note` entries must never reach a non-manager, and a player creating a
 * plot must not be able to set `initiated_by`, `status` or `st_notes` regardless of what
 * the request body sends (workflow-0.5.md Step 2c/2e).
 *
 * @see BE_PROCESS/workflow-0.5.md Step 2
 */
class PlotsControllerTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-plots-game';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Plots Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	/**
	 * Step 1.5, workflow-0.9.md: every subscriber fixture below needs a real chronicle
	 * membership row now, not just the site-wide capability Capabilities::CAPS already
	 * grants every WP role. Each test creates its own fresh player rather than sharing one
	 * via setUp(), so this is called per-test, right after that user is created - matching
	 * what a real backfilled or admin-added player membership would look like.
	 */
	private function make_player(): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $player, 'player' );
		return $player;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_st_can_create_plot_with_notes_and_status(): void {
		$st = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $st );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'ST Plot' );
		$request->set_param( 'status', 'archived' );
		$request->set_param( 'st_notes', 'plan details' );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( 'archived', $data->status );
		$this->assertSame( 'plan details', $data->st_notes );
	}

	public function test_player_cannot_set_initiated_by_status_or_st_notes(): void {
		$player = $this->make_player();
		wp_set_current_user( $player );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'Player Plot' );
		$request->set_param( 'initiated_by', 'st' );
		$request->set_param( 'status', 'archived' );
		$request->set_param( 'st_notes', 'sneaky' );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'player', $data->initiated_by );
		$this->assertSame( 'active', $data->status );
		$this->assertObjectNotHasProperty( 'st_notes', $data );
	}

	public function test_note_entry_and_st_notes_invisible_to_non_manager(): void {
		$st     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$player = $this->make_player();

		wp_set_current_user( $st );
		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'Plot With A Note' );
		$create->set_param( 'st_notes', 'top secret' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$note = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$note->set_param( 'entry_type', 'note' );
		$note->set_param( 'content', 'ST-only development' );
		$this->assertSame( 201, $this->dispatch( $note )->get_status() );

		$action = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$action->set_param( 'entry_type', 'action' );
		$action->set_param( 'content', 'A visible action' );
		$this->dispatch( $action );

		wp_set_current_user( $player );
		$get      = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$response = $this->dispatch( $get );
		$data     = $response->get_data();

		$this->assertObjectNotHasProperty( 'st_notes', $data );
		$this->assertCount( 1, $data->entries );
		$this->assertSame( 'action', $data->entries[0]->entry_type );
	}

	public function test_player_cannot_create_note_or_response_entries(): void {
		$st     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$player = $this->make_player();

		wp_set_current_user( $st );
		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'Restricted Entries Plot' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		wp_set_current_user( $player );
		foreach ( [ 'note', 'response', 'resolution' ] as $type ) {
			$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
			$request->set_param( 'entry_type', $type );
			$request->set_param( 'content', 'attempted' );
			$this->assertSame( 403, $this->dispatch( $request )->get_status(), "player must not create a {$type} entry" );
		}

		$action = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$action->set_param( 'entry_type', 'action' );
		$action->set_param( 'content', 'allowed' );
		$this->assertSame( 201, $this->dispatch( $action )->get_status() );
	}

	public function test_player_cannot_edit_action_after_st_response(): void {
		$st     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$player = $this->make_player();

		wp_set_current_user( $player );
		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'Response Locking Plot' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$action_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$action_req->set_param( 'entry_type', 'action' );
		$action_req->set_param( 'content', 'I do a thing' );
		$entry_id = $this->dispatch( $action_req )->get_data()->id;

		$edit_before = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/entries/{$entry_id}" );
		$edit_before->set_param( 'content', 'edited before response' );
		$this->assertSame( 200, $this->dispatch( $edit_before )->get_status() );

		wp_set_current_user( $st );
		$response_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$response_req->set_param( 'entry_type', 'response' );
		$response_req->set_param( 'content', 'ST reply' );
		$this->dispatch( $response_req );

		wp_set_current_user( $player );
		$edit_after = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/entries/{$entry_id}" );
		$edit_after->set_param( 'content', 'edited after response' );
		// 409, not 403: the player still owns this entry - it's a state conflict (an ST
		// already responded), not a permission problem. See `entry_locked` in
		// Entries_Controller::update_item().
		$this->assertSame( 409, $this->dispatch( $edit_after )->get_status() );
	}

	public function test_plot_from_another_game_404s(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-plots-other-game', 'name' => 'Other Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$create = new WP_REST_Request( 'POST', '/be/v1/thread-test-plots-other-game/plots' );
		$create->set_param( 'title', 'Belongs To Other Game' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$this->assertSame( 404, $this->dispatch( $get )->get_status() );
	}

	public function test_deleting_a_plot_removes_its_entries_and_connections(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'Cascade Delete Plot' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$entry = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		$entry->set_param( 'entry_type', 'note' );
		$entry->set_param( 'content', 'to be cascaded' );
		$this->dispatch( $entry );

		$delete = new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$this->assertSame( 204, $this->dispatch( $delete )->get_status() );

		global $wpdb;
		$orphans = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_plot_entries WHERE plot_id = %d",
			$plot_id
		) );
		$this->assertSame( 0, $orphans );
	}

	/**
	 * Decision 055 - the real hierarchy the user asked for: a Plot at the top, an Action
	 * created under it, a Rumor created under that Action. get_item() on the Plot must
	 * surface the Action as a child; the Rumor is reachable the same way one level down.
	 */
	public function test_plot_action_rumor_hierarchy_is_navigable(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$plot_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$plot_req->set_param( 'title', 'Top-Level Plot' );
		$plot_id = $this->dispatch( $plot_req )->get_data()->id;

		$action_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$action_req->set_param( 'title', 'Action Under Plot' );
		$action_req->set_param( 'parent_plot_id', $plot_id );
		$action_data = $this->dispatch( $action_req )->get_data();
		$this->assertSame( $plot_id, $action_data->parent_plot_id );
		$action_id = $action_data->id;

		$rumor_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$rumor_req->set_param( 'title', 'Rumor Under Action' );
		$rumor_req->set_param( 'parent_plot_id', $action_id );
		$rumor_req->set_param( 'is_rumor', true );
		$rumor_data = $this->dispatch( $rumor_req )->get_data();
		$this->assertSame( $action_id, $rumor_data->parent_plot_id );
		$rumor_id = $rumor_data->id;

		$plot_get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$plot_view = $this->dispatch( $plot_get )->get_data();
		$this->assertCount( 1, $plot_view->children );
		$this->assertSame( $action_id, $plot_view->children[0]->id );

		$action_get = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$action_id}" );
		$action_view = $this->dispatch( $action_get )->get_data();
		$this->assertCount( 1, $action_view->children );
		$this->assertSame( $rumor_id, $action_view->children[0]->id );

		// Manual rumor creation, not just the generator - the actual bug report this
		// decision fixes: is_rumor tags the plot with apr_rumor, same as the generator.
		global $wpdb;
		$tag = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_connections WHERE source_type = 'plot' AND source_id = %d AND label = 'apr_rumor'",
			$rumor_id
		) );
		$this->assertNotNull( $tag, 'A manually-created rumor must carry the same apr_rumor tag a generated one does.' );
	}

	public function test_a_player_cannot_tag_their_own_plot_as_a_rumor(): void {
		$player = $this->make_player();
		wp_set_current_user( $player );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'Player Attempted Rumor' );
		$request->set_param( 'is_rumor', true );
		$plot_id = $this->dispatch( $request )->get_data()->id;

		global $wpdb;
		$tag = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_connections WHERE source_type = 'plot' AND source_id = %d AND label = 'apr_rumor'",
			$plot_id
		) );
		$this->assertNull( $tag, 'is_rumor must be be_manage_plots-only, the same rule as initiated_by/status/st_notes.' );
	}

	public function test_a_parent_plot_id_cycle_is_rejected(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$a_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$a_req->set_param( 'title', 'Plot A' );
		$a_id = $this->dispatch( $a_req )->get_data()->id;

		$b_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$b_req->set_param( 'title', 'Plot B' );
		$b_req->set_param( 'parent_plot_id', $a_id );
		$b_id = $this->dispatch( $b_req )->get_data()->id;

		// A -> B already exists; trying to set A's parent to B would create a cycle.
		$cycle_req = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$a_id}" );
		$cycle_req->set_param( 'parent_plot_id', $b_id );
		$response = $this->dispatch( $cycle_req );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_plot_cannot_be_nested_under_a_plot_in_another_game(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => 'thread-test-plots-hierarchy-other-game', 'name' => 'Other Hierarchy Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$other_req = new WP_REST_Request( 'POST', '/be/v1/thread-test-plots-hierarchy-other-game/plots' );
		$other_req->set_param( 'title', 'Plot In Other Game' );
		$other_plot_id = $this->dispatch( $other_req )->get_data()->id;

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'Attempted Cross-Game Child' );
		$request->set_param( 'parent_plot_id', $other_plot_id );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	/**
	 * Decision 057 - a real leak found while building the Storyteller Toolkit's card grid:
	 * `get_item()` attached `children` via `Plot::children()` (raw rows) without ever
	 * running them through `prepare_plot()`, so a child plot's `st_notes` reached ANY
	 * logged-in viewer regardless of `be_manage_plots` - the exact thing `prepare_plot()`
	 * exists to strip for the parent plot itself. Live since v0.14.0.
	 */
	public function test_a_childs_st_notes_are_stripped_for_a_non_manager(): void {
		$st     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$player = $this->make_player();

		wp_set_current_user( $st );
		$plot_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$plot_req->set_param( 'title', 'Parent Plot' );
		$plot_id = $this->dispatch( $plot_req )->get_data()->id;

		$child_req = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$child_req->set_param( 'title', 'Child With Secret Notes' );
		$child_req->set_param( 'parent_plot_id', $plot_id );
		$child_req->set_param( 'st_notes', 'ST eyes only' );
		$this->dispatch( $child_req );

		wp_set_current_user( $player );
		$get  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$data = $this->dispatch( $get )->get_data();

		$this->assertCount( 1, $data->children );
		$this->assertObjectNotHasProperty( 'st_notes', $data->children[0] );
	}

	public function test_an_invalid_cover_image_is_rejected(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$not_an_attachment = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'Plot With Bad Cover' );
		$request->set_param( 'image_id', $not_an_attachment );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_valid_cover_image_resolves_a_url_sized_per_context(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			0
		);

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'Plot With A Cover' );
		$create->set_param( 'image_id', $attachment_id );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$get       = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$single    = $this->dispatch( $get )->get_data();
		$list_req  = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots" );
		$list      = $this->dispatch( $list_req )->get_data();
		$in_list   = current( array_filter( $list, static fn( $p ) => $p->id === $plot_id ) );

		$this->assertNotEmpty( $single->image_url );
		$this->assertStringContainsString( 'canola', $single->image_url );
		$this->assertNotEmpty( $in_list->image_url );
		// get_items() resolves 'thumbnail', get_item() resolves 'medium' - different sizes,
		// so the two URLs must not be byte-identical.
		$this->assertNotSame( $single->image_url, $in_list->image_url );
	}

	/**
	 * Decision 057 - update_item() previously applied NO sanitization at all (create_item()
	 * sanitized every field, update_item() passed raw request values straight through).
	 * A plain string with no markup should still round-trip correctly through the new
	 * wp_kses_post()/sanitize_text_field() split, proving the fix didn't just move the bug.
	 */
	public function test_update_item_sanitizes_rich_and_plain_fields(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$create  = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$create->set_param( 'title', 'Sanitize Me' );
		$plot_id = $this->dispatch( $create )->get_data()->id;

		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/plots/{$plot_id}" );
		$update->set_param( 'description', '<p>Real prose.</p><script>alert(1)</script>' );
		$update->set_param( 'title', 'Updated Title' );
		$data = $this->dispatch( $update )->get_data();

		$this->assertSame( 'Updated Title', $data->title );
		$this->assertStringContainsString( '<p>Real prose.</p>', $data->description );
		$this->assertStringNotContainsString( '<script>', $data->description );
	}
}
