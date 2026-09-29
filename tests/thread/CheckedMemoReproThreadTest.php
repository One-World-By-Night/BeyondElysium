<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Services\Player_Invites;
use WP_UnitTestCase;

/**
 * `Player_Invites::accept_if_invited()`'s per-request memo must not latch on a mere check that finds nothing, since
 * doing so silently blocks a real invite created afterward for the same account within the same process.
 */
class CheckedMemoReproThreadTest extends WP_UnitTestCase {

	public function test_a_check_that_finds_nothing_does_not_block_a_later_real_invite(): void {
		$game = (object) [
			'id'   => (int) Game::create( [ 'slug' => 'thread-checked-repro', 'name' => 'Checked Repro' ] ),
			'slug' => 'thread-checked-repro',
		];

		$user_id = self::factory()->user->create( [ 'user_email' => 'checkedrepro@example.com' ] );

		// A fresh account has nothing to accept yet - this must not poison the memo for the same account.
		$this->assertSame( 0, Player_Invites::accept_if_invited( get_userdata( $user_id ) ) );

		Player_Invites::hold( $game, 'checkedrepro@example.com', 0 );

		$this->assertSame( 1, Player_Invites::accept_if_invited( get_userdata( $user_id ) ), 'the same account accepts the invite once it exists' );
		$this->assertSame( 0, Player_Invites::accept_if_invited( get_userdata( $user_id ) ), 'a second check in the same process does nothing further' );
	}
}
