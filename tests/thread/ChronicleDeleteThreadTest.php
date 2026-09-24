<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Saved_Query;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * (closes).
 */
class ChronicleDeleteThreadTest extends WP_UnitTestCase {

	private int $admin;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
	}

	private function game( string $slug ): object {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $slug, 'name' => $slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return Game::find_by_slug( $slug );
	}

	private function character( string $slug, string $name = 'Left Behind' ): object {
		return Character::find( Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $slug,
		] ) );
	}

	private function transfer( string $direction, string $home_slug, ?string $host_slug ): int {
		return Transfer::create( [
			'character_uuid' => wp_generate_uuid4(), 'direction' => $direction, 'state' => 'offered',
			'home_slug' => $home_slug, 'home_site' => 'https://home.example', 'home_chronicle' => 'Home',
			'host_slug' => $host_slug, 'host_site' => 'https://host.example', 'host_chronicle' => 'Host',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => 1,
		] );
	}

	private function rows( string $table, string $where, ...$args ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_{$table} WHERE {$where}", ...$args ) );
	}

	private function dispatch( string $method, string $route, array $body = [], array $query = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_games_screen_refuses_to_orphan_a_chronicles_content(): void {
		$game      = $this->game( 'thread-delete-refused' );
		$character = $this->character( $game->slug );
		Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', $game->slug );

		$response = $this->dispatch( 'DELETE', "/be/v1/games/{$game->slug}" );

		$this->assertSame( 409, $response->get_status() );
		$error = $response->as_error();
		$this->assertSame( 'chronicle_has_content', $error->get_error_code() );
		$this->assertSame( 1, $error->get_error_data()['counts']['characters'] );
		$this->assertSame( 1, $error->get_error_data()['counts']['schema_blocks'] );
		$this->assertNotNull( Game::find_by_slug( $game->slug ) );
		$this->assertNotNull( Character::find( (int) $character->id ) );
	}

	public function test_an_administrator_can_see_what_deleting_a_chronicle_would_take_with_it(): void {
		$game = $this->game( 'thread-delete-counts' );
		$this->character( $game->slug );
		$this->character( $game->slug, 'Second Sheet' );

		$response = $this->dispatch( 'GET', "/be/v1/games/{$game->slug}/content" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['characters'] );
		$this->assertSame( 2, $response->get_data()['plots'], "each character's own plot goes with the chronicle" );

		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $game->id, $hst, 'hst' );
		wp_set_current_user( $hst );
		$this->assertSame( 403, $this->dispatch( 'GET', "/be/v1/games/{$game->slug}/content" )->get_status() );
	}

	public function test_deleting_with_content_leaves_nothing_under_the_chronicle_and_no_site_template_is_touched(): void {
		$game      = $this->game( 'thread-delete-everything' );
		$character = $this->character( $game->slug );
		Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', $game->slug );
		Attestation::issue( $character, 'gex', str_repeat( 'b', 64 ) );
		$this->transfer( 'outbound', $game->slug, 'somewhere-else' );
		$this->transfer( 'inbound', 'a-remote-home', $game->slug );
		Game_Member::set_role( (int) $game->id, $this->admin, 'hst' );
		Plot::create( [ 'game_id' => (int) $game->id, 'title' => 'Doomed Plot' ] );
		World_Object::create( [ 'game_id' => (int) $game->id, 'object_type' => 'item', 'name' => 'Doomed Item' ] );
		Saved_Query::create( [ 'game_id' => (int) $game->id, 'name' => 'Doomed Query', 'inventory' => 'char', 'conditions' => [], 'created_by' => $this->admin ] );
		$layout = [ 'version' => 1, 'columns' => 3, 'sections' => [] ];
		Template::create( [ 'game_id' => (int) $game->id, 'stack_slug' => 'vampire', 'name' => 'Doomed Template', 'template_type' => 'sheet_full', 'layout' => $layout ] );
		$site_template = Template::create( [ 'game_id' => null, 'stack_slug' => 'vampire', 'name' => 'A Site Admin\'s Own Template', 'template_type' => 'thread_site_custom', 'layout' => $layout ] );
		$this->assertGreaterThan( 0, $site_template, 'fixture sanity check' );

		$response = $this->dispatch( 'DELETE', "/be/v1/games/{$game->slug}", [], [ 'with_content' => 1 ] );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Game::find_by_slug( $game->slug ) );
		$this->assertSame( 0, $this->rows( 'characters', 'owner_slug = %s', $game->slug ) );
		$this->assertSame( 0, $this->rows( 'schema_blocks', 'game_slug = %s', $game->slug ), 'D42: forks go with their chronicle' );
		$this->assertSame( 0, $this->rows( 'character_attestations', 'game_slug = %s', $game->slug ) );
		$this->assertSame( 0, $this->rows( 'character_transfers', 'home_slug = %s OR host_slug = %s', $game->slug, $game->slug ) );
		$this->assertSame( 0, $this->rows( 'game_members', 'game_id = %d', $game->id ) );
		$this->assertSame( 0, $this->rows( 'plots', 'game_id = %d', $game->id ) );
		$this->assertSame( 0, $this->rows( 'world_objects', 'game_id = %d', $game->id ) );
		$this->assertSame( 0, $this->rows( 'queries', 'game_id = %d', $game->id ) );
		$this->assertSame( 0, $this->rows( 'templates', 'game_id = %d', $game->id ) );
		$this->assertNotNull( Template::find( $site_template ), 'deleting one chronicle must never delete a site-wide template' );
	}

	public function test_an_empty_chronicle_deletes_along_with_its_memberships(): void {
		$game = $this->game( 'thread-delete-empty' );
		Game_Member::set_role( (int) $game->id, $this->admin, 'hst' );
		Saved_Query::save_recent( (int) $game->id, $this->admin, 'char', true, [] );

		$response = $this->dispatch( 'DELETE', "/be/v1/games/{$game->slug}" );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( 0, $this->rows( 'game_members', 'game_id = %d', $game->id ) );
		$this->assertSame( 0, $this->rows( 'queries', 'game_id = %d', $game->id ) );
	}

	public function test_a_new_chronicle_never_takes_a_slug_that_still_holds_a_deleted_chronicles_content(): void {
		// No be_games row: an earlier row-only delete left this character behind.
		$this->character( 'thread-orphaned-slug', 'Orphaned Sheet' );

		$generated = Game::find( (int) Game::create( [ 'name' => 'Thread Orphaned Slug' ] ) );
		$this->assertNotSame( 'thread-orphaned-slug', $generated->slug );

		$response = $this->dispatch( 'POST', '/be/v1/games', [ 'name' => 'Explicit Slug', 'slug' => 'thread-orphaned-slug' ] );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'slug_has_orphaned_content', $response->as_error()->get_error_code() );
	}

	public function test_a_rename_never_adopts_orphaned_content_at_its_destination(): void {
		$game   = $this->game( 'thread-rename-source' );
		$orphan = $this->character( 'thread-rename-orphans', 'Not Yours' );

		$response = $this->dispatch( 'PUT', "/be/v1/games/{$game->slug}", [ 'slug' => 'thread-rename-orphans' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'orphan_collision', $response->as_error()->get_error_code() );
		$this->assertSame( 'thread-rename-source', Game::find( (int) $game->id )->slug );
		$this->assertSame( 'thread-rename-orphans', Character::find( (int) $orphan->id )->owner_slug );
	}

	public function test_a_rename_carries_the_chronicles_verification_codes_and_transfers(): void {
		$game      = $this->game( 'thread-rename-carry' );
		$character = $this->character( $game->slug );
		$code      = Attestation::issue( $character, 'gex', str_repeat( 'c', 64 ) );
		$outbound  = $this->transfer( 'outbound', $game->slug, 'far-away' );
		$inbound   = $this->transfer( 'inbound', 'far-away-home', $game->slug );
		// A visitor whose home chronicle, on another site, happens to share this slug is not ours to rename.
		$foreign = $this->transfer( 'inbound', $game->slug, 'another-local-chronicle' );

		$response = $this->dispatch( 'PUT', "/be/v1/games/{$game->slug}", [ 'slug' => 'thread-rename-carried' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'thread-rename-carried', Attestation::find( (int) $code->id )->game_slug );
		$this->assertSame( 'thread-rename-carried', Transfer::find( $outbound )->home_slug );
		$this->assertSame( 'thread-rename-carried', Transfer::find( $inbound )->host_slug );
		$this->assertSame( $game->slug, Transfer::find( $foreign )->home_slug );
	}

	public function test_a_deleted_demo_chronicle_is_never_seeded_again(): void {
		if ( ! Game::find_by_slug( 'be-demo' ) ) {
			$this->game( 'be-demo' );
		}
		$this->assertTrue( Game::delete_with_content( 'be-demo' ) );

		// An install upgrading from a version that predates the seeded-once flag.
		delete_option( Seeder::DEMO_SEEDED_OPTION );
		Seeder::seed_demo_characters( false );
		Seeder::seed_demo_characters( false );

		$this->assertNull( Game::find_by_slug( 'be-demo' ) );
		$this->assertSame( 0, $this->rows( 'characters', 'owner_slug = %s', 'be-demo' ) );
	}
}
