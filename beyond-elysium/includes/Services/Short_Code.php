<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * The token and human-typeable short code both attestation tables use (1.1.0 §3.13) - shared
 * so a character attestation and an item attestation can never collide on the same short
 * code, since a single `GET /be/v1/verify/{code}` route looks both tables up by it.
 *
 * Extracted from `Models\Attestation`'s own original generation methods (GX-7) - `Attestation`
 * itself now calls this rather than generating inline, with no change to its own token/short
 * code shape or alphabet.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.13
 */
class Short_Code {

	/** Human-typeable alphabet for a short code: no 0/O, 1/I/L - nothing a person could misread aloud or by hand. */
	private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

	/**
	 * @return string 256-bit random token, base64url-encoded (43 characters, no padding).
	 */
	public static function generate_token(): string {
		$bytes = random_bytes( 32 );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * @return string A human-typeable code, e.g. "K3F7-QM2P", drawn from an alphabet with
	 *                every visually-ambiguous character removed.
	 */
	public static function generate(): string {
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$chars    = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$chars .= $alphabet[ random_int( 0, $max ) ];
		}
		return substr( $chars, 0, 4 ) . '-' . substr( $chars, 4, 4 );
	}

	/**
	 * Regenerates on the vanishingly unlikely event of a collision against any of the given
	 * tables' own UNIQUE KEY on `short_code`, rather than trusting randomness alone. Checking
	 * every table named, not just the caller's own, is what keeps a character code and an
	 * item code from ever colliding with each other.
	 *
	 * @param string[] $tables Unprefixed table names (as `Manager::table()` expects), e.g.
	 *                         `['character_attestations', 'item_attestations']`.
	 * @return string
	 */
	public static function generate_unique( array $tables ): string {
		do {
			$code   = self::generate();
			$exists = false;
			foreach ( $tables as $table ) {
				if ( Manager::get_row( 'SELECT id FROM ' . Manager::table( $table ) . ' WHERE short_code = %s', $code ) !== null ) {
					$exists = true;
					break;
				}
			}
		} while ( $exists );
		return $code;
	}
}
