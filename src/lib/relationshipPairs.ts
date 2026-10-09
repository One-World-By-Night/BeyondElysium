/**
 * The pure parts of the Relationships chart: connection rows with numeric ids, one line per pair of characters, and
 * a connection's notes split around their `[ST]` markers.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { Character } from '../types/character';
import type { Connection } from '../types/plot';

/**
 * Connection rows with every id as a number, whatever shape the server sent them in. A missing target stays null.
 */
export function normalizeConnections( rows: Connection[] ): Connection[] {
	return rows.map( ( row ) => ( {
		...row,
		id: Number( row.id ),
		game_id: Number( row.game_id ),
		source_id: Number( row.source_id ),
		target_id: row.target_id === null ? null : Number( row.target_id ),
		created_by: Number( row.created_by ),
	} ) );
}

/**
 * Player characters and NPCs as one list in name order, a character held in both lists once.
 */
export function mergeCharacters(
	players: Character[],
	npcs: Character[]
): Character[] {
	const byId = new Map< number, Character >();
	for ( const character of [ ...players, ...npcs ] ) {
		byId.set( character.id, character );
	}
	return Array.from( byId.values() ).sort( ( a, b ) =>
		a.name.localeCompare( b.name )
	);
}

/**
 * A name broken into lines of at most `width` characters, at spaces where it can; a single word longer than that is
 * cut into pieces of that length.
 */
export function wrapLabel( name: string, width = 22 ): string[] {
	const lines: string[] = [];
	let line = '';
	for ( const word of name.split( /\s+/ ).filter( Boolean ) ) {
		let rest = word;
		while ( rest.length > width ) {
			if ( line ) {
				lines.push( line );
				line = '';
			}
			lines.push( rest.slice( 0, width ) );
			rest = rest.slice( width );
		}
		const joined = line ? `${ line } ${ rest }` : rest;
		if ( joined.length > width ) {
			lines.push( line );
			line = rest;
		} else {
			line = joined;
		}
	}
	if ( line ) {
		lines.push( line );
	}
	return lines.length > 0 ? lines : [ name ];
}

/**
 * A pair of characters, in either order, as one comparison key.
 */
export function pairKey( a: number, b: number ): string {
	return a < b ? `${ a }-${ b }` : `${ b }-${ a }`;
}

export interface EdgeGroup {
	key: string;
	a: number;
	b: number;
	connections: Connection[];
}

/**
 * The connections between two characters that are both on the chart, grouped one entry per pair whichever way each
 * connection points, in the order the pairs first appear.
 */
export function groupEdges(
	connections: Connection[],
	isVisible: ( id: number ) => boolean
): EdgeGroup[] {
	const groups = new Map< string, EdgeGroup >();
	for ( const connection of connections ) {
		if (
			connection.target_id === null ||
			! isVisible( connection.source_id ) ||
			! isVisible( connection.target_id )
		) {
			continue;
		}
		const key = pairKey( connection.source_id, connection.target_id );
		const group = groups.get( key );
		if ( group ) {
			group.connections.push( connection );
		} else {
			groups.set( key, {
				key,
				a: Math.min( connection.source_id, connection.target_id ),
				b: Math.max( connection.source_id, connection.target_id ),
				connections: [ connection ],
			} );
		}
	}
	return Array.from( groups.values() );
}

export interface NoteSegment {
	text: string;
	st: boolean;
}

/**
 * Plain text cut at its `[ST]` ... `[/ST]` markers, so a Storyteller's own passages can be shown highlighted without
 * putting any of it into markup. An opener with no closer runs to the end.
 */
export function splitStMarkers( text: string ): NoteSegment[] {
	const segments: NoteSegment[] = [];
	let position = 0;
	while ( position < text.length ) {
		const open = text.indexOf( '[ST]', position );
		if ( open === -1 ) {
			segments.push( { text: text.slice( position ), st: false } );
			break;
		}
		if ( open > position ) {
			segments.push( { text: text.slice( position, open ), st: false } );
		}
		const close = text.indexOf( '[/ST]', open + 4 );
		if ( close === -1 ) {
			segments.push( { text: text.slice( open + 4 ), st: true } );
			break;
		}
		segments.push( { text: text.slice( open + 4, close ), st: true } );
		position = close + 5;
	}
	return segments.filter( ( segment ) => segment.text !== '' );
}

/**
 * What an edge says when it is hovered or read aloud: who it joins, and its label or how many connections it holds.
 */
export function edgeSummary(
	nameA: string,
	nameB: string,
	connections: Connection[]
): string {
	if ( connections.length > 1 ) {
		return sprintf(
			/* translators: 1: a character's name, 2: another character's name, 3: how many connections join them */
			_n(
				'%1$s and %2$s: %3$d connection',
				'%1$s and %2$s: %3$d connections',
				connections.length,
				'beyond-elysium'
			),
			nameA,
			nameB,
			connections.length
		);
	}
	const label = connections[ 0 ]?.label;
	if ( label ) {
		return sprintf(
			/* translators: 1: a character's name, 2: another character's name, 3: what joins them, such as Packmates */
			__( '%1$s and %2$s: %3$s', 'beyond-elysium' ),
			nameA,
			nameB,
			label
		);
	}
	return sprintf(
		/* translators: 1: a character's name, 2: another character's name */
		__( '%1$s and %2$s', 'beyond-elysium' ),
		nameA,
		nameB
	);
}
