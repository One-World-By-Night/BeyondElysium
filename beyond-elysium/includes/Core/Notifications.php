<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Mail_Log;
use BeyondElysium\Models\Notification_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Queues and sends email notifications to players: their character's submitted change was approved or rejected, a new
 * rumor now reaches one of their characters, or a plot they can see got a new post.
 */
class Notifications {

	/**
	 * wp_user_id => list of { character_name, game_id, game_name, label, status }
	 *
	 * @var array<int,list<array<string,mixed>>>
	 */
	private static array $pending = [];

	/**
	 * wp_user_id => [ batch_id => { game_id, game_name, character_names: string[], rumor_count, entry_count } ]
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private static array $pending_release = [];

	/**
	 * wp_user_id => [ plot_id => { game_id, game_name, plot_title, posted_by: string[], link } ]
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private static array $pending_posts = [];

	/**
	 * wp_user_id => list of { game_id, game_name, kind, title, link }
	 *
	 * @var array<int,array<int,array{game_id:int,game_name:string,kind:string,title:string,link:string}>>
	 */
	private static array $pending_visible = [];

	/**
	 * Whether a player should receive a notification email at all: not opted out
	 * (User_Settings::NOTIFICATIONS_OPT_OUT_META), and not on a game that has notifications turned off
	 * (be_games.notifications_enabled), and not on a demo chronicle.
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game
	 * @return bool
	 */
	public static function should_notify( int $wp_user_id, $game ): bool {
		return self::skip_reason( $wp_user_id, $game ) === null;
	}

	/**
	 * Why a user gets no notification email on a chronicle, or null when they do.
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game
	 * @return string|null A Mail_Log REASON_ constant.
	 */
	public static function skip_reason( int $wp_user_id, $game ): ?string {
		if ( get_user_meta( $wp_user_id, User_Settings::NOTIFICATIONS_OPT_OUT_META, true ) === '1' ) {
			return Mail_Log::REASON_OPTED_OUT;
		}
		if ( $game && isset( $game->notifications_enabled ) && ! (int) $game->notifications_enabled ) {
			return Mail_Log::REASON_CHRONICLE_OFF;
		}
		if ( $game && \BeyondElysium\Services\Demo_Chronicle::is_demo( $game ) ) {
			return Mail_Log::REASON_DEMO;
		}
		return null;
	}

	/**
	 * Whether to go on and notify one user, recording an email that won't be sent and why.
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game
	 * @param array{kind:string,entity_type?:string,entity_id?:int,subject?:string} $about What the email would have been.
	 * @return bool
	 */
	private static function allowed( int $wp_user_id, $game, array $about ): bool {
		$reason = self::skip_reason( $wp_user_id, $game );
		if ( $reason === null ) {
			return true;
		}
		Mailer::skipped( self::meta( $game, $wp_user_id, $about ), $reason );
		return false;
	}

	/**
	 * What the mail log needs to know about one email.
	 *
	 * @param object|null $game
	 * @param int         $wp_user_id
	 * @param array<string,mixed> $about kind, and optionally entity_type, entity_id, subject.
	 * @return array<string,mixed>
	 */
	private static function meta( $game, int $wp_user_id, array $about ): array {
		return array_merge( [ 'game_id' => (int) ( $game->id ?? 0 ), 'wp_user_id' => $wp_user_id ], $about );
	}

	/**
	 * Queues one change's outcome for its submitting player.
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
		if ( ! self::allowed( $wp_user_id, $game, [ 'kind' => 'change_outcome' ] ) ) {
			return;
		}

		self::$pending[ $wp_user_id ][] = [
			'character_name' => (string) ( $character->name ?? '' ),
			'game_id'        => (int) ( $game->id ?? 0 ),
			'game_name'      => (string) ( $game->name ?? $character->owner_slug ?? '' ),
			'label'          => self::label_for( $change ),
			'status'         => (string) ( $change->status ?? '' ),
		];
	}

	/**
	 * Builds a short human-readable label for one change, for use in the notification digest.
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
	 * Queues one held item's arrival for one recipient player, as part of one release batch - Release_Engine::release()
	 * calls this once per (player, held plot or entry) the batch reaches, naming which of that player's own characters
	 * the item reached.
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game             Decoded Game row - name, notifications_enabled.
	 * @param int         $batch_id
	 * @param string[]    $character_names  This player's own characters the item reached -
	 *                                       merged into the batch's running set, not replaced.
	 * @param string      $kind             'rumor' (a held plot), 'entry' (a held plot_entry),
	 *                                       or 'reveal' (a held secret_reveal).
	 * @return void
	 */
	public static function enqueue_release( int $wp_user_id, $game, int $batch_id, array $character_names, string $kind ): void {
		if ( ! $wp_user_id || ! self::allowed( $wp_user_id, $game, [ 'kind' => 'release', 'entity_type' => 'release_batch', 'entity_id' => $batch_id ] ) ) {
			return;
		}

		$bucket = self::$pending_release[ $wp_user_id ][ $batch_id ] ?? [
			'game_id'         => (int) ( $game->id ?? 0 ),
			'game_name'       => (string) ( $game->name ?? '' ),
			'character_names' => [],
			'rumor_count'     => 0,
			'entry_count'     => 0,
			'reveal_count'    => 0,
		];

		foreach ( $character_names as $name ) {
			if ( $name !== '' && ! in_array( $name, $bucket['character_names'], true ) ) {
				$bucket['character_names'][] = $name;
			}
		}
		if ( $kind === 'rumor' ) {
			$bucket['rumor_count']++;
		} elseif ( $kind === 'entry' ) {
			$bucket['entry_count']++;
		} elseif ( $kind === 'reveal' ) {
			$bucket['reveal_count']++;
		}

		self::$pending_release[ $wp_user_id ][ $batch_id ] = $bucket;
	}

