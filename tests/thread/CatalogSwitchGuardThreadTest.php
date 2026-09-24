<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\REST\Catalog_Switch_Guard;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.3.3 pre-deploy trace: a save that lands between a character's re-key and the site's flip is
 * written to the retired block of a sheet that has already moved, and is invisible afterwards with
 * its XP spent. While a cutover run holds its lock, every save to the plugin's REST API is refused
 * (503); reads are not, and the refusal ends by itself when a run dies holding the lock.
 */
class CatalogSwitchGuardThreadTest extends WP_UnitTestCase {

	private string $game = 'switch-guard';
	private int $st_id;
	private int $player_id;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		// apply() runs over the whole install, and this database's plugin tables outlive a run; clear
		// every character inside this test's own transaction so a case sees only what it builds.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );

		$game_id         = (int) Game::create( [ 'slug' => $this->game, 'name' => 'Switch Guard' ] );
		$this->st_id     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player_id, 'player' );

		$this->character_id = $this->character( 'Guarded Vampire', [ 'met-abilities' => [ [ 'name' => 'Melee', 'count' => 2 ] ] ], $this->player_id );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		delete_option( Catalog_Cutover::RECORD_OPTION );
		Option_Lock::release( Catalog_Cutover::LOCK );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	/** @param array<string,mixed> $sheet */
	private function character( string $name, array $sheet, ?int $owner = null ): int {
		$id = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game, 'wp_user_id' => $owner, 'status' => 'active', 'sheet_data' => $sheet,
		] );
		Character::update_xp( $id, 50, 12 );
		return $id;
	}

	/** Holds the cutover lock as a run that claimed it `$seconds_ago` seconds ago. */
	private function hold_lock( int $seconds_ago = 0 ): void {
		global $wpdb;
		$this->assertTrue( Option_Lock::claim( Catalog_Cutover::LOCK, 1800 ) );
		$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() - $seconds_ago ) ], [ 'option_name' => Catalog_Cutover::LOCK ] );
		wp_cache_delete( Catalog_Cutover::LOCK, 'options' );
	}

	private function submit_melee() {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'add_trait' );
		$request->set_param( 'category', 'met-abilities' );
		$request->set_param( 'change_data', [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Alertness', 'count' => 1 ] ] );
		return rest_get_server()->dispatch( $request );
	}

	private function approve( int $change_id ) {
		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$request->set_param( 'review_token', Change::review_token( Change::find( $change_id ) ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_save_is_refused_while_a_cutover_run_holds_the_lock_and_nothing_is_written(): void {
		$this->hold_lock();

		$response = $this->submit_melee();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'catalog_switch_in_progress', $response->as_error()->get_error_code() );
		$this->assertSame( [], Change::for_character( $this->character_id ), 'no change row was created' );
		$this->assertCount( 1, Character::find( $this->character_id )->sheet_data['met-abilities'] );
	}

	public function test_a_read_is_never_refused(): void {
		$this->hold_lock();

		wp_set_current_user( $this->player_id );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game}/characters/{$this->character_id}" ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_saving_works_again_once_the_run_releases_the_lock(): void {
		$this->hold_lock();
		$this->assertSame( 503, $this->submit_melee()->get_status() );

		Option_Lock::release( Catalog_Cutover::LOCK );

		$this->assertSame( 201, $this->submit_melee()->get_status() );
	}

	public function test_a_lock_a_dead_run_left_behind_stops_refusing_saves_after_the_hold(): void {
		$this->hold_lock( Catalog_Cutover::WRITE_HOLD + 5 );
		$this->assertFalse( Catalog_Cutover::switching() );
		$this->assertSame( 201, $this->submit_melee()->get_status(), 'a run that died two minutes ago is not still running' );
	}

	public function test_a_lock_still_inside_the_hold_keeps_refusing(): void {
		$this->hold_lock( Catalog_Cutover::WRITE_HOLD - 5 );
		$this->assertTrue( Catalog_Cutover::switching() );
		$this->assertSame( 503, $this->submit_melee()->get_status() );
	}

	public function test_the_hold_outlasts_a_run_and_falls_well_short_of_the_locks_own_thirty_minute_claim(): void {
		$this->assertGreaterThanOrEqual( 60, Catalog_Cutover::WRITE_HOLD, 'a run over a large chronicle takes seconds, so a minute is the least that covers it' );
		$this->assertLessThanOrEqual( 300, Catalog_Cutover::WRITE_HOLD, 'a run that died must not freeze saves for the full 1800 seconds' );
	}

	/** @return array<string,array{string}> */
	public static function write_methods(): array {
		return [ 'POST' => [ 'POST' ], 'PUT' => [ 'PUT' ], 'PATCH' => [ 'PATCH' ], 'DELETE' => [ 'DELETE' ] ];
	}

	/** @dataProvider write_methods */
	public function test_every_write_method_on_a_plugin_route_is_refused( string $method ): void {
		$this->hold_lock();

		$result = Catalog_Switch_Guard::check( null, [], new WP_REST_Request( $method, "/be/v1/{$this->game}/plots" ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'catalog_switch_in_progress', $result->get_error_code() );
	}

	/** @dataProvider read_methods */
	public function test_a_read_method_on_a_plugin_route_passes( string $method ): void {
		$this->hold_lock();

		$this->assertNull( Catalog_Switch_Guard::check( null, [], new WP_REST_Request( $method, "/be/v1/{$this->game}/plots" ) ) );
	}

	/** @return array<string,array{string}> */
	public static function read_methods(): array {
		return [ 'GET' => [ 'GET' ], 'HEAD' => [ 'HEAD' ], 'OPTIONS' => [ 'OPTIONS' ] ];
	}

	public function test_only_the_plugins_own_routes_are_guarded(): void {
		$this->hold_lock();

		$this->assertNull( Catalog_Switch_Guard::check( null, [], new WP_REST_Request( 'POST', '/wp/v2/posts' ) ) );
	}

	public function test_an_earlier_filters_error_is_passed_through_untouched(): void {
		$this->hold_lock();
		$earlier = new WP_Error( 'earlier', 'an earlier filter said no', [ 'status' => 400 ] );

		$this->assertSame( $earlier, Catalog_Switch_Guard::check( $earlier, [], new WP_REST_Request( 'POST', "/be/v1/{$this->game}/plots" ) ) );
	}

	public function test_no_lock_means_nothing_is_refused(): void {
		$this->assertFalse( Catalog_Cutover::switching() );
		$this->assertNull( Catalog_Switch_Guard::check( null, [], new WP_REST_Request( 'POST', "/be/v1/{$this->game}/plots" ) ) );
	}

	public function test_a_change_approved_while_apply_runs_is_refused_and_nothing_is_stranded(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		$scenario = $this->character( 'A Scenario', [ 'met-abilities' => [ [ 'name' => 'Melee', 'count' => 2 ] ] ] );
		$trigger  = $this->character( 'Z Trigger', [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 1 ] ] ] );
		$change   = (int) Change::create( [
			'character_id' => $scenario, 'change_type' => 'add_trait', 'category' => 'trait',
			'change_data'  => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Alertness', 'count' => 1 ] ],
			'xp_cost'      => 3, 'status' => 'pending', 'submitted_by' => $this->st_id,
		] );
		$this->assertGreaterThan( 0, $change );

		// The instant the trigger is reached, everything before it - the scenario included - has been
		// re-keyed, and the install has not been flipped yet: the window a save can land in.
		$midrun = [];
		$result = Catalog_Cutover::apply( $this->st_id, [ 'on_character' => function ( int $id ) use ( $trigger, $scenario, $change, &$midrun ) {
			if ( $id !== $trigger ) {
				return;
			}
			$midrun = [
				'declared' => Catalog_Cutover::is_declared(),
				'moved'    => ! isset( Character::find( $scenario )->sheet_data['met-abilities'] ),
				'response' => $this->approve( $change ),
			];
		} ] );

		$this->assertSame( 'applied', $result['status'] );
		$this->assertNotEmpty( $midrun, 'the trigger character was reached' );
		$this->assertFalse( $midrun['declared'], 'precondition: the install is not flipped yet' );
		$this->assertTrue( $midrun['moved'], 'precondition: the scenario is already on the declared blocks' );
		$this->assertSame( 503, $midrun['response']->get_status() );

		$sheet = Character::find( $scenario )->sheet_data;
		$this->assertArrayNotHasKey( 'met-abilities', $sheet, 'nothing was written to the retired block' );
		$this->assertSame( 12.0, (float) Character::find( $scenario )->xp_unspent, 'no XP was spent on a save that never happened' );
		$this->assertSame( 'pending', Change::find( $change )->status );
		$this->assertFalse( Catalog_Cutover::switching(), 'the lock is released when the run ends' );

		// Once the run is over the same change goes through, into the live block.
		$after = $this->approve( $change );
		$this->assertSame( 200, $after->get_status() );
		$sheet = Character::find( $scenario )->sheet_data;
		$this->assertArrayNotHasKey( 'met-abilities', $sheet );
		$this->assertContains( 'Alertness', array_column( $sheet['vampire-abilities'], 'name' ) );
		$this->assertSame( 0, Catalog_Cutover::plan( $this->game, [ 'rows' => false, 'suggestions' => false ] )['characters_changed'], 'nothing is left under a retired block' );
	}
}
