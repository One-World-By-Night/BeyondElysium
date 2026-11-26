<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The book a shipped catalog entry comes from, read out of the `source` it carries. A source that names only how the
 * entry was built, a creature type or a family is not a book and gives nothing.
 */
class Catalog_Citation {

	/**
	 * Words that make a standalone source a book or packet title.
	 */
	private const BOOK_WORDS = '/\b(?:Packet|Guide|Laws of|Clanbook|Lore of|Book of|Faith and Fire|Dirty Secrets|Core|Genre|Companion|Sourcebook|Players Guide|Revised)\b/i';

	/**
	 * Marks a part that only says how the entry was built.
	 */
	private const BUILD_PART = '/GVM|\.csv|samples\/|\.pdf|resolved\.json/i';

	/**
	 * The book a source names, or null when it names none.
	 *
	 * @param mixed $source A shipped entry's `source`.
	 */
	public static function book_of( $source ): ?string {
		if ( ! is_string( $source ) || trim( $source ) === '' ) {
			return null;
		}

		foreach ( explode( ';', $source ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^met-mechanics\.csv\s*:\s*(.+)$/i', $part, $match ) === 1 ) {
				$title = trim( $match[1] );
				if ( $title !== '' ) {
					return $title;
				}
				continue;
			}
			if ( $part === '' || preg_match( self::BUILD_PART, $part ) === 1 ) {
				continue;
			}
			if ( preg_match( self::BOOK_WORDS, $part ) === 1 ) {
				return $part;
			}
		}

		return null;
	}
}
