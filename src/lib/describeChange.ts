/**
 * Formats a single `Change` record as a short human-readable string, e.g. "Celerity 2 →
 * 3", for display in an approval queue. Exports `describeChange()`, which switches on
 * the change's type and reads whatever fields that type's `change_data` carries -
 * `trait`, `previous`, `values`, `fields`, or `amount`/`reason` - falling back to a
 * generic label when an expected field isn't present.
 */

import type { ChangeType } from '../types/character';

interface TraitPayload {
	name?: string;
	count?: number;
	level?: number;
	specialization?: string;
}

interface ChangeDataLike {
	trait?: TraitPayload;
	previous?: TraitPayload;
	values?: Record<string, { permanent?: number; temporary?: number }>;
	fields?: Record<string, unknown>;
	amount?: number;
	reason?: string;
}

/**
 * Builds the display string for one change, branching on `changeType`: trait additions,
 * removals, and modifications; resource and identity field updates; XP earn/adjust
 * entries; and imported notes. Falls back to showing only the new value when a
 * `previous` side isn't available, and to a generic label for an unrecognized type.
 */
export function describeChange( changeType: ChangeType, changeData: ChangeDataLike ): string {
	switch ( changeType ) {
		case 'add_trait': {
			const trait = changeData.trait ?? {};
			const name = trait.name ?? 'Unknown';
			if ( trait.level !== undefined ) {
				return `Added ${ name } ${ trait.level }`;
			}
			const count = trait.count ?? 1;
			const suffix = trait.specialization ? ` (${ trait.specialization })` : '';
			return count > 1 ? `Added ${ name } x${ count }${ suffix }` : `Added ${ name }${ suffix }`;
		}

		case 'remove_trait': {
			const name = changeData.trait?.name ?? 'Unknown';
			return `Removed ${ name }`;
		}

		case 'modify_trait': {
			const trait = changeData.trait ?? {};
			const previous = changeData.previous;
			const name = trait.name ?? previous?.name ?? 'Unknown';

			if ( trait.level !== undefined ) {
				return previous?.level !== undefined
					? `${ name } ${ previous.level } → ${ trait.level }`
					: `${ name } → level ${ trait.level }`;
			}
			if ( trait.count !== undefined ) {
				return previous?.count !== undefined
					? `${ name } x${ previous.count } → x${ trait.count }`
					: `${ name } → x${ trait.count }`;
			}
			return `${ name } updated`;
		}

		case 'modify_resource': {
			const entries = Object.entries( changeData.values ?? {} );
			if ( entries.length === 0 ) {
				return 'Resource updated';
			}
			const [ pool, value ] = entries[ 0 ];
			return `${ pool }: ${ value.permanent ?? '?' } perm / ${ value.temporary ?? '?' } temp`;
		}

		case 'modify_identity': {
			const entries = Object.entries( changeData.fields ?? {} );
			if ( entries.length === 0 ) {
				return 'Identity updated';
			}
			const [ field, value ] = entries[ 0 ];
			return `${ field } → ${ String( value ) }`;
		}

		case 'xp_earn':
			return `+${ changeData.amount ?? 0 } XP${ changeData.reason ? ` (${ changeData.reason })` : '' }`;

		case 'xp_adjust':
			return `XP adjusted by ${ changeData.amount ?? 0 }${ changeData.reason ? ` (${ changeData.reason })` : '' }`;

		case 'import_note':
			return changeData.reason ?? 'Imported note';

		default:
			return 'Unknown change';
	}
}
