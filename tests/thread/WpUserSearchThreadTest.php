<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-010: GET /wp-users searched every account on the site by name, login, or
 * email and returned up to 50 email addresses to any WordPress editor - every Storyteller of
 * every chronicle on a shared install. WordPress's own /wp/v2/users hides emails from editors.
 * The site-wide search is now a site administrator's (adding chronicle members); a chronicle's
 * Storyteller assigning a player searches through the chronicle's own route, by at least three
 * letters of a name, and sees an email only when they already typed that exact address.
 */
class WpUserSearchThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-user-search';
	private int $hst;
	private int $stranger;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->slug )->id, $this->hst, 'hst' );

		$this->stranger = self::factory()->user->create( [
			'role' => 'subscriber', 'display_name' => 'Stranger Person', 'user_email' => 'stranger.secret@example.org',
		] );
	}

	private function get( string $route, string $search ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( [ 'search' => $search ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_storyteller_cannot_use_the_site_wide_directory(): void {
		wp_set_current_user( $this->hst );

		$this->assertSame( 403, $this->get( '/be/v1/wp-users', 'example.org' )->get_status() );
	}

	public function test_a_name_search_through_the_chronicle_route_returns_no_emails(): void {
		wp_set_current_user( $this->hst );

		$response = $this->get( "/be/v1/{$this->slug}/wp-users", 'Stranger' );
		$match    = array_values( array_filter( $response->get_data(), fn( $u ) => $u['id'] === $this->stranger ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $match );
		$this->assertArrayNotHasKey( 'email', $match[0] );
	}

	public function test_emails_cannot_be_found_by_partial_address(): void {
		wp_set_current_user( $this->hst );

		$ids = array_column( $this->get( "/be/v1/{$this->slug}/wp-users", 'secret@example' )->get_data(), 'id' );

		$this->assertNotContains( $this->stranger, $ids );
	}

	public function test_an_exact_email_finds_the_account_and_confirms_the_address(): void {
		wp_set_current_user( $this->hst );

		$data = $this->get( "/be/v1/{$this->slug}/wp-users", 'stranger.secret@example.org' )->get_data();

		$this->assertSame( $this->stranger, $data[0]['id'] );
		$this->assertSame( 'stranger.secret@example.org', $data[0]['email'] );
	}

	public function test_a_search_shorter_than_three_letters_returns_nothing(): void {
		wp_set_current_user( $this->hst );

		$this->assertSame( [], $this->get( "/be/v1/{$this->slug}/wp-users", 'St' )->get_data() );
	}

	public function test_a_site_administrator_still_searches_the_whole_site(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$data = $this->get( '/be/v1/wp-users', 'stranger.secret' )->get_data();

		$this->assertSame( 'stranger.secret@example.org', $data[0]['email'] );
	}
}
