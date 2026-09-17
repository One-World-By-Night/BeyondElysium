<?php

namespace BeyondElysium\Services;

use BeyondElysium\Core\Notifications;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Secret_Reveal;

defined( 'ABSPATH' ) || exit;

/**
 * Releases one scheduled batch (1.1.0 §3.2): flips it to released, then emails every
 * player who has new content because of it.
 *
 * Idempotent by construction - release() locks the batch row before checking notified_at,
 * so a click racing the cron sweep, or the sweep firing twice on the same batch, can only
 * ever send its emails once. Three callers reach this: Release_Batches_Controller's
 * `release-now` route, the `be_release_batch` single event scheduled the moment a batch is
 * first set to `scheduled`, and the `be_release_sweep` quarter-hour catch-all for anything
 * that missed its own single event or was never scheduled one at all.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.2
 */
class Release_Engine {

	/**
	 * Releases a batch. Returns false without sending anything when the batch doesn't exist
	 * or was already notified - the caller does not need to distinguish "someone else just
	 * released it" from "nothing to do here," since both mean the same thing to a player.
	 *
	 * @param int $batch_id
	 * @return bool True only when this call is the one that actually released it.
	 */
	public static function release( int $batch_id ): bool {
		$savepoint = Transaction::begin( 'be_release_batch' );

		$batch = Release_Batch::find_for_update( $batch_id );
		if ( ! $batch || $batch->notified_at !== null ) {
			Transaction::commit( $savepoint );
			return false;
		}

		$now         = current_time( 'mysql' );
		$released_at = $batch->released_at ?: ( $batch->release_at ?: $now );
		Release_Batch::mark_released( $batch_id, $released_at, $now );

		$game = Game::find( (int) $batch->game_id );
		self::queue_recipients( $batch, $game );

		Transaction::commit( $savepoint );

		// Sent after commit, not before: an email describing a release that then failed to
		// commit would be a lie a rollback could never take back.
		Notifications::flush_release();
		return true;
	}

	/**
	 * Queues one Notifications::enqueue_release() call per (player, held item) in this
	 * batch - rumors, downtime answers, and secret reveals (1.1.0 §3.11) alike.
	 *
	 * @param object      $batch
	 * @param object|null $game
	 * @return void
	 */
	private static function queue_recipients( object $batch, $game ): void {
		$game_slug = (string) ( $game->slug ?? '' );

		foreach ( Plot::for_release_batch( (int) $batch->id ) as $plot ) {
			foreach ( self::recipients_for_plot( $plot, $game_slug ) as $wp_user_id => $names ) {
				Notifications::enqueue_release( $wp_user_id, $game, (int) $batch->id, $names, 'rumor' );
			}
		}

		foreach ( Plot_Entry::for_release_batch( (int) $batch->id ) as $entry ) {
			foreach ( self::recipients_for_entry( $entry, $game_slug ) as $wp_user_id => $names ) {
				Notifications::enqueue_release( $wp_user_id, $game, (int) $batch->id, $names, 'entry' );
			}
		}

		foreach ( Secret_Reveal::for_release_batch( (int) $batch->id ) as $reveal ) {
			foreach ( self::recipients_for_reveal( $reveal ) as $wp_user_id => $names ) {
				Notifications::enqueue_release( $wp_user_id, $game, (int) $batch->id, $names, 'reveal' );
			}
		}
	}

	/**
	 * A reveal names exactly one character - its own owner is the only recipient, unlike a
	 * plot's or entry's audience-derived set.
	 *
	 * @param object $reveal
	 * @return array<int,string[]> wp_user_id => that player's own character names reached.
	 */
	private static function recipients_for_reveal( object $reveal ): array {
		return self::group_characters_by_owner( [ (int) $reveal->character_id ] );
	}

	/**
	 * A held plot (a rumor) reaches whoever its own audience already reaches -
	 * Audience::visible_character_ids() is exactly that list, audience-normalized the same
	 * way any other reader of this plot would see it (§3.2).
	 *
	 * @param object $plot
	 * @param string $game_slug
	 * @return array<int,string[]> wp_user_id => that player's own character names reached.
	 */
	private static function recipients_for_plot( object $plot, string $game_slug ): array {
		return self::group_characters_by_owner( Audience::visible_character_ids( $plot, 'plot', $game_slug ) );
	}

	/**
	 * A held entry reaches the characters connected to its own parent plot, by any label,
	 * who can also see the entry itself - never the whole chronicle of an `everyone` plot,
	 * even though the plot's own audience would otherwise reach everyone (§3.2's own wording:
	 * "never the whole chronicle of an everyone plot").
	 *
	 * @param object $entry
	 * @param string $game_slug
	 * @return array<int,string[]> wp_user_id => that player's own character names reached.
	 */
	private static function recipients_for_entry( object $entry, string $game_slug ): array {
		$plot = Plot::find( (int) $entry->plot_id );
		if ( ! $plot ) {
			return [];
		}

		$connected_ids = array_values( array_unique( array_map(
			static fn( $c ) => (int) $c->target_id,
			array_filter(
				Connection::for_source( 'plot', (int) $plot->id ),
				static fn( $c ) => $c->target_type === 'character'
			)
		) ) );

		$reached = [];
		foreach ( self::group_characters_by_owner( $connected_ids ) as $wp_user_id => $names ) {
			if ( Audience::can_see_entry( $entry, $wp_user_id, $game_slug, false ) ) {
				$reached[ $wp_user_id ] = $names;
			}
		}
		return $reached;
	}

	/**
	 * Resolves character ids to their owning players, grouping each player's own reached
	 * character names together - a player with two qualifying characters gets one entry
	 * naming both, matching the notification's own "New for Marcus Vitel and Isabel Cruz"
	 * shape rather than two separate emails. An NPC (no wp_user_id) or an id that no longer
	 * resolves is silently dropped; neither has a player to notify.
	 *
	 * @param int[] $character_ids
	 * @return array<int,string[]> wp_user_id => character names.
	 */
	private static function group_characters_by_owner( array $character_ids ): array {
		$by_owner = [];
		foreach ( $character_ids as $character_id ) {
			$character  = Character::find( $character_id );
			$wp_user_id = (int) ( $character->wp_user_id ?? 0 );
			if ( ! $character || ! $wp_user_id ) {
				continue;
			}
			$by_owner[ $wp_user_id ][] = (string) $character->name;
		}
		return $by_owner;
	}
}
