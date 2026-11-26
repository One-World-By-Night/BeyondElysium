/**
 * How long ago a stored time was, in whole days, so a clock that is an hour out never changes the day.
 */
import { __, sprintf } from '@wordpress/i18n';
import { formatStoredTime } from './storedTime';

const DATE = /^(\d{4})-(\d{2})-(\d{2})/;

function dayNumber( value: string ): number | null {
	const parts = DATE.exec( value );
	if ( ! parts ) {
		return null;
	}
	return Math.floor(
		Date.UTC(
			Number( parts[ 1 ] ),
			Number( parts[ 2 ] ) - 1,
			Number( parts[ 3 ] )
		) / 86400000
	);
}

/**
 * Whole days from a stored date to today (both `YYYY-MM-DD` at the front); null when either is not a date.
 */
export function daysBefore( stored: string, today: string ): number | null {
	const then = dayNumber( stored );
	const now = dayNumber( today );
	return then === null || now === null ? null : now - then;
}

/**
 * "today", "yesterday", "3 days ago", and from a week on the date itself. A date ahead of today reads as the date.
 */
export function relativeDay( stored: string, today: string ): string {
	const days = daysBefore( stored, today );
	if ( days === null || days < 0 || days >= 7 ) {
		return formatStoredTime( stored );
	}
	if ( days === 0 ) {
		return __( 'today', 'beyond-elysium' );
	}
	if ( days === 1 ) {
		return __( 'yesterday', 'beyond-elysium' );
	}
	return sprintf(
		/* translators: %d: how many days ago, from 2 to 6 */
		__( '%d days ago', 'beyond-elysium' ),
		days
	);
}
