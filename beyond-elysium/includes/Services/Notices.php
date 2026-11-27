<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notice;
use BeyondElysium\Services\Display\Change_Description;

defined( 'ABSPATH' ) || exit;

/**
 * Writes what the site tells a person in its own header. A notice leaves the site nowhere, so none of them waits on a
 * person's email choice, a chronicle's notification switch or the demo.
 */
class Notices {

	/**
	 * The decisions of one request, by character, until the request writes them as one notice each.
	 *
	 * @var array<int,array{character:object,approved:array<int,array<string,string>>,refused:array<int,array<string,string>>,undone:array<int,array<string,string>>}>
	 */
	private static array $decided = [];

	/**
	 * The items released in one request, by player and batch, until the request writes them as one notice each.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private static array $released = [];

	/**
	 * Notes one decided change against its character's player. The change reads as it now stands: approved, rejected or
	 * undone.
	 *
	 * @param object $change
	 * @param object $character
	 * @param int    $by         Who decided it.
	 */
	public static function decided( $change, $character, int $by ): void {
		$owner = (int) ( $character->wp_user_id ?? 0 );
		if ( $owner === 0 || $owner === $by ) {
			return;
		}
		$bucket = [ 'approved' => 'approved', 'rejected' => 'refused', 'undone' => 'undone' ][ (string) $change->status ] ?? null;
		if ( $bucket === null ) {
			return;
		}

		$id = (int) $character->id;
		self::$decided[ $id ] ??= [ 'character' => $character, 'approved' => [], 'refused' => [], 'undone' => [] ];
		self::$decided[ $id ][ $bucket ][] = [
			'text' => Change_Description::describe( (string) $change->change_type, is_array( $change->change_data ) ? $change->change_data : [] ),
			'note' => self::player_text( $character, (string) ( $change->status === 'undone' ? ( $change->undone_note ?? '' ) : ( $change->review_notes ?? '' ) ) ),
		];
	}

	/**
	 * Writes one notice for each character whose changes were decided, then forgets them.
	 */
	public static function flush_decided(): void {
		foreach ( self::$decided as $entry ) {
			$character = $entry['character'];
			$game      = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
			if ( ! $game ) {
				continue;
			}
			$kind  = $entry['undone'] !== [] && $entry['approved'] === [] && $entry['refused'] === [] ? 'changes_undone' : 'changes_decided';
			$lines = [];
			foreach ( [ 'approved', 'refused', 'undone' ] as $outcome ) {
				foreach ( $entry[ $outcome ] as $line ) {
					$lines[] = [ 'text' => $line['text'], 'status' => $outcome, 'note' => $line['note'] ];
				}
			}
			Notice::add( (int) $game->id, (int) $character->wp_user_id, $kind, [
				'character_id'   => (int) $character->id,
				'character_name' => (string) $character->name,
				'approved'       => count( $entry['approved'] ),
				'refused'        => count( $entry['refused'] ),
				'undone'         => count( $entry['undone'] ),
				'lines'          => $lines,
			] );
		}
		self::$decided = [];
	}

	/**
	 * Tells a player that a Storyteller changed a request that was waiting.
	 *
	 * @param object      $change    The change as it now stands.
	 * @param object      $character
	 * @param int         $by
	 * @param string|null $note      The Storyteller's note to the player.
	 */
	public static function edited( $change, $character, int $by, ?string $note ): void {
		$owner = (int) ( $character->wp_user_id ?? 0 );
		$game  = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
		if ( $owner === 0 || $owner === $by || ! $game ) {
			return;
		}
		Notice::add( (int) $game->id, $owner, 'change_edited', [
			'character_id'   => (int) $character->id,
			'character_name' => (string) $character->name,
			'text'           => Change_Description::describe( (string) $change->change_type, is_array( $change->change_data ) ? $change->change_data : [] ),
			'note'           => self::player_text( $character, (string) $note ),
		] );
	}

