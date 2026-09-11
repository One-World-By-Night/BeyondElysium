/**
 * BlockEditor dispatches to the correct editor component for a schema block's
 * section_type, wiring the shared blockSlug/data/onChange/readOnly props through to
 * whichever editor handles that type: trait_list, tiered_power, resource_pool, or
 * identity_field. Falls back to a read-only unknown-type warning for anything else.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { SectionType, BlockDefinition, TraitListDefinition, TieredPowerDefinition, ResourcePoolDefinition, IdentityFieldDefinition } from '../../types';
import type { ResourcePoolValue } from '../../lib/displayTemper';
import TraitListEditor, { type EditableTrait } from './TraitListEditor';
import TieredPowerEditor, { type EditableHeldPower } from './TieredPowerEditor';
import ResourcePoolEditor from './ResourcePoolEditor';
import IdentityFieldEditor, { type IdentityFieldValue } from './IdentityFieldEditor';
import './BlockEditor.css';

export interface BlockEditorProps {
	blockSlug: string;
	sectionType: SectionType;
	definition: BlockDefinition;
	data: unknown;
	onChange: ( blockSlug: string, nextData: unknown ) => void;
	costFor?: ( power: EditableHeldPower ) => number | null;
	readOnly?: boolean;
	/** The character's full sheet_data, used to resolve a pool's display name from another block's value. */
	sheetData?: Record<string, unknown>;
}

/**
 * Renders the editor for one schema block, chosen by its section_type: trait_list,
 * tiered_power, resource_pool, or identity_field. Passes through blockSlug, data,
 * onChange, and readOnly to whichever editor component handles that type.
 * An unrecognized section_type renders a read-only warning instead of a blank editor.
 */
export function BlockEditor( { blockSlug, sectionType, definition, data, onChange, costFor, readOnly, sheetData }: BlockEditorProps ) {
	switch ( sectionType ) {
		case 'trait_list':
			return (
				<TraitListEditor
					blockSlug={ blockSlug }
					data={ ( data as EditableTrait[] | undefined ) ?? [] }
					definition={ definition as TraitListDefinition }
					onChange={ onChange as ( blockSlug: string, nextData: EditableTrait[] ) => void }
					readOnly={ readOnly }
				/>
			);

		case 'tiered_power':
			return (
				<TieredPowerEditor
					blockSlug={ blockSlug }
					data={ ( data as EditableHeldPower[] | undefined ) ?? [] }
					definition={ definition as TieredPowerDefinition }
					onChange={ onChange as ( blockSlug: string, nextData: EditableHeldPower[] ) => void }
					costFor={ costFor }
					readOnly={ readOnly }
				/>
			);

		case 'resource_pool':
			return (
				<ResourcePoolEditor
					blockSlug={ blockSlug }
					data={ ( data as Record<string, ResourcePoolValue> | undefined ) ?? {} }
					definition={ definition as ResourcePoolDefinition }
					onChange={ onChange as ( blockSlug: string, nextData: Record<string, ResourcePoolValue> ) => void }
					readOnly={ readOnly }
					sheetData={ sheetData }
				/>
			);

		case 'identity_field':
			return (
				<IdentityFieldEditor
					blockSlug={ blockSlug }
					data={ ( data as Record<string, IdentityFieldValue> | undefined ) ?? {} }
					definition={ definition as IdentityFieldDefinition }
					onChange={ onChange as ( blockSlug: string, nextData: Record<string, IdentityFieldValue> ) => void }
					readOnly={ readOnly }
				/>
			);

		default:
			return (
				<div className="be-block-editor__unknown" data-block-slug={ blockSlug }>
					{ sprintf(
						/* translators: 1: section type slug, 2: block slug */
						__( '⚠ Unknown section type "%1$s" for block "%2$s" - cannot be edited here.', 'beyond-elysium' ),
						sectionType,
						blockSlug
					) }
				</div>
			);
	}
}

export default BlockEditor;
