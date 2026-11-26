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
		$told = [];
		foreach ( Game_Member::for_game( (int) $game->id ) as $member ) {
			$person = (int) $member->wp_user_id;
			if ( ! in_array( $member->role, [ 'hst', 'ast' ], true ) || $person === $by || isset( $told[ $person ] ) ) {
				continue;
			}
			$told[ $person ] = true;
			Notice::add( (int) $game->id, $person, 'changes_waiting', [
				'character_id'   => (int) $character->id,
				'character_name' => (string) $character->name,
				'waiting'        => $waiting,
				'total'          => $total,
			] );
		}
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