	/**
	 * Tells a player that XP was awarded to a character.
	 *
	 * @param object $character
	 * @param int    $amount
	 * @param string $reason
	 * @param int    $by
	 */
	public static function xp_awarded( $character, int $amount, string $reason, int $by ): void {
		$owner = (int) ( $character->wp_user_id ?? 0 );
		$game  = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
		if ( $amount <= 0 || $owner === 0 || $owner === $by || ! $game ) {
			return;
		}
		Notice::add( (int) $game->id, $owner, 'xp_awarded', [
			'character_id'   => (int) $character->id,
			'character_name' => (string) $character->name,
			'amount'         => $amount,
			'reason'         => self::player_text( $character, $reason ),
			'date'           => current_time( 'Y-m-d' ),
		] );
	}

	/**
	 * Tells the chronicle's HSTs and ASTs that a player sent changes that wait for them.
	 *
	 * @param object $game
	 * @param object $character
	 * @param int    $waiting   How many of the changes wait.
	 * @param int    $total     How many were sent.
	 * @param int    $by        Who sent them, who is not told.
	 */
	public static function waiting( $game, $character, int $waiting, int $total, int $by ): void {
		if ( $waiting <= 0 ) {
			return;
		}
		self::to_staff( $game, 'changes_waiting', [
			'character_id'   => (int) $character->id,
			'character_name' => (string) $character->name,
			'waiting'        => $waiting,
			'total'          => $total,
		], $by );
	}

	/**
	 * Tells the chronicle's HSTs and ASTs that a member made a new character that waits for them.
	 *
	 * @param object $game
	 * @param object $character
	 * @param int    $picks     How many of its picks the chronicle's rules hand to a Storyteller.
	 * @param int    $by        The player, who is not told.
	 */
	public static function character_waiting( $game, $character, int $picks, int $by ): void {
		$player = get_userdata( (int) ( $character->wp_user_id ?? 0 ) );
		self::to_staff( $game, 'character_waiting', [
			'character_id'   => (int) $character->id,
			'character_name' => (string) $character->name,
			'player_name'    => $player ? (string) $player->display_name : '',
			'picks'          => $picks,
		], $by );
	}

	/**
	 * Tells one person something in their own header.
	 *
	 * @param object|null          $game
	 * @param int                 $wp_user_id
	 * @param string              $kind
	 * @param array<string,mixed> $payload
	 * @param int                 $by         Who did it, who is not told.
	 */
	public static function to_person( $game, int $wp_user_id, string $kind, array $payload, int $by = 0 ): void {
		if ( ! is_object( $game ) || $wp_user_id <= 0 || $wp_user_id === $by ) {
			return;
		}
		Notice::add( (int) $game->id, $wp_user_id, $kind, $payload );
	}

	/**
	 * Tells each member of a chronicle's staff something, once each.
	 *
	 * @param object|null          $game
	 * @param string              $kind
	 * @param array<string,mixed> $payload
	 * @param int                 $by      Who did it, who is not told.
	 * @param string[]            $roles   The chronicle roles that are told.
	 */
	public static function to_staff( $game, string $kind, array $payload, int $by = 0, array $roles = [ 'hst', 'ast' ] ): void {
		if ( ! is_object( $game ) ) {
			return;
		}
		$told = [];
		foreach ( Game_Member::for_game( (int) $game->id ) as $member ) {
			$person = (int) $member->wp_user_id;
			if ( ! in_array( $member->role, $roles, true ) || $person === $by || isset( $told[ $person ] ) ) {
				continue;
			}
			$told[ $person ] = true;
			Notice::add( (int) $game->id, $person, $kind, $payload );
		}
	}

	/**
	 * Tells a player that a Storyteller posted on a plot one of their characters is in.
	 *
	 * @param object $game
	 * @param int    $wp_user_id
	 * @param int    $plot_id
	 * @param string $plot_title
	 * @param string $character_name The player's character the plot reaches.
	 */
	public static function plot_post( $game, int $wp_user_id, int $plot_id, string $plot_title, string $character_name ): void {
		self::to_person( $game, $wp_user_id, 'plot_post', [
			'plot_id'        => $plot_id,
			'plot_title'     => $plot_title,
			'character_name' => $character_name,
		] );
	}

