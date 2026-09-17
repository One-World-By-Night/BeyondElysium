/**
 * Formats a single `Change` record as a short human-readable string, e.g. "Celerity 2 →
 * 3", for display in an approval queue. Exports `describeChange()`, which switches on
 * the change's type and reads whatever fields that type's `change_data` carries -
 * `trait`, `previous`, `values`, `fields`, or `amount`/`reason` - falling back to a
 * generic label when an expected field isn't present.
 */

import { __, sprintf } from '@wordpress/i18n';
import type { ChangeType } from '../types/character';
import { identityValueText } from './identityValue';

interface TraitPayload {
	name?: string;
	count?: number;
	level?: number;
	specialization?: string;
}

interface ChangeDataLike {
	trait?: TraitPayload;
	previous?: TraitPayload;
	values?: Record< string, { permanent?: number; temporary?: number } >;
	fields?: Record< string, unknown >;
	amount?: number;
	reason?: string;
	name?: string;
	object_type?: string;
	faction_type?: string;
}

/**
 * Builds the display string for one change, branching on `changeType`: trait additions,
 * removals, and modifications; resource and identity field updates; XP earn/adjust
 * entries; and imported notes. Falls back to showing only the new value when a
 * `previous` side isn't available, and to a generic label for an unrecognized type.
 * Every word is translated; names, numbers, and reasons are the change's own (1.0.0-review F-084).
 */
export function describeChange(
	changeType: ChangeType,
	changeData: ChangeDataLike
): string {
	switch ( changeType ) {
		case 'add_trait': {
			const trait = changeData.trait ?? {};
			const name = trait.name ?? __( 'Unknown', 'beyond-elysium' );
			if ( trait.level !== undefined ) {
				return sprintf(
					/* translators: 1: trait or power name, 2: level */
					__( 'Added %1$s %2$s', 'beyond-elysium' ),
					name,
					String( trait.level )
				);
			}
			const count = trait.count ?? 1;
			const suffix = trait.specialization
				? ` (${ trait.specialization })`
				: '';
			return count > 1
				? sprintf(
						/* translators: 1: trait name, 2: count, 3: specialization in parentheses, or nothing */
						__( 'Added %1$s x%2$s%3$s', 'beyond-elysium' ),
						name,
						String( count ),
						suffix
				  )
				: sprintf(
						/* translators: 1: trait name, 2: specialization in parentheses, or nothing */
						__( 'Added %1$s%2$s', 'beyond-elysium' ),
						name,
						suffix
				  );
		}

		case 'remove_trait':
			return sprintf(
				/* translators: %s: trait name */
				__( 'Removed %s', 'beyond-elysium' ),
				changeData.trait?.name ?? __( 'Unknown', 'beyond-elysium' )
			);

		case 'modify_trait': {
			const trait = changeData.trait ?? {};
			const previous = changeData.previous;
			const name =
				trait.name ??
				previous?.name ??
				__( 'Unknown', 'beyond-elysium' );

			if ( trait.level !== undefined ) {
				return previous?.level !== undefined
					? sprintf(
							/* translators: 1: power name, 2: level before, 3: level after */
							__( '%1$s %2$s → %3$s', 'beyond-elysium' ),
							name,
							String( previous.level ),
							String( trait.level )
					  )
					: sprintf(
							/* translators: 1: power name, 2: level after */
							__( '%1$s → level %2$s', 'beyond-elysium' ),
							name,
							String( trait.level )
					  );
			}
			if ( trait.count !== undefined ) {
				return previous?.count !== undefined
					? sprintf(
							/* translators: 1: trait name, 2: count before, 3: count after */
							__( '%1$s x%2$s → x%3$s', 'beyond-elysium' ),
							name,
							String( previous.count ),
							String( trait.count )
					  )
					: sprintf(
							/* translators: 1: trait name, 2: count after */
							__( '%1$s → x%2$s', 'beyond-elysium' ),
							name,
							String( trait.count )
					  );
			}
			/* translators: %s: trait name */
			return sprintf( __( '%s updated', 'beyond-elysium' ), name );
		}

		case 'modify_resource': {
			const entries = Object.entries( changeData.values ?? {} );
			if ( entries.length === 0 ) {
				return __( 'Resource updated', 'beyond-elysium' );
			}
			const [ pool, value ] = entries[ 0 ];
			return sprintf(
				/* translators: 1: resource pool name, 2: permanent value, 3: temporary value */
				__( '%1$s: %2$s perm / %3$s temp', 'beyond-elysium' ),
				pool,
				String( value.permanent ?? '?' ),
				String( value.temporary ?? '?' )
			);
		}

		case 'modify_identity': {
			const entries = Object.entries( changeData.fields ?? {} );
			if ( entries.length === 0 ) {
				return __( 'Identity updated', 'beyond-elysium' );
			}
			const [ field, value ] = entries[ 0 ];
			// A multiselect's choices read as the sheet shows them (F-086).
			return sprintf(
				/* translators: 1: identity field name, 2: its new value */
				__( '%1$s → %2$s', 'beyond-elysium' ),
				field,
				identityValueText( value ) ?? ''
			);
		}

		case 'xp_earn':
			return sprintf(
				/* translators: 1: XP awarded, 2: the award's reason in parentheses, or nothing */
				__( '+%1$s XP%2$s', 'beyond-elysium' ),
				String( changeData.amount ?? 0 ),
				changeData.reason ? ` (${ changeData.reason })` : ''
			);

		case 'xp_adjust':
			return sprintf(
				/* translators: 1: signed XP adjustment, 2: the adjustment's reason in parentheses, or nothing */
				__( 'XP adjusted by %1$s%2$s', 'beyond-elysium' ),
				String( changeData.amount ?? 0 ),
				changeData.reason ? ` (${ changeData.reason })` : ''
			);

		case 'import_note':
			return changeData.reason || __( 'Imported note', 'beyond-elysium' );

		case 'propose_world_object':
			return sprintf(
				/* translators: 1: object type (item/location/rote), 2: its proposed name */
				__( 'Proposed %1$s: %2$s', 'beyond-elysium' ),
				changeData.object_type ?? __( 'item', 'beyond-elysium' ),
				changeData.name ?? __( 'Unknown', 'beyond-elysium' )
			);

		case 'propose_faction':
			return sprintf(
				/* translators: 1: faction type (coterie/pack/cabal/motley/other), 2: its proposed name */
				__( 'Proposed %1$s: %2$s', 'beyond-elysium' ),
				changeData.faction_type ?? __( 'group', 'beyond-elysium' ),
				changeData.name ?? __( 'Unknown', 'beyond-elysium' )
			);

		default:
			return __( 'Unknown change', 'beyond-elysium' );
	}
}
