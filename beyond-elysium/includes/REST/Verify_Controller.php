<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Item_Attestation;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\Not_Exportable_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's first unauthenticated REST route (GX-7): answers "is this
 * character document still current" for anyone holding a short verification
 * code, with no login required. Confirmed to be the first such route in this
 * plugin - `grep -rn "__return_true"` across `includes/` returned nothing
 * before this file - so it carries its own explicit security posture rather
 * than inheriting a pattern that doesn't exist elsewhere:
 *
 *   - Keyed by a random per-issuance code, never the character's own UUID.
 *   - The response echoes what was attested at issue time (a stored copy,
 *     never a live read) plus `still_matches` - five booleans, the only
 *     live information disclosed.
 *   - An unknown, expired, or malformed code returns a bare 404, with no
 *     distinction between "never existed" and "expired" - never an oracle.
 *   - `revoked: true` sets `valid: false` and suppresses `still_matches`
 *     entirely, but is a real 200, not a 404 - a receiving chronicle must be
 *     able to tell "this document is void" from "this code is bogus".
 *   - Rate limited at 30/minute per IP via a transient. The rate-limit
 *     response never depends on whether the code was real.
 *   - Never returned, at any outcome: `sheet_data`, biography, notes, the
 *     UUID, `wp_user_id`, player identity, or the local numeric id.
 *
 * @see BE_PROCESS/design/gex-export-transfer-design.md GX-7, §6.2, §6.3
 */
class Verify_Controller extends Base_Controller {

	protected $rest_base = 'verify';

