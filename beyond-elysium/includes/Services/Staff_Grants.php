<?php

namespace BeyondElysium\Services;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Game_Member;

defined( 'ABSPATH' ) || exit;

/**
 * What an HST gives one AST or Narrator beyond the role's own bundle: any of five areas, in one chronicle. A grant is a
 * row with no `revoked_at`; taking it away stamps who and when, so the table is also the log.
 */
class Staff_Grants {

	/**
	 * The five capabilities a grant can carry, in the order a screen lists them.
	 *
	 * @var string[]
	 */
	public const AREAS = [
		'be_manage_schemas',
		'be_manage_approval_rules',
		'be_manage_templates',
		'be_manage_chronicle_setup',
		'be_delete_characters',
	];

	/**
	 * The chronicle roles a grant can be given to.
	 *
	 * @var string[]
	 */
	private const ELIGIBLE_ROLES = [ 'ast', 'narrator' ];

	public const REFUSAL_INVALID_AREA     = 'invalid_area';
	public const REFUSAL_NOT_ELIGIBLE     = 'not_eligible';
	public const REFUSAL_DELETE_NEEDS_AST = 'delete_needs_ast';
	public const REFUSAL_SAVE_FAILED      = 'save_failed';

	/**
	 * The areas each person holds in each chronicle for the rest of the request, keyed "{game_id}|{wp_user_id}".
	 *
	 * @var array<string,string[]>
	 */
	private static array $memo = [];

	/**
	 * Whether a capability is one a grant can carry.
	 */
	public static function is_area( string $capability ): bool {
		return in_array( $capability, self::AREAS, true );
	}

	/**
	 * Whether a grant of this area counts for someone holding this chronicle role: an AST or a Narrator, and an AST
	 * alone for deleting characters, which needs the Characters list.
	 */
	public static function counts_for( string $capability, string $role ): bool {
		if ( ! self::is_area( $capability ) ) {
			return false;
		}
		return 'be_delete_characters' === $capability ? 'ast' === $role : in_array( $role, self::ELIGIBLE_ROLES, true );
	}

	/**
	 * The chronicle roles a grant of this area counts for, in the order they are asked.
	 *
	 * @return string[]
	 */
	public static function roles_for( string $capability ): array {
		return array_values( array_filter( self::ELIGIBLE_ROLES, static fn( $role ) => self::counts_for( $capability, $role ) ) );
	}

	/**
	 * The areas one person currently holds in one chronicle, in `AREAS` order.
	 *
	 * @return string[]
	 */
	public static function held( int $game_id, int $wp_user_id ): array {
		$key = $game_id . '|' . $wp_user_id;
		if ( ! isset( self::$memo[ $key ] ) ) {
			$areas = Manager::get_results(
				'SELECT DISTINCT area FROM ' . Manager::table( 'staff_grants' ) . ' WHERE game_id = %d AND wp_user_id = %d AND revoked_at IS NULL',
				$game_id,
				$wp_user_id
			);
			$held = array_map( static fn( $row ) => (string) $row->area, $areas );

			self::$memo[ $key ] = array_values( array_intersect( self::AREAS, $held ) );
		}
		return self::$memo[ $key ];
	}

