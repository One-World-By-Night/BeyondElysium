<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/RowLockProbe.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Tests\Support\RowLockProbe;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-090 (Pass H intake `t3-models`). Creating a connection looked for an identical
 * one and then inserted, with nothing holding the two together: requests that arrived together
 * each found none and each inserted, and the item was listed on the character twice. On local
 * MySQL, six simultaneous creates left up to six rows. Each create now holds its source's row
 * while it checks and writes, so the next one finds the first.
 */
class ConnectionCreateRaceThreadTest extends WP_UnitTestCase {

	private object $source;
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		// A committed character, so a second connection's lock attempt measures this request's hold.
		$this->source = Character::find_by_name_in_game( 'Isolde Marchetti', 'be-demo' );
		$this->assertNotNull( $this->source, 'The demo chronicle seeds Isolde Marchetti.' );
		$this->game_id = (int) Game::find_by_slug( 'be-demo' )->id;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function item_connection(): array {
		return [
			'game_id'     => $this->game_id,
			'source_type' => 'character',
			'source_id'   => (int) $this->source->id,
			'target_type' => 'world_object',
			'target_id'   => 999999,
			'label'       => null,
			'created_by'  => 1,
		];
	}

	public function test_another_request_waits_while_a_connection_is_checked_and_written(): void {
		$lockable = null;
		$probe    = function ( $query ) use ( &$lockable ) {
			if ( $lockable === null && preg_match( '/^\s*INSERT INTO `?\w*be_connections`?/i', $query ) ) {
				$lockable = RowLockProbe::could_lock( 'characters', (int) $this->source->id );
			}
			return $query;
		};

		add_filter( 'query', $probe );
		$id = Connection::create( $this->item_connection() );
		remove_filter( 'query', $probe );

		$this->assertNotFalse( $id );
		$this->assertFalse( $lockable, 'Another request could start connecting from the same character mid-write.' );
	}

	public function test_a_create_that_cannot_hold_its_source_writes_nothing(): void {
		global $wpdb;
		$break = static fn( $query ) => preg_match( '/FOR UPDATE\s*$/i', trim( $query ) ) ? 'SELECT broken FOR UPDATE' : $query;

		add_filter( 'query', $break );
		$quiet = $wpdb->suppress_errors( true );
		$id    = Connection::create( $this->item_connection() );
		$wpdb->suppress_errors( $quiet );
		remove_filter( 'query', $break );

		$this->assertFalse( $id );
		$this->assertSame( [], Connection::for_source( 'character', (int) $this->source->id ) );
	}

	public function test_an_identical_connection_still_returns_the_first(): void {
		$first  = Connection::create( $this->item_connection() );
		$second = Connection::create( $this->item_connection() );

		$this->assertSame( (int) $first, (int) $second );
		$this->assertCount( 1, Connection::for_source( 'character', (int) $this->source->id ) );
	}
}
