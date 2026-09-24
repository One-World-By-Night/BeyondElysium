<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The boon ledger costs no more queries however long it is, names both parties of each boon, filters by either, keeps a
 * party's id a number and leaves off a boon missing a party.
 */
class BoonLedgerQueryCountThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-boon-ledger-queries';
	private int $game_id;
	/** @var int[] */
	private array $characters = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Boon Ledger Queries',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		foreach ( [ 'Ashe', 'Bram', 'Cyrus', 'Delia' ] as $name ) {
			$this->characters[] = (int) Character::create( [ 'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function add_boons( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$debtor   = $this->characters[ $i % 4 ];
			$creditor = $this->characters[ ( $i + 1 ) % 4 ];
			$boon     = (int) World_Object::create( [
				'game_id' => $this->game_id, 'object_type' => 'boon', 'name' => "Boon {$i}",
				'properties' => [ 'boon_level' => 'minor', 'status' => 'outstanding' ], 'created_by' => 1,
			] );
			Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'world_object', 'source_id' => $boon, 'target_type' => 'character', 'target_id' => $debtor, 'label' => 'owed_by', 'created_by' => 1 ] );
			Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'world_object', 'source_id' => $boon, 'target_type' => 'character', 'target_id' => $creditor, 'label' => 'owed_to', 'created_by' => 1 ] );
		}
	}

	/**
	 * @return array{0:array<int,array<string,mixed>>,1:int} The ledger, and the queries it took.
	 */
	private function ledger( array $params = [] ): array {
		global $wpdb;
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/boons" );
		$request->set_query_params( $params );
		rest_get_server()->dispatch( $request );

		$before   = $wpdb->num_queries;
		$response = rest_get_server()->dispatch( $request );
		return [ $response->get_data(), $wpdb->num_queries - $before ];
	}

	public function test_a_longer_ledger_costs_no_more_queries(): void {
		$this->add_boons( 5 );
		[ $five, $five_queries ] = $this->ledger();

		$this->add_boons( 25 );
		[ $thirty, $thirty_queries ] = $this->ledger();

		$this->assertCount( 5, $five );
		$this->assertCount( 30, $thirty );
		$this->assertLessThanOrEqual( $five_queries, $thirty_queries, "Five boons took {$five_queries} queries, thirty took {$thirty_queries}." );
	}

	public function test_each_boon_still_names_both_parties_and_filters_by_either(): void {
		$this->add_boons( 4 );

		[ $ledger ] = $this->ledger();
		$named      = array_map( static fn( $row ) => $row['owed_by']['name'] . ' owes ' . $row['owed_to']['name'], $ledger );
		sort( $named );
		$this->assertSame( [ 'Ashe owes Bram', 'Bram owes Cyrus', 'Cyrus owes Delia', 'Delia owes Ashe' ], $named );

		[ $cyrus ] = $this->ledger( [ 'character_id' => $this->characters[2] ] );
		$this->assertCount( 2, $cyrus );
	}

	/**
	 * A party's id came back as text, and the ledger scoped to one character sorts its boons into owed and owed-to by
	 * comparing that id with the character's number.
	 */
	public function test_a_partys_id_is_a_number(): void {
		$this->add_boons( 1 );

		[ $ledger ] = $this->ledger();
		$this->assertSame( $this->characters[0], $ledger[0]['owed_by']['id'] );
		$this->assertSame( $this->characters[1], $ledger[0]['owed_to']['id'] );
	}

	public function test_a_boon_missing_a_party_stays_off_the_ledger(): void {
		$this->add_boons( 2 );
		$lone = (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'boon', 'name' => 'Half a boon',
			'properties' => [ 'boon_level' => 'minor', 'status' => 'outstanding' ], 'created_by' => 1,
		] );
		Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'world_object', 'source_id' => $lone, 'target_type' => 'character', 'target_id' => $this->characters[0], 'label' => 'owed_by', 'created_by' => 1 ] );

		[ $ledger ] = $this->ledger();
		$this->assertCount( 2, $ledger );
	}
}
