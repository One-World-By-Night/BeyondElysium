<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Import_Controller::commit()` on a Hunter record, hand-built to the exact shape
 * `GEX_Parser::parse_character_hunter()` really produces.
 */
class HunterImportThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-hunter-import';
	private int $game_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Hunter Import',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A Hunter character record shaped exactly like `GEX_Parser::parse_character_hunter()`'s real return array.
	 */
	private function synthetic_hunter(): array {
		return [
			'race'            => 'hunter',
			'name'            => 'Synthetic Hunter',
			'creed'           => 'Innocence',
			'nature'          => 'Caregiver',
			'demeanor'        => 'Caregiver',
			'camp'            => 'The Safehouse',
			'handle'          => 'chemist105',
			'conviction'      => 3,
			'temp_conviction' => 3,
			'willpower'       => 2,
			'temp_willpower'  => 2,
			'mercy'           => 3,
			'temp_mercy'      => 3,
			'vision'          => 0,
			'temp_vision'     => 0,
			'zeal'            => 0,
			'temp_zeal'       => 0,
			'physical_max'    => 6,
			'social_max'      => 4,
			'mental_max'      => 3,
			'player'          => 'Jane Doe',
			'status'          => 'Active',
			'id'              => '',
			'start_date'      => null,
			'narrator'        => '',
			'is_npc'          => false,
			'last_modified'   => null,
			'experience'      => [ 'earned' => 0.0, 'unspent' => 0.0, 'history' => [] ],
			'trait_lists'     => [
				'Abilities' => [
					'name'   => 'Abilities',
					'traits' => [ [ 'name' => 'Academics', 'total' => '2', 'note' => '' ] ],
				],
				'Backgrounds' => [
					'name'   => 'Backgrounds',
					'traits' => [ [ 'name' => 'Allies', 'total' => '2', 'note' => '' ] ],
				],
				'Edges' => [
					'name'   => 'Edges',
					'traits' => [ [ 'name' => 'Innocence Path: Hide', 'total' => '1', 'note' => '' ] ],
				],
			],
			'biography' => '',
			'notes'     => '',
		];
	}

	private function synthetic_parsed( array $characters ): array {
		return [
			'version'    => 2.399,
			'players'    => [ [ 'name' => 'Jane Doe', 'email' => 'jane@example.test' ] ],
			'characters' => $characters,
			'items'      => [], 'locations' => [], 'rotes' => [], 'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
		];
	}

	private function inject_job( array $parsed ): string {
		$job_id     = wp_generate_uuid4();
		$reflection = new \ReflectionMethod( \BeyondElysium\REST\Import_Controller::class, 'build_preview' );
		$reflection->setAccessible( true );
		$preview = $reflection->invoke( null, $parsed, $this->game_slug, $this->game_id );

		set_transient( 'be_import_job_' . $job_id, [
			'game_id'     => $this->game_id,
			'parsed'      => $parsed,
			'preview'     => $preview,
			'source_file' => 'synthetic-hunter.gex',
		], HOUR_IN_SECONDS );

		return $job_id;
	}

	private function commit_request( string $job_id ): WP_REST_Request {
		return new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/import/{$job_id}/commit" );
	}

	public function test_a_hunter_record_imports_with_identity_resources_virtues_and_an_edge_resolved(): void {
		wp_set_current_user( $this->admin_id );

		$job_id   = $this->inject_job( $this->synthetic_parsed( [ $this->synthetic_hunter() ] ) );
		$response = $this->dispatch( $this->commit_request( $job_id ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertCount( 1, $data['characters'] );
		$this->assertSame( 'created', $data['characters'][0]['action'] );

		$character = Character::find( (int) $data['characters'][0]['id'] );
		$this->assertSame( 'Synthetic Hunter', $character->name );
		$this->assertSame( 'hunter', $character->stack_slug );

		$this->assertSame( 'Innocence', $character->sheet_data['hunter-identity']['Creed'] );
		$this->assertSame( 'chemist105', $character->sheet_data['hunter-identity']['Handle'] );
		$this->assertSame( 'Caregiver', $character->sheet_data['met-archetypes']['Nature'] );

		$this->assertEquals(
			[ 'permanent' => 2, 'temporary' => 2 ],
			$character->sheet_data['hunter-resources']['Willpower']
		);
		$this->assertEquals(
			[ 'permanent' => 3, 'temporary' => 3 ],
			$character->sheet_data['hunter-resources']['Conviction']
		);
		$this->assertEquals(
			[ 'permanent' => 3, 'temporary' => 3 ],
			$character->sheet_data['hunter-virtues']['Mercy']
		);

		$abilities = $character->sheet_data['hunter-abilities'] ?? [];
		$this->assertSame( 'Academics', $abilities[0]['name'] ?? null );
		$this->assertSame( 2, $abilities[0]['count'] ?? null );

		$backgrounds = $character->sheet_data['hunter-backgrounds'] ?? [];
		$this->assertSame( 'Allies', $backgrounds[0]['name'] ?? null );

		$edges = $character->sheet_data['hunter-edges'] ?? [];
		$this->assertCount( 1, $edges );
		$this->assertSame( 'Innocence Path', $edges[0]['name'] );
		$this->assertSame( 'Hide', $edges[0]['power_name'] );
	}
}
