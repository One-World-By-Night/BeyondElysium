<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes a catalog item's free-text `description` field - a site-wide
 * (never per-chronicle by default) note a schema-block admin can attach to a
 * trait_list item, a tiered_power level, or a tiered_power family itself, for
 * a house rule, a page/document reference, or similar. Deliberately stricter
 * than `wp_kses_post()` (which every other rich-text field in this plugin
 * uses): formatting, lists, and tables survive; images and anything that
 * isn't plain content formatting do not. This is a catalog *definition*
 * field, never held character data, so nothing in the Grapevine import/export
 * pipeline reads or writes it - sanitizing it here is the only gate it ever
 * passes through.
 *
 * `description` is a small object with three fixed, independently rich-text
 * sections - `reference`, `description`, `source` - not one HTML blob, so an
 * admin can divide a house rule from a citation from a general note rather
 * than running them together in one field. (The inner `source` key is a
 * separate concept from a catalog item's own top-level `source` citation
 * string - the former is admin-written free text inside this structured
 * field, the latter is the plain GVM/CSV-sourced citation every item already
 * carries.)
 *
 * Modeled directly on `Pdf_Writer::PROSE_ALLOWED_TAGS`/`sanitize_prose()`,
 * extended with table elements per this field's own requirement.
 */
class Rich_Text_Sanitizer {

	/** The only three keys `description` may carry. Any other key is dropped, never trusted. */
	private const SECTIONS = [ 'reference', 'description', 'source' ];

	/**
	 * `wp_kses()` unwraps a disallowed tag but keeps its inner text, so a
	 * `<script>`/`<style>` tag's own content is stripped first - the same fix
	 * `Pdf_Writer::sanitize_prose()` already carries, for the identical reason.
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
	 * Sanitizes one HTML string against the allowlist above. No `<img>`, no
	 * script/style/iframe/object/embed/form - formatting, lists, tables, and
	 * plain links only.
	 */
	public static function sanitize( string $html ): string {
		$html = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );
		return wp_kses( $html, self::ALLOWED_TAGS );
	}

	/**
	 * Sanitizes a `description` value's three fixed sections
	 * (`reference`/`description`/`source`), dropping any other key present
	 * and omitting a section entirely once it sanitizes down to nothing
	 * (rather than storing an empty string) - matching every other optional
	 * catalog-item field's own `!empty()` convention.
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
	 * Sanitizes every `description` field inside a schema block definition,
	 * shaped per section_type: a trait_list's `items[].description`, or a
	 * tiered_power's `powers[].description` (the family/"top") and
	 * `powers[].levels[].description` (each individual level). Every other
	 * section_type, and every other field, passes through untouched - this
	 * method's only job is narrowing `description` values, never validating
	 * or reshaping the rest of the definition.
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
