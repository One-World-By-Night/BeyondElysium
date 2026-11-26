/**
 * What a notice in the header says and where it points.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatStoredDay, formatStoredTime } from './storedTime';
import {
	PLAYER_TABS,
	STORYTELLER_TABS,
	playerTabUrl,
	storytellerTabUrl,
} from './pluginPages';

export interface Notice {
	id: number;
	game_id: number;
	kind: string;
	payload: Record< string, unknown >;
	created_at: string;
	read_at: string | null;
}

/**
 * What a Storyteller may choose about hearing of changes waiting, with what applies when they have chosen nothing.
 */
export interface NoticeSettings {
	waiting_notify: '' | 'each' | 'daily' | 'never';
	effective: 'each' | 'daily' | 'never';
	staff_address: boolean;
}

export interface NoticeList {
	unread: number;
	items: Notice[];
	/**
	 * Null for anyone who is not an HST or AST of the chronicle.
	 */
	settings: NoticeSettings | null;
}

export interface NoticeText {
	/**
	 * The one line a notice is known by.
	 */
	title: string;
	/**
	 * What it carries beyond that: the lines it covers and any note.
	 */
	lines: string[];
}

function text( value: unknown ): string {
	return typeof value === 'string' ? value : '';
}

function count( value: unknown ): number {
	return Math.max( 0, Math.trunc( Number( value ) ) || 0 );
}

/**
 * The lines of a decision, each as "Approved: Occult x4" with any note beside it.
 */
function decidedLines( payload: Record< string, unknown > ): string[] {
	const lines = Array.isArray( payload.lines ) ? payload.lines : [];
	return lines.map( ( line ) => {
		const row = line as Record< string, unknown >;
		const outcome = {
			approved: __( 'Approved', 'beyond-elysium' ),
			refused: __( 'Refused', 'beyond-elysium' ),
			undone: __( 'Undone', 'beyond-elysium' ),
		}[ text( row.status ) ];
		const base = [ outcome, text( row.text ) ]
			.filter( Boolean )
			.join( ': ' );
		return text( row.note ) ? `${ base } — ${ text( row.note ) }` : base;
	} );
}

