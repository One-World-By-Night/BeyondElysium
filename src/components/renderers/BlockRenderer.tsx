/**
 * Dispatches a schema block to the renderer matching its section type (trait_list, tiered_power, resource_pool,
 * identity_field).
 */
import { __, sprintf } from '@wordpress/i18n';
import type {
	SectionType,
	BlockDefinition,
	TraitListDefinition,
	TieredPowerDefinition,
	ResourcePoolDefinition,
	IdentityFieldDefinition,
} from '../../types';
import type { DisplayType, Trait } from '../../lib/displayTrait';
import type { ResourcePoolValue } from '../../lib/displayTemper';
import TraitListRenderer, { resolveTraitListMode } from './TraitListRenderer';
import TieredPowerRenderer, { type HeldPower } from './TieredPowerRenderer';
import ResourcePoolRenderer from './ResourcePoolRenderer';
import IdentityFieldRenderer from './IdentityFieldRenderer';

export interface BlockRendererProps {
	blockSlug: string;
	sectionType: SectionType;
	definition: BlockDefinition;
	data: unknown;
	/**
	 * Template section override, trait_list only.
	 */
	display?: DisplayType | null;
	/**
	 * Stack display_preferences override, tiered_power only.
	 */
	displayMode?: 'named' | 'numeric';
	/**
	 * Whether a count_is_cost trait_list block's flat XP price is shown at all, trait_list only.
	 */
	showCost?: boolean;
	/**
	 * The character's full sheet_data, resource_pool only, for a pool whose display name depends on another block's
	 * value.
	 */
	sheetData?: Record< string, unknown >;
}

/**
 * Renders a block using the renderer matching its `sectionType`: trait_list, tiered_power, resource_pool, or
 * identity_field.
 */
export function BlockRenderer( {
	blockSlug,
	sectionType,
	definition,
	data,
	display,
	displayMode,
	showCost,
	sheetData,
}: BlockRendererProps ) {
	switch ( sectionType ) {
		case 'trait_list':
			return (
				<TraitListRenderer
					blockSlug={ blockSlug }
					data={
						resolveTraitListMode(
							definition as TraitListDefinition,
							display ?? null,
							showCost
						) === 'points'
							? toPointTraits(
									data,
									definition as TraitListDefinition
							  )
							: toTraits( data )
					}
					definition={ definition as TraitListDefinition }
					display={ display ?? null }
					showCost={ showCost }
				/>
			);

		case 'tiered_power':
			return (
				<TieredPowerRenderer
					blockSlug={ blockSlug }
					data={ ( data as HeldPower[] | undefined ) ?? [] }
					definition={ definition as TieredPowerDefinition }
					displayMode={ displayMode }
				/>
			);

		case 'resource_pool':
			return (
				<ResourcePoolRenderer
					blockSlug={ blockSlug }
					data={
						( data as
							| Record< string, ResourcePoolValue >
							| undefined ) ?? {}
					}
					definition={ definition as ResourcePoolDefinition }
					sheetData={ sheetData }
				/>
			);

		case 'identity_field':
			return (
				<IdentityFieldRenderer
					blockSlug={ blockSlug }
					data={
						( data as
							| Record< string, string | number | string[] >
							| undefined ) ?? {}
					}
					definition={ definition as IdentityFieldDefinition }
				/>
			);

		default:
			return (
				<div
					className="be-block-renderer__unknown"
					data-block-slug={ blockSlug }
				>
					{ sprintf(
						/* translators: 1: section type slug, 2: block slug */
						__(
							'Unknown section type "%1$s" for block "%2$s"',
							'beyond-elysium'
						),
						sectionType,
						blockSlug
					) }
				</div>
			);
	}
}

/**
 * Converts a block's raw stored `sheet_data` value into `Trait[]` for `displayTrait()`.
 *
 * @param data Raw `sheet_data[block_slug]` value, untyped JSON.
 * @return Trait[]
 */
export function toTraits( data: unknown ): Trait[] {
	if ( ! Array.isArray( data ) ) {
		return [];
	}
	return data.map( ( entry: Record< string, unknown > ) => {
		// Combines the entry's specialization field with its note into one displayed string.
		const specialization = entry.specialization as string | undefined;
		const note = entry.note as string | undefined;
		const combinedNote =
			specialization && note
				? `${ specialization }, ${ note }`
				: specialization || note;

		return {
			name: String( entry.name ?? '' ),
			total: ( entry.total ?? entry.count ) as Trait[ 'total' ],
			note: combinedNote,
		};
	} );
}

/**
 * One held entry's points: the cost the character chose, else a held count above 1, else the catalog item's fixed
 * cost, else the held count, or null when there is none.
 */
export function pointsFor(
	entry: Record< string, unknown >,
	item?: { cost?: string | null }
): number | null {
	const numeric = ( value: unknown ): number | null => {
		if ( typeof value === 'number' ) {
			return Number.isFinite( value ) ? Math.trunc( value ) : null;
		}
		if ( typeof value === 'string' && value.trim() !== '' ) {
			const parsed = Number( value );
			return Number.isFinite( parsed ) ? Math.trunc( parsed ) : null;
		}
		return null;
	};

	const chosen = numeric( entry.chosen_cost );
	if ( chosen !== null ) {
		return chosen;
	}

	const count = numeric( entry.count );
	if ( count !== null && count > 1 ) {
		return count;
	}

	const cost = ( item?.cost ?? '' ).trim();
	if ( /^\d+$/.test( cost ) ) {
		return parseInt( cost, 10 );
	}
	return count;
}

/**
 * Converts a trait_list block's held entries into `Trait[]` whose total is each entry's points.
 */
export function toPointTraits(
	data: unknown,
	definition: TraitListDefinition
): Trait[] {
	const traits = toTraits( data );
	if ( ! Array.isArray( data ) ) {
		return traits;
	}
	const itemsByName = new Map(
		( definition.items ?? [] ).map( ( item ) => [ item.name, item ] )
	);
	return traits.map( ( trait, index ) => {
		const points = pointsFor(
			( data[ index ] ?? {} ) as Record< string, unknown >,
			itemsByName.get( trait.name )
		);
		return { ...trait, total: points ?? undefined };
	} );
}

export default BlockRenderer;