	/** Requests allowed per IP per rolling minute. */
	private const RATE_LIMIT = 30;

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/verify/(?P<code>[A-Za-z0-9-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'verify' ],
				// Deliberate: this route is public by design (GX-7), not an oversight.
				'permission_callback' => '__return_true',
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify( $request ) {
		if ( self::is_rate_limited() ) {
			return $this->error( 'rate_limited', __( 'Too many requests. Please try again shortly.', 'beyond-elysium' ), 429 );
		}

		$code = (string) $request['code'];
		if ( ! preg_match( '/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/', $code ) ) {
			return self::not_found();
		}

		// 1.1.0 §3.13 - character attestations are checked first, then item attestations; a
		// short code space shared via Services\Short_Code means the two can never collide, so
		// this order is never ambiguous.
		$attestation = Attestation::resolve( $code );
		if ( $attestation !== null ) {
			if ( $attestation->expires_at !== null && strtotime( $attestation->expires_at ) < time() ) {
				return self::not_found();
			}
			return $this->verify_character( $attestation );
		}

		$item_attestation = Item_Attestation::resolve( $code );
		if ( $item_attestation !== null ) {
			return $this->verify_item( $item_attestation );
		}

		return self::not_found();
	}

	/**
	 * @param object $attestation
	 * @return \WP_REST_Response
	 */
	private function verify_character( object $attestation ) {

		$game   = Game::find_by_slug( $attestation->game_slug );
		$issuer = [
			'chronicle' => $game->name ?? $attestation->game_slug,
			'slug'      => $attestation->game_slug,
			'site'      => home_url(),
		];

		if ( $attestation->revoked_at !== null ) {
			return $this->success( [
				'valid'     => false,
				'revoked'   => true,
				'issuer'    => $issuer,
				'issued_at' => $attestation->issued_at,
				'kind'      => $attestation->kind,
				'attested'  => $attestation->attested,
			] );
		}

		return $this->success( [
			'valid'         => true,
			'revoked'       => false,
			'issuer'        => $issuer,
			'issued_at'     => $attestation->issued_at,
			'kind'          => $attestation->kind,
			'attested'      => $attestation->attested,
			'still_matches' => self::still_matches( $attestation ),
			'as_of'         => current_time( 'mysql', true ),
		] );
	}

	/**
	 * The item-shaped verify response (1.1.0 §3.13) - public like a character's own: never the
	 * item's description or powers, never a player's name, only what was attested at issue
	 * time plus whether it still matches a live read.
	 *
	 * @param object $attestation A row from `Item_Attestation::resolve()`.
	 * @return \WP_REST_Response
	 */
	private function verify_item( object $attestation ) {
		$game      = Game::find_by_slug( $attestation->game_slug );
		$attested  = $attestation->attested;

		if ( $attestation->revoked_at !== null ) {
			return $this->success( [
				'kind'      => 'item',
				'name'      => $attested['name'] ?? null,
				'chronicle' => $game->name ?? $attestation->game_slug,
				'issued_at' => $attestation->issued_at,
				'revoked'   => true,
			] );
		}

		$item = World_Object::find( (int) $attestation->world_object_id );

		$current_holder_name = null;
		if ( $item !== null ) {
			foreach ( Connection::for_entity( 'world_object', (int) $item->id ) as $connection ) {
				$character_id = $connection->source_type === 'character' ? $connection->source_id
					: ( $connection->target_type === 'character' ? $connection->target_id : null );
				if ( $character_id !== null ) {
					$holder               = Character::find( (int) $character_id );
					$current_holder_name  = $holder->name ?? null;
					break;
				}
			}
		}

		$current_uses_left  = $item !== null ? ( $item->properties['uses_left'] ?? null ) : null;
		$current_expires_on = $item !== null ? ( $item->properties['expires_on'] ?? null ) : null;

		return $this->success( [
			'kind'          => 'item',
			'name'          => $attested['name'] ?? null,
			'chronicle'     => $game->name ?? $attestation->game_slug,
			'issued_at'     => $attestation->issued_at,
			'revoked'       => false,
			'still_matches' => [
				'holder'    => $item !== null && $current_holder_name === ( $attested['holder'] ?? null ),
				'uses_left' => $item !== null && (int) $current_uses_left === (int) ( $attested['uses_left'] ?? -1 ),
				'expiry'    => $item !== null && $current_expires_on === ( $attested['expires_on'] ?? null ),
			],
			'current'       => [
				'expired'  => $item !== null && World_Object::is_expired( $item ),
				'used_up'  => $item !== null && World_Object::is_used_up( $item ),
			],
		] );
	}

	/**
	 * Compares the attestation's stored snapshot against a live read of the
	 * character. A character that no longer exists (deleted since issuance)
	 * reports every field as no longer matching, rather than erroring - the
	 * document plainly isn't current anymore, which is exactly the question
	 * this endpoint answers.
	 *
	 * @param object $attestation
	 * @return array<string,bool>
	 */
	private static function still_matches( object $attestation ): array {
		$character = $attestation->character_id !== null ? Character::find( (int) $attestation->character_id ) : null;
		if ( ! $character ) {
			return [ 'name' => false, 'status' => false, 'xp_earned' => false, 'xp_unspent' => false, 'sheet' => false ];
		}

		$attested = $attestation->attested;
		try {
			$current_hash = hash( 'sha256', Character_Exporter::export( (int) $character->id )['xml'] );
		} catch ( Not_Exportable_Exception ) {
			$current_hash = null; // Its creature type no longer has an exchange shape to compare.
		}

		return [
			'name'       => $character->name === ( $attested['name'] ?? null ),
			'status'     => $character->status === ( $attested['status'] ?? null ),
			'xp_earned'  => (int) $character->xp_earned === (int) ( $attested['xp_earned'] ?? -1 ),
			'xp_unspent' => (int) $character->xp_unspent === (int) ( $attested['xp_unspent'] ?? -1 ),
			'sheet'      => $current_hash === $attestation->sheet_hash,
		];
	}

	/**
	 * A bare 404 with no distinguishing detail - unknown, expired, and
	 * malformed codes are all indistinguishable from one another, so this
	 * endpoint cannot be used to enumerate real codes.
	 *
	 * @return \WP_Error
	 */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'not_found', '', [ 'status' => 404 ] );
	}

	/**
	 * A fixed-window counter, 30 requests per rolling minute per IP,
	 * transient-backed. Adequate for this audience per the design doc's own
	 * assessment, not a defense against a determined attacker - the real
	 * mitigation is that no response here, rate-limited or not, ever
	 * discloses whether a given code is real.
	 *
	 * @return bool
	 */
	private static function is_rate_limited(): bool {
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'be_verify_rl_' . sha1( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}
}
