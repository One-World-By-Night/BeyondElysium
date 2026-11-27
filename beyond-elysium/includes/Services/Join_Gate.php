<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Join_Request;
use BeyondElysium\Models\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Whether someone with no membership yet may start a character here: a chronicle taking join requests, and no request or
 * character of theirs already waiting.
 */
class Join_Gate {

	/**
	 * The reason a joiner is refused, or null when they may go on.
	 *
	 * @param object      $game         A games row.
	 * @param object|null $join_request The person's waiting join request, when they have one.
	 * @param int         $wp_user_id   The person.
	 * @return array{code:string,message:string,status:int}|null
	 */
	public static function refusal( object $game, ?object $join_request, int $wp_user_id ): ?array {
		// An open request already carrying a character or file: a second one is refused.
		if ( $join_request && ( ! empty( $join_request->character_id ) || ! empty( $join_request->submission_id ) ) ) {
			return self::already_requested();
		}
		// No open request at all: the checks for a site not using the Join panel.
		if ( $join_request ) {
			return null;
		}
		if ( ! Join_Request::open_on( $game ) ) {
			return [
				'code'    => 'join_requests_off',
				'message' => __( 'This chronicle is not taking join requests right now.', 'beyond-elysium' ),
				'status'  => 403,
			];
		}
		$waiting = Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) . " WHERE owner_type = 'chronicle' AND owner_slug = %s AND wp_user_id = %d AND status = 'pending'",
			(string) $game->slug,
			$wp_user_id
		);
		if ( (int) $waiting > 0 || Submission::has_waiting( (int) $game->id, $wp_user_id ) ) {
			return self::already_requested();
		}
		return null;
	}

	/**
	 * @return array{code:string,message:string,status:int}
	 */
	private static function already_requested(): array {
		return [
			'code'    => 'join_already_requested',
			'message' => __( 'Your character is already waiting for this chronicle\'s Storytellers to approve it.', 'beyond-elysium' ),
			'status'  => 409,
		];
	}
}
