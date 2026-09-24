<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes a catalog item's free-text `description` field.
 */
class Rich_Text_Sanitizer {

	/**
	 * The only three keys `description` may carry.
	 */
	private const SECTIONS = [ 'reference', 'description', 'source' ];

	/**
	 * `wp_kses()` unwraps a disallowed tag but keeps its inner text.
	 */
	private const ALLOWED_TAGS = [
		'p'          => [],
		'br'         => [],
		'strong'     => [],
		'b'          => [],
		'em'         => [],
		'i'          => [],
		'u'          => [],
		's'          => [],
		'strike'     => [],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'h1'         => [],
		'h2'         => [],
		'h3'         => [],
		'h4'         => [],
		'h5'         => [],
		'h6'         => [],
		'blockquote' => [],
		'a'          => [ 'href' => true, 'title' => true ],
		'table'      => [],
		'thead'      => [],
		'tbody'      => [],
		'tfoot'      => [],
		'tr'         => [],
		'th'         => [ 'colspan' => true, 'rowspan' => true ],
		'td'         => [ 'colspan' => true, 'rowspan' => true ],
		'caption'    => [],
	];

	/**
	 * Sanitizes one HTML string against the allowlist above.
	 */
	public static function sanitize( string $html ): string {
		$html = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );
		return wp_kses( $html, self::ALLOWED_TAGS );
	}

	/**
	 * Sanitizes a `description` value's three fixed sections (`reference`, `description`, `source`), dropping any other
	 * key and omitting a section that sanitizes down to nothing.
	 *
	 * @param mixed $value A JSON-decoded object/array, or anything else (ignored).
	 * @return array<string,string>
	 */
	public static function sanitize_sections( $value ): array {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( self::SECTIONS as $key ) {
			if ( empty( $value[ $key ] ) ) {
				continue;
			}
			$sanitized = self::sanitize( (string) $value[ $key ] );
			if ( $sanitized !== '' ) {
				$out[ $key ] = $sanitized;
			}
		}
		return $out;
	}

	/**
	 * Sanitizes every `description` field inside a schema block definition, shaped per section_type: a trait_list's
	 * `items[].description`, or a tiered_power's `powers[].description` (the family/"top") and
	 * `powers[].levels[].description` (each individual level).
	 *
	 * @param array<string,mixed> $definition
	 * @return array<string,mixed>
	 */
	public static function sanitize_definition( array $definition, string $section_type ): array {
		if ( $section_type === 'trait_list' && isset( $definition['items'] ) && is_array( $definition['items'] ) ) {
			foreach ( $definition['items'] as &$item ) {
				if ( is_array( $item ) && isset( $item['description'] ) ) {
					$sections = self::sanitize_sections( $item['description'] );
					if ( $sections === [] ) {
						unset( $item['description'] );
					} else {
						$item['description'] = $sections;
					}
				}
			}
			unset( $item );
		}

		if ( $section_type === 'tiered_power' && isset( $definition['powers'] ) && is_array( $definition['powers'] ) ) {
			foreach ( $definition['powers'] as &$power ) {
				if ( ! is_array( $power ) ) {
					continue;
				}
				if ( isset( $power['description'] ) ) {
					$sections = self::sanitize_sections( $power['description'] );
					if ( $sections === [] ) {
						unset( $power['description'] );
					} else {
						$power['description'] = $sections;
					}
				}
				if ( isset( $power['levels'] ) && is_array( $power['levels'] ) ) {
					foreach ( $power['levels'] as &$level ) {
						if ( is_array( $level ) && isset( $level['description'] ) ) {
							$sections = self::sanitize_sections( $level['description'] );
							if ( $sections === [] ) {
								unset( $level['description'] );
							} else {
								$level['description'] = $sections;
							}
						}
					}
					unset( $level );
				}
			}
			unset( $power );
		}

		return $definition;
	}
}