export function noticeText( notice: Notice ): NoticeText {
	const p = notice.payload;
	const name = text( p.character_name );

	switch ( notice.kind ) {
		case 'changes_decided': {
			const parts: string[] = [];
			if ( count( p.approved ) > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many changes a Storyteller approved */
						__( '%d approved', 'beyond-elysium' ),
						count( p.approved )
					)
				);
			}
			if ( count( p.refused ) > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many changes a Storyteller refused */
						__( '%d refused', 'beyond-elysium' ),
						count( p.refused )
					)
				);
			}
			return {
				title: `${ name }: ${ parts.join( ', ' ) }`,
				lines: decidedLines( p ),
			};
		}
		case 'changes_undone':
			return {
				title: sprintf(
					/* translators: 1: a character's name, 2: how many changes a Storyteller undid */
					__( '%1$s: %2$d undone', 'beyond-elysium' ),
					name,
					count( p.undone )
				),
				lines: decidedLines( p ),
			};
		case 'change_edited':
			return {
				title: sprintf(
					/* translators: %s: what the changed request now asks for */
					__(
						'A Storyteller changed your request: %s',
						'beyond-elysium'
					),
					text( p.text )
				),
				lines: text( p.note ) ? [ text( p.note ) ] : [],
			};
		case 'xp_awarded':
			return {
				title: sprintf(
					/* translators: 1: the XP awarded, 2: a character's name, 3: why and when */
					__( '+%1$d XP for %2$s: %3$s', 'beyond-elysium' ),
					count( p.amount ),
					name,
					[
						text( p.reason ),
						text( p.date )
							? formatStoredTime( text( p.date ), {
									time: false,
								} )
							: '',
					]
						.filter( Boolean )
						.join( ', ' )
				),
				lines: [],
			};
		case 'changes_waiting':
			return {
				title: sprintf(
					/* translators: 1: a character's name, 2: how many changes they sent, 3: how many of them wait */
					_n(
						'%1$s sent %2$d change; %3$d waits for you',
						'%1$s sent %2$d changes; %3$d wait for you',
						count( p.total ),
						'beyond-elysium'
					),
					name,
					count( p.total ),
					count( p.waiting )
				),
				lines: [],
			};
		case 'plot_post':
			return {
				title: sprintf(
					/* translators: %s: the title of a plot */
					__( 'A Storyteller posted on %s', 'beyond-elysium' ),
					text( p.plot_title )
				),
				lines: name
					? [
							sprintf(
								/* translators: %s: a character's name */
								__( 'For %s', 'beyond-elysium' ),
								name
							),
						]
					: [],
			};
		case 'post_waiting':
			return {
				title: sprintf(
					/* translators: 1: who posted, 2: the title of a plot */
					__( '%1$s posted actions on %2$s', 'beyond-elysium' ),
					text( p.posted_by ),
					text( p.plot_title )
				),
				lines: [],
			};
		case 'released': {
			const parts: string[] = [];
			if ( count( p.rumors ) > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many rumors were released */
						_n(
							'%d rumor',
							'%d rumors',
							count( p.rumors ),
							'beyond-elysium'
						),
						count( p.rumors )
					)
				);
			}
			if ( count( p.entries ) > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many plot posts were released */
						_n(
							'%d plot post',
							'%d plot posts',
							count( p.entries ),
							'beyond-elysium'
						),
						count( p.entries )
					)
				);
			}
			if ( count( p.reveals ) > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many secrets were released */
						_n(
							'%d secret',
							'%d secrets',
							count( p.reveals ),
							'beyond-elysium'
						),
						count( p.reveals )
					)
				);
			}
			const names = Array.isArray( p.character_names )
				? p.character_names.map( text ).filter( Boolean ).join( ', ' )
				: '';
			return {
				title: sprintf(
					/* translators: 1: the player's characters it reached, 2: what was released */
					__( 'New for %1$s: %2$s', 'beyond-elysium' ),
					names,
					parts.join( ', ' )
				),
				lines: [],
			};
		}
		case 'now_visible':
			return {
				title: visibleTitle( text( p.entity ), text( p.title ) ),
				lines: [],
			};
		case 'join_answered':
			return {
				title: sprintf(
					/* translators: %s: a chronicle's name */
					__(
						'Your request to join %s was approved',
						'beyond-elysium'
					),
					text( p.chronicle )
				),
				lines: [],
			};
		case 'file_answered':
			return {
				title: p.accepted
					? sprintf(
							/* translators: %s: a character's name */
							__(
								'Your Grapevine file for %s was accepted',
								'beyond-elysium'
							),
							name
						)
					: sprintf(
							/* translators: %s: a character's name */
							__(
								"Your Grapevine file for %s wasn't accepted",
								'beyond-elysium'
							),
							name
						),
				lines: text( p.note ) ? [ text( p.note ) ] : [],
			};
		case 'downtime_due':
			return {
				title: sprintf(
					/* translators: 1: the game night's date, 2: when downtime closes */
					__( 'Downtime for %1$s closes %2$s', 'beyond-elysium' ),
					formatStoredDay( text( p.game_date ) ),
					formatStoredDay( text( p.deadline ), { time: true } )
				),
				lines: [
					sprintf(
						/* translators: %s: a character's name */
						__( "%s hasn't sent any actions.", 'beyond-elysium' ),
						name
					),
				],
			};
		case 'join_requested':
			return {
				title: sprintf(
					/* translators: 1: a person's name, 2: a chronicle's name */
					__( '%1$s asks to join %2$s', 'beyond-elysium' ),
					text( p.applicant ),
					text( p.chronicle )
				),
				lines: text( p.message ) ? [ text( p.message ) ] : [],
			};
		case 'link_requested': {
			const names = Array.isArray( p.character_names )
				? p.character_names.map( text ).filter( Boolean ).join( ', ' )
				: '';
			return {
				title: names
					? sprintf(
							/* translators: %s: the characters a Discord link is for */
							__(
								'A Discord link for %s waits for you',
								'beyond-elysium'
							),
							names
						)
					: __( 'A Discord link waits for you', 'beyond-elysium' ),
				lines: [],
			};
		}
		case 'file_received':
			return {
				title: sprintf(
					/* translators: 1: a person's name, 2: a character's name */
					__(
						'%1$s sent a Grapevine file for %2$s',
						'beyond-elysium'
					),
					text( p.sender ),
					name
				),
				lines: [],
			};
		case 'secret_told':
			return {
				title: sprintf(
					/* translators: 1: the character who told it, 2: the character it was told to, 3: the secret's title */
					__( '%1$s told %2$s a secret: %3$s', 'beyond-elysium' ),
					text( p.teller_name ),
					text( p.recipient_name ),
					text( p.secret_title )
				),
				lines: [],
			};
		default:
			return { title: notice.kind, lines: [] };
	}
}

