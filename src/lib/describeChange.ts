/**
 * Formats a single `Change` record as a short human-readable string, e.g. "Celerity 2 →
 * 3", for display in an approval queue. Exports `describeChange()`, which switches on
 * the change's type and reads whatever fields that type's `change_data` carries -
 * `trait`, `previous`, `values`, `fields`, or `amount`/`reason` - falling back to a
 * generic label when an expected field isn't present.
 */

import { __, _n, sprintf } from '@wordpress/i18n';
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
	// `catalog_rekey`: what the cutover did to one character's sheet (Catalog_Cutover::rekey_character()).
	counts?: { moved_rows?: number; rekeyed?: number; respelled?: number };
	records?: CatalogRekeyRecord[];
	// `catalog_rekey_revert`: the rollback restored a sheet that had changed since the cutover.
	forced?: boolean;
}

interface CatalogRekeyRecord {
	outcome?: string;
	from?: string;
	to?: string;
	label?: string | null;
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

		case 'catalog_rekey': {
			const moved = changeData.counts?.moved_rows ?? 0;
			const matched = changeData.counts?.rekeyed ?? 0;
			const respelled = changeData.counts?.respelled ?? 0;
			const parts: string[] = [];
			if ( moved > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many rows the catalog update moved to their new section */
						_n(
							'%d row moved to its new catalog section',
							'%d rows moved to their new catalog sections',
							moved,
							'beyond-elysium'
						),
						moved
					)
				);
			}
			if ( matched > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many custom entries the catalog update matched to a catalog item */
						_n(
							'%d custom entry matched to the catalog',
							'%d custom entries matched to the catalog',
							matched,
							'beyond-elysium'
						),
						matched
					)
				);
			}
			if ( respelled > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many catalog names the catalog update respelled to the spelling the catalog now uses */
						_n(
							'%d name spelled to match the catalog',
							'%d names spelled to match the catalog',
							respelled,
							'beyond-elysium'
						),
						respelled
					)
				);
			}
			if ( parts.length === 0 ) {
				return __( 'Catalog update', 'beyond-elysium' );
			}
			return sprintf(
				/* translators: %s: what the catalog update did, e.g. "24 rows moved to their new catalog sections, 7 custom entries matched to the catalog" */
				__( 'Catalog update: %s', 'beyond-elysium' ),
				parts.join( ', ' )
			);
		}

		case 'catalog_rekey_revert':
			return changeData.forced
				? __(
						'Catalog update undone, including changes made since',
						'beyond-elysium'
				  )
				: __( 'Catalog update undone', 'beyond-elysium' );

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

/**
 * The lines that sit beneath a change's one-line description: for a catalog update, each custom
 * entry it matched to a catalog item as "what it was -> what it is". Empty for every other kind.
 * The signed sheet's history table has room for one line per change, so this has no PHP twin.
 */
export function describeChangeDetail(
	changeType: ChangeType,
	changeData: ChangeDataLike
): string[] {
	if ( changeType !== 'catalog_rekey' ) {
		return [];
	}
	const lines: string[] = [];
	for ( const record of changeData.records ?? [] ) {
		if ( record.outcome !== 'rekeyed' || ! record.to ) {
			continue;
		}
		lines.push(
			sprintf(
				/* translators: 1: what the entry was called before, 2: what it is called now */
				__( '%1$s → %2$s', 'beyond-elysium' ),
				record.from ?? '',
				record.label ? `${ record.to } (${ record.label })` : record.to
			)
		);
	}
	return lines;
}

/**
 * A change's XP cost or refund as it reads beside its description - "+3 XP", "-2 XP" - or null
 * when it costs nothing. The column arrives as a decimal string ("0.00"), so "free" is decided on
 * the number, never on the raw value: compared as a string, every free change printed "+0 XP".
 */
export function describeChangeCost( cost: number | string ): string | null {
	const amount = Number( cost );
	if ( ! amount ) {
		return null;
	}
	return `${ amount >= 0 ? '+' : '' }${ sprintf(
		/* translators: %d: the XP cost or refund for this change */
		__( '%d XP', 'beyond-elysium' ),
		amount
	) }`;
}
