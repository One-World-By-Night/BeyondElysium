<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Chronicle_Sync;
use BeyondElysium\Models\Game;
use WP_UnitTestCase;

/**
 * Step 6a, workflow-0.9.md - keeps `be_games` aligned with `owbn_chronicle` CPTs.
 * `owbn-chronicle-manager` itself is not installed in this test environment (confirmed,
 * matching `PLATFORM.md`'s own accessSchema note - no OWBN plugin beyond `owbn-core` has
 * ever been present here) - this exercises `Chronicle_Sync::sync()` directly against a
 * real `owbn_chronicle` post and real `chronicle_slug` post meta, both plain WordPress
 * core mechanics that need no third-party plugin present to construct correctly. What
 * this deliberately does NOT prove: that `owbn-chronicle-manager`'s real save form
 * actually results in this exact post/meta shape - that is WordPress core's own
 * `save_post`/`save_post_{$post_type}` behavior, not something specific to this project.
 */
class ChronicleSyncTest extends WP_UnitTestCase {

	private function chronicle( string $slug, string $title = 'Thread Test Chronicle', string $status = 'publish' ): int {
		register_post_type( 'owbn_chronicle' ); // No-op if owbn-chronicle-manager already registered it; needed here since it isn't installed.
		$post_id = wp_insert_post( [
			'post_type'   => 'owbn_chronicle',
			'post_title'  => $title,
			'post_status' => $status,
		] );
		update_post_meta( $post_id, 'chronicle_slug', $slug );
		return $post_id;
	}

	public function test_a_new_chronicle_auto_provisions_a_be_games_row(): void {
		$this->assertNull( Game::find_by_slug( 'thread-sync-newchron' ) );

		$post_id = $this->chronicle( 'thread-sync-newchron', 'Brand New Chronicle' );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), false );

		$game = Game::find_by_slug( 'thread-sync-newchron' );
		$this->assertNotNull( $game );
		$this->assertSame( 'Brand New Chronicle', $game->name );
	}

	public function test_an_existing_chronicles_title_change_updates_the_games_name(): void {
		$post_id = $this->chronicle( 'thread-sync-retitle', 'Original Title' );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), false );
		$this->assertSame( 'Original Title', Game::find_by_slug( 'thread-sync-retitle' )->name );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Renamed Chronicle' ] );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), true );

		$this->assertSame( 'Renamed Chronicle', Game::find_by_slug( 'thread-sync-retitle' )->name );
	}

	public function test_a_trashed_chronicle_is_left_alone_entirely(): void {
		$post_id = $this->chronicle( 'thread-sync-trashed', 'About To Be Trashed', 'draft' );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), false );

		$this->assertNull( Game::find_by_slug( 'thread-sync-trashed' ), 'a non-published chronicle must never auto-provision a be_games row' );
	}

	public function test_sync_is_a_no_op_without_a_chronicle_slug_yet(): void {
		$post_id = wp_insert_post( [ 'post_type' => 'owbn_chronicle', 'post_title' => 'No Slug Yet', 'post_status' => 'publish' ] );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), false );

		$games_before = Game::all( [ 'per_page' => 500 ] );
		$this->assertNotContains( 'No Slug Yet', array_column( $games_before, 'name' ) );
	}

	/**
	 * Not a cascade test - `Chronicle_Sync` deliberately does not attempt one (see its own
	 * class doc comment: no stable post-to-game correlation exists to detect a rename by,
	 * and a naive attempt was built, found to silently duplicate rather than rename, and
	 * removed). This documents the actual, accepted behavior if `chronicle_slug` were ever
	 * changed despite the upstream immutability: a second, independent `be_games` row at
	 * the new slug, old row untouched - not data loss, but not a rename either, and an
	 * admin would see two similarly-named chronicles and need to investigate. Real
	 * production risk is effectively zero given the confirmed upstream guarantee, but the
	 * actual behavior should be tested and known, not assumed.
	 */
	public function test_changing_the_slug_creates_a_second_row_rather_than_renaming(): void {
		$post_id = $this->chronicle( 'thread-sync-oldslug', 'Original Chronicle' );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), false );
		$this->assertNotNull( Game::find_by_slug( 'thread-sync-oldslug' ) );

		update_post_meta( $post_id, 'chronicle_slug', 'thread-sync-newslug' );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), true );

		$this->assertNotNull( Game::find_by_slug( 'thread-sync-oldslug' ), 'the old row is untouched, not renamed - this is the documented, accepted behavior' );
		$this->assertNotNull( Game::find_by_slug( 'thread-sync-newslug' ), 'a second row is created at the new slug' );
	}
}
