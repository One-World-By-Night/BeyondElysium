<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Pricing a batch of changes gives each one the price it would have alone, so a picker can price many options in one
 * request.
 */
class PreviewChangesBatchThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-preview-batch';
	private int $character_id;
	private int $player_id;
	private int $st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Preview Batch',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player_id, 'player' );
		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->character_id = Character::create( [
			'name' => 'Batch Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 2 ] ] ],
		] );
		Character::update_xp( $this->character_id, 30, 30 );
	}

	/** @return array<int,array<string,mixed>> */
	private function results( int $as, array $changes ): array {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/preview-changes" );
		$request->set_param( 'changes', $changes );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data()['results'];
	}

	/** @return array<string,mixed> */
	private function add( string $name, int $count = 1 ): array {
		return [
			'change_type' => 'add_trait',
			'category'    => 'trait',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => $name, 'count' => $count ] ],
		];
	}

	/** @return string[] */
	private function ability_names( int $how_many ): array {
		$block = Schema_Block::find_for_game( 'vampire-abilities', $this->game_slug );
		$this->assertNotNull( $block );
		$names = array_values( array_filter( array_map( static fn( $item ) => (string) ( ( (object) $item )->name ?? '' ), (array) ( $block->definition->items ?? [] ) ) ) );
		$this->assertGreaterThanOrEqual( $how_many, count( $names ) );
		return array_slice( $names, 0, $how_many );
	}

	public function test_twenty_adds_in_one_request_each_price_as_they_would_alone(): void {
		$changes = array_map( fn( $name ) => $this->add( $name ), $this->ability_names( 20 ) );

		$together = $this->results( $this->player_id, $changes );

		$this->assertCount( 20, $together );
		foreach ( $changes as $i => $change ) {
			$alone = $this->results( $this->player_id, [ $change ] )[0];
			foreach ( [ 'xp_cost', 'priced', 'approval_level', 'invalid' ] as $key ) {
				$this->assertSame( $alone[ $key ] ?? null, $together[ $i ][ $key ] ?? null, "{$key} of change {$i} matches its own price" );
			}
		}
	}

	public function test_a_change_the_server_refuses_does_not_change_the_others(): void {
		$names   = $this->ability_names( 3 );
		$changes = [ $this->add( $names[0] ), $this->add( 'No Such Ability Anywhere' ), $this->add( $names[1] ) ];

		$together = $this->results( $this->player_id, $changes );

		$this->assertSame( $this->results( $this->player_id, [ $changes[0] ] )[0]['xp_cost'], $together[0]['xp_cost'] );
		$this->assertSame( $this->results( $this->player_id, [ $changes[2] ] )[0]['xp_cost'], $together[2]['xp_cost'] );
	}

	public function test_a_raise_of_a_held_trait_prices_the_extra_dot(): void {
		$raise = [
			'change_type' => 'modify_trait',
			'category'    => 'trait',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 3 ], 'previous' => [ 'name' => 'Occult', 'count' => 2 ] ],
		];

		$result = $this->results( $this->player_id, [ $raise ] )[0];

		$this->assertGreaterThan( 0, (float) $result['xp_cost'] );
	}

	public function test_a_storyteller_prices_catalog_adds_at_nothing(): void {
		$changes = array_map( fn( $name ) => $this->add( $name ), $this->ability_names( 5 ) );

		foreach ( $this->results( $this->st_id, $changes ) as $result ) {
			$this->assertSame( 0, (int) $result['xp_cost'] );
		}
	}
}
