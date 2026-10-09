<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A connection's ids reach the browser as integers, so a client can compare them with a character's own id.
 */
class ConnectionIdTypesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-connection-id-types';
	private int $game_id;
	private int $first;
	private int $second;
	private int $manager;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Thread Connection Ids' ] );
		$this->first   = Character::create( [ 'name' => 'First', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		$this->second  = Character::create( [ 'name' => 'Second', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $this->first,
			'target_type' => 'character', 'target_id' => $this->second, 'label' => 'Packmates', 'created_by' => 1,
		] );
		$this->manager = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->manager );
	}

	private function assert_integer_ids( object $connection ): void {
		foreach ( [ 'id', 'game_id', 'source_id', 'target_id', 'created_by' ] as $field ) {
			$this->assertIsInt( $connection->$field, "$field is an integer" );
		}
	}

	public function test_the_game_list_returns_integer_ids(): void {
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/connections" );
		$request->set_param( 'source_type', 'character' );
		$request->set_param( 'target_type', 'character' );

		$rows = rest_get_server()->dispatch( $request )->get_data();

		$this->assertCount( 1, $rows );
		$this->assert_integer_ids( $rows[0] );
		$this->assertSame( $this->first, $rows[0]->source_id );
		$this->assertSame( $this->second, $rows[0]->target_id );
	}

	public function test_an_entitys_own_list_returns_integer_ids(): void {
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/connections" );
		$request->set_param( 'entity_type', 'character' );
		$request->set_param( 'entity_id', $this->first );

		$rows = rest_get_server()->dispatch( $request )->get_data();

		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assert_integer_ids( $row );
		}
	}

	public function test_every_read_path_of_the_model_returns_integer_ids(): void {
		$reads = [
			'find'         => [ Connection::find( (int) Connection::for_game( $this->game_id )[0]->id ) ],
			'for_source'   => Connection::for_source( 'character', $this->first ),
			'for_target'   => Connection::for_target( 'character', $this->second ),
			'for_entity'   => Connection::for_entity( 'character', $this->first ),
			'for_entities' => Connection::for_entities( 'character', [ $this->first ] ),
			'for_game'     => Connection::for_game( $this->game_id ),
		];
		foreach ( $reads as $name => $rows ) {
			$this->assertNotEmpty( $rows, $name );
			foreach ( $rows as $row ) {
				$this->assert_integer_ids( $row );
			}
		}
	}

	public function test_a_connection_with_no_target_keeps_a_null_target(): void {
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $this->first,
			'target_type' => 'tag', 'target_id' => null, 'label' => 'Haunted', 'created_by' => 1,
		] );

		$tagged = array_values( array_filter( Connection::for_game( $this->game_id ), static fn( $c ) => $c->target_type === 'tag' ) );

		$this->assertCount( 1, $tagged );
		$this->assertNull( $tagged[0]->target_id );
	}
}