	/**
	 * Sends one summary email per queued (player, batch) pair, then empties the queue.
	 *
	 * @return void
	 */
	public static function flush_release(): void {
		foreach ( self::$pending_release as $wp_user_id => $by_batch ) {
			$user = get_userdata( (int) $wp_user_id );
			foreach ( $by_batch as $batch_id => $entry ) {
				$meta = [
					'game_id'     => $entry['game_id'],
					'wp_user_id'  => (int) $wp_user_id,
					'kind'        => 'release',
					'entity_type' => 'release_batch',
					'entity_id'   => (int) $batch_id,
				];
				if ( ! $user || ! $user->user_email ) {
					Mailer::skipped( $meta, Mail_Log::REASON_NO_EMAIL );
					continue;
				}
				Mailer::send( $user->user_email, self::release_subject( $entry ), self::release_body( $user, $entry ), $meta );
			}
		}

		self::$pending_release = [];
	}

	/**
	 * A recipient's own plot-post preference: 'immediate' (default), 'daily', or 'off'.
	 *
	 * @param int $wp_user_id
	 * @return string
	 */
	public static function plot_notify_preference( int $wp_user_id ): string {
		$value = get_user_meta( $wp_user_id, User_Settings::PLOT_NOTIFY_META, true );
		return in_array( $value, User_Settings::PLOT_NOTIFY_VALUES, true ) ? $value : 'immediate';
	}

	/**
	 * Notifies one recipient that a plot got a new post: immediately, in tomorrow's digest, or not at all, per their
	 * preference.
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game            Decoded Game row - id, name, notifications_enabled.
	 * @param int         $plot_id
	 * @param string      $plot_title
	 * @param string      $posted_by_label "A Storyteller", or a character's own name.
	 * @param string      $link            Where the email points - the caller decides.
	 * @return void
	 */
	public static function notify_post( int $wp_user_id, $game, int $plot_id, string $plot_title, string $posted_by_label, string $link ): void {
		$about = [
			'kind'        => 'plot_post',
			'entity_type' => 'plot',
			'entity_id'   => $plot_id,
			'subject'     => self::post_subject( [ 'game_name' => (string) ( $game->name ?? '' ), 'plot_title' => $plot_title, 'posted_by' => [] ] ),
		];
		if ( ! $wp_user_id || ! self::allowed( $wp_user_id, $game, $about ) ) {
			return;
		}

		$preference = self::plot_notify_preference( $wp_user_id );
		if ( $preference === 'off' ) {
			Mailer::skipped( self::meta( $game, $wp_user_id, $about ), Mail_Log::REASON_PREFERENCE_OFF );
			return;
		}

		if ( $preference === 'daily' ) {
			Notification_Queue::create( $wp_user_id, (int) ( $game->id ?? 0 ), 'plot_post', [
				'game_name'  => (string) ( $game->name ?? '' ),
				'plot_title' => $plot_title,
				'posted_by'  => $posted_by_label,
				'link'       => $link,
			] );
			Mailer::queued( self::meta( $game, $wp_user_id, $about ) );
			return;
		}

		$bucket = self::$pending_posts[ $wp_user_id ][ $plot_id ] ?? [
			'game_id'    => (int) ( $game->id ?? 0 ),
			'game_name'  => (string) ( $game->name ?? '' ),
			'plot_title' => $plot_title,
			'posted_by'  => [],
			'link'       => $link,
		];
		if ( ! in_array( $posted_by_label, $bucket['posted_by'], true ) ) {
			$bucket['posted_by'][] = $posted_by_label;
		}
		self::$pending_posts[ $wp_user_id ][ $plot_id ] = $bucket;
	}

	/**
	 * Sends one email per queued plot for each recipient, then empties the queue.
	 *
	 * @return void
	 */
	public static function flush_posts(): void {
		foreach ( self::$pending_posts as $wp_user_id => $by_plot ) {
			$user = get_userdata( (int) $wp_user_id );
			foreach ( $by_plot as $plot_id => $entry ) {
				$meta = [
					'game_id'     => $entry['game_id'],
					'wp_user_id'  => (int) $wp_user_id,
					'kind'        => 'plot_post',
					'entity_type' => 'plot',
					'entity_id'   => (int) $plot_id,
					'subject'     => self::post_subject( $entry ),
				];
				if ( ! $user || ! $user->user_email ) {
					Mailer::skipped( $meta, Mail_Log::REASON_NO_EMAIL );
					continue;
				}
				Mailer::send( $user->user_email, self::post_subject( $entry ), self::post_body( $user, $entry ), $meta );
			}
		}

		self::$pending_posts = [];
	}

	/**
	 * @param array{game_name:string,plot_title:string,posted_by:string[]} $entry
	 * @return string
	 */
	private static function post_subject( array $entry ): string {
		return sprintf(
			/* translators: 1: plot title, 2: chronicle name */
			__( '[Beyond Elysium] New post on %1$s in %2$s', 'beyond-elysium' ),
			$entry['plot_title'],
			$entry['game_name']
		);
	}

	/**
	 * @param \WP_User                                                        $user
	 * @param array{plot_title:string,posted_by:string[],link:string}         $entry
	 * @return string
	 */
	private static function post_body( \WP_User $user, array $entry ): string {
		$lines = [
			sprintf(
				/* translators: %s: display name */
				__( 'Hi %s,', 'beyond-elysium' ),
				$user->display_name
			),
			'',
			sprintf(
				/* translators: 1: who posted, 2: plot title */
				__( '%1$s posted on %2$s.', 'beyond-elysium' ),
				self::join_names( $entry['posted_by'] ),
				$entry['plot_title']
			),
			$entry['link'],
		];

		return implode( "\n", $lines );
	}

