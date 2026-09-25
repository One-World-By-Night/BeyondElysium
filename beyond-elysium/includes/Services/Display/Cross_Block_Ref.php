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
	 * Resolves a resource pool's displayed name: returns `$pool->name` unless its `name_lookup` names it.
	 *
	 * @param object $pool       Has a `name` string and an optional `name_lookup`
	 *                           object (a `keyed_by` ref, a string=>string `table`, optional `ignore_words`, an
	 *                           optional `unmatched` name and an optional `otherwise` lookup of the same shape).
	 * @param array  $sheet_data Character sheet data, keyed by block slug.
	 * @return string
	 */
	public static function resolve_pool_name( object $pool, array $sheet_data ): string {
		$name = (string) $pool->name;

		if ( empty( $pool->name_lookup ) || ! is_object( $pool->name_lookup ) ) {
			return $name;
		}

		return self::resolve_name_lookup( $pool->name_lookup, $sheet_data ) ?? $name;
	}

	/**
	 * The name a lookup gives for a character: its table's entry for the current value of `keyed_by`, else its
	 * `unmatched` name when that value is set but not in the table, else whatever its `otherwise` lookup gives, else null.
	 *
	 * @param object $lookup
	 * @param array  $sheet_data Character sheet data, keyed by block slug.
	 */
	public static function resolve_name_lookup( object $lookup, array $sheet_data ): ?string {
		$key = is_object( $lookup->keyed_by ?? null ) ? self::resolve_cross_block_value( $lookup->keyed_by, $sheet_data ) : null;

		if ( $key !== null ) {
			$table = (array) ( $lookup->table ?? [] );
			$found = isset( $lookup->ignore_words )
				? self::loose_table_value( $table, $key, (array) $lookup->ignore_words )
				: ( $table[ $key ] ?? null );
			if ( $found !== null ) {
				return (string) $found;
			}
			if ( isset( $lookup->unmatched ) ) {
				return (string) $lookup->unmatched;
			}
		}

		$otherwise = $lookup->otherwise ?? null;
		return is_object( $otherwise ) ? self::resolve_name_lookup( $otherwise, $sheet_data ) : null;
	}

	/**
	 * A name reduced for loose comparison: lower case, a trailing parenthetical dropped, anything but letters and digits
	 * read as a space, and the ignored words removed.
	 *
	 * @param array<int,string> $ignore_words
	 */
	public static function loose_name( string $name, array $ignore_words ): string {
		$ignore = array_map( 'strtolower', array_map( 'strval', $ignore_words ) );
		$name   = (string) preg_replace( '/\s*\([^()]*\)\s*$/', '', strtolower( $name ) );
		$words  = preg_split( '/[^a-z0-9]+/', $name ) ?: [];

		return implode( ' ', array_filter( $words, static fn( string $word ): bool => $word !== '' && ! in_array( $word, $ignore, true ) ) );
	}

	/**
	 * The table entry whose key reads the same as `$key` once both are reduced by `loose_name()`.
	 *
	 * @param array<string,mixed> $table
	 * @param array<int,string>   $ignore_words
	 */
	private static function loose_table_value( array $table, string $key, array $ignore_words ): ?string {
		$wanted = self::loose_name( $key, $ignore_words );
		if ( $wanted === '' ) {
			return null;
		}
		foreach ( $table as $entry => $value ) {
			if ( self::loose_name( (string) $entry, $ignore_words ) === $wanted ) {
				return (string) $value;
			}
		}
		return null;
	}
}
