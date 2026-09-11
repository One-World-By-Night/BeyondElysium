/**
 * Renders an identity_field schema block (Clan/Sect/Generation,
 * Tribe/Auspice/Breed, ...) as a definition list of label/value pairs,
 * in the order the block's definition specifies. Fields missing from
 * the character's data still render with an em-dash placeholder.
 */
import { __ } from '@wordpress/i18n';
import type { IdentityFieldDefinition } from '../../types';
import './IdentityFieldRenderer.css';

export interface IdentityFieldRendererProps {
	blockSlug: string;
	data: Record<string, string | number | null | undefined>;
	definition: IdentityFieldDefinition;
}

/**
 * Renders one label/value row per field in `definition.fields`, in
 * order. A field absent from the character's data still renders its
 * label with an em-dash value, so a half-filled sheet reads as a sheet
 * rather than a shorter one.
 */
export function IdentityFieldRenderer( { blockSlug, data, definition }: IdentityFieldRendererProps ) {
	return (
		<dl className="be-identity-fields" data-block-slug={ blockSlug }>
			{ definition.fields.map( ( field ) => {
				const value = data[ field.name ];
				const display = value === undefined || value === null || value === '' ? __( '—', 'beyond-elysium' ) : String( value );

				return (
					<div className="be-identity-fields__row" key={ field.name }>
						<dt>{ field.name }</dt>
						<dd>{ display }</dd>
					</div>
				);
			} ) }
		</dl>
	);
}

export default IdentityFieldRenderer;
