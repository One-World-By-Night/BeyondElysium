<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps be_games rows aligned with owbn_chronicle posts. Auto-provisions
 * a be_games row the first time a chronicle post is saved, and updates its
 * name when the chronicle's title changes. A trashed, draft, or otherwise
 * unpublished chronicle post is left untouched, and a chronicle's slug is
 * never rewritten once set.
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
			Game::create( [
				'name' => $post->post_title,
				'slug' => $chronicle_slug,
			] );
			return;
		}

		if ( $existing->name !== $post->post_title ) {
			Game::update( $chronicle_slug, [ 'name' => $post->post_title ] );
		}
	}
}
