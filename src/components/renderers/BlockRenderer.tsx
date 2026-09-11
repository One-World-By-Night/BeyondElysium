/**
 * Dispatches a schema block to the renderer matching its section type
 * (trait_list, tiered_power, resource_pool, identity_field). Also
 * exports `toTraits()`, which converts a block's raw stored JSON into
 * the `Trait[]` shape the trait-list renderer and formatter expect.
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
import TraitListRenderer from './TraitListRenderer';
import TieredPowerRenderer, { type HeldPower } from './TieredPowerRenderer';
import ResourcePoolRenderer from './ResourcePoolRenderer';
import IdentityFieldRenderer from './IdentityFieldRenderer';

export interface BlockRendererProps {
	blockSlug: string;
	sectionType: SectionType;
	definition: BlockDefinition;
	data: unknown;
	/** Template section override, trait_list only. Null falls through to the block default. */
	display?: DisplayType | null;
	/** Stack display_preferences override, tiered_power only. */
	displayMode?: 'named' | 'numeric';
	/** The character's full sheet_data, resource_pool only, for a pool whose display name depends on another block's value. */
	sheetData?: Record<string, unknown>;
}

/**
 * Renders a block using the renderer matching its `sectionType`:
 * trait_list, tiered_power, resource_pool, or identity_field. An
 * unrecognized section type renders a visible warning naming the block
 * slug instead of rendering nothing.
 */
export function BlockRenderer( {
	blockSlug,
	sectionType,
	definition,
	data,
	display,
	displayMode,
	sheetData,
}: BlockRendererProps ) {
	switch ( sectionType ) {
		case 'trait_list':
			return (
				<TraitListRenderer
					blockSlug={ blockSlug }
					data={ toTraits( data ) }
					definition={ definition as TraitListDefinition }
					display={ display ?? null }
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
					data={ ( data as Record<string, ResourcePoolValue> | undefined ) ?? {} }
					definition={ definition as ResourcePoolDefinition }
					sheetData={ sheetData }
				/>
			);

		case 'identity_field':
			return (
				<IdentityFieldRenderer
					blockSlug={ blockSlug }
					data={ ( data as Record<string, string | number> | undefined ) ?? {} }
					definition={ definition as IdentityFieldDefinition }
				/>
			);

		default:
			return (
				<div className="be-block-renderer__unknown" data-block-slug={ blockSlug }>
					{ sprintf(
						/* translators: 1: section type slug, 2: block slug */
						__( 'Unknown section type "%1$s" for block "%2$s"', 'beyond-elysium' ),
						sectionType,
						blockSlug
					) }
				</div>
			);
	}
}

/**
 * Converts a block's raw stored `sheet_data` value into `Trait[]` for
 * `displayTrait()`: reads each entry's numeric value from `total` or,
 * failing that, `count`, and combines a separate `specialization` field
 * with `note` into one displayed note string. Returns an empty array for
 * non-array input.
 *
 * @param data Raw `sheet_data[block_slug]` value, untyped JSON.
 * @return Trait[]
 */
export function toTraits( data: unknown ): Trait[] {
	if ( ! Array.isArray( data ) ) {
		return [];
	}
	return data.map( ( entry: Record<string, unknown> ) => {
		// Combines the entry's specialization field with its note into one displayed string.
		const specialization = entry.specialization as string | undefined;
		const note = entry.note as string | undefined;
		const combinedNote = specialization && note
			? `${ specialization }, ${ note }`
			: specialization || note;

		return {
			name: String( entry.name ?? '' ),
			total: ( entry.total ?? entry.count ) as Trait[ 'total' ],
			note: combinedNote,
		};
	} );
}

export default BlockRenderer;
