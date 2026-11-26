/**
 * How a stored date or date and time reads on screen.
 */

/**
 * The site's language as a browser locale ("pt_BR" reads "pt-BR"), or undefined when the page does not say.
 */
export function siteLocale(): string | undefined {
	const locale = window.beyondElysium?.locale;
	return locale ? locale.replace( '_', '-' ) : undefined;
}

export interface StoredTimeOptions {
	/**
	 * Show the time as well as the date; a value stored without a time shows the date alone.
	 */
	time?: boolean;
	locale?: string;
}

const STORED = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/;

/**
 * A stored `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS` in the site's language. The stored parts are written as they are,
 * with no change of time zone. Text that is not a stored date or time comes back unchanged.
 */
export function formatStoredTime(
	value: string,
	{ time = false, locale }: StoredTimeOptions = {}
): string {
	const parts = STORED.exec( value );
	if ( ! parts ) {
		return value;
	}
	const [ year, month, day ] = [ parts[ 1 ], parts[ 2 ], parts[ 3 ] ].map(
		Number
	);
	const hour = parts[ 4 ] === undefined ? 0 : Number( parts[ 4 ] );
	const minute = parts[ 5 ] === undefined ? 0 : Number( parts[ 5 ] );
	const when = new Date( Date.UTC( year, month - 1, day, hour, minute ) );
	if (
		when.getUTCFullYear() !== year ||
		when.getUTCMonth() !== month - 1 ||
		when.getUTCDate() !== day ||
		when.getUTCHours() !== hour ||
		when.getUTCMinutes() !== minute
	) {
		return value;
	}
	const showTime = time && parts[ 4 ] !== undefined;
	return new Intl.DateTimeFormat( locale ?? siteLocale(), {
		dateStyle: 'medium',
		...( showTime ? { timeStyle: 'short' } : {} ),
		timeZone: 'UTC',
	} ).format( when );
}

/**
 * A stored date as a short weekday and day ("Sat 28 Nov"), with its time when asked and stored ("Fri 27 Nov, 23:59").
 * Text that is not a stored date comes back unchanged.
 */
export function formatStoredDay(
	value: string,
	{ time = false, locale }: StoredTimeOptions = {}
): string {
	const parts = STORED.exec( value );
	if ( ! parts ) {
		return value;
	}
	const [ year, month, day ] = [ parts[ 1 ], parts[ 2 ], parts[ 3 ] ].map(
		Number
	);
	const hour = parts[ 4 ] === undefined ? 0 : Number( parts[ 4 ] );
	const minute = parts[ 5 ] === undefined ? 0 : Number( parts[ 5 ] );
	const when = new Date( Date.UTC( year, month - 1, day, hour, minute ) );
	if ( when.getUTCMonth() !== month - 1 || when.getUTCDate() !== day ) {
		return value;
	}
	const showTime = time && parts[ 4 ] !== undefined;
	return new Intl.DateTimeFormat( locale ?? siteLocale(), {
		weekday: 'short',
		day: 'numeric',
		month: 'short',
		...( showTime
			? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }
			: {} ),
		timeZone: 'UTC',
	} ).format( when );
}
