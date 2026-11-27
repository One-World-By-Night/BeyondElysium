/**
 * What a Storyteller's home says is waiting, one line each, where each line goes, and what it says of the next game
 * night and of who has had no attention.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { STORYTELLER_TABS, storytellerTabUrl } from './pluginPages';
import { formatStoredDay } from './storedTime';
import type { StorytellerHomeData, StorytellerNight } from '../types/home';

export interface WaitingLine {
	key: string;
	text: string;
	href: string;
}

function tabUrl( tab: string, gameSlug: string ): string {
	return `${ storytellerTabUrl( tab ) }&game_slug=${ encodeURIComponent(
		gameSlug
	) }`;
}

/**
 * The lines under "Waiting on you", each left out when nothing is waiting there.
 */
export function waitingLines(
	data: StorytellerHomeData,
	gameSlug: string
): WaitingLine[] {
	const lines: WaitingLine[] = [];
	const { approvals, downtime, plots, players, castings } = data.waiting;

	if (
		approvals &&
		approvals.changes + approvals.files + approvals.new_characters > 0
	) {
		const parts: string[] = [];
		if ( approvals.changes > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many changes wait in the Approval Queue */
					__( '%d waiting', 'beyond-elysium' ),
					approvals.changes
				)
			);
		}
		if ( approvals.xp_requests > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many requests for XP wait */
					_n(
						'%d XP request',
						'%d XP requests',
						approvals.xp_requests,
						'beyond-elysium'
					),
					approvals.xp_requests
				)
			);
		}
		if ( approvals.files > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many Grapevine files wait for review */
					_n(
						'%d Grapevine file',
						'%d Grapevine files',
						approvals.files,
						'beyond-elysium'
					),
					approvals.files
				)
			);
		}
		if ( approvals.new_characters > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many new characters wait for a Storyteller */
					_n(
						'%d new character',
						'%d new characters',
						approvals.new_characters,
						'beyond-elysium'
					),
					approvals.new_characters
				)
			);
		}
		if ( ( approvals.with_coordinators ?? 0 ) > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many waiting changes a Storyteller sent to a Coordinator */
					_n(
						'%d with a Coordinator',
						'%d with Coordinators',
						approvals.with_coordinators ?? 0,
						'beyond-elysium'
					),
					approvals.with_coordinators ?? 0
				)
			);
		}
		lines.push( {
			key: 'approvals',
			text: sprintf(
				/* translators: %s: the counts waiting in the Approval Queue, joined */
				__( 'Approval Queue: %s', 'beyond-elysium' ),
				parts.join( ' · ' )
			),
			href: tabUrl( STORYTELLER_TABS.approvalQueue, gameSlug ),
		} );
	}

	if ( downtime && downtime.mine + downtime.unassigned > 0 ) {
		const parts: string[] = [];
		if ( downtime.mine > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many downtime answers wait on the person */
					__( '%d to answer', 'beyond-elysium' ),
					downtime.mine
				)
			);
		}
		if ( downtime.unassigned > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many downtime answers nobody has taken */
					__( '%d nobody has taken', 'beyond-elysium' ),
					downtime.unassigned
				)
			);
		}
		lines.push( {
			key: 'downtime',
			text: sprintf(
				/* translators: %s: the downtime counts, joined */
				__( 'Downtime: %s', 'beyond-elysium' ),
				parts.join( ' · ' )
			),
			href: tabUrl(
				downtime.mine > 0
					? STORYTELLER_TABS.myQueue
					: STORYTELLER_TABS.downtime,
				gameSlug
			),
		} );
	}

	if ( plots && plots.mine + plots.unassigned > 0 ) {
		const parts: string[] = [];
		if ( plots.mine > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many plot posts wait on the person */
					__( '%d waiting on you', 'beyond-elysium' ),
					plots.mine
				)
			);
		}
		if ( plots.unassigned > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many plot posts nobody has taken */
					__( '%d nobody has taken', 'beyond-elysium' ),
					plots.unassigned
				)
			);
		}
		lines.push( {
			key: 'plots',
			text: sprintf(
				/* translators: %s: the plot post counts, joined */
				__( 'Plot posts: %s', 'beyond-elysium' ),
				parts.join( ' · ' )
			),
			href: tabUrl(
				plots.mine > 0
					? STORYTELLER_TABS.myQueue
					: STORYTELLER_TABS.plots,
				gameSlug
			),
		} );
	}

	if ( players && players.join_requests + players.links > 0 ) {
		const parts: string[] = [];
		if ( players.join_requests > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many people ask to join */
					_n(
						'%d asks to join',
						'%d ask to join',
						players.join_requests,
						'beyond-elysium'
					),
					players.join_requests
				)
			);
		}
		if ( players.links > 0 ) {
			parts.push(
				sprintf(
					/* translators: %d: how many Discord links wait for approval */
					_n(
						'%d Discord link to approve',
						'%d Discord links to approve',
						players.links,
						'beyond-elysium'
					),
					players.links
				)
			);
		}
		lines.push( {
			key: 'players',
			text: sprintf(
				/* translators: %s: the join request and Discord link counts, joined */
				__( 'Players: %s', 'beyond-elysium' ),
				parts.join( ' · ' )
			),
			href: tabUrl( STORYTELLER_TABS.players, gameSlug ),
		} );
	}

	if ( castings.length > 0 ) {
		lines.push( {
			key: 'castings',
			text: sprintf(
				/* translators: %s: the NPCs the person is cast as, each with its game night */
				__( 'Your castings: %s', 'beyond-elysium' ),
				castings
					.map( ( casting ) =>
						sprintf(
							/* translators: 1: an NPC's name, 2: the game night's date */
							__( '%1$s on %2$s', 'beyond-elysium' ),
							casting.character_name,
							formatStoredDay( casting.game_date )
						)
					)
					.join( '; ' )
			),
			href: tabUrl( STORYTELLER_TABS.myQueue, gameSlug ),
		} );
	}

	return lines;
}

