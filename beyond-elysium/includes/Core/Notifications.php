<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notification_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Queues and sends email notifications to players: their character's
 * submitted change was approved or rejected, a new rumor now reaches one
 * of their characters, or a plot they can see got a new post (1.1.0 §3.5).
 * Also tells a chronicle's Storytellers that a character transfer is
 * waiting for them to accept or refuse. Each kind has its own queue/flush
 * pair (the shapes don't share a sensible email format), but every one
 * honors the same per-user opt-out and per-game toggle via should_notify().
 * Callers enqueue() one outcome at a time during a request, then flush()
 * sends one summary email per recipient, grouping multiple items into a
 * single message. A plot post additionally honors the recipient's own
 * plot_notify_preference() - immediate (the same queue-and-flush pattern),
 * daily (queued into Notification_Queue for Maintenance::run()'s once-a-day
 * digest), or off.
 */
class Notifications {

	/** wp_user_id => list of { character_name, game_name, label, status } */
	private static array $pending = [];

	/** wp_user_id => [ batch_id => { game_name, character_names: string[], rumor_count, entry_count } ] */
	private static array $pending_release = [];

	/** wp_user_id => [ plot_id => { game_name, plot_title, posted_by: string[], link } ] */
	private static array $pending_posts = [];

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
	 * Queues one held item's arrival for one recipient player, as part of one release batch
	 * (1.1.0 §3.2) - Release_Engine::release() calls this once per (player, held plot or
	 * entry) the batch reaches, naming which of that player's own characters the item
	 * reached. No-op when should_notify() says not to; the digest preference (§3.5) does
	 * not apply here - a release email is always sent immediately, never deferred to a
	 * daily digest.
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game             Decoded Game row - name, notifications_enabled.
	 * @param int         $batch_id
	 * @param string[]    $character_names  This player's own characters the item reached -
	 *                                       merged into the batch's running set, not replaced.
	 * @param string      $kind             'rumor' (a held plot), 'entry' (a held plot_entry),
	 *                                       or 'reveal' (a held secret_reveal, 1.1.0 §3.11).
	 * @return void
	 */
	public static function enqueue_release( int $wp_user_id, $game, int $batch_id, array $character_names, string $kind ): void {
		if ( ! $wp_user_id || ! self::should_notify( $wp_user_id, $game ) ) {
			return;
		}

		$bucket = self::$pending_release[ $wp_user_id ][ $batch_id ] ?? [
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
	 * Sends one summary email per queued (player, batch) pair, then empties the queue. Safe
	 * to call when nothing is queued; iterates zero times and sends nothing. No content in
	 * the email (§3.2) - only who it's for, where, and how much, with a link to go look.
	 *
	 * @return void
	 */
	public static function flush_release(): void {
		foreach ( self::$pending_release as $wp_user_id => $by_batch ) {
			$user = get_userdata( (int) $wp_user_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}
			foreach ( $by_batch as $entry ) {
				wp_mail( $user->user_email, self::release_subject( $entry ), self::release_body( $user, $entry ) );
			}
		}

		self::$pending_release = [];
	}

	/**
	 * A recipient's own plot-post preference (1.1.0 §3.5): 'immediate' (default), 'daily', or
	 * 'off'. Distinct from should_notify()'s opt-out/game-toggle check, which still wins
	 * regardless of this value - a caller checks should_notify() first, this second.
	 *
	 * @param int $wp_user_id
	 * @return string
	 */
	public static function plot_notify_preference( int $wp_user_id ): string {
		$value = get_user_meta( $wp_user_id, User_Settings::PLOT_NOTIFY_META, true );
		return in_array( $value, User_Settings::PLOT_NOTIFY_VALUES, true ) ? $value : 'immediate';
	}

	/**
	 * Notifies one recipient that a plot got a new post (1.1.0 §3.5) - immediately (queued for
	 * this request's own flush_posts()), queued for tomorrow's digest (Notification_Queue,
	 * Maintenance::run()), or not at all, per should_notify() and this recipient's own
	 * plot_notify_preference(). Never carries the post's own text - only who posted, on which
	 * plot, in which chronicle, and a link.
	 *
	 * @param int         $wp_user_id
	 * @param object|null $game            Decoded Game row - id, name, notifications_enabled.
	 * @param int         $plot_id
	 * @param string      $plot_title
	 * @param string      $posted_by_label "A Storyteller", or a character's own name.
	 * @param string      $link            Where the email points - the caller decides, since it
	 *                                      already knows whether this recipient reads the post
	 *                                      as a Storyteller or as a player.
	 * @return void
	 */
	public static function notify_post( int $wp_user_id, $game, int $plot_id, string $plot_title, string $posted_by_label, string $link ): void {
		if ( ! $wp_user_id || ! self::should_notify( $wp_user_id, $game ) ) {
			return;
		}

		$preference = self::plot_notify_preference( $wp_user_id );
		if ( $preference === 'off' ) {
			return;
		}

		if ( $preference === 'daily' ) {
			Notification_Queue::create( $wp_user_id, (int) ( $game->id ?? 0 ), 'plot_post', [
				'game_name'  => (string) ( $game->name ?? '' ),
				'plot_title' => $plot_title,
				'posted_by'  => $posted_by_label,
				'link'       => $link,
			] );
			return;
		}

		$bucket = self::$pending_posts[ $wp_user_id ][ $plot_id ] ?? [
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
	 * Sends one email per queued plot for each recipient - a recipient with new posts on
	 * several plots this request still gets one message per plot, since each names a
	 * different title and link, then empties the queue. Safe to call when nothing is queued.
	 *
	 * @return void
	 */
	public static function flush_posts(): void {
		foreach ( self::$pending_posts as $wp_user_id => $by_plot ) {
			$user = get_userdata( (int) $wp_user_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}
			foreach ( $by_plot as $entry ) {
				wp_mail( $user->user_email, self::post_subject( $entry ), self::post_body( $user, $entry ) );
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
	 * Sends one digest email per user with anything queued in Notification_Queue, listing
	 * every plot that got a new post since their last digest, then deletes exactly the rows
	 * it sent (1.1.0 §3.5). Called from Maintenance::run(), once a day.
	 *
	 * @return void
	 */
	public static function send_daily_digests(): void {
		foreach ( Notification_Queue::distinct_user_ids() as $wp_user_id ) {
			$rows = Notification_Queue::for_user( $wp_user_id );
			if ( empty( $rows ) ) {
				continue;
			}
			$user = get_userdata( $wp_user_id );
			if ( $user && $user->user_email ) {
				wp_mail( $user->user_email, self::digest_subject( $rows ), self::digest_body( $user, $rows ) );
			}
			Notification_Queue::delete_ids( array_map( static fn( $row ) => (int) $row->id, $rows ) );
		}
	}

	/**
	 * @param object[] $rows Decoded Notification_Queue rows.
	 * @return string
	 */
	private static function digest_subject( array $rows ): string {
		if ( count( $rows ) === 1 ) {
			return sprintf(
				/* translators: %s: plot title */
				__( '[Beyond Elysium] New post on %s', 'beyond-elysium' ),
				(string) ( $rows[0]->payload['plot_title'] ?? '' )
			);
		}
		return sprintf(
			/* translators: %d: number of plots with new posts */
			__( '[Beyond Elysium] %d plots with new posts', 'beyond-elysium' ),
			count( $rows )
		);
	}

	/**
	 * @param \WP_User $user
	 * @param object[] $rows Decoded Notification_Queue rows.
	 * @return string
	 */
	private static function digest_body( \WP_User $user, array $rows ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: display name */
			__( 'Hi %s,', 'beyond-elysium' ),
			$user->display_name
		);
		$lines[] = '';

		foreach ( $rows as $row ) {
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

		return implode( "\n", $lines );
	}

	/** The front-end Storyteller Toolkit's own Plots & Rumors tab URL, one plot's own thread. */
	public static function storyteller_plot_url( int $plot_id ): string {
		$page = get_page_by_path( \BeyondElysium\Core\Page_Provisioner::STORYTELLER_SLUG, OBJECT, 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' . \BeyondElysium\Core\Page_Provisioner::STORYTELLER_SLUG . '/' );
		return $base . ( strpos( (string) $base, '?' ) === false ? '?' : '&' ) . 'tab=plots&open_plot=' . $plot_id;
	}

	/** The front-end "My Plots & Rumors" tab URL, one plot's own thread. */
	public static function player_plot_url( int $plot_id ): string {
		return self::player_plots_url() . '&plot_id=' . $plot_id;
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
	 * Joins character names for the subject line: "Marcus Vitel" alone, "Marcus Vitel and
	 * Isabel Cruz" for two, "Marcus Vitel, Isabel Cruz and Anne Rowan" for three or more.
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

	/** The front-end "My Plots & Rumors" tab URL - the release email's one link, no content. */
	private static function player_plots_url(): string {
		$page = get_page_by_path( \BeyondElysium\Core\Page_Provisioner::PLAYER_SLUG, OBJECT, 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' . \BeyondElysium\Core\Page_Provisioner::PLAYER_SLUG . '/' );
		return $base . ( strpos( (string) $base, '?' ) === false ? '?' : '&' ) . 'tab=plots';
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

	/**
	 * Emails the receiving chronicle's HSTs and ASTs that a character transfer
	 * is waiting for one of them to accept or refuse - nothing is added to the
	 * chronicle until someone does (1.0.0-review F-003). Sent at once, one
	 * message per Storyteller, honoring the same opt-out and per-chronicle
	 * toggle as every other notification.
	 *
	 * @param object $game     The receiving chronicle's row.
	 * @param object $transfer The inbound transfer row.
	 * @return void
	 */
	public static function transfer_offered( object $game, object $transfer ): void {
		foreach ( self::storytellers( $game ) as $user ) {
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

			wp_mail(
				$user->user_email,
				sprintf(
					/* translators: 1: character name, 2: receiving chronicle */
					__( '[Beyond Elysium] Transfer waiting for review: %1$s to %2$s', 'beyond-elysium' ),
					$character,
					(string) $game->name
				),
				implode( "\n", $lines )
			);
		}
	}

	/**
	 * Emails the chronicle's HSTs and ASTs that someone asked to join by
	 * starting a character (owner ruling, 1.0.0-review F-033): the character
	 * waits, pending, until one of them sets it active, which makes its player
	 * a member.
	 *
	 * @param object   $game      The chronicle's row.
	 * @param object   $character The pending character.
	 * @param \WP_User $applicant
	 * @return void
	 */
	public static function join_requested( object $game, object $character, \WP_User $applicant ): void {
		foreach ( self::storytellers( $game ) as $user ) {
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

			wp_mail(
				$user->user_email,
				sprintf(
					/* translators: 1: chronicle, 2: character name */
					__( '[Beyond Elysium] Request to join %1$s: %2$s', 'beyond-elysium' ),
					(string) $game->name,
					(string) $character->name
				),
				implode( "\n", $lines )
			);
		}
	}

	/**
	 * Emails the chronicle's HSTs and ASTs that a player sent a Grapevine
	 * file waiting for review (F-122). Nothing is added to the chronicle
	 * until a Storyteller reviews it, under Beyond Elysium > Import.
	 *
	 * @param object $game
	 * @param object $submission
	 * @param \WP_User $sender
	 * @return void
	 */
	public static function submission_received( object $game, object $submission, \WP_User $sender ): void {
		$character = (string) ( $submission->character_name ?? '' ) !== '' ? (string) $submission->character_name : __( 'A character', 'beyond-elysium' );
		$arriving  = self::arrival_phrase( $submission );

		foreach ( self::storytellers( $game ) as $user ) {
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

			wp_mail(
				$user->user_email,
				sprintf(
					/* translators: 1: chronicle, 2: character name */
					__( '[Beyond Elysium] Sheet to review for %1$s: %2$s', 'beyond-elysium' ),
					(string) $game->name,
					$character
				),
				implode( "\n", $lines )
			);
		}
	}

	/**
	 * Emails the sender of a player-submitted Grapevine file once a
	 * Storyteller has accepted or refused it (F-122). Skipped when the
	 * sender opted out or the chronicle has notifications off - the same
	 * checks every other player-facing notification honors.
	 *
	 * @param object $game
	 * @param object $submission
	 * @return void
	 */
	public static function submission_answered( object $game, object $submission ): void {
		$sender_id = (int) $submission->submitted_by;
		if ( ! self::should_notify( $sender_id, $game ) ) {
			return;
		}
		$sender = get_userdata( $sender_id );
		if ( ! $sender || ! $sender->user_email ) {
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

		wp_mail( $sender->user_email, $subject, $body );
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
	 * The chronicle's HSTs and ASTs who should get a staff notification: each
	 * once, with an email address, not opted out, on a chronicle with
	 * notifications on.
	 *
	 * @param object $game
	 * @return array<int,\WP_User>
	 */
	private static function storytellers( object $game ): array {
		$users = [];
		foreach ( Game_Member::for_game( (int) $game->id ) as $member ) {
			$wp_user_id = (int) $member->wp_user_id;
			if ( ! in_array( $member->role, [ 'hst', 'ast' ], true ) || isset( $users[ $wp_user_id ] ) || ! self::should_notify( $wp_user_id, $game ) ) {
				continue;
			}
			$user = get_userdata( $wp_user_id );
			if ( $user && $user->user_email ) {
				$users[ $wp_user_id ] = $user;
			}
		}
		return array_values( $users );
	}

	/**
	 * An unassigned player post's own fallback recipients (1.1.0 §3.5): every hst/ast/narrator
	 * member, each once, with an email address, not opted out, on a chronicle with
	 * notifications on. Deliberately its own method rather than widening storytellers() above -
	 * that method's other three callers (transfer_offered, join_requested, submission_received)
	 * are HST/AST-only by design; a Narrator gets plot-post mail but not those.
	 *
	 * @param object $game
	 * @return array<int,\WP_User>
	 */
	public static function staff_including_narrators( object $game ): array {
		$users = [];
		foreach ( Game_Member::staff_for_game( (int) $game->id ) as $member ) {
			$wp_user_id = (int) $member->wp_user_id;
			if ( isset( $users[ $wp_user_id ] ) || ! self::should_notify( $wp_user_id, $game ) ) {
				continue;
			}
			$user = get_userdata( $wp_user_id );
			if ( $user && $user->user_email ) {
				$users[ $wp_user_id ] = $user;
			}
		}
		return array_values( $users );
	}
}
