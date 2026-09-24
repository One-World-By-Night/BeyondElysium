<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Chronicle_Sync;
use BeyondElysium\Models\Game;
use WP_UnitTestCase;

/**
 * `Chronicle_Sync` keeps `be_games` aligned with `owbn_chronicle` posts.
 */
class ChronicleSyncTest extends WP_UnitTestCase {

	private function chronicle( string $slug, string $title = 'Thread Test Chronicle', string $status = 'publish' ): int {
		register_post_type( 'owbn_chronicle' ); // No-op if owbn-chronicle-manager already registered it.
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
		$this->assertSame( $post_id, (int) $game->owbn_chronicle_post_id, 'a newly-created row must be correlated to the post from creation, not left for the backfill to find later' );
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

	public function test_changing_the_slug_still_creates_a_second_row_and_the_unique_index_survives_it(): void {
		$post_id = $this->chronicle( 'thread-sync-oldslug', 'Original Chronicle' );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), false );
		$old_game = Game::find_by_slug( 'thread-sync-oldslug' );
		$this->assertNotNull( $old_game );
		$this->assertSame( $post_id, (int) $old_game->owbn_chronicle_post_id );

		update_post_meta( $post_id, 'chronicle_slug', 'thread-sync-newslug' );
		Chronicle_Sync::sync( $post_id, get_post( $post_id ), true );

		$this->assertNotNull( Game::find_by_slug( 'thread-sync-oldslug' ), 'the old row is untouched, not renamed - this is the documented, accepted behavior' );
		$new_game = Game::find_by_slug( 'thread-sync-newslug' );
		$this->assertNotNull( $new_game, 'a second row is created at the new slug' );

		$this->assertNull( $new_game->owbn_chronicle_post_id, 'the new row must NOT also claim the post the old row already holds' );
		$this->assertSame( $post_id, (int) Game::find_by_slug( 'thread-sync-oldslug' )->owbn_chronicle_post_id, 'the old row keeps its correlation, undisturbed by the second row being created' );
	}

	/**
	 * Another post sharing the slug does not rename or claim the chronicle.
	 */
	public function test_another_post_sharing_the_slug_does_not_rename_or_claim_the_chronicle(): void {
		$first = $this->chronicle( 'thread-sync-shared', 'Kony Sabbat' );
		Chronicle_Sync::sync( $first, get_post( $first ), false );

		$duplicate = $this->chronicle( 'thread-sync-shared', 'Copy of Kony Sabbat' );
		Chronicle_Sync::sync( $duplicate, get_post( $duplicate ), false );

		$game = Game::find_by_slug( 'thread-sync-shared' );
		$this->assertSame( 'Kony Sabbat', $game->name );
		$this->assertSame( $first, (int) $game->owbn_chronicle_post_id );
	}

	public function test_a_chronicle_made_on_the_games_screen_is_still_linked_by_its_first_post(): void {
		Game::create( [ 'name' => 'Made By Hand', 'slug' => 'thread-sync-byhand' ] );

		$post = $this->chronicle( 'thread-sync-byhand', 'Made By Hand, Officially' );
		Chronicle_Sync::sync( $post, get_post( $post ), false );

		$game = Game::find_by_slug( 'thread-sync-byhand' );
		$this->assertSame( $post, (int) $game->owbn_chronicle_post_id );
		$this->assertSame( 'Made By Hand, Officially', $game->name );
	}
}
