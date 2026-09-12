<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps be_games rows aligned with owbn_chronicle posts, on sites running
 * the upstream owbn-chronicle-manager plugin (pinned in practice to
 * v2.16.4, the version this correlation was built and verified against).
 * Auto-provisions a be_games row the first time a chronicle post is
 * saved, correlated to that post via owbn_chronicle_post_id from
 * creation, and updates its name when the chronicle's title changes. A
 * trashed, draft, or otherwise unpublished chronicle post is left
 * untouched.
 *
 * Deliberately does NOT rewrite a be_games row's slug when the upstream
 * post's own chronicle_slug changes - an earlier version of this class
 * attempted that and was removed (see
 * BE_PROCESS/chronicle-rename-design.md §5 for the full history) because
 * there was no stable, collision-proof way to tell "this post's slug
 * changed" from "a different chronicle now happens to share a slug" with
 * no correlation column to anchor on. owbn_chronicle_post_id is that
 * anchor, and CR-6's drift detector (Health_Notice) exists specifically
 * so this deferred branch can be revisited safely later without anyone
 * having to remember it is missing - it will say so.
 */
class Chronicle_Sync {

	/**
	 * Hooks sync() onto save_post_owbn_chronicle at priority 20, after
	 * chronicle post meta has been saved, so chronicle_slug and
	 * post_title are already final by the time sync() reads them.
	 */
	public static function register(): void {
		// Runs after owbn-chronicle-manager's own save_post meta handler, at its default priority 10.
		add_action( 'save_post_owbn_chronicle', [ self::class, 'sync' ], 20, 3 );
	}

	/**
	 * Creates or updates the be_games row for one owbn_chronicle post.
	 * Skips autosaves, revisions, and posts that are not published or
	 * private. Creates a new row when no be_games row exists at the
	 * chronicle's slug yet, or updates the existing row's name when the
	 * post title has changed.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 * @param bool     $update Unused - create-vs-update is determined by whether a
	 *                         be_games row already exists at this slug, not by this flag.
	 * @return void
	 */
	public static function sync( int $post_id, \WP_Post $post, bool $update ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Trashed or draft chronicles are left alone; sync never deletes a be_games row.
		if ( ! in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			return;
		}

		$chronicle_slug = get_post_meta( $post_id, 'chronicle_slug', true );
		if ( empty( $chronicle_slug ) ) {
			return; // No slug yet; nothing to sync.
		}
		$chronicle_slug = sanitize_title( (string) $chronicle_slug );

		$existing = Game::find_by_slug( $chronicle_slug );

		if ( ! $existing ) {
			// No existing row at this slug; creates one (game_type defaults to 'met').
			// Correlated to this post from creation, UNLESS this exact post is already
			// claimed by a different row - the real case this guards is a post whose
			// chronicle_slug changed: the OLD row keeps its correlation (Section 5.3's
			// deferred-rename decision - a slug change upstream does not migrate the
			// existing row), so the NEW row created at the new slug must not also try to
			// claim the same post, which the unique index would refuse anyway. Left NULL
			// here, this new row is picked up correctly if a future admin manually
			// corrects it, or if the deferred rename branch is ever built.
			$post_id_for_new_row = self::post_already_claimed( $post_id, 0 ) ? null : $post_id;
			$insert               = [ 'name' => $post->post_title, 'slug' => $chronicle_slug ];
			if ( $post_id_for_new_row !== null ) {
				$insert['owbn_chronicle_post_id'] = $post_id_for_new_row;
			}
			Game::create( $insert );
			return;
		}

		if ( $existing->name !== $post->post_title ) {
			Game::update( $chronicle_slug, [ 'name' => $post->post_title ] );
		}

		// Opportunistic correlation for a row that predates this column: same two rules
		// as Schema::backfill_owbn_chronicle_post_ids() (exact slug match already got us
		// here; only "is this post already claimed by a different row" remains to check),
		// expressed in one place rather than duplicated between create-time and backfill.
		if ( empty( $existing->owbn_chronicle_post_id ) && ! self::post_already_claimed( $post_id, (int) $existing->id ) ) {
			Game::update( $chronicle_slug, [ 'owbn_chronicle_post_id' => $post_id ] );
		}
	}

	/**
	 * Checks whether a chronicle post is already correlated to a different
	 * games row than the one currently being synced, so neither the create
	 * branch nor the opportunistic-correlation branch above ever contests
	 * the unique index by claiming a post a sibling row already holds.
	 * $except_game_id of 0 means "any row at all" - used from the create
	 * branch, where there is no existing row's own id to exclude.
	 *
	 * @param int $post_id
	 * @param int $except_game_id
	 * @return bool
	 */
	private static function post_already_claimed( int $post_id, int $except_game_id ): bool {
		global $wpdb;
		$table = \BeyondElysium\Database\Manager::table( 'games' );
		$claim = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE owbn_chronicle_post_id = %d AND id <> %d",
				$post_id,
				$except_game_id
			)
		);
		return (bool) $claim;
	}
}
