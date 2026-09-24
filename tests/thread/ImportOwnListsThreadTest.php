<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Purchase_Scope;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Import of a Grapevine file's Abilities, Merits, Flaws and Rites: each list lands on the block the character's
 * creature type keeps (`vampire-abilities`; `fera-abilities` for a Fera and a Bete; `werewolf-rites` for a Werewolf
 * and `fera-rites` for a Fera and a Bete).
 */
class ImportOwnListsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-import-own-lists';
	private int $game_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		do_action( 'rest_api_init' );
		Purchase_Scope::reset_cache();

		// Declares the install and seeds the creature stacks.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::declare_fresh_install();
		Seeder::seed_creature_stacks();

		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Import Own Lists',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id  = (int) $wpdb->insert_id;
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Purchase_Scope::reset_cache();
		parent::tearDown();
	}

	/**
	 * The first item the catalog's block lists.
	 */
	private function first_item( string $block_slug ): string {
		$block = Schema_Block::find_by_slug( $block_slug );
		$this->assertNotNull( $block, "{$block_slug} is a seeded block" );
		return (string) $block->definition->items[0]->name;
	}

	/**
	 * One list of a parsed record, holding one entry.
	 */
	private function list_of( string $name, string $entry ): array {
		return [ 'name' => $name, 'traits' => [ [ 'name' => $entry, 'total' => '1', 'note' => '' ] ] ];
	}

	/**
	 * A parsed record for one creature type, holding one entry in each list named.
	 *
	 * @param array<string,string> $entries GV list name => the catalog entry to hold.
	 */
	private function record( string $race, string $name, array $entries ): array {
		$lists = [];
		foreach ( $entries as $list => $entry ) {
			$lists[ $list ] = $this->list_of( $list, $entry );
		}
		return [
			'race' => $race, 'name' => $name, 'player' => 'Jane Doe',
			'nature' => 'Survivor', 'demeanor' => 'Bravo',
			'is_npc' => false, 'narrator' => '', 'start_date' => null, 'biography' => '', 'notes' => '', 'status' => 'Active',
			'experience' => [ 'earned' => 12.0, 'unspent' => 5.0, 'history' => [] ],
			'trait_lists' => $lists,
		];
	}

	/**
	 * Imports one record through the real preview and commit and returns the character it made.
	 */
	private function import( array $character ): object {
		wp_set_current_user( $this->admin_id );
		$parsed = [
			'version' => 2.399, 'players' => [ [ 'name' => 'Jane Doe', 'email' => 'jane@example.test' ] ],
			'characters' => [ $character ],
			'items' => [], 'locations' => [], 'rotes' => [], 'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
		];
		$build = new \ReflectionMethod( \BeyondElysium\REST\Import_Controller::class, 'build_preview' );
		$build->setAccessible( true );
		$preview = $build->invoke( null, $parsed, $this->game_slug, $this->game_id );

		$job_id = wp_generate_uuid4();
		set_transient( 'be_import_job_' . $job_id, [ 'game_id' => $this->game_id, 'parsed' => $parsed, 'preview' => $preview, 'source_file' => 'own-lists.gex' ], HOUR_IN_SECONDS );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/import/{$job_id}/commit" ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		return Character::find( (int) $data['characters'][0]['id'] );
	}

	/** @param array<string,mixed> $sheet */
	private function names_in( array $sheet, string $block ): array {
		return array_map( static fn( $row ) => $row['name'], (array) ( $sheet[ $block ] ?? [] ) );
	}

	/**
	 * @dataProvider stacks_with_own_lists
	 *
	 * @param string   $stack    The creature type's slug.
	 * @param string   $race     The record's race.
	 * @param string   $lists_of The block family holding its Abilities, Merits and Flaws.
	 * @param string[] $rites    The Rites block it holds, if any.
	 */
	public function test_abilities_merits_and_flaws_import_onto_the_creature_types_own_lists( string $stack, string $race, string $lists_of, array $rites ): void {
		$entries = [
			'Abilities' => $this->first_item( "{$lists_of}-abilities" ),
			'Merits'    => $this->first_item( "{$lists_of}-merits" ),
			'Flaws'     => $this->first_item( "{$lists_of}-flaws" ),
		];
		foreach ( $rites as $block ) {
			$entries['Rites'] = $this->first_item( $block );
		}

		$character = $this->import( $this->record( $race, "Own Lists {$stack}", $entries ) );

		$this->assertSame( $stack, $character->stack_slug );
		$this->assertSame( [ $entries['Abilities'] ], $this->names_in( $character->sheet_data, "{$lists_of}-abilities" ) );
		$this->assertSame( [ $entries['Merits'] ], $this->names_in( $character->sheet_data, "{$lists_of}-merits" ) );
		$this->assertSame( [ $entries['Flaws'] ], $this->names_in( $character->sheet_data, "{$lists_of}-flaws" ) );
		foreach ( $rites as $block ) {
			$this->assertSame( [ $entries['Rites'] ], $this->names_in( $character->sheet_data, $block ), "Rites land on {$block}" );
		}

		foreach ( [ 'met-abilities', 'met-merits', 'met-flaws' ] as $retired ) {
			$this->assertArrayNotHasKey( $retired, $character->sheet_data, "{$stack} holds no {$retired}" );
		}
		if ( $rites !== [ 'werewolf-rites' ] ) {
			$this->assertArrayNotHasKey( 'werewolf-rites', $character->sheet_data, "{$stack} holds no werewolf-rites" );
		}
	}

	/** @return array<string,array{0:string,1:string,2:string,3:string[]}> */
	public static function stacks_with_own_lists(): array {
		return [
			'vampire'  => [ 'vampire', 'vampire', 'vampire', [] ],
			'werewolf' => [ 'werewolf', 'werewolf', 'werewolf', [ 'werewolf-rites' ] ],
			'fera'     => [ 'fera', 'fera', 'fera', [ 'fera-rites' ] ],
			'bete'     => [ 'bete', 'bete', 'fera', [ 'fera-rites' ] ],
		];
	}
}
