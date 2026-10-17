<?php

namespace BeyondElysium\Models;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * One Discord account's request, and later approval, to act as a chronicle member.
 */
class Discord_Link {

	public const REQUESTED = 'requested';
	public const CONFIRMED = 'confirmed';
	public const APPROVED  = 'approved';
	public const DECLINED  = 'declined';
	public const REVOKED   = 'revoked';

	public const STATUSES = [ self::REQUESTED, self::CONFIRMED, self::APPROVED, self::DECLINED, self::REVOKED ];

	/**
	 * How long a confirm token lasts.
	 */
	public const TOKEN_TTL_SECONDS = 30 * MINUTE_IN_SECONDS;

	/**
	 * Starts a link request, or renews one already waiting, or reports one already further along.
	 *
	 * @param int    $game_id
	 * @param string $discord_user_id
	 * @param string $discord_username
	 * @return array{status:string,link_id:int,token?:string,expires_at?:string}
	 */
	public static function start_or_renew( int $game_id, string $discord_user_id, string $discord_username ): array {
		$savepoint = Transaction::begin( 'be_discord_link_start' );

		$active = self::active_for_discord_user( $game_id, $discord_user_id );

		if ( $active && $active->status === self::APPROVED ) {
			Transaction::commit( $savepoint );
			return [ 'status' => self::APPROVED, 'link_id' => (int) $active->id ];
		}
		if ( $active && $active->status === self::CONFIRMED ) {
			Transaction::commit( $savepoint );
			return [ 'status' => self::CONFIRMED, 'link_id' => (int) $active->id ];
		}

		$token        = bin2hex( random_bytes( 32 ) );
		$token_hash   = hash( 'sha256', $token );
		$expires_at   = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS );

		if ( $active && $active->status === self::REQUESTED ) {
			Manager::update( 'discord_links', [
				'discord_username'  => $discord_username,
				'token_hash'        => $token_hash,
				'token_expires_at'  => $expires_at,
				'requested_at'      => current_time( 'mysql' ),
			], [ 'id' => (int) $active->id ] );
			Transaction::commit( $savepoint );
			return [ 'status' => self::REQUESTED, 'link_id' => (int) $active->id, 'token' => $token, 'expires_at' => (string) $expires_at ];
		}

		$id = Manager::insert( 'discord_links', [
			'game_id'          => $game_id,
			'discord_user_id'  => $discord_user_id,
			'discord_username' => $discord_username,
			'status'           => self::REQUESTED,
			'token_hash'       => $token_hash,
			'token_expires_at' => $expires_at,
			'requested_at'     => current_time( 'mysql' ),
		] );