/**
 * The line a newly visible plot, item, location or secret is announced by.
 */
function visibleTitle( entity: string, title: string ): string {
	switch ( entity ) {
		case 'item':
			return sprintf(
				/* translators: %s: an item's name */
				__( 'You can now see the item %s', 'beyond-elysium' ),
				title
			);
		case 'location':
			return sprintf(
				/* translators: %s: a location's name */
				__( 'You can now see the location %s', 'beyond-elysium' ),
				title
			);
		case 'secret_told':
			return sprintf(
				/* translators: %s: a secret's title */
				__( 'A secret was told to you: %s', 'beyond-elysium' ),
				title
			);
		default:
			return sprintf(
				/* translators: %s: a plot's title */
				__( 'You can now see the plot %s', 'beyond-elysium' ),
				title
			);
	}
}

/**
 * A link the server wrote, kept only when it points back at this site.
 */
function sameSiteUrl( value: unknown ): string | null {
	if ( typeof value !== 'string' || value === '' ) {
		return null;
	}
	try {
		const url = new URL( value, window.location.origin );
		return url.origin === window.location.origin ? url.href : null;
	} catch {
		return null;
	}
}

/**
 * Where a notice points: a Storyteller's work to the Toolkit page that holds it, a player's news to the plot, the
 * Home or the character it is about.
 */
export function noticeHref( notice: Notice, gameSlug: string ): string {
	const slug = encodeURIComponent( gameSlug );
	const player = ( tab: string, extra = '' ) =>
		`${ playerTabUrl( tab ) }&game_slug=${ slug }${ extra }`;
	const staff = ( tab: string, extra = '' ) =>
		`${ storytellerTabUrl( tab ) }&game_slug=${ slug }${ extra }`;
	const plotId = count( notice.payload.plot_id );
	const openPlot = plotId > 0 ? `&open_plot=${ plotId }` : '';

	switch ( notice.kind ) {
		case 'changes_waiting':
		case 'file_received':
		case 'secret_told':
			return staff( STORYTELLER_TABS.approvalQueue );
		case 'post_waiting':
			return staff( STORYTELLER_TABS.plots, openPlot );
		case 'join_requested':
		case 'link_requested':
			return staff( STORYTELLER_TABS.players );
		case 'plot_post':
		case 'downtime_due':
			return player( PLAYER_TABS.plots, openPlot );
		case 'released':
			return player( PLAYER_TABS.plots );
		case 'now_visible':
			return (
				sameSiteUrl( notice.payload.url ) ?? player( PLAYER_TABS.plots )
			);
		case 'join_answered':
			return player( PLAYER_TABS.dashboard );
		case 'file_answered':
			return player( PLAYER_TABS.characters );
		default: {
			const id = count( notice.payload.character_id );
			return player( PLAYER_TABS.history, `&character_id=${ id }` );
		}
	}
}
