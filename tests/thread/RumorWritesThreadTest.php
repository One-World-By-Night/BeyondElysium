<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/RowLockProbe.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Rumor_Generator;
use BeyondElysium\Tests\Support\RowLockProbe;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * (found triaging).
 */
class RumorWritesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-rumor-writes';
	private int $game_id;
	private string $date = '2026-03-14';
	private array $mail = [];
	private string $broken_table = '';
	private int $spared = 0;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		// Two rumors a date: Public Knowledge, and the character's own.
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Rumor Writes', 'settings' => [ 'apr' => [ 'personal_rumors' => true ] ] ] );
		Character::create( [
			'name' => 'Rumor Writes Character', 'stack_slug' => 'vampire', 'status' => 'active',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => self::factory()->user->create( [ 'role' => 'subscriber' ] ),
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_filter( 'query', [ $this, 'break_inserts' ] );
		parent::tear_down();
	}

	public function capture_mail( $pre, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	/**
	 * Fails inserts into one table after the first `$spared`, as a lost connection or lock timeout would.
	 */
	public function break_inserts( string $query ): string {
		global $wpdb;
		if ( $this->broken_table === '' || ! preg_match( "/^\s*INSERT INTO `?{$wpdb->prefix}be_{$this->broken_table}`?/i", $query ) ) {
			return $query;
		}
		if ( $this->spared > 0 ) {
			--$this->spared;
			return $query;
		}
		return 'INSERT INTO be_no_such_table VALUES (1)';
	}

	private function with_failing_inserts( string $table, callable $call, int $spared = 0 ) {
		global $wpdb;
		$this->broken_table = $table;
		$this->spared       = $spared;
		add_filter( 'query', [ $this, 'break_inserts' ] );
		$quiet = $wpdb->suppress_errors( true );
		try {
			return $call();
		} finally {
			$wpdb->suppress_errors( $quiet );
			remove_filter( 'query', [ $this, 'break_inserts' ] );
			$this->broken_table = '';
		}
	}

	private function commit_rumors(): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/plots/generate-rumors" );
		$request->set_param( 'game_date', $this->date );
		$request->set_param( 'commit', true );
		return rest_get_server()->dispatch( $request );
	}

	private function plots_on_the_date(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_plots WHERE game_id = %d AND game_date = %s", $this->game_id, $this->date ) );
	}

	public function test_rumors_whose_tags_did_not_save_are_not_kept_or_announced(): void {
		$response = $this->with_failing_inserts( 'connections', fn() => $this->commit_rumors() );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 0, $this->plots_on_the_date(), 'no untagged plot left for the next generation to duplicate' );
		$this->assertSame( [], $this->mail, 'no player told about a rumor that was never saved' );
	}

	public function test_one_rumor_that_did_not_save_keeps_none_of_the_date(): void {
		// The date's first rumor saves.
		$response = $this->with_failing_inserts( 'plots', fn() => $this->commit_rumors(), 1 );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 0, $this->plots_on_the_date() );
		$this->assertSame( [], $this->mail );

		$this->assertSame( 201, $this->commit_rumors()->get_status(), 'and the Storyteller can simply commit again' );
		$this->assertSame( 2, $this->plots_on_the_date() );
	}

	public function test_a_hand_written_rumor_whose_tag_did_not_save_is_not_kept_as_a_plot(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/plots" );
		$request->set_param( 'title', 'Whispers at the harbor' );
		$request->set_param( 'is_rumor', true );

		$response = $this->with_failing_inserts( 'connections', static fn() => rest_get_server()->dispatch( $request ) );

		$this->assertSame( 500, $response->get_status() );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_plots WHERE title = %s", 'Whispers at the harbor' ) ) );
	}

	public function test_another_generation_waits_while_one_checks_and_writes_the_date(): void {
		$demo = Game::find_by_slug( 'be-demo' );
		$this->assertNotNull( $demo, 'The demo chronicle is seeded.' );

		$lockable = null;
		$probe    = static function ( $query ) use ( &$lockable, $demo ) {
			if ( $lockable === null && preg_match( '/^\s*INSERT INTO `?\w*be_plots`?/i', $query ) ) {
				$lockable = RowLockProbe::could_lock( 'games', (int) $demo->id );
			}
			return $query;
		};

		add_filter( 'query', $probe );
		Rumor_Generator::generate( (int) $demo->id, '2099-12-31', true );
		remove_filter( 'query', $probe );

		$this->assertFalse( $lockable, 'Another generation for the chronicle could check the date mid-write.' );
	}
}
