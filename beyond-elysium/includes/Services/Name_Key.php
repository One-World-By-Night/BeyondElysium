<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The one name-normalization rule this codebase compares catalog and held-item names by:
 * trim, drop a leading "a"/"an"/"the ", lowercase. Originally `Seeder::met_name_comparison_key()`
 * (Decision 043's protected-base matching); moved here as a shared service (1.1.0 §3.15, C1) so
 * a report-time comparison (a held rote's name against a `rote` world object's name) uses the
 * exact same rule a seed-time catalog merge already does, rather than a second, independently
 * drifting implementation.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.15
 */
class Name_Key {

	/** Zero-width/formatting code points MySQL's utf8mb4_unicode_520_ci collation gives zero
	 * weight to - see for()'s own docblock for why these are stripped here rather than left
	 * for the database to (inconsistently, silently) decide are the same string. */
	private const INVISIBLE_CHARS = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}\x{2060}]/u';

	/**
	 * @param string $name
	 * @return string
	 */
	public static function for( string $name ): string {
		// A zero-width space/joiner, BOM, soft hyphen or word joiner is invisible and carries
		// zero weight under MySQL's utf8mb4_unicode_520_ci collation - two PHP-distinct
		// strings differing only by one of these collide into the SAME row at the database
		// layer while still looking like two different keys in PHP. Found live: real
		// `vampire-blood-magic` catalog data has both "tainted" and U+200B+"tainted", which
		// silently broke Catalog_Translator::rescan()'s own added/updated bookkeeping (1.2.0
		// releases/1.2.0-design-workflow.md, B3) - every distinct-string count this codebase
		// derives from Name_Key must agree with what the database already enforces, or a
		// PHP-side "distinct" count quietly overstates what MySQL actually stores.
		$name = (string) preg_replace( self::INVISIBLE_CHARS, '', $name );
		return strtolower( (string) preg_replace( '/^(a|an|the)\s+/i', '', trim( $name ) ) );
	}
}
