/**
 * Formats a single `Change` record as a short human-readable string, e.g. "Celerity 2 → 3", for display in an
 * approval queue.
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import type { ChangeType } from '../types/character';
import { identityValueText } from './identityValue';

interface TraitPayload {
	name?: string;
	count?: number;
	level?: number;
	specialization?: string;
	power_name?: string;
	spent_rank?: string;
	spent_cost?: number;
	spent_pool?: string;
}

interface ChangeDataLike {
	trait?: TraitPayload;
	previous?: TraitPayload;
	values?: Record<
		string,
		{
			permanent?: number;
			temporary?: number;
			raised_cost?: number;
			raised_from?: string;
		}
	>;
	fields?: Record< string, unknown >;
	amount?: number;
	reason?: string;
	name?: string;
	pool_field?: string;
	object_type?: string;
	faction_type?: string;
	// `catalog_rekey`: what the cutover did to one character's sheet (Catalog_Cutover::rekey_character()).
	counts?: {
		moved_rows?: number;
		rekeyed?: number;
		respelled?: number;
		dropped?: number;
	};
	records?: CatalogRekeyRecord[];
	forced?: boolean;
	// `player_link`: the account a character was linked to or unlinked from.
	player?: string;
	invite_id?: number;
	unlinked?: boolean;
	// `log_knowledge`: what a character learned, and from whom.
	title?: string;
	teller_name?: string;
	// `pass_secret`: who told whom.
	secret_title?: string;
	from_name?: string;
	to_name?: string;
	// `visit_note`: the host chronicle's own note about a visiting character.
	note?: string | null;
	// `visit_pairing`: the host chronicle asking to pair with this character, and its site's address.
	host_chronicle?: string;
	host_site?: string;
}

interface CatalogRekeyRecord {
	outcome?: string;
	from?: string;
	to?: string;
	label?: string | null;
	tradition?: string;
	level?: number | null;
	held_level?: number | null;
}

/**
 * Builds the display string for one change, branching on `changeType`: trait additions, removals, and modifications.
 */
