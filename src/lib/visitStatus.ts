/**
 * Display text for a character's own open visits - "Also active at" for the home side, "Visiting from" for the
 * host side - naming whether each one is kept current, when it last delivered, and whether it's gone quiet.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { Visit, VisitingFrom } from '../types/transfer';

const NO_HOST = () => __( 'no host confirmed yet', 'beyond-elysium' );

/**
 * One outbound visit's own status, for the home side's "Also active at" list.
 */
export function visitSummary( visit: Visit ): string {
	const chronicle = visit.host_chronicle ?? NO_HOST();
	if ( visit.unreachable_since ) {
		return sprintf(
			/* translators: %s: the host chronicle's name */
			__( "%s (can't reach it)", 'beyond-elysium' ),
			chronicle
		);
	}
	if ( visit.keep_current && visit.delivered_at ) {
		return sprintf(
			/* translators: 1: the host chronicle's name, 2: when it last delivered an update */
			__( '%1$s (kept current, delivered %2$s)', 'beyond-elysium' ),
			chronicle,
			visit.delivered_at
		);
	}
	if ( visit.keep_current ) {
		return sprintf(
			/* translators: %s: the host chronicle's name */
			__( '%s (kept current)', 'beyond-elysium' ),
			chronicle
		);
	}
	return chronicle;
}

/**
 * The "Also active at ..." text: every other chronicle this character has an open visit at right now.
 */
export function alsoActiveAt( visits: Visit[] ): string {
	return sprintf(
		/* translators: %s: a comma-separated list of the other chronicles this character is also active at */
		__( 'Also active at %s', 'beyond-elysium' ),
		visits.map( visitSummary ).join( ', ' )
	);
}

/**
 * The roster's own short badge label for a character with at least one other open visit - calls out a visit that's
 * gone quiet, since that's the one state worth a glance without opening the tooltip.
 */
export function travellingBadgeLabel( visits: Visit[] ): string {
	return visits.some( ( visit ) => visit.unreachable_since )
		? __( "Can't reach a visit", 'beyond-elysium' )
		: __( 'Also active elsewhere', 'beyond-elysium' );
}

/**
 * The host side's own "Visiting from ..." text, naming whether the visit is kept current and when it last
 * delivered, or that it's gone quiet.
 */
export function visitingFromSummary( visitingFrom: VisitingFrom ): string {
	const chronicle = visitingFrom.home_chronicle ?? NO_HOST();

	if ( visitingFrom.unreachable_since ) {
		return sprintf(
			/* translators: 1: the home chronicle's name, 2: the date the visit started */
			__(
				"Visiting from %1$s since %2$s. Can't reach %1$s.",
				'beyond-elysium'
			),
			chronicle,
			visitingFrom.since
		);
	}
	if ( visitingFrom.keep_current && visitingFrom.delivered_at ) {
		return sprintf(
			/* translators: 1: the home chronicle's name, 2: when it last delivered an update */
			__( 'Kept current from %1$s, updated %2$s.', 'beyond-elysium' ),
			chronicle,
			visitingFrom.delivered_at
		);
	}
	if ( visitingFrom.keep_current ) {
		return sprintf(
			/* translators: %s: the home chronicle's name */
			__( 'Kept current from %s.', 'beyond-elysium' ),
			chronicle
		);
	}
	return sprintf(
		/* translators: 1: the home chronicle's name, 2: the date the visit started */
		__( 'Visiting from %1$s since %2$s.', 'beyond-elysium' ),
		chronicle,
		visitingFrom.since
	);
}
