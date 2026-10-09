<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Various character builds with no pricing at all and imports from a Grapevine file in a chronicle that never enabled it.
 */
class VariousThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-various';
	private int $game_id;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id  = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Thread Various' ] );
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_various_npc_builds_with_an_all_zero_tally(): void {
		wp_set_current_user( $this->admin_id );
		Game::update( $this->slug, [ 'settings' => [ 'enabled_stacks' => [ 'vampire' ] ] ] );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/creation-tally" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [
			'stack_slug' => 'various',
			'sheet_data' => [
				'various-identity' => [ 'Class' => 'Spirit' ],
				'various-tempers'  => [ [ 'name' => 'Essence', 'count' => 5 ] ],
			],
		] ) );
		$tally = $this->dispatch( $request )->get_data();

		$this->assertSame( [], $tally['steps'], 'Various has no creation_rules document at all - nothing to tally' );
		$this->assertSame( 0, $tally['xp']['needed'] );
	}

	/**
	 * A Various record shaped exactly like `GEX_Parser::parse_character_various()`'s real return array.
	 */
	private function synthetic_various(): array {
		return [
			'race'          => 'various',
			'name'          => 'Synthetic Various',
			'nature'        => 'Visionary',
			'demeanor'      => 'Visionary',
			'class'         => 'Construct',
			'subclass'      => 'Clockwork Sentinel',
			'affinity'      => 'Order',
			'plane'         => 'The Clockwork Court',
			'brood'         => '',
			'physical_max'  => 6, 'social_max' => 4, 'mental_max' => 3,
			'player'        => 'Jane Doe', 'status' => 'Active', 'id' => '',
			'start_date'    => null, 'narrator' => '', 'is_npc' => false, 'last_modified' => null,
			'experience'    => [ 'earned' => 0.0, 'unspent' => 0.0, 'history' => [] ],
			'trait_lists'   => [
				'Tempers' => [
					'name'   => 'Tempers',
					'traits' => [ [ 'name' => 'Essence', 'total' => '5', 'note' => '' ] ],
				],
			],
			'other'     => 'A homebrew construct, not from any book.',
			'biography' => '',
			'notes'     => '',
		];
	}

	public function test_a_various_record_imports_with_identity_and_tempers_resolved(): void {
		wp_set_current_user( $this->admin_id );
		Game::update( $this->slug, [ 'settings' => [ 'enabled_stacks' => [ 'vampire' ] ] ] );

		$job_id     = wp_generate_uuid4();
		$parsed     = [
			'version' => 2.399, 'players' => [], 'characters' => [ $this->synthetic_various() ],
			'items' => [], 'locations' => [], 'rotes' => [], 'actions' => [], 'plots' => [], 'rumors' => [], 'queries' => [],
		];
		$reflection = new \ReflectionMethod( \BeyondElysium\REST\Import_Controller::class, 'build_preview' );
		$reflection->setAccessible( true );
		$preview = $reflection->invoke( null, $parsed, $this->slug, $this->game_id );
		set_transient( 'be_import_job_' . $job_id, [
			'game_id' => $this->game_id, 'parsed' => $parsed, 'preview' => $preview, 'source_file' => 'synthetic-various.gex',
		], HOUR_IN_SECONDS );

		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/import/{$job_id}/commit" ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$character = Character::find( (int) $data['characters'][0]['id'] );
		$this->assertSame( 'various', $character->stack_slug );
		$this->assertSame( 'Construct', $character->sheet_data['various-identity']['Class'] );
		$this->assertSame( 'The Clockwork Court', $character->sheet_data['various-identity']['Plane'] );
		$this->assertSame( 'Essence', $character->sheet_data['various-tempers'][0]['name'] );
		$this->assertSame( 5, $character->sheet_data['various-tempers'][0]['count'] );
	}
}
