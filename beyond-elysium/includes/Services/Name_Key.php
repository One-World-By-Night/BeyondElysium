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

	/**
	 * @param string $name
	 * @return string
	 */
	public static function for( string $name ): string {
		return strtolower( (string) preg_replace( '/^(a|an|the)\s+/i', '', trim( $name ) ) );
	}
}
