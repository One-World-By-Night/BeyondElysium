<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves `CrossBlockRef` lookups against a character's resolved sheet data: reading a value from another
 * block/field pair, building a section's title from its configured title refs, and resolving a resource pool's
 * display name from a keyed lookup table.
 */
class Cross_Block_Ref {

	/**
	 * Reads the value at `$ref->block_slug`/`$ref->field` from a character's sheet data and returns it as a string.
	 *
	 * @param object $ref        Has `block_slug` and `field` string properties.
	 * @param array  $sheet_data Character sheet data (Character::decode_row() shape),
	 *                           keyed by block slug.
	 * @return string|null The resolved value as a string, or null if the block/field
	 *                      isn't present or hasn't been set yet (e.g. no Morality Path
	 *                      chosen).
	 */
	public static function resolve_cross_block_value( object $ref, array $sheet_data ): ?string {
		$block_data = $sheet_data[ $ref->block_slug ] ?? null;
		if ( ! is_array( $block_data ) || array_is_list( $block_data ) ) {
			return null;
		}

		$value = $block_data[ $ref->field ] ?? null;
		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( is_array( $value ) && array_key_exists( 'permanent', $value ) ) {
			return (string) $value['permanent'];
		}

		return (string) $value;
	}

	/**
	 * Builds a section's displayed title.
	 *
	 * @param object $section    Has a `title` string and an optional `title_refs` array
	 *                           of ref objects (each with `block_slug`/`field`).
	 * @param array  $sheet_data Character sheet data, keyed by block slug.
	 * @return string
	 */
	public static function resolve_section_title( object $section, array $sheet_data ): string {
		$title      = (string) $section->title;
		$title_refs = $section->title_refs ?? [];

		if ( empty( $title_refs ) ) {
			return $title;
		}

		$parts = [ $title ];
		foreach ( $title_refs as $ref ) {
			$value = self::resolve_cross_block_value( $ref, $sheet_data );
			if ( $value === null ) {
				return $title;
			}
			$parts[] = $value;
		}

		return implode( ' ', $parts );
	}

	/**
	 * Resolves a resource pool's displayed name: returns `$pool->name` unless `name_lookup` maps the current value of its
	 * `keyed_by` reference to an entry in `table`.
	 *
	 * @param object $pool       Has a `name` string and an optional `name_lookup`
	 *                           object (a `keyed_by` ref plus a string=>string `table`).
	 * @param array  $sheet_data Character sheet data, keyed by block slug.
	 * @return string
	 */
	public static function resolve_pool_name( object $pool, array $sheet_data ): string {
		$name = (string) $pool->name;

		if ( empty( $pool->name_lookup ) ) {
			return $name;
		}

		$key = self::resolve_cross_block_value( $pool->name_lookup->keyed_by, $sheet_data );
		if ( $key === null ) {
			return $name;
		}

		$table = (array) $pool->name_lookup->table;
		return (string) ( $table[ $key ] ?? $name );
	}
}
