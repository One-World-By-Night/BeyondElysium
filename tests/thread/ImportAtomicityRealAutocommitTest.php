<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Both import routes roll back the whole import when a step fails, on a real autocommit connection.
 */
class ImportAtomicityRealAutocommitTest extends TestCase {

	private const ADMIN_ID = 1;

	private string $prior_autocommit = '1';
	private string $slug;
	private int $game_id;
	private string $new_chronicle_name;

	/** @var string[] */
	private array $transients = [];

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR not configured - see BE_PROCESS/now/PLATFORM.md.' );
		}
	}

	protected function setUp(): void {
		global $wpdb;
		$this->prior_autocommit = (string) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( 'SET autocommit = 1;' );

		$suffix                   = strtolower( wp_generate_password( 8, false ) );
		$this->slug               = 'atomic-import-' . $suffix;
		$this->new_chronicle_name = 'Atomic Game Import ' . $suffix;

		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		wp_set_current_user( self::ADMIN_ID );
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	protected function tearDown(): void {
		global $wpdb;

		$slugs = [ $this->slug ];
		$new   = $wpdb->get_row( $wpdb->prepare( "SELECT id, slug FROM {$wpdb->prefix}be_games WHERE name = %s", $this->new_chronicle_name ) );
		if ( $new ) {
			$slugs[] = $new->slug;
			$wpdb->delete( $wpdb->prefix . 'be_game_members', [ 'game_id' => (int) $new->id ] );
		}

		foreach ( $slugs as $slug ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_characters WHERE owner_slug = %s", $slug ) );
			foreach ( $ids as $id ) {
				Character::delete( (int) $id );
			}
			$wpdb->delete( $wpdb->prefix . 'be_games', [ 'slug' => $slug ] );
		}

		foreach ( $this->transients as $key ) {
			delete_transient( $key );
		}

		wp_set_current_user( 0 );
		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	/**
	 * A vampire record shaped like `GEX_Parser::parse_character_vampire()`'s return.
	 */
	private function character( string $name ): array {
		return [
			'race' => 'vampire', 'name' => $name, 'player' => '', 'nature' => '', 'demeanor' => '',
			'clan' => 'Toreador', 'sect' => 'Camarilla', 'generation' => 10, 'sire' => '', 'title' => '',
			'path' => 'Humanity', 'path_traits' => 7, 'temp_path_traits' => 7, 'blood' => 12, 'temp_blood' => 12,
			'willpower' => 6, 'temp_willpower' => 6, 'conscience' => 3, 'temp_conscience' => 3,
			'self_control' => 2, 'temp_self_control' => 2, 'courage' => 4, 'temp_courage' => 4,
			'is_npc' => false, 'narrator' => '', 'start_date' => null, 'biography' => '', 'notes' => '',
			'status' => 'Active', 'experience' => [ 'earned' => 3.0, 'unspent' => 1.0, 'history' => [] ],
			'trait_lists' => [],
		];
	}

	/**
	 * The first character imports cleanly.
	 */
	private function parsed_with_a_failing_second_character(): array {
		return [
			'version' => 2.399, 'players' => [], 'items' => [], 'locations' => [], 'rotes' => [],
			'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
			'characters' => [ $this->character( 'Atomic First' ), $this->character( str_repeat( 'x', 300 ) ) ],
		];
	}

	private function characters_in( string $slug ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_characters WHERE owner_slug = %s", $slug ) );
	}

	public function test_environment_sanity(): void {
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( 'SELECT @@autocommit' ) );
		$this->assertTrue( current_user_can( 'be_manage_characters' ), 'The import note must auto-approve for this test to reach the nested transaction.' );
	}

	public function test_a_chronicle_import_that_fails_partway_keeps_nothing(): void {
		$job_id             = wp_generate_uuid4();
		$this->transients[] = 'be_import_job_' . $job_id;
		set_transient( 'be_import_job_' . $job_id, [
			'game_id'     => $this->game_id,
			'parsed'      => $this->parsed_with_a_failing_second_character(),
			'source_file' => 'atomic.gex',
		], HOUR_IN_SECONDS );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/import/{$job_id}/commit" ) );

		$this->assertSame( 500, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 0, $this->characters_in( $this->slug ), 'The first character must roll back with the failed import.' );
	}

	public function test_a_game_file_import_that_fails_partway_creates_no_chronicle(): void {
		global $wpdb;
		$job_id             = wp_generate_uuid4();
		$this->transients[] = 'be_game_import_job_' . $job_id;
		set_transient( 'be_game_import_job_' . $job_id, [
			'parsed'      => $this->parsed_with_a_failing_second_character(),
			'source_file' => 'atomic.gv3',
		], HOUR_IN_SECONDS );

		$request = new WP_REST_Request( 'POST', "/be/v1/import/game/{$job_id}/commit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'target' => [ 'action' => 'create_new', 'name' => $this->new_chronicle_name ] ] ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 500, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNull(
			$wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_games WHERE name = %s", $this->new_chronicle_name ) ),
			'A failed game-file import must not leave its new chronicle behind.'
		);
	}
}