export function describeChange(
	changeType: ChangeType,
	changeData: ChangeDataLike
): string {
	switch ( changeType ) {
		case 'add_trait': {
			const trait = changeData.trait ?? {};
			const name = trait.name ?? __( 'Unknown', 'beyond-elysium' );
			if ( trait.spent_rank && trait.spent_cost !== undefined ) {
				const rank =
					trait.spent_rank.charAt( 0 ).toUpperCase() +
					trait.spent_rank.slice( 1 );
				return sprintf(
					/* translators: 1: edge name, 2: edge rank (Touched, Gifted, Devoted, Inspired, Exalted), 3: how many dots it spends, 4: the pool it spends from */
					__(
						'Added %1$s: %2$s Edge, %3$s %4$s Trait(s) spent',
						'beyond-elysium'
					),
					trait.power_name ?? name,
					rank,
					String( trait.spent_cost ),
					trait.spent_pool ?? ''
				);
			}
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
				changeData.trait?.power_name ??
					changeData.trait?.name ??
					__( 'Unknown', 'beyond-elysium' )
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
			if ( value.raised_cost !== undefined ) {
				return sprintf(
					/* translators: 1: pool being raised (e.g. Mercy), 2: its new permanent rating, 3: how many temporary points it cost, 4: the pool those temporary points came from (e.g. Conviction) */
					__( '%1$s raised to %2$s for %3$s %4$s', 'beyond-elysium' ),
					pool,
					String( value.permanent ?? '?' ),
					String( value.raised_cost ),
					value.raised_from ?? ''
				);
			}
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
			// A multiselect's choices read as the sheet shows them.
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

		case 'creation_spend':
			return (
				( changeData.reason as string | undefined ) ||
				__( 'Character build', 'beyond-elysium' )
			);

		case 'pool_spend':
			return sprintf(
				/* translators: 1: trait name, 2: the pool it was paid from */
				__( 'Granted %1$s from %2$s', 'beyond-elysium' ),
				changeData.trait?.name ?? __( 'Unknown', 'beyond-elysium' ),
				changeData.pool_field ?? __( 'a pool', 'beyond-elysium' )
			);

		case 'catalog_rekey': {
			const moved = changeData.counts?.moved_rows ?? 0;
			const matched = changeData.counts?.rekeyed ?? 0;
			const respelled = changeData.counts?.respelled ?? 0;
			const dropped = changeData.counts?.dropped ?? 0;
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
			if ( dropped > 0 ) {
				parts.push(
					sprintf(
						/* translators: %d: how many rows the catalog update dropped because the sheet already held them */
						_n(
							'%d repeated row dropped',
							'%d repeated rows dropped',
							dropped,
							'beyond-elysium'
						),
						dropped
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

		case 'log_knowledge':
			return sprintf(
				/* translators: %s: the title of what the character claims to have learned */
				__( 'Logged knowledge: %s', 'beyond-elysium' ),
				changeData.title ?? __( 'Unknown', 'beyond-elysium' )
			);

		case 'pass_secret':
			return sprintf(
				/* translators: 1: the telling character, 2: the recipient, 3: the secret's title */
				__( '%1$s wants to tell %2$s: %3$s', 'beyond-elysium' ),
				changeData.from_name ?? __( 'A character', 'beyond-elysium' ),
				changeData.to_name ??
					__( 'another character', 'beyond-elysium' ),
				changeData.secret_title ?? __( 'a secret', 'beyond-elysium' )
			);

		case 'visit_note':
			return sprintf(
				/* translators: %s: the host chronicle's note about a visiting character */
				__( 'Note from the host chronicle: %s', 'beyond-elysium' ),
				changeData.note ?? ''
			);

		case 'visit_pairing': {
			const hostName =
				changeData.host_chronicle ??
				__( 'A host chronicle', 'beyond-elysium' );
			if ( changeData.host_site ) {
				return sprintf(
					/* translators: 1: the host chronicle's own name, 2: the host site's address */
					__(
						'%1$s (%2$s) asks to keep this character current.',
						'beyond-elysium'
					),
					hostName,
					changeData.host_site
				);
			}
			return sprintf(
				/* translators: %s: the host chronicle's own name */
				__(
					'%s asks to keep this character current.',
					'beyond-elysium'
				),
				hostName
			);
		}

		case 'player_link': {
			const player =
				changeData.player || __( 'a player', 'beyond-elysium' );
			if ( changeData.unlinked ) {
				return sprintf(
					/* translators: %s: the player's display name */
					__( 'Unlinked from %s', 'beyond-elysium' ),
					player
				);
			}
			return changeData.invite_id
				? sprintf(
						/* translators: %s: the player's display name */
						__(
							'Linked to %s through an invite',
							'beyond-elysium'
						),
						player
					)
				: sprintf(
						/* translators: %s: the player's display name */
						__( 'Linked to %s', 'beyond-elysium' ),
						player
					);
		}

		default:
			return __( 'Unknown change', 'beyond-elysium' );
	}
}

/**
 * The lines that sit beneath a change's one-line description: for a catalog update, each custom entry it matched to a
 * catalog item as "what it was -> what it is", each row it dropped because the sheet already held it, and each row it
 * kept that disagrees with a held one on level.
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
		if ( record.outcome === 'duplicate' && record.to ) {
			lines.push(
				sprintf(
					/* translators: 1: the entry as it was filed, 2: the entry the sheet already held, 3: its tradition */
					__(
						'%1$s: already held as %2$s (%3$s), not added again',
						'beyond-elysium'
					),
					record.from ?? '',
					record.to,
					record.tradition ?? ''
				)
			);
			continue;
		}
		if ( record.outcome === 'conflict' && record.to ) {
			lines.push(
				sprintf(
					/* translators: 1: the entry's name, 2: its tradition, 3: the level the sheet already held, 4: the level this entry carried */
					__(
						'%1$s (%2$s): held at level %3$d, this entry says %4$d, both kept',
						'beyond-elysium'
					),
					record.to,
					record.tradition ?? '',
					record.held_level ?? 0,
					record.level ?? 0
				)
			);
			continue;
		}
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
 * A change's XP cost or refund as it reads beside its description.
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