	/**
	 * Tells staff that a player posted actions or another post that waits for a Storyteller: the plot's assignee, or
	 * else each HST, AST and Narrator.
	 *
	 * @param object $game
	 * @param object $plot
	 * @param string $posted_by Who the post is from, as the email names them.
	 * @param int    $author_id The poster, who is not told.
	 */
	public static function post_waiting( $game, $plot, string $posted_by, int $author_id ): void {
		$payload = [
			'plot_id'    => (int) ( $plot->id ?? 0 ),
			'plot_title' => \BeyondElysium\Models\Character::plot_display_title( (string) ( $plot->title ?? '' ) ),
			'posted_by'  => $posted_by,
		];
		if ( ! empty( $plot->assigned_to ) ) {
			self::to_person( $game, (int) $plot->assigned_to, 'post_waiting', $payload, $author_id );
			return;
		}
		self::to_staff( $game, 'post_waiting', $payload, $author_id, [ 'hst', 'ast', 'narrator' ] );
	}

	/**
	 * Notes one released item against the player it reached, as part of one release batch, until the request writes one
	 * notice per player per batch.
	 *
	 * @param object|null $game
	 * @param int      $wp_user_id
	 * @param int      $batch_id
	 * @param string[] $character_names The player's characters the item reached.
	 * @param string   $kind            `rumor`, `entry` or `reveal`.
	 */
	public static function released( $game, int $wp_user_id, int $batch_id, array $character_names, string $kind ): void {
		if ( ! is_object( $game ) || $wp_user_id <= 0 ) {
			return;
		}
		$bucket = self::$released[ $wp_user_id ][ $batch_id ] ?? [
			'game'            => $game,
			'character_names' => [],
			'rumors'          => 0,
			'entries'         => 0,
			'reveals'         => 0,
		];
		foreach ( $character_names as $name ) {
			if ( $name !== '' && ! in_array( $name, $bucket['character_names'], true ) ) {
				$bucket['character_names'][] = $name;
			}
		}
		$counter = [ 'rumor' => 'rumors', 'entry' => 'entries', 'reveal' => 'reveals' ][ $kind ] ?? null;
		if ( $counter !== null ) {
			++$bucket[ $counter ];
		}
		self::$released[ $wp_user_id ][ $batch_id ] = $bucket;
	}

	/**
	 * Writes one notice for each player and batch with something released, then forgets them.
	 */
	public static function flush_released(): void {
		foreach ( self::$released as $wp_user_id => $by_batch ) {
			foreach ( $by_batch as $batch_id => $bucket ) {
				self::to_person( $bucket['game'], (int) $wp_user_id, 'released', [
					'batch_id'        => (int) $batch_id,
					'character_names' => $bucket['character_names'],
					'rumors'          => $bucket['rumors'],
					'entries'         => $bucket['entries'],
					'reveals'         => $bucket['reveals'],
				] );
			}
		}
		self::$released = [];
	}

	/**
	 * Tells a player that downtime for a game night closes soon and a character has sent nothing.
	 *
	 * @param object|null $game
	 * @param object      $character
	 * @param object      $session
	 * @param string      $deadline  When it closes for this character.
	 * @param int|null    $plot_id   The character's plot for the night, once it exists.
	 */
	public static function downtime_due( $game, $character, $session, string $deadline, ?int $plot_id ): void {
		self::to_person( $game, (int) $character->wp_user_id, 'downtime_due', [
			'session_id'     => (int) $session->id,
			'character_id'   => (int) $character->id,
			'character_name' => (string) $character->name,
			'game_date'      => (string) $session->game_date,
			'deadline'       => $deadline,
			'plot_id'        => $plot_id,
		] );
	}