	/**
	 * Notifies one recipient that a plot, item or location (or, for `secret_told`, a secret) became visible to one of
	 * their characters: immediately, in tomorrow's digest, or not at all, per their plot-notify preference (the same
	 * choice `notify_post()` reads).
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game  Decoded Game row - id, name, notifications_enabled.
	 * @param string      $kind  `plot`, `item`, `location`, or `secret_told`.
	 * @param string      $title The entity's own name or title.
	 * @param string      $link  The player's own view of it.
	 * @return void
	 */
	public static function notify_visible( int $wp_user_id, $game, string $kind, string $title, string $link ): void {
		$entry = [
			'game_id'   => (int) ( $game->id ?? 0 ),
			'game_name' => (string) ( $game->name ?? '' ),
			'kind'      => $kind,
			'title'     => $title,
			'link'      => $link,
		];
		$about = [ 'kind' => 'visible', 'subject' => self::visible_subject( [ $entry ] ) ];
		if ( ! $wp_user_id || ! self::allowed( $wp_user_id, $game, $about ) ) {
			return;
		}

		$preference = self::plot_notify_preference( $wp_user_id );
		if ( $preference === 'off' ) {
			Mailer::skipped( self::meta( $game, $wp_user_id, $about ), Mail_Log::REASON_PREFERENCE_OFF );
			return;
		}

		if ( $preference === 'daily' ) {
			Notification_Queue::create( $wp_user_id, (int) ( $game->id ?? 0 ), 'visible', $entry );
			Mailer::queued( self::meta( $game, $wp_user_id, $about ) );
			return;
		}

		self::$pending_visible[ $wp_user_id ][] = $entry;
	}

	/**
	 * Notifies one connected character's player only when a plot, item or location just became visible to them that
	 * wasn't already - never when they could already see it, never for the player who made the change themselves,
	 * never for a character with no player. Takes `$was_visible` and `$now_visible` already resolved.
	 *
	 * @param object $character         Decoded Character row - wp_user_id.
	 * @param bool   $was_visible       Whether this character could already see it, checked before the change.
	 * @param bool   $now_visible       Whether this character can see it now, checked after the change.
	 * @param object $game
	 * @param string $kind              `plot`, `item`, `location`, or `secret_told` - the notification's own kind.
	 * @param string $title
	 * @param string $link
	 * @param int    $acting_wp_user_id The user making the change; never notified about their own action.
	 * @return void
	 */
	public static function notify_if_newly_visible(
		object $character,
		bool $was_visible,
		bool $now_visible,
		object $game,
		string $kind,
		string $title,
		string $link,
		int $acting_wp_user_id
	): void {
		if ( $was_visible || ! $now_visible ) {
			return;
		}

		$wp_user_id = (int) ( $character->wp_user_id ?? 0 );
		if ( ! $wp_user_id || $wp_user_id === $acting_wp_user_id ) {
			return;
		}

		self::notify_visible( $wp_user_id, $game, $kind, $title, $link );
	}

	/**
	 * Sends one summary email per recipient with anything queued ("3 new things your characters can see in Kony"),
	 * then empties the queue.
	 *
	 * @return void
	 */
	public static function flush_visible(): void {
		foreach ( self::$pending_visible as $wp_user_id => $entries ) {
			$user = get_userdata( (int) $wp_user_id );
			$meta = [
				'game_ids'   => array_column( $entries, 'game_id' ),
				'wp_user_id' => (int) $wp_user_id,
				'kind'       => 'visible',
				'subject'    => self::visible_subject( $entries ),
			];
			if ( ! $user || ! $user->user_email ) {
				Mailer::skipped( $meta, Mail_Log::REASON_NO_EMAIL );
				continue;
			}
			Mailer::send( $user->user_email, self::visible_subject( $entries ), self::visible_body( $user, $entries ), $meta );
		}

		self::$pending_visible = [];
	}

	/**
	 * @param array<int,array{game_name:string,kind:string,title:string,link:string}> $entries
	 * @return string
	 */
	private static function visible_subject( array $entries ): string {
		if ( count( $entries ) === 1 ) {
			return sprintf(
				/* translators: 1: kind label (Plot, Item, Location), 2: its title */
				__( '[Beyond Elysium] New %1$s you can see: %2$s', 'beyond-elysium' ),
				self::kind_label( $entries[0]['kind'] ),
				$entries[0]['title']
			);
		}
		return sprintf(
			/* translators: 1: number of things, 2: chronicle name */
			__( '[Beyond Elysium] %1$d new things your characters can see in %2$s', 'beyond-elysium' ),
			count( $entries ),
			$entries[0]['game_name']
		);
	}

	/**
	 * @param \WP_User                                                                     $user
	 * @param array<int,array{game_name:string,kind:string,title:string,link:string}> $entries
	 * @return string
	 */
	private static function visible_body( \WP_User $user, array $entries ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: display name */
			__( 'Hi %s,', 'beyond-elysium' ),
			$user->display_name
		);
		$lines[] = '';