		Transaction::commit( $savepoint );
		return [ 'status' => self::REQUESTED, 'link_id' => (int) $id, 'token' => $token, 'expires_at' => (string) $expires_at ];
	}

	/**
	 * A live, still-requested row for a token, or null when it is wrong, used, or expired.
	 *
	 * @param string $token
	 * @return object|null
	 */
	public static function find_by_live_token( string $token ): ?object {
		$hash = hash( 'sha256', $token );
		$row  = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'discord_links' ) . " WHERE token_hash = %s AND status = %s",
			$hash,
			self::REQUESTED
		);
		if ( ! $row || strtotime( (string) $row->token_expires_at ) < time() ) {
			return null;
		}
		return self::decode( $row );
	}

	/**
	 * Confirms a link with a signed-in user's chosen characters. Staff are approved at once.
	 *
	 * @param object             $link
	 * @param int                $wp_user_id
	 * @param array<int,int>     $character_ids
	 * @param bool               $is_staff
	 * @return object
	 */
	public static function confirm( object $link, int $wp_user_id, array $character_ids, bool $is_staff ): object {
		$savepoint = Transaction::begin( 'be_discord_link_confirm' );

		Manager::update( 'discord_links', [
			'wp_user_id'    => $wp_user_id,
			'status'        => $is_staff ? self::APPROVED : self::CONFIRMED,
			'confirmed_at'  => current_time( 'mysql' ),
			'token_hash'    => null,
			'token_expires_at' => null,
		], [ 'id' => (int) $link->id ] );

		foreach ( $character_ids as $character_id ) {
			Manager::insert( 'discord_link_characters', [
				'link_id'      => (int) $link->id,
				'character_id' => $character_id,
			] );
		}

		if ( $is_staff ) {
			self::finalize_approval( (int) $link->id, (int) $link->game_id, $wp_user_id, $wp_user_id );
		} else {
			\BeyondElysium\Models\Bot_Notice::add( (int) $link->game_id, null, 'link_request', [
				'discord_username' => (string) ( $link->discord_username ?? '' ),
			] );
		}

		Transaction::commit( $savepoint );
		$updated = self::find( (int) $link->id );
		return $updated ?? $link;
	}

	/**
	 * A Storyteller approves a confirmed link, revoking any other approved link for the same account.
	 *
	 * @param int $link_id
	 * @param int $decided_by
	 * @return bool
	 */
	public static function approve( int $link_id, int $decided_by ): bool {
		$link = self::find( $link_id );
		if ( ! $link || $link->status !== self::CONFIRMED ) {
			return false;
		}

		$savepoint = Transaction::begin( 'be_discord_link_approve' );
		Manager::update( 'discord_links', [
			'status'      => self::APPROVED,
			'decided_by'  => $decided_by,
			'decided_at'  => current_time( 'mysql' ),
		], [ 'id' => $link_id ] );
		self::finalize_approval( $link_id, (int) $link->game_id, (int) $link->wp_user_id, $decided_by );
		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Revokes every other approved link for the same (game, WordPress user) besides the one just approved.
	 *
	 * @param int $keep_link_id
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @param int $revoked_by
	 * @return void
	 */
	private static function finalize_approval( int $keep_link_id, int $game_id, int $wp_user_id, int $revoked_by ): void {
		global $wpdb;
		$table = Manager::table( 'discord_links' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = %s, revoked_by = %d, revoked_at = %s
			 WHERE game_id = %d AND wp_user_id = %d AND status = %s AND id != %d",
			self::REVOKED,
			$revoked_by,
			current_time( 'mysql' ),
			$game_id,
			$wp_user_id,
			self::APPROVED,
			$keep_link_id
		) );
	}

	/**
	 * A Storyteller declines a confirmed link.
	 *
	 * @param int $link_id
	 * @param int $decided_by
	 * @return bool
	 */
	public static function decline( int $link_id, int $decided_by ): bool {
		$link = self::find( $link_id );
		if ( ! $link || $link->status !== self::CONFIRMED ) {
			return false;
		}
		return Manager::update( 'discord_links', [
			'status'     => self::DECLINED,
			'decided_by' => $decided_by,
			'decided_at' => current_time( 'mysql' ),
		], [ 'id' => $link_id ] ) !== false;
	}

	/**
	 * A Storyteller revokes an approved link.
	 *
	 * @param int $link_id
	 * @param int $revoked_by
	 * @return bool
	 */
	public static function revoke_by_staff( int $link_id, int $revoked_by ): bool {
		$link = self::find( $link_id );
		if ( ! $link || $link->status !== self::APPROVED ) {
			return false;
		}
		return Manager::update( 'discord_links', [
			'status'     => self::REVOKED,
			'revoked_by' => $revoked_by,
			'revoked_at' => current_time( 'mysql' ),
		], [ 'id' => $link_id ] ) !== false;
	}

	/**
	 * Sets or clears the Discord channel recorded as this link's player room.
	 *
	 * @param int         $link_id
	 * @param string|null $channel_id
	 * @return bool
	 */
	public static function set_room( int $link_id, ?string $channel_id ): bool {
		return Manager::update( 'discord_links', [
			'room_channel_id' => $channel_id,
		], [ 'id' => $link_id ] ) !== false;
	}

	/**
	 * A player ends their own approved link, or cancels one waiting for a Storyteller.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	public static function revoke_own( int $game_id, int $wp_user_id ): bool {
		global $wpdb;
		$table = Manager::table( 'discord_links' );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = %s, revoked_by = %d, revoked_at = %s
			 WHERE game_id = %d AND wp_user_id = %d AND status IN (%s, %s)",
			self::REVOKED,
			$wp_user_id,
			current_time( 'mysql' ),
			$game_id,
			$wp_user_id,
			self::CONFIRMED,
			self::APPROVED
		) );
		return (int) $updated > 0;
	}

	/**
	 * The approved or confirmed (not declined or revoked) row for a Discord user in a game, or null.
	 *
	 * @param int    $game_id
	 * @param string $discord_user_id
	 * @return object|null
	 */
	public static function active_for_discord_user( int $game_id, string $discord_user_id ): ?object {
		$row = Manager::get_row(
			'SELECT * FROM ' . Manager::table( 'discord_links' ) . '
			 WHERE game_id = %d AND discord_user_id = %s AND status IN (%s, %s, %s)
			 ORDER BY id DESC LIMIT 1',
			$game_id,
			$discord_user_id,
			self::REQUESTED,
			self::CONFIRMED,
			self::APPROVED
		);
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		$row = Manager::get_row( 'SELECT * FROM ' . Manager::table( 'discord_links' ) . ' WHERE id = %d', $id );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * A game's links, newest first, optionally narrowed to one status.
	 *
	 * @param int    $game_id
	 * @param string $status
	 * @return array<int,object>
	 */
	public static function for_game( int $game_id, string $status = '' ): array {
		if ( $status !== '' && in_array( $status, self::STATUSES, true ) ) {
			$rows = Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'discord_links' ) . ' WHERE game_id = %d AND status = %s ORDER BY id DESC',
				$game_id,
				$status
			);
		} else {
			$rows = Manager::get_results(
				'SELECT * FROM ' . Manager::table( 'discord_links' ) . ' WHERE game_id = %d ORDER BY id DESC',
				$game_id
			);
		}
		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * A link's characters, kept to ones that still belong to the link's own user, aren't deleted, and are in the
	 * link's own game.
	 *
	 * @param object $link
	 * @return array<int,object>
	 */
	public static function live_characters( object $link ): array {
		if ( ! $link->wp_user_id ) {
			return [];
		}
		$game = Game::find( (int) $link->game_id );
		if ( ! $game ) {
			return [];
		}
		$ids = Manager::get_results(
			'SELECT character_id FROM ' . Manager::table( 'discord_link_characters' ) . ' WHERE link_id = %d',
			(int) $link->id
		);
		$ids = array_map( static fn( $row ) => (int) $row->character_id, $ids );
		if ( ! $ids ) {
			return [];
		}
		$characters = [];
		foreach ( $ids as $id ) {
			$character = Character::find( $id );
			if ( $character
				&& (int) $character->wp_user_id === (int) $link->wp_user_id
				&& $character->owner_slug === $game->slug
			) {
				$characters[] = $character;
			}
		}
		return $characters;
	}

	/**
	 * Whether a WordPress user holds `be_manage_characters` in a game, checked without a current REST request.
	 *
	 * @param int $game_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	public static function is_staff( int $game_id, int $wp_user_id ): bool {
		if ( ! user_can( $wp_user_id, 'be_manage_characters' ) ) {
			return false;
		}
		$game = Game::find( $game_id );
		if ( ! $game ) {
			return false;
		}
		if ( user_can( $wp_user_id, 'be_manage_games' ) ) {
			return true;
		}
		$member = Game_Member::find( $game_id, $wp_user_id );
		if ( ! $member ) {
			return false;
		}
		return in_array( 'be_manage_characters', Authorization::role_grants( (string) $member->role ), true );
	}

	/**
	 * Deletes every still-requested row whose token expired over a day ago.
	 *
	 * @return void
	 */
	public static function prune_expired_requests(): void {
		global $wpdb;
		$table = Manager::table( 'discord_links' );
		$ids   = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = %s AND token_expires_at < %s",
			self::REQUESTED,
			wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );
		foreach ( $ids as $id ) {
			Manager::delete( 'discord_link_characters', [ 'link_id' => (int) $id ] );
			Manager::delete( 'discord_links', [ 'id' => (int) $id ] );
		}
	}

	/**
	 * @param object $row
	 * @return object
	 */
	private static function decode( object $row ): object {
		foreach ( [ 'id', 'game_id', 'wp_user_id', 'decided_by', 'revoked_by' ] as $field ) {
			if ( isset( $row->$field ) ) {
				$row->$field = (int) $row->$field;
			}
		}
		return $row;
	}
}
