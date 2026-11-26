/**
 * What a player's home says is due or new, in the order it should be read, and where each line goes.
 */
import { __, sprintf } from '@wordpress/i18n';
import { CHARACTER_SECTION } from './characterViews';
import { noticeHref, noticeText } from './notices';
import { PLAYER_TABS, playerTabUrl } from './pluginPages';
import { formatStoredDay } from './storedTime';
import type { HomeSession, PlayerHomeData } from '../types/home';

export interface NeedItem {
	key: string;
	kind: 'downtime' | 'report' | 'notice';
	title: string;
	lines: string[];
	href: string;
	/**
	 * The notice a line stands for, read when it is opened.
	 */
	noticeId?: number;
}

function tabUrl( tab: string, gameSlug: string, extra = '' ): string {
	return `${ playerTabUrl( tab ) }&game_slug=${ encodeURIComponent(
		gameSlug
	) }${ extra }`;
}

/**
 * Where a character's card goes: the Character section, on the view last used on this device.
 */
export function cardHref( characterId: number, gameSlug: string ): string {
	return tabUrl(
		CHARACTER_SECTION,
		gameSlug,
		`&character_id=${ characterId }`
	);
}

/**
 * Sorts by a stored time, soonest first, with those that have none last.
 */
function bySoonest< T >( rows: T[], when: ( row: T ) => string | null ): T[] {
	return [ ...rows ].sort( ( a, b ) => {
		const x = when( a );
		const y = when( b );
		if ( x === y ) {
			return 0;
		}
		if ( x === null ) {
			return 1;
		}
		if ( y === null ) {
			return -1;
		}
		return x < y ? -1 : 1;
	} );
}

/**
 * The lines under "Needs you": downtime that is open with nothing sent, soonest deadline first; reports still to
 * write, soonest due first; then the newest unread notices. A downtime reminder whose character and night already
 * have their own open-downtime line is left out, since that line says the same thing.
 */
export function needsYou( data: PlayerHomeData, gameSlug: string ): NeedItem[] {
	const items: NeedItem[] = [];

	for ( const row of bySoonest( data.due.downtime, ( d ) => d.deadline ) ) {
		const night = formatStoredDay( row.game_date );
		items.push( {
			key: `downtime-${ row.character_id }-${ row.game_date }`,
			kind: 'downtime',
			title: row.deadline
				? sprintf(
						/* translators: 1: the game night's date, 2: when the downtime window closes */
						__(
							'Downtime for %1$s is open until %2$s.',
							'beyond-elysium'
						),
						night,
						formatStoredDay( row.deadline, { time: true } )
					)
				: sprintf(
						/* translators: %s: the game night's date */
						__( 'Downtime for %s is open.', 'beyond-elysium' ),
						night
					),
			lines: [
				sprintf(
					/* translators: %s: a character's name */
					__( "%s hasn't sent any actions.", 'beyond-elysium' ),
					row.character_name
				),
			],
			href:
				row.plot_id !== null
					? tabUrl(
							PLAYER_TABS.plots,
							gameSlug,
							`&open_plot=${ row.plot_id }`
						)
					: tabUrl( PLAYER_TABS.plots, gameSlug ),
		} );
	}

	for ( const row of bySoonest( data.due.reports, ( r ) => r.due_at ) ) {
		items.push( {
			key: `report-${ row.character_id }-${ row.session_id }`,
			kind: 'report',
			title: sprintf(
				/* translators: 1: the game night's date, 2: when the report is due */
				__(
					'After-Game Report for %1$s is due %2$s.',
					'beyond-elysium'
				),
				formatStoredDay( row.game_date ),
				formatStoredDay( row.due_at )
			),
			lines: [
				sprintf(
					/* translators: %s: a character's name */
					__( "%s hasn't written one.", 'beyond-elysium' ),
					row.character_name
				),
			],
			href: tabUrl(
				PLAYER_TABS.afterGameReport,
				gameSlug,
				`&character_id=${ row.character_id }&session_id=${ row.session_id }`
			),
		} );
	}

	const stated = new Set(
		data.due.downtime.map( ( d ) => `${ d.character_id }-${ d.game_date }` )
	);
	for ( const notice of data.notices.items ) {
		if (
			notice.kind === 'downtime_due' &&
			stated.has(
				`${ notice.payload.character_id }-${ notice.payload.game_date }`
			)
		) {
			continue;
		}
		const words = noticeText( notice );
		items.push( {
			key: `notice-${ notice.id }`,
			kind: 'notice',
			title: words.title,
			lines: words.lines,
			href: noticeHref( notice, gameSlug ),
			noticeId: notice.id,
		} );
	}

	return items;
}

/**
 * What the next game night's card says beyond its date: when downtime closes and who the person is playing.
 */
export function nextSessionLines( session: HomeSession ): string[] {
	const lines: string[] = [];
	if ( session.downtime_deadline_at ) {
		lines.push(
			sprintf(
				/* translators: %s: when the downtime window closes */
				__( 'Downtime closes %s', 'beyond-elysium' ),
				formatStoredDay( session.downtime_deadline_at, { time: true } )
			)
		);
	}
	for ( const name of session.castings ) {
		lines.push(
			sprintf(
				/* translators: %s: the name of an NPC the person is cast as */
				__( "You're playing %s", 'beyond-elysium' ),
				name
			)
		);
	}
	return lines;
}
