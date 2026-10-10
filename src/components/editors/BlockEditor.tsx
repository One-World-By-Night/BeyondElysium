/**
 * BlockEditor dispatches to the correct editor component for a schema block's section_type, wiring the shared
 * blockSlug/data/onChange/readOnly props through to whichever editor handles that type.
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
import type { ResourcePoolValue } from '../../lib/displayTemper';
import TraitListEditor, { type EditableTrait } from './TraitListEditor';
import TieredPowerEditor, { type EditableHeldPower } from './TieredPowerEditor';
import ResourcePoolEditor from './ResourcePoolEditor';
import IdentityFieldEditor, {
	type IdentityFieldValue,
} from './IdentityFieldEditor';
import './BlockEditor.css';

export interface BlockEditorProps {
	blockSlug: string;
	sectionType: SectionType;
	definition: BlockDefinition;
	data: unknown;
	onChange: ( blockSlug: string, nextData: unknown ) => void;
	costFor?: ( power: EditableHeldPower ) => number | null;
	readOnly?: boolean;
	/**
	 * The character's full sheet_data, used to resolve a pool's display name from another block's value.
	 */
	sheetData?: Record< string, unknown >;
	/**
	 * Consumed by identity_field's own textarea fields (AI Assist) and a player_order block's "Save order" call.
	 */
	gameSlug?: string;
	/**
	 * Needed only for a player_order block's "Save order" call.
	 */
	characterId?: number;
	/**
	 * A Storyteller of this chronicle sets any resource_pool value directly, bypassing its own max and any
	 * raised_by cost - the same standing exception every other section_type already gives a manager.
	 */
	isManager?: boolean;
	/**
	 * True while a new character is being made, so a pool only play can raise takes its free dots directly.
	 */
	creating?: boolean;
}

/**
 * Renders the editor for one schema block, chosen by its section_type: trait_list, tiered_power, resource_pool, or
 * identity_field.
 */
export function BlockEditor( {
	blockSlug,
	sectionType,
	definition,
	data,
	onChange,
	costFor,
	readOnly,
	sheetData,
	gameSlug,
	characterId,
	isManager,
	creating,
}: BlockEditorProps ) {
	switch ( sectionType ) {
		case 'trait_list':
			return (
				<TraitListEditor
					blockSlug={ blockSlug }
					data={ ( data as EditableTrait[] | undefined ) ?? [] }
					definition={ definition as TraitListDefinition }
					onChange={
						onChange as (
							blockSlug: string,
							nextData: EditableTrait[]
						) => void
					}
					readOnly={ readOnly }
					gameSlug={ gameSlug }
					characterId={ characterId }
					sheetData={ sheetData }
				/>
			);

		case 'tiered_power':
			return (
				<TieredPowerEditor
					blockSlug={ blockSlug }
					data={ ( data as EditableHeldPower[] | undefined ) ?? [] }
					definition={ definition as TieredPowerDefinition }
					onChange={
						onChange as (
							blockSlug: string,
							nextData: EditableHeldPower[]
						) => void
					}
					costFor={ costFor }
					readOnly={ readOnly }
					gameSlug={ gameSlug }
					characterId={ characterId }
				/>
			);

		case 'resource_pool':
			return (
				<ResourcePoolEditor
					blockSlug={ blockSlug }
					data={
						( data as
							Record< string, ResourcePoolValue > | undefined ) ??
						{}
					}
					definition={ definition as ResourcePoolDefinition }
					onChange={
						onChange as (
							blockSlug: string,
							nextData: Record< string, ResourcePoolValue >
						) => void
					}
					readOnly={ readOnly }
					sheetData={ sheetData }
					isManager={ isManager }
					creating={ creating }
				/>
			);

		case 'identity_field':
			return (
				<IdentityFieldEditor
					blockSlug={ blockSlug }
					data={
						( data as
							| Record< string, IdentityFieldValue >
							| undefined ) ?? {}
					}
					definition={ definition as IdentityFieldDefinition }
					onChange={
						onChange as (
							blockSlug: string,
							nextData: Record< string, IdentityFieldValue >
						) => void
					}
					readOnly={ readOnly }
					gameSlug={ gameSlug }
				/>
			);

		default:
			return (
				<div
					className="be-block-editor__unknown"
					data-block-slug={ blockSlug }
				>
					{ sprintf(
						/* translators: 1: section type slug, 2: block slug */
						__(
							'⚠ Unknown section type "%1$s" for block "%2$s" - cannot be edited here.',
							'beyond-elysium'
						),
						sectionType,
						blockSlug
					) }
				</div>
			);
	}
}

export default BlockEditor;
