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
 * The plugin's first unauthenticated REST route: answers "is this character document still current" for anyone
 * holding a short verification code, with no login required.
 */
class Verify_Controller extends Base_Controller {

	protected $rest_base = 'verify';

	/**
	 * Requests allowed per IP per rolling minute.
	 */
	private const RATE_LIMIT = 30;

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/verify/(?P<code>[A-Za-z0-9-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'verify' ],
				// Deliberate: this route is public by design.
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
	 * The item-shaped verify response.
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
	 * Compares the attestation's stored snapshot against a live read of the character.
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
			$current_hash = null;
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
	 * A bare 404 with no distinguishing detail.
	 *
	 * @return \WP_Error
	 */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'not_found', '', [ 'status' => 404 ] );
	}

	/**
	 * A fixed-window counter: 30 requests per rolling minute per IP, transient-backed.
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
