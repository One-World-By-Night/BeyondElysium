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
 * Releases one scheduled batch: flips it to released.
 */
class Release_Engine {

	/**
	 * Releases a batch.
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

		// Sends the release emails after the commit.
		Notifications::flush_release();
		return true;
	}

	/**
	 * Queues one Notifications::enqueue_release() call per (player, held item) in this batch.
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
	 * A reveal names exactly one character.
	 *
	 * @param object $reveal
	 * @return array<int,string[]> wp_user_id => that player's own character names reached.
	 */
	private static function recipients_for_reveal( object $reveal ): array {
		return self::group_characters_by_owner( [ (int) $reveal->character_id ] );
	}

	/**
	 * A held plot (a rumor) reaches whoever its own audience already reaches.
	 *
	 * @param object $plot
	 * @param string $game_slug
	 * @return array<int,string[]> wp_user_id => that player's own character names reached.
	 */
	private static function recipients_for_plot( object $plot, string $game_slug ): array {
		return self::group_characters_by_owner( Audience::visible_character_ids( $plot, 'plot', $game_slug ) );
	}

	/**
	 * A held entry reaches the characters connected to its own parent plot, by any label, who can also see the entry
	 * itself.
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
	 * Resolves character ids to their owning players, grouping each player's own reached character names together.
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
