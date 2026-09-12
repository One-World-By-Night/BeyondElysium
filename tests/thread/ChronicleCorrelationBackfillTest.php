<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game;
use WP_UnitTestCase;

/**
 * BE_PROCESS/chronicle-rename-design.md CR-8/§8.2. Every case here proves the same one
 * property: a failure mode of the matching logic must leave a row NULL, never point it at
 * the wrong post. NULL is harmless and already the state of the world; a wrong post ID
 * would be silent corruption a later cascade could act on.
 */
class ChronicleCorrelationBackfillTest extends WP_UnitTestCase {

	private function chronicle( string $slug, string $status = 'publish' ): int {
		register_post_type( 'owbn_chronicle' );
		$post_id = wp_insert_post( [ 'post_type' => 'owbn_chronicle', 'post_title' => 'Backfill Test', 'post_status' => $status ] );
		update_post_meta( $post_id, 'chronicle_slug', $slug );
		return $post_id;
	}

	public function test_zero_matching_posts_leaves_the_row_null(): void {
		Game::create( [ 'name' => 'No Post', 'slug' => 'thread-backfill-no-post' ] );

		Schema::backfill_owbn_chronicle_post_ids();

		$this->assertNull( Game::find_by_slug( 'thread-backfill-no-post' )->owbn_chronicle_post_id );
	}

	public function test_a_single_matching_post_correlates(): void {
		$post_id = $this->chronicle( 'thread-backfill-single-match' );
		Game::create( [ 'name' => 'Single Match', 'slug' => 'thread-backfill-single-match' ] );

		Schema::backfill_owbn_chronicle_post_ids();

		$this->assertSame( $post_id, (int) Game::find_by_slug( 'thread-backfill-single-match' )->owbn_chronicle_post_id );
	}

	public function test_two_matching_posts_is_ambiguous_and_leaves_the_row_null(): void {
		$this->chronicle( 'thread-backfill-ambiguous' );
		$this->chronicle( 'thread-backfill-ambiguous' ); // A second post claiming the identical slug.
		Game::create( [ 'name' => 'Ambiguous', 'slug' => 'thread-backfill-ambiguous' ] );

		Schema::backfill_owbn_chronicle_post_ids();

		$this->assertNull( Game::find_by_slug( 'thread-backfill-ambiguous' )->owbn_chronicle_post_id, 'two candidate posts must never be resolved by picking one' );
	}

	/**
	 * Constructs the belt-and-braces case directly rather than hoping for it: one real
	 * post carrying TWO `chronicle_slug` postmeta rows (a real, if unusual, WordPress
	 * state - `add_post_meta()` allows a repeated key). Row A backfills correctly against
	 * the post's first slug value. Row B's own slug exact-matches the SAME post's second
	 * meta value, so the read alone would find exactly one candidate - the already-claimed
	 * check is what has to catch it from there.
	 */
	public function test_a_post_already_claimed_by_another_row_leaves_the_new_row_null(): void {
		$post_id = $this->chronicle( 'thread-backfill-claimed-a' );
		add_post_meta( $post_id, 'chronicle_slug', 'thread-backfill-claimed-b' ); // A second value on the SAME post.

		Game::create( [ 'name' => 'First Claim', 'slug' => 'thread-backfill-claimed-a' ] );
		Game::create( [ 'name' => 'Second Claimant', 'slug' => 'thread-backfill-claimed-b' ] );

		Schema::backfill_owbn_chronicle_post_ids();

		$this->assertSame( $post_id, (int) Game::find_by_slug( 'thread-backfill-claimed-a' )->owbn_chronicle_post_id, 'the first row to claim the post keeps it' );
		$this->assertNull( Game::find_by_slug( 'thread-backfill-claimed-b' )->owbn_chronicle_post_id, 'a post already claimed by another row must never be claimed twice' );
	}

	public function test_a_row_with_an_id_already_set_is_never_repointed(): void {
		$this->chronicle( 'thread-backfill-repoint' ); // A real, matching post exists...
		$fake_post_id = 999999; // ...but the row already points elsewhere, and must stay there.
		Game::create( [ 'name' => 'Already Correlated', 'slug' => 'thread-backfill-repoint', 'owbn_chronicle_post_id' => $fake_post_id ] );

		Schema::backfill_owbn_chronicle_post_ids();

		$this->assertSame( $fake_post_id, (int) Game::find_by_slug( 'thread-backfill-repoint' )->owbn_chronicle_post_id, 'a row that already has a post id must never be re-pointed, even at a real matching post' );
	}

	public function test_a_draft_chronicle_post_is_invisible_to_the_backfill(): void {
		$this->chronicle( 'thread-backfill-draft', 'draft' );
		Game::create( [ 'name' => 'Draft Match', 'slug' => 'thread-backfill-draft' ] );

		Schema::backfill_owbn_chronicle_post_ids();

		$this->assertNull( Game::find_by_slug( 'thread-backfill-draft' )->owbn_chronicle_post_id, 'a draft chronicle is invisible to Chronicle_Sync and must be invisible here too' );
	}

	public function test_running_twice_is_byte_identical(): void {
		$post_id = $this->chronicle( 'thread-backfill-idempotent' );
		Game::create( [ 'name' => 'Idempotent', 'slug' => 'thread-backfill-idempotent' ] );

		Schema::backfill_owbn_chronicle_post_ids();
		$first_run = Game::find_by_slug( 'thread-backfill-idempotent' )->owbn_chronicle_post_id;

		Schema::backfill_owbn_chronicle_post_ids();
		$second_run = Game::find_by_slug( 'thread-backfill-idempotent' )->owbn_chronicle_post_id;

		$this->assertSame( $post_id, (int) $first_run );
		$this->assertSame( $first_run, $second_run );
	}
}