	/**
	 * Tells a player that something just became visible to one of their characters.
	 *
	 * @param object|null $game
	 * @param int    $wp_user_id
	 * @param string $entity `plot`, `item`, `location` or `secret_told`.
	 * @param string $title
	 * @param string $url    The player's own view of it.
	 */
	public static function now_visible( $game, int $wp_user_id, string $entity, string $title, string $url ): void {
		self::to_person( $game, $wp_user_id, 'now_visible', [ 'entity' => $entity, 'title' => $title, 'url' => $url ] );
	}

	/**
	 * Tells a newcomer their request to join was approved. A refusal tells no one: the person has no place in the
	 * chronicle to read it in.
	 *
	 * @param object $game
	 * @param int    $wp_user_id
	 */
	public static function join_approved( $game, int $wp_user_id ): void {
		self::to_person( $game, $wp_user_id, 'join_answered', [ 'chronicle' => (string) ( $game->name ?? '' ) ] );
	}

	/**
	 * Tells the chronicle's HSTs and ASTs that someone asked to join.
	 *
	 * @param object   $game
	 * @param \WP_User $applicant
	 * @param string   $message
	 */
	public static function join_requested( $game, \WP_User $applicant, string $message ): void {
		self::to_staff( $game, 'join_requested', [
			'applicant' => (string) $applicant->display_name,
			'chronicle' => (string) ( $game->name ?? '' ),
			'message'   => mb_substr( $message, 0, 300 ),
		], (int) $applicant->ID );
	}

	/**
	 * Tells the chronicle's HSTs and ASTs that a Discord link waits for them.
	 *
	 * @param object|null $game
	 * @param string   $discord_username
	 * @param string[] $character_names The characters the person chose.
	 */
	public static function link_requested( $game, string $discord_username, array $character_names ): void {
		self::to_staff( $game, 'link_requested', [
			'discord_username' => $discord_username,
			'character_names'  => array_values( $character_names ),
		] );
	}

	/**
	 * Tells the chronicle's HSTs and ASTs that a player sent a Grapevine file to review.
	 *
	 * @param object   $game
	 * @param object   $submission
	 * @param \WP_User $sender
	 */
	public static function file_received( $game, $submission, \WP_User $sender ): void {
		self::to_staff( $game, 'file_received', [
			'sender'         => (string) $sender->display_name,
			'character_name' => (string) ( $submission->character_name ?? '' ),
		], (int) $sender->ID );
	}

	/**
	 * Tells a player that a Storyteller accepted or refused the Grapevine file they sent.
	 *
	 * @param object $game
	 * @param object $submission
	 */
	public static function file_answered( $game, $submission ): void {
		$note = (string) ( $submission->answer_note ?? '' );
		self::to_person( $game, (int) $submission->submitted_by, 'file_answered', [
			'character_name' => (string) ( $submission->character_name ?? '' ),
			'accepted'       => ( $submission->state ?? '' ) === 'accepted',
			'note'           => $note === '' ? '' : St_Filter::strip_for_game( $note, $game->settings ?? null ),
		] );
	}

	/**
	 * Tells the chronicle's HSTs and ASTs that a secret was told on an Immediate chronicle and waits for approval.
	 *
	 * @param object $game
	 * @param object $secret
	 * @param string $teller_name
	 * @param string $recipient_name
	 * @param int    $by             Who told it, who is not told.
	 */
	public static function secret_told( $game, $secret, string $teller_name, string $recipient_name, int $by ): void {
		self::to_staff( $game, 'secret_told', [
			'secret_title'   => (string) ( $secret->title ?? '' ),
			'teller_name'    => $teller_name,
			'recipient_name' => $recipient_name,
		], $by );
	}

	/**
	 * A Storyteller's words as the player they are for may read them.
	 *
	 * @param object $character
	 */
	private static function player_text( $character, string $text ): string {
		if ( $text === '' ) {
			return '';
		}
		$game = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
		return St_Filter::strip_for_game( $text, $game->settings ?? null );
	}
}
