<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The one name-normalization rule this codebase compares catalog and held-item names by: trim, drop a leading
 * "a"/"an"/"the ", lowercase.
 */
class Name_Key {

	/**
	 * Zero-width/formatting code points MySQL's utf8mb4_unicode_520_ci collation gives zero weight.
	 */
	private const INVISIBLE_CHARS = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}\x{2060}]/u';

	/**
	 * @param string $name
	 * @return string
	 */
	public static function for( string $name ): string {
		$name = (string) preg_replace( self::INVISIBLE_CHARS, '', $name );
		return strtolower( (string) preg_replace( '/^(a|an|the)\s+/i', '', trim( $name ) ) );
	}
}
