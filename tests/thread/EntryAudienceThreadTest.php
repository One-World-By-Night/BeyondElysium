<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Audience;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A plot entry's own audience.
 */
class EntryAudienceThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-entry-audience';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Entry Audience',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function make_player(): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		return $player;
	}

	private function make_manager(): int {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		return $hst;
	}

	private function make_character( int $wp_user_id, array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $wp_user_id,
			'created_by' => $wp_user_id,
		], $overrides ) );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A manager-created global plot, visible to everyone.
	 */
	private function make_open_plot(): int {
		$plot_id = (int) Plot::create( [
			'game_id'    => $this->game_id,
			'title'      => 'An Open Plot',
			'audience'   => Audience::EVERYONE,
			'created_by' => 1,
		] );
		return $plot_id;
	}

	private function post_entry( int $wp_user_id, int $plot_id, array $params ): \WP_REST_Response {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	private function list_entries( int $wp_user_id, int $plot_id ): array {
		wp_set_current_user( $wp_user_id );
		return $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/entries" ) )->get_data();
	}

	// -------------------------------------------------------------------------
	// Defaults and player choices (plot, storytellers)
	// -------------------------------------------------------------------------

	public function test_an_entry_with_no_audience_defaults_to_plot_and_is_public(): void {
		$plot_id = $this->make_open_plot();
		$author  = $this->make_player();
		$this->make_character( $author );
		$onlooker = $this->make_player();
		$this->make_character( $onlooker );

		$response = $this->post_entry( $author, $plot_id, [ 'entry_type' => 'action', 'content' => 'A public deed.' ] );
		$this->assertSame( 'plot', $response->get_data()->audience );

		$titles = array_map( static fn( $e ) => $e->content, $this->list_entries( $onlooker, $plot_id ) );
		$this->assertContains( 'A public deed.', $titles );
	}

	public function test_a_player_may_post_a_private_reply_visible_to_themself_and_staff_only(): void {
		$plot_id = $this->make_open_plot();
		$author  = $this->make_player();
		$this->make_character( $author );
		$onlooker = $this->make_player();
		$this->make_character( $onlooker );

		$this->post_entry( $author, $plot_id, [
			'entry_type' => 'action',
			'content'    => 'A private confession.',
			'audience'   => 'storytellers',
		] );

		$author_view    = array_map( static fn( $e ) => $e->content, $this->list_entries( $author, $plot_id ) );
		$onlooker_view  = array_map( static fn( $e ) => $e->content, $this->list_entries( $onlooker, $plot_id ) );
		$manager_view   = array_map( static fn( $e ) => $e->content, $this->list_entries( $this->make_manager(), $plot_id ) );

		$this->assertContains( 'A private confession.', $author_view, 'the author always sees their own entry' );
		$this->assertNotContains( 'A private confession.', $onlooker_view );
		$this->assertContains( 'A private confession.', $manager_view );
	}

	public function test_a_player_cannot_direct_a_post_to_specific_characters(): void {
		$plot_id = $this->make_open_plot();
		$author  = $this->make_player();
		$other_character = $this->make_character( $this->make_player() );

		$response = $this->post_entry( $author, $plot_id, [
			'entry_type'              => 'action',
			'content'                 => 'Trying to whisper to one person.',
			'audience'                => 'characters',
			'audience_character_ids'  => [ $other_character ],
		] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_invalid_audience_value_is_rejected(): void {
		$plot_id = $this->make_open_plot();
		$response = $this->post_entry( $this->make_manager(), $plot_id, [
			'entry_type' => 'response',
			'content'    => 'Reply.',
			'audience'   => 'nonsense',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// GET .../plots/{id}/visible-characters - the directed-post picker's own data source
	// -------------------------------------------------------------------------

	public function test_visible_characters_lists_who_can_see_the_plot(): void {
		$plot_id       = $this->make_open_plot(); // audience: everyone
		$player        = $this->make_player();
		$character_id  = $this->make_character( $player );

		wp_set_current_user( $this->make_manager() );
		$data  = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/visible-characters" ) )->get_data();
		$names = array_map( static fn( $c ) => $c['name'], $data );

		$this->assertContains( 'Fixture Character', $names );
	}

	public function test_visible_characters_excludes_who_cannot_see_a_storytellers_only_plot(): void {
		$hidden_plot_id = (int) Plot::create( [
			'game_id'    => $this->game_id,
			'title'      => 'Storytellers Only',
			'audience'   => Audience::STORYTELLERS,
			'created_by' => 1,
		] );
		$this->make_character( $this->make_player() );

		wp_set_current_user( $this->make_manager() );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$hidden_plot_id}/visible-characters" ) )->get_data();

		$this->assertSame( [], $data, 'storytellers-only reaches no character through this path' );
	}

	public function test_a_player_cannot_read_the_visible_characters_route(): void {
		$plot_id = $this->make_open_plot();
		wp_set_current_user( $this->make_player() );

		$this->assertSame(
			403,
			$this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}/visible-characters" ) )->get_status()
		);
	}

	// -------------------------------------------------------------------------
	// Storyteller-directed posts (characters)
	// -------------------------------------------------------------------------

	public function test_a_manager_can_direct_a_post_to_a_specific_character(): void {
		$plot_id      = $this->make_open_plot();
		$target_player = $this->make_player();
		$target_id    = $this->make_character( $target_player );
		$other_player = $this->make_player();
		$this->make_character( $other_player );

		$response = $this->post_entry( $this->make_manager(), $plot_id, [
			'entry_type'             => 'response',
			'content'                => 'A secret message for you.',
			'audience'               => 'characters',
			'audience_character_ids' => [ $target_id ],
		] );
		$this->assertSame( 201, $response->get_status() );

		$target_view = array_map( static fn( $e ) => $e->content, $this->list_entries( $target_player, $plot_id ) );
		$other_view  = array_map( static fn( $e ) => $e->content, $this->list_entries( $other_player, $plot_id ) );

		$this->assertContains( 'A secret message for you.', $target_view );
		$this->assertNotContains( 'A secret message for you.', $other_view );
	}

	public function test_directing_a_post_requires_at_least_one_character(): void {
		$plot_id = $this->make_open_plot();
		$response = $this->post_entry( $this->make_manager(), $plot_id, [
			'entry_type' => 'response',
			'content'    => 'Reply.',
			'audience'   => 'characters',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_directed_post_cannot_name_a_character_who_cannot_see_the_plot(): void {
		$hidden_plot_id = (int) Plot::create( [
			'game_id'    => $this->game_id,
			'title'      => 'Storytellers Only',
			'audience'   => Audience::STORYTELLERS,
			'created_by' => 1,
		] );
		$outsider_id = $this->make_character( $this->make_player() );

		$response = $this->post_entry( $this->make_manager(), $hidden_plot_id, [
			'entry_type'             => 'response',
			'content'                => 'Reply.',
			'audience'               => 'characters',
			'audience_character_ids' => [ $outsider_id ],
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// update_item()
	// -------------------------------------------------------------------------

	public function test_a_manager_may_widen_a_private_reply_to_public(): void {
		$plot_id = $this->make_open_plot();
		$author  = $this->make_player();
		$this->make_character( $author );
		$onlooker = $this->make_player();
		$this->make_character( $onlooker );

		$create   = $this->post_entry( $author, $plot_id, [
			'entry_type' => 'action',
			'content'    => 'Quiet at first.',
			'audience'   => 'storytellers',
		] );
		$entry_id = $create->get_data()->id;

		wp_set_current_user( $this->make_manager() );
		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/entries/{$entry_id}" );
		$update->set_param( 'content', 'Quiet at first.' );
		$update->set_param( 'audience', 'plot' );
		$this->dispatch( $update );

		$onlooker_view = array_map( static fn( $e ) => $e->content, $this->list_entries( $onlooker, $plot_id ) );
		$this->assertContains( 'Quiet at first.', $onlooker_view );
	}

	public function test_updating_content_alone_leaves_a_private_audience_unchanged(): void {
		$plot_id = $this->make_open_plot();
		$author  = $this->make_player();
		$this->make_character( $author );
		$onlooker = $this->make_player();
		$this->make_character( $onlooker );

		$create   = $this->post_entry( $author, $plot_id, [
			'entry_type' => 'action',
			'content'    => 'Original.',
			'audience'   => 'storytellers',
		] );
		$entry_id = $create->get_data()->id;

		wp_set_current_user( $author );
		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/entries/{$entry_id}" );
		$update->set_param( 'content', 'Edited, still private.' );
		$this->dispatch( $update );

		$onlooker_view = array_map( static fn( $e ) => $e->content, $this->list_entries( $onlooker, $plot_id ) );
		$this->assertNotContains( 'Edited, still private.', $onlooker_view, 'a plain content edit must not reset audience back to plot' );
	}
}
