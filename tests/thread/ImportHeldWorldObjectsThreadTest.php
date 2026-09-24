<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\World_Object;
use BeyondElysium\REST\Import_Controller;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A character's Equipment and Locations lists become catalog entries plus connections on import.
 */
class ImportHeldWorldObjectsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-held-objects';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function parsed(): array {
		return [
			'version' => 2.399, 'players' => [], 'items' => [], 'locations' => [], 'rotes' => [],
			'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
			'characters' => [ [
				'race' => 'vampire', 'name' => 'Well Equipped', 'player' => '', 'nature' => '', 'demeanor' => '',
				'clan' => 'Ventrue', 'sect' => 'Camarilla', 'generation' => 11, 'sire' => '', 'title' => '',
				'path' => 'Humanity', 'path_traits' => 6, 'temp_path_traits' => 6, 'blood' => 11, 'temp_blood' => 11,
				'willpower' => 5, 'temp_willpower' => 5, 'conscience' => 3, 'temp_conscience' => 3,
				'self_control' => 3, 'temp_self_control' => 3, 'courage' => 3, 'temp_courage' => 3,
				'is_npc' => false, 'narrator' => '', 'start_date' => null, 'biography' => '', 'notes' => '',
				'status' => 'Active', 'experience' => [ 'earned' => 0.0, 'unspent' => 0.0, 'history' => [] ],
				'trait_lists' => [
					'Equipment' => [ 'name' => 'Equipment', 'traits' => [ [ 'name' => 'Silver Knife', 'total' => '1', 'note' => 'Blessed at St. Anne\'s' ] ] ],
					'Locations' => [ 'name' => 'Locations', 'traits' => [ [ 'name' => 'The Crypt', 'total' => '1', 'note' => '' ] ] ],
				],
			] ],
		];
	}

	private function commit( array $duplicates = [] ) {
		$job_id = wp_generate_uuid4();
		set_transient( 'be_import_job_' . $job_id, [ 'game_id' => $this->game_id, 'parsed' => $this->parsed(), 'source_file' => 'held.gex', 'format' => 'XML' ], HOUR_IN_SECONDS );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/import/{$job_id}/commit" );
		if ( $duplicates ) {
			$request->set_param( 'resolutions', [ 'duplicates' => $duplicates ] );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function held_by( int $character_id ): array {
		$held = [];
		foreach ( Connection::for_source( 'character', $character_id ) as $connection ) {
			if ( $connection->target_type === 'world_object' ) {
				$object = World_Object::find( (int) $connection->target_id );
				$held[ $object->object_type . ':' . $object->name ] = $connection->notes;
			}
		}
		ksort( $held );
		return $held;
	}

	public function test_an_imported_character_arrives_holding_its_items_and_locations(): void {
		$crypt = (int) World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'location', 'name' => 'The Crypt' ] );

		$preview = Import_Controller::build_preview( $this->parsed(), $this->slug, $this->game_id, [], 'XML' );
		$this->assertStringContainsString( 'Silver Knife', implode( ' ', $preview['warnings'] ), 'the preview says what the import will add to the catalog' );

		$response = $this->commit();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character = (int) $response->get_data()['characters'][0]['id'];
		$this->assertSame( [ 'item:Silver Knife' => 'Blessed at St. Anne\'s', 'location:The Crypt' => null ], $this->held_by( $character ) );
		$this->assertSame( $crypt, (int) World_Object::find_by_name_in_game( $this->game_id, 'location', 'The Crypt' )->id, 'an existing catalog entry is used, not copied' );
		$this->assertSame( [ 'Silver Knife' ], array_column( $response->get_data()['items'], 'name' ) );

		$xml = Character_Exporter::export( $character )['xml'];
		$this->assertStringContainsString( 'name="Silver Knife"', $xml );
		$this->assertStringContainsString( 'name="The Crypt"', $xml );
	}

	public function test_importing_the_same_character_again_adds_nothing_twice(): void {
		$first     = $this->commit();
		$character = (int) $first->get_data()['characters'][0]['id'];

		$this->assertSame( 200, $this->commit( [ 'Well Equipped' => 'overwrite' ] )->get_status() );

		$this->assertCount( 2, $this->held_by( $character ) );
		$this->assertCount( 1, World_Object::for_game( $this->game_id, [ 'object_type' => 'item' ] ) );
	}
}
