/**
 * What a notice in the header says and where it points.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatStoredTime } from './storedTime';
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
		default:
			return { title: notice.kind, lines: [] };
	}
}

/**
 * Where a notice points: a player's notices to the character's History, a Storyteller's to the Approval Queue.
 */
export function noticeHref( notice: Notice, gameSlug: string ): string {
	const slug = encodeURIComponent( gameSlug );
	if ( notice.kind === 'changes_waiting' ) {
		return `${ storytellerTabUrl(
			STORYTELLER_TABS.approvalQueue
		) }&game_slug=${ slug }`;
	}
	const id = count( notice.payload.character_id );
	return `${ playerTabUrl( PLAYER_TABS.history ) }&character_id=${ id }&game_slug=${ slug }`;
}
