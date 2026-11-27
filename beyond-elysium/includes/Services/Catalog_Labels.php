<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The site-language word for a name the shipped catalog gives a section, a creation rule, a pool or a template title.
 * Applied to display copies only; a slug, a pool key or a rate key is never run through it.
 */
class Catalog_Labels {

	/**
	 * @var array<string,string>|null
	 */
	private static ?array $words = null;

	/**
	 * The word in the site's language; the English back when the strings file does not hold it.
	 */
	public static function word( string $english ): string {
		if ( self::$words === null ) {
			self::$words = include BE_PLUGIN_DIR . 'includes/Database/catalog-label-strings.php';
		}
		return self::$words[ $english ] ?? $english;
	}

	/**
	 * A sheet layout with each section's title in the site's language.
	 *
	 * @param array<string,mixed> $layout
	 * @return array<string,mixed>
	 */
	public static function layout( array $layout ): array {
		if ( ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) ) {
			return $layout;
		}
		foreach ( $layout['sections'] as $i => $section ) {
			if ( is_array( $section ) && isset( $section['title'] ) && is_string( $section['title'] ) ) {
				$layout['sections'][ $i ]['title'] = self::word( $section['title'] );
			}
		}
		return $layout;
	}

	/**
	 * A copy of a creature type with each section label, creation-rule label and quota label in the site's language.
	 * Slugs, pool keys and rate keys are left as stored.
	 */
	public static function stack( object $stack ): object {
		$copy = unserialize( serialize( $stack ) );
		if ( ! is_object( $copy ) ) {
			return $stack;
		}
		foreach ( (array) ( $copy->stack_definition->sections ?? [] ) as $section ) {
			if ( is_object( $section ) && is_string( $section->label ?? null ) ) {
				$section->label = self::word( $section->label );
			}
		}
		foreach ( (array) ( $copy->creation_rules->steps ?? [] ) as $step ) {
			if ( ! is_object( $step ) ) {
				continue;
			}
			if ( is_string( $step->label ?? null ) ) {
				$step->label = self::word( $step->label );
			}
			foreach ( (array) ( $step->quotas ?? [] ) as $quota ) {
				if ( is_object( $quota ) && is_string( $quota->label ?? null ) ) {
					$quota->label = self::word( $quota->label );
				}
			}
		}
		return $copy;
	}

	/**
	 * Forgets the loaded words, so a changed locale is read again.
	 */
	public static function reset(): void {
		self::$words = null;
	}
}
