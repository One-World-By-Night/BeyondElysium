<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Queues and sends email notifications to players: their character's
 * submitted change was approved or rejected, or a new rumor now reaches one
 * of their characters. Each kind has its own queue/flush pair (the shapes
 * don't share a sensible email format), but both honor the same per-user
 * opt-out and per-game toggle via should_notify(). Callers enqueue() one
 * outcome at a time during a request, then flush() sends one summary email
 * per recipient, grouping multiple items into a single message.
 */
class Notifications {

	/** wp_user_id => list of { character_name, game_name, label, status } */
	private static array $pending = [];

	/** wp_user_id => [ game_name => rumor titles[] ] */
	private static array $pending_rumors = [];

	/**
	 * Whether a player should receive a notification email at all: not
	 * opted out (User_Settings::NOTIFICATIONS_OPT_OUT_META), and not on a
	 * game that has notifications turned off (be_games.notifications_enabled).
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game
	 * @return bool
	 */
	private static function should_notify( int $wp_user_id, $game ): bool {
		if ( get_user_meta( $wp_user_id, User_Settings::NOTIFICATIONS_OPT_OUT_META, true ) === '1' ) {
			return false;
		}
		if ( $game && isset( $game->notifications_enabled ) && ! (int) $game->notifications_enabled ) {
			return false;
		}
		return true;
	}

	/**
	 * Queues one change's outcome for its submitting player. No-op when
	 * the character has no linked player, when that player is the one who
	 * just reviewed it, or when should_notify() says not to.
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

		$game = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
		if ( ! self::should_notify( $wp_user_id, $game ) ) {
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
	 * Queues one rumor's arrival for one recipient player. No-op when
	 * should_notify() says not to. Call once per (player, rumor) pair the
	 * rumor's target_query resolved to - a player with several matching
	 * characters, or several new rumors in one generation pass, still gets
	 * one summary email via flush_rumors().
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game        Decoded Game row - name, notifications_enabled.
	 * @param string      $rumor_title
	 * @return void
	 */
	public static function enqueue_rumor( int $wp_user_id, $game, string $rumor_title ): void {
		if ( ! $wp_user_id || $rumor_title === '' || ! self::should_notify( $wp_user_id, $game ) ) {
			return;
		}

		$game_name = (string) ( $game->name ?? '' );
		if ( ! isset( self::$pending_rumors[ $wp_user_id ][ $game_name ] ) ) {
			self::$pending_rumors[ $wp_user_id ][ $game_name ] = [];
		}
		if ( ! in_array( $rumor_title, self::$pending_rumors[ $wp_user_id ][ $game_name ], true ) ) {
			self::$pending_rumors[ $wp_user_id ][ $game_name ][] = $rumor_title;
		}
	}

	/**
	 * Sends one summary email per queued rumor recipient, then empties the
	 * queue. Safe to call when nothing is queued; iterates zero times and
	 * sends nothing.
	 *
	 * @return void
	 */
	public static function flush_rumors(): void {
		foreach ( self::$pending_rumors as $wp_user_id => $by_game ) {
			$user = get_userdata( (int) $wp_user_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			wp_mail( $user->user_email, self::rumor_subject( $by_game ), self::rumor_body( $user, $by_game ) );
		}

		self::$pending_rumors = [];
	}

	/**
	 * Builds the rumor email subject line: names the single rumor when there
	 * is exactly one queued across every game, or states a total count.
	 *
	 * @param array<string,string[]> $by_game game_name => rumor titles.
	 * @return string
	 */
	private static function rumor_subject( array $by_game ): string {
		$titles = array_merge( ...array_values( $by_game ) );
		if ( count( $titles ) === 1 ) {
			return sprintf(
				/* translators: %s: rumor title */
				__( '[Beyond Elysium] New rumor: %s', 'beyond-elysium' ),
				$titles[0]
			);
		}
		return sprintf(
			/* translators: %d: number of new rumors */
			__( '[Beyond Elysium] %d new rumors available', 'beyond-elysium' ),
			count( $titles )
		);
	}

	/**
	 * Builds the plain-text rumor email body: a greeting line followed by
	 * one line per queued rumor, naming the rumor and which game it's in.
	 *
	 * @param \WP_User                $user
	 * @param array<string,string[]> $by_game game_name => rumor titles.
	 * @return string
	 */
	private static function rumor_body( \WP_User $user, array $by_game ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: display name */
			__( 'Hi %s,', 'beyond-elysium' ),
			$user->display_name
		);
		$lines[] = '';

		foreach ( $by_game as $game_name => $titles ) {
			foreach ( $titles as $title ) {
				$lines[] = sprintf( '- %1$s (%2$s)', $title, $game_name );
			}
		}

		return implode( "\n", $lines );
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
