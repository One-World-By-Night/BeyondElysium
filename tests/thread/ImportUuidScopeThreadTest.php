<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\REST\Import_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The import half.
 */
class ImportUuidScopeThreadTest extends WP_UnitTestCase {

	private string $other_slug = 'thread-uuid-other';
	private string $slug       = 'thread-uuid-importing';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->other_slug, $this->slug ] as $slug ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => $slug, 'settings' => '{}',
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}
		$this->game_id = (int) $wpdb->insert_id;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function parsed_character( string $uuid ): array {
		return [
			'race' => 'vampire', 'name' => 'Carried Identity', 'player' => '', 'nature' => '', 'demeanor' => '',
			'clan' => 'Brujah', 'sect' => 'Anarch', 'generation' => 12, 'sire' => '', 'title' => '',
			'path' => 'Humanity', 'path_traits' => 5, 'temp_path_traits' => 5, 'blood' => 10, 'temp_blood' => 10,
			'willpower' => 4, 'temp_willpower' => 4, 'conscience' => 3, 'temp_conscience' => 3,
			'self_control' => 3, 'temp_self_control' => 3, 'courage' => 3, 'temp_courage' => 3,
			'is_npc' => false, 'narrator' => '', 'start_date' => null, 'biography' => '', 'notes' => '',
			'status' => 'Active', 'experience' => [ 'earned' => 40.0, 'unspent' => 0.0, 'history' => [] ],
			'trait_lists' => [], 'uuid' => $uuid,
		];
	}

	private function job( string $uuid ): string {
		$job_id = wp_generate_uuid4();
		set_transient( 'be_import_job_' . $job_id, [
			'game_id'     => $this->game_id,
			'parsed'      => [
				'version' => 2.399, 'players' => [], 'items' => [], 'locations' => [], 'rotes' => [],
				'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
				'characters' => [ $this->parsed_character( $uuid ) ],
			],
			'source_file' => 'carried.gex',
			'format'      => 'XML',
		], HOUR_IN_SECONDS );
		return $job_id;
	}

	private function commit( string $job_id, array $duplicates = [] ) {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/import/{$job_id}/commit" );
		if ( $duplicates ) {
			$request->set_param( 'resolutions', [ 'duplicates' => $duplicates ] );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function owned_by( string $slug, string $uuid ): int {
		return Character::create( [
			'name' => 'Already Here', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $slug, 'status' => 'active', 'uuid' => $uuid,
		] );
	}

	public function test_an_import_never_overwrites_a_character_in_another_chronicle(): void {
		$uuid     = wp_generate_uuid4();
		$elsewhere = $this->owned_by( $this->other_slug, $uuid );

		$preview = Import_Controller::build_preview( get_transient( 'be_import_job_' . $this->job( $uuid ) )['parsed'], $this->slug, $this->game_id, [], 'XML' );
		$this->assertSame( 'uuid_elsewhere', $preview['duplicates'][0]['matched_by'] );
		$this->assertSame( 0, $preview['duplicates'][0]['existing_id'], 'another chronicle\'s row id is not this Storyteller\'s to see' );

		$this->assertSame( 409, $this->commit( $this->job( $uuid ) )->get_status() );
		$this->assertSame( 409, $this->commit( $this->job( $uuid ), [ 'Carried Identity' => 'overwrite' ] )->get_status() );
		$this->assertSame( 'Already Here', Character::find( $elsewhere )->name );

		$copied = $this->commit( $this->job( $uuid ), [ 'Carried Identity' => 'import_as_new' ] );
		$this->assertSame( 200, $copied->get_status(), wp_json_encode( $copied->get_data() ) );
		$copy = Character::find( (int) $copied->get_data()['characters'][0]['id'] );
		$this->assertSame( $this->slug, $copy->owner_slug );
		$this->assertNotSame( $uuid, $copy->uuid );
		$this->assertSame( $this->other_slug, Character::find( $elsewhere )->owner_slug );
		$this->assertSame( 'Already Here', Character::find( $elsewhere )->name );
	}

	public function test_a_uuid_match_in_this_chronicle_still_waits_for_the_storytellers_decision(): void {
		$uuid = wp_generate_uuid4();
		$here = $this->owned_by( $this->slug, $uuid );

		$this->assertSame( 409, $this->commit( $this->job( $uuid ) )->get_status(), 'no automatic overwrite' );
		$this->assertSame( 'Already Here', Character::find( $here )->name );

		$skipped = $this->commit( $this->job( $uuid ), [ 'Carried Identity' => 'skip' ] );
		$this->assertSame( 200, $skipped->get_status() );
		$this->assertSame( 'Already Here', Character::find( $here )->name, 'Skip means skip' );

		$overwritten = $this->commit( $this->job( $uuid ), [ 'Carried Identity' => 'overwrite' ] );
		$this->assertSame( 200, $overwritten->get_status() );
		$this->assertSame( 'Carried Identity', Character::find( $here )->name );
		$this->assertSame( $uuid, Character::find( $here )->uuid );
	}
}
