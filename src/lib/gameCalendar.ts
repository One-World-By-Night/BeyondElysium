/**
 * How a game night reads in the Game Calendar.
 */

export interface CalendarRow {
	date: string;
	time?: string | null;
	place?: string | null;
	notes?: string | null;
}

/**
 * A game date in YYYY-MM-DD form as the viewer's locale writes it, or the text unchanged when it is not a date.
 */
export function formatGameDate( date: string, locale?: string ): string {
	const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec( date );
	if ( ! parts ) {
		return date;
	}
	const when = new Date(
		Date.UTC(
			Number( parts[ 1 ] ),
			Number( parts[ 2 ] ) - 1,
			Number( parts[ 3 ] )
		)
	);
	if ( Number.isNaN( when.getTime() ) ) {
		return date;
	}
	return new Intl.DateTimeFormat( locale, {
		weekday: 'long',
		year: 'numeric',
		month: 'long',
		day: 'numeric',
		timeZone: 'UTC',
	} ).format( when );
}

/**
 * The line a game night opens with: its date, then its start time when it has one.
 */
export function gameNightHeading( row: CalendarRow, locale?: string ): string {
	return [ formatGameDate( row.date, locale ), row.time ?? '' ]
		.filter( ( part ) => part.trim() !== '' )
		.join( ' · ' );
}
