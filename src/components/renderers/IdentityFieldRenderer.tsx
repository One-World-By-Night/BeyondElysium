/**
 * Renders an identity_field schema block (Clan/Sect/Generation, Tribe/Auspice/Breed,...) as a definition list of
 * label/value pairs, in the order the block's definition specifies.
 */
import type { IdentityFieldDefinition } from '../../types';
import {
	filledIdentityFields,
	identityValueText,
} from '../../lib/identityValue';
import {
	localizedFieldLabel,
	localizedIdentityValue,
} from '../../lib/localizeName';
import './IdentityFieldRenderer.css';

export interface IdentityFieldRendererProps {
	blockSlug: string;
	/**
	 * A multiselect field holds a list of its choices.
	 */
	data: Record< string, string | number | string[] | null | undefined >;
	definition: IdentityFieldDefinition;
}

/**
 * Renders one label/value row per field in `definition.fields` that holds a value, in order.
 */
export function IdentityFieldRenderer( {
	blockSlug,
	data,
	definition,
}: IdentityFieldRendererProps ) {
	return (
		<dl className="be-identity-fields" data-block-slug={ blockSlug }>
			{ filledIdentityFields( definition.fields, data ).map(
				( field ) => {
					const value = data[ field.name ];
					const display =
						identityValueText(
							localizedIdentityValue( field, value )
						) ?? '';

					// A textarea field is rich text and has to render as markup or the reader sees escaped tags.
					const isRich =
						field.field_type === 'textarea' &&
						typeof value === 'string' &&
						value !== '';

					return (
						<div
							className="be-identity-fields__row"
							key={ field.name }
						>
							<dt>{ localizedFieldLabel( field ) }</dt>
							{ isRich ? (
								<dd
									className="be-identity-fields__prose"
									dangerouslySetInnerHTML={ {
										__html: value as string,
									} }
								/>
							) : (
								<dd>{ display }</dd>
							) }
						</div>
					);
				}
			) }
		</dl>
	);
}

export default IdentityFieldRenderer;
