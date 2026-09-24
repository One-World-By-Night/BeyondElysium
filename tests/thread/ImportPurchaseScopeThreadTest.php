<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Purchase_Scope;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Import reads the purchase lists a chronicle has opened.
 */
class ImportPurchaseScopeThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-import-scope';
	private int $game_id;
	private int $admin_id;
	private string $ability;

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		do_action( 'rest_api_init' );
		Purchase_Scope::reset_cache();

		// A new install starts on the per-creature catalog.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::declare_fresh_install();
		Seeder::seed_creature_stacks();

		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Import Scope',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id  = (int) $wpdb->insert_id;
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// Named from the catalog itself.
		$vampire       = array_map( static fn( $item ) => $item->name, Schema_Block::find_by_slug( 'vampire-abilities' )->definition->items );
		$only_mage     = array_values( array_filter( array_map( static fn( $item ) => $item->name, Schema_Block::find_by_slug( 'mage-abilities' )->definition->items ), static fn( $name ) => ! in_array( $name, $vampire, true ) ) );
		$this->assertNotEmpty( $only_mage, 'the catalog has an Ability only Mage lists' );
		$this->ability = $only_mage[0];
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Purchase_Scope::reset_cache();
		parent::tearDown();
	}

	/** @param array<string,bool> $scope */
	private function open( array $scope ): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'purchase_scope' => $scope ] ] );
		Purchase_Scope::reset_cache();
	}

	/**
	 * A vampire record shaped like `GEX_Parser::parse_character_vampire()`, holding one Ability.
	 */
	private function character( string $ability, string $name ): array {
		return [
			'race' => 'vampire', 'name' => $name, 'player' => 'Jane Doe',
			'nature' => 'Survivor', 'demeanor' => 'Bravo', 'clan' => 'Toreador', 'sect' => 'Camarilla',
			'generation' => 10, 'sire' => 'Old One', 'title' => 'Whip', 'path' => 'Humanity', 'path_traits' => 7, 'temp_path_traits' => 7,
			'blood' => 12, 'temp_blood' => 12, 'willpower' => 6, 'temp_willpower' => 6,
			'conscience' => 3, 'temp_conscience' => 3, 'self_control' => 2, 'temp_self_control' => 2, 'courage' => 4, 'temp_courage' => 4,
			'is_npc' => false, 'narrator' => '', 'start_date' => null, 'biography' => '', 'notes' => '', 'status' => 'Active',
			'experience' => [ 'earned' => 12.0, 'unspent' => 5.0, 'history' => [] ],
			'trait_lists' => [
				'Abilities' => [ 'name' => 'Abilities', 'traits' => [ [ 'name' => $ability, 'total' => '2', 'note' => '' ] ] ],
			],
		];
	}

	/** @return array{0:array<string,mixed>,1:array<string,mixed>} The parsed file and the real preview built from it. */
	private function preview( string $ability, string $name = 'Scope Importer' ): array {
		$parsed = [
			'version' => 2.399, 'players' => [ [ 'name' => 'Jane Doe', 'email' => 'jane@example.test' ] ],
			'characters' => [ $this->character( $ability, $name ) ],
			'items' => [], 'locations' => [], 'rotes' => [], 'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
		];
		$build = new \ReflectionMethod( \BeyondElysium\REST\Import_Controller::class, 'build_preview' );
		$build->setAccessible( true );
		return [ $parsed, $build->invoke( null, $parsed, $this->game_slug, $this->game_id ) ];
	}

	/**
	 * Imports one character through the real preview and commit, and returns its stored Abilities.
	 */
	private function import_abilities( string $ability, string $name = 'Scope Importer' ): array {
		wp_set_current_user( $this->admin_id );
		[ $parsed, $preview ] = $this->preview( $ability, $name );

		$job_id = wp_generate_uuid4();
		set_transient( 'be_import_job_' . $job_id, [ 'game_id' => $this->game_id, 'parsed' => $parsed, 'preview' => $preview, 'source_file' => 'scope.gex' ], HOUR_IN_SECONDS );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/import/{$job_id}/commit" ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		return Character::find( (int) $data['characters'][0]['id'] )->sheet_data['vampire-abilities'] ?? [];
	}

	public function test_an_ability_only_another_creature_type_lists_imports_as_custom_until_the_list_is_open(): void {
		$rows = $this->import_abilities( $this->ability );

		$this->assertCount( 1, $rows );
		$this->assertSame( $this->ability, $rows[0]['name'] );
		$this->assertTrue( $rows[0]['custom'], 'not in the Vampire list, so a custom entry waiting for a price' );
	}

	public function test_with_abilities_open_it_imports_as_the_catalog_entry_it_is(): void {
		$this->open( [ 'abilities' => true ] );

		$rows = $this->import_abilities( $this->ability );

		$this->assertCount( 1, $rows );
		$this->assertSame( $this->ability, $rows[0]['name'] );
		$this->assertArrayNotHasKey( 'custom', $rows[0], 'a catalog entry, priced by its own list' );
		$this->assertSame( 2, $rows[0]['count'] );
	}

	public function test_another_area_being_open_does_not_open_abilities(): void {
		$this->open( [ 'backgrounds' => true, 'merits_flaws' => true ] );

		$rows = $this->import_abilities( $this->ability );

		$this->assertTrue( $rows[0]['custom'] );
	}

	public function test_the_preview_suggests_the_other_creature_types_entry_for_a_near_miss_only_when_the_list_is_open(): void {
		$near_miss = substr( $this->ability, 0, -1 );
		$suggested = function () use ( $near_miss ): array {
			$found = [];
			foreach ( $this->preview( $near_miss )[1]['flagged_traits'] as $flag ) {
				if ( $flag['raw'] === $near_miss ) {
					$found = array_merge( $found, (array) $flag['suggestions'] );
				}
			}
			return $found;
		};

		$this->assertNotContains( $this->ability, $suggested(), 'not offered while the list is closed' );

		$this->open( [ 'abilities' => true ] );

		$this->assertContains( $this->ability, $suggested(), 'offered as a suggestion once the list is open' );
	}

	public function test_an_entry_the_characters_own_list_has_imports_the_same_either_way(): void {
		$own = Schema_Block::find_by_slug( 'vampire-abilities' )->definition->items[0]->name;

		$closed = $this->import_abilities( $own, 'Closed Importer' );
		$this->open( [ 'abilities' => true ] );
		$open = $this->import_abilities( $own, 'Open Importer' );

		$this->assertSame( $own, $closed[0]['name'] );
		$this->assertArrayNotHasKey( 'custom', $closed[0] );
		$this->assertSame( $closed[0]['name'], $open[0]['name'] );
		$this->assertArrayNotHasKey( 'custom', $open[0] );
	}
}
