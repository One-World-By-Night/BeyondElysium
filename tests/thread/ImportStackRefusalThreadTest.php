<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A character whose creature type doesn't exist on this site - or isn't enabled in this chronicle - is refused by
 * name at both preview and commit, and nothing is written for it. The rest of a file still imports. "chimera" here
 * is a synthetic race name standing in for "no real creature type on this site" - every real Grapevine race now
 * ships a stack, so none is left to use as the example.
 */
class ImportStackRefusalThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-import-stack-refusal';
	private int $game_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Import Stack Refusal',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A minimal vampire character record - shaped like `GEX_Parser::parse_character_vampire()`'s real return array,
	 * trimmed to what a vampire actually needs to import cleanly.
	 */
	private function vampire( string $name ): array {
		return [
			'race' => 'vampire', 'name' => $name, 'player' => '', 'nature' => '', 'demeanor' => '',
			'clan' => 'Toreador', 'sect' => 'Camarilla', 'generation' => 10, 'sire' => '', 'title' => '',
			'path' => 'Humanity', 'path_traits' => 7, 'temp_path_traits' => 7,
			'blood' => 10, 'temp_blood' => 10, 'willpower' => 5, 'temp_willpower' => 5,
			'conscience' => 3, 'temp_conscience' => 3, 'self_control' => 3, 'temp_self_control' => 3,
			'courage' => 3, 'temp_courage' => 3, 'is_npc' => false, 'narrator' => '', 'start_date' => null,
			'biography' => '', 'notes' => '', 'status' => 'Active',
			'experience' => [ 'earned' => 0.0, 'unspent' => 0.0, 'history' => [] ],
			'trait_lists' => [],
		];
	}

	/**
	 * A minimal character of a race with no fields this importer ever reads beyond name/race, since a refused
	 * character is turned away before anything else about it is touched.
	 */
	private function refusable( string $race, string $name ): array {
		return [ 'race' => $race, 'name' => $name, 'trait_lists' => [] ];
	}

	private function synthetic_parsed( array $characters ): array {
		return [
			'version' => 2.399, 'players' => [], 'characters' => $characters,
			'items' => [], 'locations' => [], 'rotes' => [], 'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
		];
	}

	private function build_preview( array $parsed ) {
		$reflection = new \ReflectionMethod( \BeyondElysium\REST\Import_Controller::class, 'build_preview' );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, $parsed, $this->game_slug, $this->game_id );
	}

	private function apply_import( array $parsed ) {
		$reflection = new \ReflectionMethod( \BeyondElysium\REST\Import_Controller::class, 'apply_import' );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, $this->game_id, $this->game_slug, $parsed, 'synthetic-test.gex', [] );
	}

	public function test_a_file_with_a_vampire_and_a_chimera_imports_the_vampire_and_refuses_the_chimera_by_name(): void {
		wp_set_current_user( $this->admin_id );
		$before = Character::count_for_game( $this->game_slug );

		$result = $this->apply_import( $this->synthetic_parsed( [
			$this->vampire( 'Real Vampire' ),
			$this->refusable( 'chimera', 'A Chimera' ),
		] ) );

		$this->assertCount( 2, $result['characters'] );
		$this->assertSame( $before + 1, Character::count_for_game( $this->game_slug ), 'only the vampire is written' );

		$vampire_row = current( array_filter( $result['characters'], static fn( $c ) => $c['name'] === 'Real Vampire' ) );
		$this->assertSame( 'created', $vampire_row['action'] );
		$this->assertGreaterThan( 0, $vampire_row['id'] );

		$chimera_row = current( array_filter( $result['characters'], static fn( $c ) => $c['name'] === 'A Chimera' ) );
		$this->assertSame( 'refused', $chimera_row['action'] );
		$this->assertSame( 0, $chimera_row['id'] );
		$this->assertStringContainsString( 'Chimera', $chimera_row['reason'] );
		$this->assertStringContainsString( 'there is no Chimera creature type on this site', $chimera_row['reason'] );
	}

	public function test_the_preview_lists_the_refusal(): void {
		$preview = $this->build_preview( $this->synthetic_parsed( [
			$this->vampire( 'Real Vampire' ),
			$this->refusable( 'chimera', 'A Chimera' ),
		] ) );

		$this->assertCount( 1, $preview['refused'] );
		$this->assertSame( 'A Chimera', $preview['refused'][0]['character'] );
		$this->assertStringContainsString( 'Chimera', $preview['refused'][0]['reason'] );
	}

	public function test_a_chimera_character_is_refused_the_same_way_with_no_target_chronicle_chosen_yet(): void {
		// Game_Import_Controller's own "create a new chronicle" preview calls build_preview() with no game_slug -
		// the exact case where an import is creating a chronicle from scratch, not merging into one.
		$reflection = new \ReflectionMethod( \BeyondElysium\REST\Import_Controller::class, 'build_preview' );
		$reflection->setAccessible( true );
		$preview = $reflection->invoke( null, $this->synthetic_parsed( [
			$this->refusable( 'chimera', 'A Chimera' ),
		] ), '', 0 );

		$this->assertCount( 1, $preview['refused'] );
		$this->assertSame( 'A Chimera', $preview['refused'][0]['character'] );
		$this->assertStringContainsString( 'Chimera', $preview['refused'][0]['reason'] );
	}

	public function test_a_chronicle_that_disabled_a_creature_type_refuses_that_type_the_same_way(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_stacks' => [ 'vampire' ] ] ] );

		$result = $this->apply_import( $this->synthetic_parsed( [
			$this->vampire( 'Real Vampire' ),
			$this->refusable( 'werewolf', 'A Werewolf' ),
		] ) );

		$werewolf_row = current( array_filter( $result['characters'], static fn( $c ) => $c['name'] === 'A Werewolf' ) );
		$this->assertSame( 'refused', $werewolf_row['action'] );
		$this->assertStringContainsString( 'Werewolf', $werewolf_row['reason'] );

		$vampire_row = current( array_filter( $result['characters'], static fn( $c ) => $c['name'] === 'Real Vampire' ) );
		$this->assertSame( 'created', $vampire_row['action'] );
	}

	public function test_nothing_is_written_for_a_refused_character(): void {
		wp_set_current_user( $this->admin_id );
		$before_characters = Character::count_for_game( $this->game_slug );
		$before_objects     = World_Object::count_for_game( $this->game_id );

		$this->apply_import( $this->synthetic_parsed( [
			$this->refusable( 'chimera', 'A Chimera' ),
		] ) );

		$this->assertSame( $before_characters, Character::count_for_game( $this->game_slug ) );
		$this->assertSame( $before_objects, World_Object::count_for_game( $this->game_id ) );
	}
}
