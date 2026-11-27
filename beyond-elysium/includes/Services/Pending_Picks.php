<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * The picks of a pending new character that ask for a Coordinator, with the request a Storyteller sent for each. A pick
 * has no change behind it, so its request is found by a key made from the character and the pick, which also makes a
 * second send of the same pick safe.
 */
class Pending_Picks {

	/**
	 * A pick's short key: stable for one section and name, the same on every read.
	 */
	public static function key( string $section, string $name ): string {
		return substr( sha1( strtolower( $section . '|' . $name ) ), 0, 12 );
	}

	/**
	 * The request key for one pick of one character, in the shape of a uuid, the same every time.
	 */
	public static function uuid( int $game_id, int $character_id, string $key ): string {
		$hash = sha1( "be-pick|{$game_id}|{$character_id}|{$key}" );
		return sprintf(
			'%s-%s-5%s-%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 13, 3 ),
			substr( '89ab', hexdec( $hash[16] ) % 4, 1 ) . substr( $hash, 17, 3 ),
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * The picks of a pending character the rules send to a Coordinator, as a Storyteller sees them. Empty where the
	 * chronicle does not send, for a character that is not pending, and for an NPC.
	 *
	 * @return array<int,array{key:string,section:string,section_label:string,name:string,reason:?string,signal:array{kind:?string,coordinators:string[]},request:?array<string,mixed>}>
	 */
	public static function for_character( object $game, object $character ): array {
		if ( Coordinator_Send::mode( $game ) === null || (string) $character->status !== 'pending' || ! empty( $character->is_npc ) ) {
			return [];
		}
		$resolved = Creature_Stack::resolve( (string) $character->stack_slug, (string) $game->slug );
		if ( $resolved === null ) {
			return [];
		}
		$sheet = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
		$tally = Creation_Tally::for_stack( $resolved['stack'], $resolved['blocks'], $sheet, (string) $game->slug, (int) ( $game->settings->starting_xp ?? 0 ) );

		$picks = [];
		foreach ( Creation_Review::needs_storyteller( $resolved['stack'], $resolved['blocks'], $sheet, $game, $tally ) as $pick ) {
			$signal = Coordinator_Signal::of( $pick['reason'] );
			if ( $signal['kind'] === null ) {
				continue;
			}
			$key     = self::key( $pick['section'], $pick['name'] );
			$request = Coordinator_Request::find_by_uuid( self::uuid( (int) $game->id, (int) $character->id, $key ) );
			$picks[] = $pick + [
				'key'     => $key,
				'signal'  => [ 'kind' => $signal['kind'], 'coordinators' => $signal['coordinators'] ],
				'request' => $request ? Coordinator_Request::view( $request, true ) : null,
			];
		}
		return $picks;
	}

	/**
	 * Whether every pick that asks for a Coordinator's approval has been approved by one, and there is at least one.
	 */
	public static function all_approved( object $game, object $character ): bool {
		$asking = array_filter( self::for_character( $game, $character ), static fn( array $pick ): bool => $pick['signal']['kind'] === Coordinator_Signal::APPROVAL );
		if ( $asking === [] ) {
			return false;
		}
		foreach ( $asking as $pick ) {
			if ( ( $pick['request']['state'] ?? '' ) !== 'approved' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Makes a pending character active once the Coordinator has approved the last pick that asked for one. A character
	 * a Storyteller already moved on is left as it is.
	 */
	public static function on_answered( object $request, string $outcome ): void {
		if ( $request->change_id !== null || $outcome !== 'approved' ) {
			return;
		}
		$character = Character::find( $request->character_id );
		$game      = Game::find( $request->game_id );
		if ( ! $character || ! $game || (string) $character->status !== 'pending' ) {
			return;
		}
		if ( self::all_approved( $game, $character ) ) {
			Character::update_header( (int) $character->id, [ 'status' => 'active' ] );
		}
	}
}
