<?php

namespace BeyondElysium\Services;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Core\Page_Provisioner;
use BeyondElysium\Models\Game_Member;

defined( 'ABSPATH' ) || exit;

/**
 * Adds and removes a chronicle's players: the site membership, the chronicle membership row and, on a site that reads
 * accessSchema, the chronicle's player role there. A staff member's role is never changed.
 */
class Chronicle_Players {

	/**
	 * The accessSchema client ID for this plugin.
	 */
	private const CLIENT_ID = 'beyond_elysium';

	/**
	 * Makes an existing account a player in the chronicle.
	 *
	 * @param object $game       The chronicle.
	 * @param int    $wp_user_id
	 * @return array{status:string,role?:string,site_added?:bool,asc?:array<string,mixed>} `status` is `added`,
	 *         `already_player`, `staff` (a staff member, left unchanged) or `no_account`.
	 */
	public static function add( object $game, int $wp_user_id ): array {
		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return [ 'status' => 'no_account' ];
		}

		$existing = Game_Member::find( (int) $game->id, $wp_user_id );
		if ( $existing && $existing->role !== 'player' ) {
			return [ 'status' => 'staff', 'role' => (string) $existing->role ];
		}

		$site_added = self::ensure_site_membership( $wp_user_id );

		Game_Member::ensure_player( (int) $game->id, $wp_user_id );

		return [
			'status'     => $existing ? 'already_player' : 'added',
			'site_added' => $site_added,
			'asc'        => self::grant( $game, $user ),
		];
	}

	/**
	 * Takes a player out of the chronicle; their characters stay where they are.
	 *
	 * @param object $game       The chronicle.
	 * @param int    $wp_user_id
	 * @return array{status:string,role?:string,asc?:array<string,mixed>} `status` is `removed`, `not_member`, `staff`
	 *         (a staff member, left unchanged) or `no_account`.
	 */
	public static function remove( object $game, int $wp_user_id ): array {
		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return [ 'status' => 'no_account' ];
		}

		$existing = Game_Member::find( (int) $game->id, $wp_user_id );
		if ( $existing && $existing->role !== 'player' ) {
			return [ 'status' => 'staff', 'role' => (string) $existing->role ];
		}

		if ( $existing ) {
			Game_Member::remove( (int) $game->id, $wp_user_id );
		}

		return [
			'status' => $existing ? 'removed' : 'not_member',
			'asc'    => self::revoke( $game, $user ),
		];
	}

	/**
	 * Adds an account to this site as a subscriber, on a network, when it holds no role here yet - the site-only half
	 * of `add()`, usable on its own before a chronicle membership is granted.
	 *
	 * @param int $wp_user_id
	 * @return bool Whether the account was newly added to the site.
	 */
	public static function ensure_site_membership( int $wp_user_id ): bool {
		if ( is_multisite() && ! is_user_member_of_blog( $wp_user_id, get_current_blog_id() ) ) {
			return add_user_to_blog( get_current_blog_id(), $wp_user_id, 'subscriber' ) === true;
		}
		return false;
	}

	/**
	 * The public link that opens this chronicle's Join panel, carrying `auth=sso` only when the chronicle is linked
	 * to an OWbN player role.
	 *
	 * @param object $game
	 * @return string
	 */
	public static function join_link( object $game ): string {
		$args = [ 'join_slug' => (string) $game->slug, 'join' => '1' ];
		if ( Authorization::asc_role_path( $game, 'player' ) !== null ) {
			$args['auth'] = 'sso';
		}
		return add_query_arg( $args, home_url( '/' . Page_Provisioner::PLAYER_SLUG . '/' ) );
	}

	/**
	 * Grants the chronicle's accessSchema player role, then refreshes that one account's cached roles.
	 *
	 * @return array{attempted:bool,granted?:bool,role_path?:string,message?:string}
	 */
	private static function grant( object $game, \WP_User $user ): array {
		$path = Authorization::asc_role_path( $game, 'player' );
		if ( $path === null || ! function_exists( 'owc_asc_grant_role' ) ) {
			return [ 'attempted' => false ];
		}

		$result = owc_asc_grant_role( self::CLIENT_ID, $user->user_email, $path );
		if ( is_wp_error( $result ) ) {
			return [ 'attempted' => true, 'granted' => false, 'role_path' => $path, 'message' => $result->get_error_message() ];
		}

		$granted = is_array( $result ) && ! empty( $result['granted'] );
		$message = $granted ? '' : (string) ( is_array( $result ) ? ( $result['reason'] ?? '' ) : '' );
		if ( ! $granted && function_exists( 'owc_asc_check_access' ) && owc_asc_check_access( self::CLIENT_ID, $user->user_email, $path, false ) === true ) {
			$granted = true;
			$message = '';
		}
		if ( $granted && function_exists( 'owc_asc_refresh_user_roles_safe' ) ) {
			owc_asc_refresh_user_roles_safe( (int) $user->ID );
		}

		return [ 'attempted' => true, 'granted' => $granted, 'role_path' => $path, 'message' => $message ];
	}

	/**
	 * Revokes the chronicle's accessSchema player role, then refreshes that one account's cached roles.
	 *
	 * @return array{attempted:bool,revoked?:bool,role_path?:string,message?:string}
	 */
	private static function revoke( object $game, \WP_User $user ): array {
		$path = Authorization::asc_role_path( $game, 'player' );
		if ( $path === null || ! function_exists( 'owc_asc_revoke_role' ) ) {
			return [ 'attempted' => false ];
		}

		$result = owc_asc_revoke_role( self::CLIENT_ID, $user->user_email, $path );
		if ( is_wp_error( $result ) ) {
			return [ 'attempted' => true, 'revoked' => false, 'role_path' => $path, 'message' => $result->get_error_message() ];
		}
		if ( function_exists( 'owc_asc_refresh_user_roles_safe' ) ) {
			owc_asc_refresh_user_roles_safe( (int) $user->ID );
		}

		return [ 'attempted' => true, 'revoked' => is_array( $result ) && ! empty( $result['revoked'] ), 'role_path' => $path, 'message' => '' ];
	}
}
