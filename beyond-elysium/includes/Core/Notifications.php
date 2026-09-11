<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Queues and sends email notifications to players when their character's
 * submitted change is approved or rejected. Callers enqueue() one outcome
 * at a time during a request, then flush() sends one summary email per
 * recipient, grouping multiple changes into a single message.
 */
class Notifications {

	/** wp_user_id => list of { character_name, game_name, label, status } */
	private static array $pending = [];

	/**
	 * Queues one change's outcome for its submitting player. No-op when
	 * the character has no linked player, when that player is the one who
	 * just reviewed it, when the player has opted out
	 * (User_Settings::NOTIFICATIONS_OPT_OUT_META), or when the game has
	 * notifications turned off (be_games.notifications_enabled).
	 *
	 * @param object $change      Decoded Change row - change_type, category, status.
	 * @param object $character   Decoded Character row - wp_user_id, name, owner_slug.
	 * @param int    $reviewed_by
	 * @return void
	 */
	public static function enqueue( $change, $character, int $reviewed_by ): void {
		$wp_user_id = (int) ( $character->wp_user_id ?? 0 );
		if ( ! $wp_user_id || $wp_user_id === $reviewed_by ) {
			return;
		}

		if ( get_user_meta( $wp_user_id, User_Settings::NOTIFICATIONS_OPT_OUT_META, true ) === '1' ) {
			return;
		}

		$game = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
		if ( $game && isset( $game->notifications_enabled ) && ! (int) $game->notifications_enabled ) {
			return;
		}

		self::$pending[ $wp_user_id ][] = [
			'character_name' => (string) ( $character->name ?? '' ),
			'game_name'      => (string) ( $game->name ?? $character->owner_slug ?? '' ),
			'label'          => self::label_for( $change ),
			'status'         => (string) ( $change->status ?? '' ),
		];
	}

	/**
	 * Builds a short human-readable label for one change, for use in the
	 * notification digest. Uses the trait name for add_trait/remove_trait/
	 * modify_trait changes; falls back to the change's category or
	 * change_type for anything else.
	 *
	 * @param object $change
	 * @return string
	 */
	private static function label_for( $change ): string {
		$change_data = is_array( $change->change_data ?? null ) ? $change->change_data : [];
		$trait_name  = $change_data['trait']['name'] ?? null;
		if ( $trait_name ) {
			return (string) $trait_name;
		}
		return (string) ( $change->category ?: ( $change->change_type ?? '' ) );
	}

	/**
	 * Sends one summary email per queued recipient, then empties the
	 * queue. Safe to call when nothing is queued; iterates zero times and
	 * sends nothing.
	 *
	 * @return void
	 */
	public static function flush(): void {
		foreach ( self::$pending as $wp_user_id => $items ) {
			$user = get_userdata( (int) $wp_user_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			wp_mail( $user->user_email, self::subject( $items ), self::body( $user, $items ) );
		}

		self::$pending = [];
	}

	/**
	 * Builds the email subject line: names the single change's approve/
	 * reject status when there is exactly one queued item, or states a
	 * count of changes reviewed when there is more than one.
	 *
	 * @param array $items
	 * @return string
	 */
	private static function subject( array $items ): string {
		if ( count( $items ) === 1 ) {
			return sprintf(
				/* translators: %s: approved or rejected */
				__( '[Beyond Elysium] Your character change was %s', 'beyond-elysium' ),
				$items[0]['status']
			);
		}
		return sprintf(
			/* translators: %d: number of changes reviewed */
			__( '[Beyond Elysium] %d character changes reviewed', 'beyond-elysium' ),
			count( $items )
		);
	}

	/**
	 * Builds the plain-text email body: a greeting line followed by one
	 * line per queued change, naming the character, game, what changed,
	 * and its approved/rejected status.
	 *
	 * @param \WP_User $user
	 * @param array    $items
	 * @return string
	 */
	private static function body( \WP_User $user, array $items ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: display name */
			__( 'Hi %s,', 'beyond-elysium' ),
			$user->display_name
		);
		$lines[] = '';

		foreach ( $items as $item ) {
			$lines[] = sprintf(
				'- %1$s (%2$s): %3$s - %4$s',
				$item['character_name'],
				$item['game_name'],
				$item['label'],
				strtoupper( $item['status'] )
			);
		}

		return implode( "\n", $lines );
	}
}