/**
 * What the next game night's card says beyond its date: how far downtime has come and how far the last night's
 * reports have.
 */
export function nightLines( night: StorytellerNight ): string[] {
	const lines: string[] = [];

	if ( night.downtime ) {
		const progress = sprintf(
			/* translators: 1: how many characters have sent actions, 2: how many characters there are */
			_n(
				'%1$d of %2$d character has sent actions',
				'%1$d of %2$d characters have sent actions',
				night.downtime.total,
				'beyond-elysium'
			),
			night.downtime.sent,
			night.downtime.total
		);
		lines.push(
			night.downtime_deadline_at
				? sprintf(
						/* translators: 1: when the downtime window closes, 2: how far downtime has come */
						__( 'Downtime closes %1$s: %2$s', 'beyond-elysium' ),
						formatStoredDay( night.downtime_deadline_at, {
							time: true,
						} ),
						progress
					)
				: progress
		);
	}

	if ( night.reports ) {
		lines.push(
			sprintf(
				/* translators: 1: the game night's date, 2: how many have written a report, 3: how many came */
				__(
					'Reports for %1$s: %2$d of %3$d have written theirs',
					'beyond-elysium'
				),
				formatStoredDay( night.reports.game_date ),
				night.reports.written,
				night.reports.attended
			)
		);
	}

	return lines;
}

/**
 * The Spotlight line: how many characters have had no staff post, and for how long.
 */
export function spotlightLine( flagged: number, days: number ): string {
	return sprintf(
		/* translators: 1: how many characters have had no staff post, 2: how many days */
		_n(
			'%1$d character has had no staff post in %2$d days',
			'%1$d characters have had no staff post in %2$d days',
			flagged,
			'beyond-elysium'
		),
		flagged,
		days
	);
}
