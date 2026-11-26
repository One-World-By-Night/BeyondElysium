/**
 * The book a shipped catalog entry comes from, read out of the `source` it carries. A source that names only how the
 * entry was built, a creature type or a family is not a book and gives nothing. The server's `Catalog_Citation` reads
 * the same way, and one fixture holds both to it.
 */

const BOOK_WORDS =
	/\b(?:Packet|Guide|Laws of|Clanbook|Lore of|Book of|Faith and Fire|Dirty Secrets|Core|Genre|Companion|Sourcebook|Players Guide|Revised)\b/i;

const BUILD_PART = /GVM|\.csv|samples\/|\.pdf|resolved\.json/i;

const CSV_BOOK = /^met-mechanics\.csv\s*:\s*(.+)$/i;

/**
 * The book a source names, or null when it names none.
 */
export function bookOf( source: unknown ): string | null {
	if ( typeof source !== 'string' || source.trim() === '' ) {
		return null;
	}

	for ( const raw of source.split( ';' ) ) {
		const part = raw.trim();
		const csv = CSV_BOOK.exec( part );
		if ( csv ) {
			const title = csv[ 1 ].trim();
			if ( title !== '' ) {
				return title;
			}
			continue;
		}
		if ( part === '' || BUILD_PART.test( part ) ) {
			continue;
		}
		if ( BOOK_WORDS.test( part ) ) {
			return part;
		}
	}

	return null;
}
