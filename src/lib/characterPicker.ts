/**
 * The rows a character picker shows for a chronicle's player characters: filtered by name, the free ones first, then
 * those waiting for an invite's email, then those linked to an account, each group by name.
 */
import type { Character } from '../types/character';
import { idOf, sameId } from './ids';

/**
 * `free`: linked to nobody and waiting for no one. `waiting`: holds an invite's email. `linked`: linked to an account.
 * `own`: linked to the player being given characters.
 */
export type PickerRowState = 'free' | 'waiting' | 'linked' | 'own';

export interface PickerRow {
	id: number;
	name: string;
	state: PickerRowState;
	/**
	 * The linked player's name, or the email a waiting character holds.
	 */
	note: string;
}

const ORDER: Record< PickerRowState, number > = {
	free: 0,
	waiting: 1,
	own: 2,
	linked: 3,
};

/**
 * The picker's rows for these characters, narrowed to names containing `filter`; `forUserId` is the player being given
 * characters, whose own rows read `own`.
 */
export function pickerRows(
	characters: Character[],
	filter: string,
	forUserId: number | null = null
): PickerRow[] {
	const wanted = filter.trim().toLowerCase();
	return characters
		.filter( ( c ) => ! c.is_npc )
		.filter(
			( c ) => wanted === '' || c.name.toLowerCase().includes( wanted )
		)
		.map( ( c ): PickerRow => {
			if ( idOf( c.wp_user_id ) !== null ) {
				return {
					id: Number( c.id ),
					name: c.name,
					state: sameId( c.wp_user_id, forUserId ) ? 'own' : 'linked',
					note: c.player_name ?? '',
				};
			}
			if ( c.pending_player_email ) {
				return {
					id: Number( c.id ),
					name: c.name,
					state: 'waiting',
					note: c.pending_player_email,
				};
			}
			return {
				id: Number( c.id ),
				name: c.name,
				state: 'free',
				note: '',
			};
		} )
		.sort(
			( a, b ) =>
				ORDER[ a.state ] - ORDER[ b.state ] ||
				a.name.localeCompare( b.name )
		);
}