	/**
	 * The highest chronicle role the person holds here, by their own accessSchema roles or their membership row.
	 */
	public static function role_here( object $game, \WP_User $user ): ?string {
		$roles = [];
		$linked = Authorization::asc_role_in_game( $user, $game );
		if ( null !== $linked ) {
			$roles[] = $linked;
		}
		$member = Game_Member::find( (int) $game->id, $user->ID );
		if ( $member ) {
			$roles[] = (string) $member->role;
		}

		foreach ( Game_Member::VALID_ROLES as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return $role;
			}
		}
		return null;
	}

	/**
	 * Makes the person's grants in this chronicle exactly `$areas`: adds the ones they lack and stamps the ones that
	 * are gone, in one transaction. Returns null when it was saved, else the code of the refusal.
	 *
	 * Taking areas away is always allowed; adding one needs the person to be an AST or a Narrator here now (an AST for
	 * deleting characters).
	 *
	 * @param string[] $areas
	 */
	public static function set( object $game, int $wp_user_id, array $areas, int $by ): ?string {
		$areas = array_values( array_unique( array_map( 'strval', $areas ) ) );
		foreach ( $areas as $area ) {
			if ( ! self::is_area( $area ) ) {
				return self::REFUSAL_INVALID_AREA;
			}
		}

		$game_id = (int) $game->id;
		$held    = self::held( $game_id, $wp_user_id );
		$add     = array_values( array_diff( $areas, $held ) );
		$remove  = array_values( array_diff( $held, $areas ) );

		if ( $add ) {
			$user = get_userdata( $wp_user_id );
			$role = $user ? self::role_here( $game, $user ) : null;
			if ( null === $role || ! in_array( $role, self::ELIGIBLE_ROLES, true ) ) {
				return self::REFUSAL_NOT_ELIGIBLE;
			}
			if ( in_array( 'be_delete_characters', $add, true ) && 'ast' !== $role ) {
				return self::REFUSAL_DELETE_NEEDS_AST;
			}
		}

		$now       = current_time( 'mysql' );
		$savepoint = Transaction::begin( 'be_staff_grants_set' );
		$ok        = true;

		foreach ( $add as $area ) {
			$ok = false !== Manager::insert( 'staff_grants', [
				'game_id'    => $game_id,
				'wp_user_id' => $wp_user_id,
				'area'       => $area,
				'granted_by' => $by,
				'granted_at' => $now,
			] ) && $ok;
		}
		foreach ( $remove as $area ) {
			$ok = false !== Manager::update(
				'staff_grants',
				[ 'revoked_by' => $by, 'revoked_at' => $now ],
				[ 'game_id' => $game_id, 'wp_user_id' => $wp_user_id, 'area' => $area, 'revoked_at' => null ]
			) && $ok;
		}

		if ( ! $ok ) {
			Transaction::rollback( $savepoint );
			unset( self::$memo[ $game_id . '|' . $wp_user_id ] );
			return self::REFUSAL_SAVE_FAILED;
		}

		Transaction::commit( $savepoint );
		unset( self::$memo[ $game_id . '|' . $wp_user_id ] );
		return null;
	}

	/**
	 * Every person holding at least one area in the chronicle, each with the areas and who gave each and when.
	 *
	 * @return array<int,array<int,array{area:string,by:int,at:string}>> Keyed by person.
	 */
	public static function people( object $game ): array {
		$rows = Manager::get_results(
			'SELECT wp_user_id, area, granted_by, granted_at FROM ' . Manager::table( 'staff_grants' ) . ' WHERE game_id = %d AND revoked_at IS NULL ORDER BY id ASC',
			(int) $game->id
		);
		$people = [];
		foreach ( $rows as $row ) {
			$people[ (int) $row->wp_user_id ][] = [
				'area' => (string) $row->area,
				'by'   => (int) $row->granted_by,
				'at'   => (string) $row->granted_at,
			];
		}
		return $people;
	}

	/**
	 * The most recent grants and removals in the chronicle, newest first.
	 *
	 * @return array<int,array{action:string,area:string,wp_user_id:int,by:int,at:string}>
	 */
	public static function log( object $game, int $limit = 50 ): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'staff_grants' ) . ' WHERE game_id = %d ORDER BY COALESCE( revoked_at, granted_at ) DESC, id DESC LIMIT %d',
			(int) $game->id,
			$limit
		);

		$events = [];
		foreach ( $rows as $row ) {
			$events[] = [
				'action'     => 'granted',
				'area'       => (string) $row->area,
				'wp_user_id' => (int) $row->wp_user_id,
				'by'         => (int) $row->granted_by,
				'at'         => (string) $row->granted_at,
				'id'         => (int) $row->id,
			];
			if ( null !== $row->revoked_at ) {
				$events[] = [
					'action'     => 'revoked',
					'area'       => (string) $row->area,
					'wp_user_id' => (int) $row->wp_user_id,
					'by'         => (int) $row->revoked_by,
					'at'         => (string) $row->revoked_at,
					'id'         => (int) $row->id,
				];
			}
		}

		usort( $events, static fn( $a, $b ) => [ $b['at'], $b['id'], 'revoked' === $b['action'] ? 1 : 0 ] <=> [ $a['at'], $a['id'], 'revoked' === $a['action'] ? 1 : 0 ] );

		return array_map(
			static function ( array $event ): array {
				unset( $event['id'] );
				return $event;
			},
			array_slice( $events, 0, $limit )
		);
	}

	/**
	 * Deletes every row an account holds, in every chronicle.
	 */
	public static function remove_user_everywhere( int $wp_user_id ): void {
		Manager::delete( 'staff_grants', [ 'wp_user_id' => $wp_user_id ] );
		self::$memo = [];
	}
}
