<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps be_games rows aligned with owbn_chronicle posts, on sites running the upstream owbn-chronicle-manager plugin.
 */
class Chronicle_Sync {

	/**
	 * Hooks sync() onto save_post_owbn_chronicle.
	 */
	public static function register(): void {
		add_action( 'save_post_owbn_chronicle', [ self::class, 'sync' ], 20, 3 );
	}

	/**
	 * Creates or updates the be_games row for one owbn_chronicle post.
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
			// No row at this slug yet: creates one, correlated to this post unless another row already holds it.
			$post_id_for_new_row = self::post_already_claimed( $post_id, 0 ) ? null : $post_id;
			$insert               = [ 'name' => $post->post_title, 'slug' => $chronicle_slug ];
			if ( $post_id_for_new_row !== null ) {
				$insert['owbn_chronicle_post_id'] = $post_id_for_new_row;
			}
			$new_id = Game::create( $insert );

			// Adds the post's author as the game's HST.
			$author_id = (int) $post->post_author;
			if ( $new_id && $author_id > 0 ) {
				\BeyondElysium\Models\Game_Member::set_role( (int) $new_id, $author_id, 'hst' );
			}
			return;
		}

		// Leaves a row that another chronicle post already holds untouched.
		if ( ! empty( $existing->owbn_chronicle_post_id ) && (int) $existing->owbn_chronicle_post_id !== $post_id ) {
			return;
		}

		if ( $existing->name !== $post->post_title ) {
			Game::update( $chronicle_slug, [ 'name' => $post->post_title ] );
		}

		// Correlates a row that has no post yet to this post.
		if ( empty( $existing->owbn_chronicle_post_id ) && ! self::post_already_claimed( $post_id, (int) $existing->id ) ) {
			Game::update( $chronicle_slug, [ 'owbn_chronicle_post_id' => $post_id ] );
		}
	}

	/**
	 * Whether a chronicle post is already correlated to a different games row than the one being synced.
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