		foreach ( $entries as $entry ) {
			$lines[] = sprintf(
				'- %1$s: %2$s (%3$s)',
				self::kind_label( $entry['kind'] ),
				$entry['title'],
				$entry['game_name']
			);
			$lines[] = '  ' . $entry['link'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param string $kind `plot`, `item`, `location`, or `secret_told`.
	 * @return string
	 */
	private static function kind_label( string $kind ): string {
		switch ( $kind ) {
			case 'item':
				return __( 'Item', 'beyond-elysium' );
			case 'location':
				return __( 'Location', 'beyond-elysium' );
			case 'secret_told':
				return __( 'Secret', 'beyond-elysium' );
			default:
				return __( 'Plot', 'beyond-elysium' );
		}
	}

	/**
	 * Sends one digest email per user with anything queued: new plot posts, and new things their characters can see,
	 * as two separate sections.
	 *
	 * @return void
	 */
	public static function send_daily_digests(): void {
		foreach ( Notification_Queue::distinct_user_ids() as $wp_user_id ) {
			$rows = Notification_Queue::for_user( $wp_user_id );
			if ( empty( $rows ) ) {
				continue;
			}
			$posts   = array_values( array_filter( $rows, static fn( $row ) => $row->kind === 'plot_post' ) );
			$visible = array_values( array_filter( $rows, static fn( $row ) => $row->kind !== 'plot_post' ) );

			$user = get_userdata( $wp_user_id );
			$meta = [
				'game_ids'   => array_map( static fn( $row ) => (int) $row->game_id, $rows ),
				'wp_user_id' => $wp_user_id,
				'kind'       => 'digest',
				'subject'    => self::digest_subject( $posts, $visible ),
			];
			if ( $user && $user->user_email ) {
				Mailer::send( $user->user_email, self::digest_subject( $posts, $visible ), self::digest_body( $user, $posts, $visible ), $meta );
			} else {
				Mailer::skipped( $meta, Mail_Log::REASON_NO_EMAIL );
			}
			Notification_Queue::delete_ids( array_map( static fn( $row ) => (int) $row->id, $rows ) );
		}
	}

	/**
	 * @param object[] $posts   Decoded Notification_Queue rows, kind `plot_post`.
	 * @param object[] $visible Decoded Notification_Queue rows, every other kind.
	 * @return string
	 */
	private static function digest_subject( array $posts, array $visible ): string {
		$total = count( $posts ) + count( $visible );
		if ( $total === 1 ) {
			return ! empty( $posts )
				? sprintf(
					/* translators: %s: plot title */
					__( '[Beyond Elysium] New post on %s', 'beyond-elysium' ),
					(string) ( $posts[0]->payload['plot_title'] ?? '' )
				)
				: sprintf(
					/* translators: 1: kind label, 2: title */
					__( '[Beyond Elysium] New %1$s you can see: %2$s', 'beyond-elysium' ),
					self::kind_label( (string) ( $visible[0]->payload['kind'] ?? '' ) ),
					(string) ( $visible[0]->payload['title'] ?? '' )
				);
		}
		return sprintf(
			/* translators: %d: total number of posts and new things */
			__( '[Beyond Elysium] %d new posts and things your characters can see', 'beyond-elysium' ),
			$total
		);
	}

	/**
	 * @param \WP_User $user
	 * @param object[] $posts   Decoded Notification_Queue rows, kind `plot_post`.
	 * @param object[] $visible Decoded Notification_Queue rows, every other kind.
	 * @return string
	 */
	private static function digest_body( \WP_User $user, array $posts, array $visible ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: display name */
			__( 'Hi %s,', 'beyond-elysium' ),
			$user->display_name
		);
		$lines[] = '';

		if ( ! empty( $posts ) ) {
			$lines[] = __( 'New posts:', 'beyond-elysium' );
			foreach ( $posts as $row ) {
				$payload = $row->payload;
				$lines[] = sprintf(
					'- %1$s (%2$s): %3$s',
					(string) ( $payload['plot_title'] ?? '' ),
					(string) ( $payload['game_name'] ?? '' ),
					(string) ( $payload['posted_by'] ?? '' )
				);
				if ( ! empty( $payload['link'] ) ) {
					$lines[] = '  ' . $payload['link'];
				}
			}
			$lines[] = '';
		}

		if ( ! empty( $visible ) ) {
			$lines[] = __( 'New things your characters can see:', 'beyond-elysium' );
			foreach ( $visible as $row ) {
				$payload = $row->payload;
				$lines[] = sprintf(
					'- %1$s: %2$s (%3$s)',
					self::kind_label( (string) ( $payload['kind'] ?? '' ) ),
					(string) ( $payload['title'] ?? '' ),
					(string) ( $payload['game_name'] ?? '' )
				);
				if ( ! empty( $payload['link'] ) ) {
					$lines[] = '  ' . $payload['link'];
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * The front-end Storyteller Toolkit's own Plots & Rumors tab URL, one plot's own thread.
	 */
	public static function storyteller_plot_url( int $plot_id ): string {
		$page = get_page_by_path( \BeyondElysium\Core\Page_Provisioner::STORYTELLER_SLUG, OBJECT, 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' . \BeyondElysium\Core\Page_Provisioner::STORYTELLER_SLUG . '/' );
		return $base . ( strpos( (string) $base, '?' ) === false ? '?' : '&' ) . 'tab=plots&open_plot=' . $plot_id;
	}

	/**
	 * The front-end "My Plots & Rumors" tab URL, one plot's own thread.
	 */
	public static function player_plot_url( int $plot_id, string $game_slug = '' ): string {
		return self::player_page_url( 'plots', $game_slug ) . '&plot_id=' . $plot_id;
	}

	/**
	 * The front-end "Sheet" tab URL for one connected character - where an item or location notification points, since
	 * neither has a player-facing page of its own.
	 */
	public static function player_item_url( int $character_id, string $game_slug ): string {
		return self::player_page_url( 'sheet', $game_slug ) . '&character_id=' . $character_id;
	}

	/**
	 * The front-end "Sheet" tab URL for one connected character - where a location notification points, same as an
	 * item's.
	 */
	public static function player_location_url( int $character_id, string $game_slug ): string {
		return self::player_item_url( $character_id, $game_slug );
	}

	/**
	 * The front-end "What I Know" tab URL, where a `secret_told` notification points.
	 */
	public static function player_what_i_know_url( string $game_slug ): string {
		return self::player_page_url( 'what-i-know', $game_slug );
	}

	/**
	 * The front-end "My Plots & Rumors"/"Sheet"/"What I Know" page, one tab, optionally scoped to a chronicle.
	 */
	private static function player_page_url( string $tab, string $game_slug = '' ): string {
		$page = get_page_by_path( \BeyondElysium\Core\Page_Provisioner::PLAYER_SLUG, OBJECT, 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' . \BeyondElysium\Core\Page_Provisioner::PLAYER_SLUG . '/' );
		$url  = $base . ( strpos( (string) $base, '?' ) === false ? '?' : '&' ) . 'tab=' . $tab;
		if ( $game_slug !== '' ) {
			$url .= '&game_slug=' . rawurlencode( $game_slug );
		}
		return $url;
	}

	/**
	 * @param array{game_name:string,character_names:string[],rumor_count:int,entry_count:int,reveal_count?:int} $entry
	 * @return string
	 */
	private static function release_subject( array $entry ): string {
		return sprintf(
			/* translators: 1: character name(s) reached, 2: chronicle name, 3: counts, e.g. "2 rumors, 1 downtime answer" */
			__( '[Beyond Elysium] New for %1$s in %2$s: %3$s', 'beyond-elysium' ),
			self::join_names( $entry['character_names'] ),
			$entry['game_name'],
			self::release_counts_phrase( $entry )
		);
	}

	/**
	 * The subject line's trailing counts phrase - "2 rumors, 1 downtime answer, 1 secret".
	 *
	 * @param array{rumor_count:int,entry_count:int,reveal_count?:int} $entry
	 * @return string
	 */
	private static function release_counts_phrase( array $entry ): string {
		$parts = [];
		if ( $entry['rumor_count'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of rumors */
				_n( '%d rumor', '%d rumors', $entry['rumor_count'], 'beyond-elysium' ),
				$entry['rumor_count']
			);
		}
		if ( $entry['entry_count'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of downtime answers */
				_n( '%d downtime answer', '%d downtime answers', $entry['entry_count'], 'beyond-elysium' ),
				$entry['entry_count']
			);
		}
		if ( ( $entry['reveal_count'] ?? 0 ) > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of secrets revealed */
				_n( '%d secret', '%d secrets', $entry['reveal_count'], 'beyond-elysium' ),
				$entry['reveal_count']
			);
		}
		return implode( ', ', $parts );
	}

	/**
	 * Joins character names for the subject line: "Marcus Vitel" alone, "Marcus Vitel and Isabel Cruz" for two, "Marcus
	 * Vitel, Isabel Cruz and Anne Rowan" for three or more.
	 *
	 * @param string[] $names
	 * @return string
	 */
	private static function join_names( array $names ): string {
		if ( count( $names ) <= 1 ) {
			return $names[0] ?? '';
		}
		$last = array_pop( $names );
		return sprintf(
			/* translators: 1: every name but the last, comma-separated, 2: the last name */
			__( '%1$s and %2$s', 'beyond-elysium' ),
			implode( ', ', $names ),
			$last
		);
	}

	/**
	 * @param \WP_User                                                                       $user
	 * @param array{game_name:string,character_names:string[],rumor_count:int,entry_count:int,reveal_count?:int} $entry
	 * @return string
	 */
	private static function release_body( \WP_User $user, array $entry ): string {
		$lines = [
			sprintf(
				/* translators: %s: display name */
				__( 'Hi %s,', 'beyond-elysium' ),
				$user->display_name
			),
			'',
			sprintf(
				/* translators: %s: chronicle name */
				__( 'There is new content waiting for you in %s. Take a look on My Plots & Rumors:', 'beyond-elysium' ),
				$entry['game_name']
			),
			self::player_plots_url(),
		];

		return implode( "\n", $lines );
	}

	/**
	 * The front-end "My Plots & Rumors" tab URL - the release email's one link, no content.
	 */
	private static function player_plots_url(): string {
		$page = get_page_by_path( \BeyondElysium\Core\Page_Provisioner::PLAYER_SLUG, OBJECT, 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' . \BeyondElysium\Core\Page_Provisioner::PLAYER_SLUG . '/' );
		return $base . ( strpos( (string) $base, '?' ) === false ? '?' : '&' ) . 'tab=plots';
	}

	/**
	 * Sends one summary email per queued recipient, then empties the queue.
	 *
	 * @return void
	 */
	public static function flush(): void {
		foreach ( self::$pending as $wp_user_id => $items ) {
			$user = get_userdata( (int) $wp_user_id );
			$meta = [
				'game_ids'   => array_column( $items, 'game_id' ),
				'wp_user_id' => (int) $wp_user_id,
				'kind'       => 'change_outcome',
				'subject'    => self::subject( $items ),
			];
			if ( ! $user || ! $user->user_email ) {
				Mailer::skipped( $meta, Mail_Log::REASON_NO_EMAIL );
				continue;
			}

			Mailer::send( $user->user_email, self::subject( $items ), self::body( $user, $items ), $meta );
		}

		self::$pending = [];
	}

	/**
	 * Builds the email subject line: names the single change's approve/ reject status when there is exactly one queued
	 * item, or states a count of changes reviewed when there is more than one.
	 *
	 * @param array<int,array<string,mixed>> $items
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
	 * Builds the plain-text email body: a greeting line followed by one line per queued change, naming the character,
	 * game, what changed, and its approved/rejected status.
	 *
	 * @param \WP_User                       $user
	 * @param array<int,array<string,mixed>> $items
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

	/**
	 * Emails the receiving chronicle's HSTs and ASTs that a character transfer is waiting for one of them to accept or
	 * refuse - nothing is added to the chronicle until someone does.
	 *
	 * @param object $game     The receiving chronicle's row.
	 * @param object $transfer The inbound transfer row.
	 * @return void
	 */
	public static function transfer_offered( object $game, object $transfer ): void {
		$about = [ 'kind' => 'transfer_offered', 'entity_type' => 'transfer', 'entity_id' => (int) ( $transfer->id ?? 0 ) ];
		foreach ( self::storytellers( $game, $about ) as $user ) {
			$character = (string) ( $transfer->character_name ?? '' ) !== '' ? (string) $transfer->character_name : __( 'A character', 'beyond-elysium' );
			$lines     = [
				sprintf(
					/* translators: %s: display name */
					__( 'Hi %s,', 'beyond-elysium' ),
					$user->display_name
				),
				'',
				sprintf(
					/* translators: 1: character name, 2: sending chronicle, 3: sending site, 4: receiving chronicle */
					__( '%1$s is being transferred from %2$s (%3$s) to %4$s. Nothing has been added to your chronicle yet - review the transfer, then accept or refuse it, under Beyond Elysium > Import:', 'beyond-elysium' ),
					$character,
					(string) $transfer->home_chronicle,
					(string) $transfer->home_site,
					(string) $game->name
				),
				admin_url( 'admin.php?page=beyond-elysium-import' ),
			];

			$subject = sprintf(
				/* translators: 1: character name, 2: receiving chronicle */
				__( '[Beyond Elysium] Transfer waiting for review: %1$s to %2$s', 'beyond-elysium' ),
				$character,
				(string) $game->name
			);
			Mailer::send( $user->user_email, $subject, implode( "\n", $lines ), self::meta( $game, (int) $user->ID, $about + [ 'subject' => $subject ] ) );
		}
	}

	/**
	 * A newcomer asked to join by creating a character directly, with no open join request.
	 *
	 * @param object   $game      The chronicle's row.
	 * @param object   $character The pending character.
	 * @param \WP_User $applicant
	 * @return void
	 */
	public static function join_requested_legacy( object $game, object $character, \WP_User $applicant ): void {
		$about = [ 'kind' => 'join_requested', 'entity_type' => 'character', 'entity_id' => (int) ( $character->id ?? 0 ) ];
		foreach ( self::storytellers( $game, $about ) as $user ) {
			$lines = [
				sprintf(
					/* translators: %s: display name */
					__( 'Hi %s,', 'beyond-elysium' ),
					$user->display_name
				),
				'',
				sprintf(
					/* translators: 1: applicant's display name, 2: character name, 3: chronicle */
					__( '%1$s asked to join %3$s by starting a character, %2$s. They become a player when a Storyteller approves the character - set it active on the roster, or delete it to decline:', 'beyond-elysium' ),
					$applicant->display_name,
					(string) $character->name,
					(string) $game->name
				),
				admin_url( 'admin.php?page=beyond-elysium-characters' ),
			];

			$subject = sprintf(
				/* translators: 1: chronicle, 2: character name */
				__( '[Beyond Elysium] Request to join %1$s: %2$s', 'beyond-elysium' ),
				(string) $game->name,
				(string) $character->name
			);
			Mailer::send( $user->user_email, $subject, implode( "\n", $lines ), self::meta( $game, (int) $user->ID, $about + [ 'subject' => $subject ] ) );
		}
	}

	/**
	 * A newcomer opened a join request, carrying a message and, optionally, a character or file still to come.
	 *
	 * @param object   $game    The chronicle's row.
	 * @param \WP_User $applicant
	 * @param string   $message
	 * @return void
	 */
	public static function join_requested( object $game, \WP_User $applicant, string $message ): void {
		$about = [ 'kind' => 'join_requested' ];
		foreach ( self::storytellers( $game, $about ) as $user ) {
			$lines = [
				sprintf(
					/* translators: %s: display name */
					__( 'Hi %s,', 'beyond-elysium' ),
					$user->display_name
				),
				'',
				sprintf(
					/* translators: 1: applicant's display name, 2: chronicle */
					__( '%1$s asked to join %2$s:', 'beyond-elysium' ),
					$applicant->display_name,
					(string) $game->name
				),
				'',
				$message,
				'',
				admin_url( 'admin.php?page=beyond-elysium-chronicle-setup-hub&tab=players' ),
			];

			$subject = sprintf(
				/* translators: %s: chronicle */
				__( '[Beyond Elysium] Request to join %s', 'beyond-elysium' ),
				(string) $game->name
			);
			Mailer::send( $user->user_email, $subject, implode( "\n", $lines ), self::meta( $game, (int) $user->ID, $about + [ 'subject' => $subject ] ) );
		}
	}

	/**
	 * Emails the applicant that their join request was approved or refused.
	 *
	 * @param object      $game
	 * @param object      $join_request Decoded `be_join_requests` row - `wp_user_id`.
	 * @param bool        $approved
	 * @param string|null $note Only ever shown on a refusal.
	 * @return void
	 */
	public static function join_answered( object $game, object $join_request, bool $approved, ?string $note ): void {
		$wp_user_id = (int) $join_request->wp_user_id;
		$about      = [ 'kind' => 'join_answered', 'entity_type' => 'join_request', 'entity_id' => (int) ( $join_request->id ?? 0 ) ];
		if ( ! self::allowed( $wp_user_id, $game, $about ) ) {
			return;
		}
		$applicant = get_userdata( $wp_user_id );
		if ( ! $applicant || ! $applicant->user_email ) {
			Mailer::skipped( self::meta( $game, $wp_user_id, $about ), Mail_Log::REASON_NO_EMAIL );
			return;
		}

		$lines = [
			sprintf(
				/* translators: %s: display name */
				__( 'Hi %s,', 'beyond-elysium' ),
				$applicant->display_name
			),
			'',
			$approved
				? sprintf(
					/* translators: %s: chronicle */
					__( 'Your request to join %s was approved - welcome aboard.', 'beyond-elysium' ),
					(string) $game->name
				)
				: sprintf(
					/* translators: %s: chronicle */
					__( 'Your request to join %s was not approved this time.', 'beyond-elysium' ),
					(string) $game->name
				),
		];
		if ( ! $approved && $note ) {
			$lines[] = '';
			$lines[] = $note;
		}

		$subject = $approved
			/* translators: %s: chronicle */
			? sprintf( __( '[Beyond Elysium] Your request to join %s was approved', 'beyond-elysium' ), (string) $game->name )
			/* translators: %s: chronicle */
			: sprintf( __( '[Beyond Elysium] Your request to join %s was not approved', 'beyond-elysium' ), (string) $game->name );

		Mailer::send( $applicant->user_email, $subject, implode( "\n", $lines ), self::meta( $game, $wp_user_id, $about + [ 'subject' => $subject ] ) );
	}

	/**
	 * Emails the chronicle's HSTs, ASTs and narrators that a player told another player's character a secret on an
	 * Immediate-pass chronicle - the recipient can already read it, but passing it on waits for one of them to approve
	 * it.
	 *
	 * @param object $game
	 * @param object $secret           Decoded Secret row - title.
	 * @param string $teller_name
	 * @param string $recipient_name
	 * @return void
	 */
	public static function secret_told_staff( object $game, object $secret, string $teller_name, string $recipient_name ): void {
		$link  = self::staff_page_url( 'approval-queue', (string) ( $game->slug ?? '' ) );
		$about = [ 'kind' => 'secret_told', 'entity_type' => 'secret', 'entity_id' => (int) ( $secret->id ?? 0 ) ];

		foreach ( self::staff_including_narrators( $game, $about ) as $user ) {
			$lines = [
				sprintf(
					/* translators: %s: display name */
					__( 'Hi %s,', 'beyond-elysium' ),
					$user->display_name
				),
				'',
				sprintf(
					/* translators: 1: teller's character, 2: recipient's character, 3: secret title */
					__( '%1$s told %2$s a secret: %3$s. It waits for a Storyteller to approve before they can pass it on.', 'beyond-elysium' ),
					$teller_name,
					$recipient_name,
					(string) ( $secret->title ?? '' )
				),
				$link,
			];

			$subject = sprintf(
				/* translators: 1: chronicle, 2: secret title */
				__( '[Beyond Elysium] Secret told in %1$s: %2$s', 'beyond-elysium' ),
				(string) ( $game->name ?? '' ),
				(string) ( $secret->title ?? '' )
			);
			Mailer::send( $user->user_email, $subject, implode( "\n", $lines ), self::meta( $game, (int) $user->ID, $about + [ 'subject' => $subject ] ) );
		}
	}

	/**
	 * The front-end Storyteller Toolkit, one tab, optionally scoped to a chronicle.
	 */
	private static function staff_page_url( string $tab, string $game_slug = '' ): string {
		$page = get_page_by_path( \BeyondElysium\Core\Page_Provisioner::STORYTELLER_SLUG, OBJECT, 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' . \BeyondElysium\Core\Page_Provisioner::STORYTELLER_SLUG . '/' );
		$url  = $base . ( strpos( (string) $base, '?' ) === false ? '?' : '&' ) . 'tab=' . $tab;
		if ( $game_slug !== '' ) {
			$url .= '&game_slug=' . rawurlencode( $game_slug );
		}
		return $url;
	}

	/**
	 * Emails the chronicle's HSTs and ASTs that a player sent a Grapevine file waiting for review.
	 *
	 * @param object $game
	 * @param object $submission
	 * @param \WP_User $sender
	 * @return void
	 */
	public static function submission_received( object $game, object $submission, \WP_User $sender ): void {
		$character = (string) ( $submission->character_name ?? '' ) !== '' ? (string) $submission->character_name : __( 'A character', 'beyond-elysium' );
		$arriving  = self::arrival_phrase( $submission );
		$about     = [ 'kind' => 'submission_received', 'entity_type' => 'submission', 'entity_id' => (int) ( $submission->id ?? 0 ) ];

		foreach ( self::storytellers( $game, $about ) as $user ) {
			$lines = [
				sprintf(
					/* translators: %s: display name */
					__( 'Hi %s,', 'beyond-elysium' ),
					$user->display_name
				),
				'',
				sprintf(
					/* translators: 1: sender display name, 2: character name, 3: creature type, 4: joining/visiting phrase */
					__( '%1$s sent a Grapevine file for %2$s (%3$s), %4$s. Nothing is added until a Storyteller reviews it:', 'beyond-elysium' ),
					$sender->display_name,
					$character,
					(string) $submission->stack_slug,
					$arriving
				),
				admin_url( 'admin.php?page=beyond-elysium-import' ),
			];

			$subject = sprintf(
				/* translators: 1: chronicle, 2: character name */
				__( '[Beyond Elysium] Sheet to review for %1$s: %2$s', 'beyond-elysium' ),
				(string) $game->name,
				$character
			);
			Mailer::send( $user->user_email, $subject, implode( "\n", $lines ), self::meta( $game, (int) $user->ID, $about + [ 'subject' => $subject ] ) );
		}
	}

	/**
	 * Emails the sender of a player-submitted Grapevine file once a Storyteller has accepted or refused it.
	 *
	 * @param object $game
	 * @param object $submission
	 * @return void
	 */
	public static function submission_answered( object $game, object $submission ): void {
		$sender_id = (int) $submission->submitted_by;
		$about     = [ 'kind' => 'submission_answered', 'entity_type' => 'submission', 'entity_id' => (int) ( $submission->id ?? 0 ) ];
		if ( ! self::allowed( $sender_id, $game, $about ) ) {
			return;
		}
		$sender = get_userdata( $sender_id );
		if ( ! $sender || ! $sender->user_email ) {
			Mailer::skipped( self::meta( $game, $sender_id, $about ), Mail_Log::REASON_NO_EMAIL );
			return;
		}

		$character = (string) ( $submission->character_name ?? '' ) !== '' ? (string) $submission->character_name : __( 'Your character', 'beyond-elysium' );

		if ( $submission->state === 'accepted' ) {
			$subject = sprintf(
				/* translators: 1: chronicle, 2: character name */
				__( '[Beyond Elysium] %1$s accepted %2$s', 'beyond-elysium' ),
				(string) $game->name,
				$character
			);
			$body = sprintf(
				/* translators: 1: chronicle, 2: character name */
				__( "%1\$s's Storytellers accepted %2\$s.", 'beyond-elysium' ),
				(string) $game->name,
				$character
			);
		} else {
			$subject = sprintf(
				/* translators: 1: chronicle, 2: character name */
				__( '[Beyond Elysium] %1$s didn\'t accept %2$s', 'beyond-elysium' ),
				(string) $game->name,
				$character
			);
			$body = sprintf(
				/* translators: 1: chronicle, 2: character name */
				__( "%1\$s's Storytellers didn't accept %2\$s.", 'beyond-elysium' ),
				(string) $game->name,
				$character
			);
			if ( ! empty( $submission->answer_note ) ) {
				$body .= "\n\n" . sprintf(
					/* translators: %s: the Storyteller's note */
					__( 'Their note: %s', 'beyond-elysium' ),
					(string) $submission->answer_note
				);
			}
		}

		Mailer::send( $sender->user_email, $subject, $body, self::meta( $game, $sender_id, $about + [ 'subject' => $subject ] ) );
	}

	/**
	 * @param object $submission
	 * @return string
	 */
	private static function arrival_phrase( object $submission ): string {
		if ( $submission->arrival === 'joining' ) {
			return __( 'joining the chronicle', 'beyond-elysium' );
		}
		return ( $submission->home_chronicle ?? '' ) !== ''
			? sprintf(
				/* translators: %s: the sender's home chronicle, as they typed it */
				__( 'visiting from %s', 'beyond-elysium' ),
				(string) $submission->home_chronicle
			)
			: __( 'visiting for a game', 'beyond-elysium' );
	}

	/**
	 * The chronicle's HSTs and ASTs who should get a staff notification: each once, with an email address, not opted out,
	 * on a chronicle with notifications on. Each one left out is recorded in the mail log with the reason.
	 *
	 * @param object              $game
	 * @param array<string,mixed> $about What the email is: kind, and optionally entity_type and entity_id.
	 * @return array<int,\WP_User>
	 */
	private static function storytellers( object $game, array $about ): array {
		$users = [];
		foreach ( Game_Member::for_game( (int) $game->id ) as $member ) {
			$wp_user_id = (int) $member->wp_user_id;
			if ( ! in_array( $member->role, [ 'hst', 'ast' ], true ) || isset( $users[ $wp_user_id ] ) ) {
				continue;
			}
			$user = self::recipient( $wp_user_id, $game, $about );
			if ( $user ) {
				$users[ $wp_user_id ] = $user;
			}
		}
		return array_values( $users );
	}

	/**
	 * One user as the recipient of an email: their account, or null when they should get none. An email that won't go is
	 * recorded in the mail log with why.
	 *
	 * @param int                 $wp_user_id
	 * @param object|null         $game
	 * @param array<string,mixed> $about
	 * @return \WP_User|null
	 */
	private static function recipient( int $wp_user_id, $game, array $about ): ?\WP_User {
		if ( ! self::allowed( $wp_user_id, $game, $about ) ) {
			return null;
		}
		$user = get_userdata( $wp_user_id );
		if ( ! $user || ! $user->user_email ) {
			Mailer::skipped( self::meta( $game, $wp_user_id, $about ), Mail_Log::REASON_NO_EMAIL );
			return null;
		}
		return $user;
	}

	/**
	 * The fallback recipients for an unassigned player post: every hst, ast and narrator member, each once, with an email
	 * address, not opted out, on a chronicle with notifications on. Each one left out is recorded in the mail log with the
	 * reason when `$about` says what the email is.
	 *
	 * @param object              $game
	 * @param array<string,mixed> $about kind, and optionally entity_type and entity_id.
	 * @return array<int,\WP_User>
	 */
	public static function staff_including_narrators( object $game, array $about = [] ): array {
		$users = [];
		foreach ( Game_Member::staff_for_game( (int) $game->id ) as $member ) {
			$wp_user_id = (int) $member->wp_user_id;
			if ( isset( $users[ $wp_user_id ] ) ) {
				continue;
			}
			$user = self::recipient( $wp_user_id, $game, $about );
			if ( $user ) {
				$users[ $wp_user_id ] = $user;
			}
		}
		return array_values( $users );
	}
}
