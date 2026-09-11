/**
 * IdentityFieldEditor renders the editable controls for an identity_field block -
 * the character's named single-value fields such as Clan, Nature, or Generation.
 * Each field in the block definition renders as a select, multiselect, number,
 * textarea, or plain text input depending on its field_type.
 */
import { useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { IdentityField, IdentityFieldDefinition } from '../../types';
import SearchableSelect from '../shared/SearchableSelect';
import './IdentityFieldEditor.css';

export type IdentityFieldValue = string | number | string[] | null | undefined;

export interface IdentityFieldEditorProps {
	blockSlug: string;
	data: Record<string, IdentityFieldValue>;
	definition: IdentityFieldDefinition;
	onChange: ( blockSlug: string, nextData: Record<string, IdentityFieldValue> ) => void;
	readOnly?: boolean;
}

/** Returns a field's resolved list of selectable options, or an empty array if none. */
function resolveOptions( field: IdentityField, _definition: IdentityFieldDefinition ): string[] {
	return field.options ?? [];
}

/**
 * Renders one input per field in an identity_field block's definition: a checkbox
 * group for multiselect, a searchable or plain dropdown for select, or a number,
 * textarea, or text input otherwise. Reports every field change through onChange;
 * it never recalculates derived values itself.
 */
export function IdentityFieldEditor( { blockSlug, data, definition, onChange, readOnly }: IdentityFieldEditorProps ) {
	const baseId = useId();
	const setField = ( name: string, value: IdentityFieldValue ) => {
		onChange( blockSlug, { ...data, [ name ]: value } );
	};

	return (
		<div className="be-identity-field-editor" data-block-slug={ blockSlug }>
			{ definition.fields.map( ( field, index ) => {
				const value = data[ field.name ];
				const fieldId = `${ baseId }-${ index }`;

				if ( field.field_type === 'multiselect' ) {
					const options = resolveOptions( field, definition );
					const selected = Array.isArray( value ) ? value : [];
					const limit = field.max_selections ?? options.length;

					const toggle = ( option: string ) => {
						const isSelected = selected.includes( option );
						if ( isSelected ) {
							setField( field.name, selected.filter( ( o ) => o !== option ) );
						} else if ( selected.length < limit ) {
							setField( field.name, [ ...selected, option ] );
						}
					};

					return (
						<div className="be-identity-field-editor__row" key={ field.name }>
							<span className="be-identity-field-editor__label" id={ fieldId }>{ field.name }</span>
							<div className="be-identity-field-editor__multiselect" role="group" aria-labelledby={ fieldId }>
								{ options.map( ( option ) => (
									<label key={ option } className="be-identity-field-editor__checkbox">
										<input
											type="checkbox"
											checked={ selected.includes( option ) }
											disabled={ readOnly || ( ! selected.includes( option ) && selected.length >= limit ) }
											onChange={ () => toggle( option ) }
										/>
										{ option }
									</label>
								) ) }
							</div>
							<span className="be-identity-field-editor__hint">
								{ sprintf(
									/* translators: 1: number of options selected, 2: maximum number selectable */
									__( '%1$d / %2$d selected', 'beyond-elysium' ),
									selected.length,
									limit
								) }
							</span>
						</div>
					);
				}

				if ( field.field_type === 'select' ) {
					const options = resolveOptions( field, definition );

					// An allow_custom select accepts free text in addition to the option list.
					if ( field.allow_custom ) {
						return (
							<div className="be-identity-field-editor__row" key={ field.name }>
								<label className="be-identity-field-editor__label" htmlFor={ fieldId }>{ field.name }</label>
								<SearchableSelect
									id={ fieldId }
									options={ options }
									value={ typeof value === 'string' ? value : '' }
									allowCustom
									disabled={ readOnly }
									onChange={ ( next ) => setField( field.name, next ) }
								/>
							</div>
						);
					}

					return (
						<div className="be-identity-field-editor__row" key={ field.name }>
							<label className="be-identity-field-editor__label" htmlFor={ fieldId }>{ field.name }</label>
							<select
								id={ fieldId }
								value={ typeof value === 'string' ? value : '' }
								disabled={ readOnly }
								onChange={ ( e ) => setField( field.name, e.target.value ) }
							>
								<option value="">{ __( '—', 'beyond-elysium' ) }</option>
								{ options.map( ( option ) => (
									<option key={ option } value={ option }>
										{ option }
									</option>
								) ) }
							</select>
						</div>
					);
				}

				if ( field.field_type === 'number' ) {
					return (
						<div className="be-identity-field-editor__row" key={ field.name }>
							<label className="be-identity-field-editor__label" htmlFor={ fieldId }>{ field.name }</label>
							<input
								id={ fieldId }
								type="number"
								value={ typeof value === 'number' ? value : '' }
								min={ field.min }
								max={ field.max }
								disabled={ readOnly }
								onChange={ ( e ) => setField( field.name, e.target.value === '' ? null : Number( e.target.value ) ) }
							/>
						</div>
					);
				}

				if ( field.field_type === 'textarea' ) {
					return (
						<div className="be-identity-field-editor__row" key={ field.name }>
							<label className="be-identity-field-editor__label" htmlFor={ fieldId }>{ field.name }</label>
							<textarea
								id={ fieldId }
								value={ typeof value === 'string' ? value : '' }
								disabled={ readOnly }
								onChange={ ( e ) => setField( field.name, e.target.value ) }
							/>
						</div>
					);
				}

				return (
					<div className="be-identity-field-editor__row" key={ field.name }>
						<label className="be-identity-field-editor__label" htmlFor={ fieldId }>{ field.name }</label>
						<input
							id={ fieldId }
							type="text"
							value={ typeof value === 'string' ? value : '' }
							disabled={ readOnly }
							onChange={ ( e ) => setField( field.name, e.target.value ) }
						/>
					</div>
				);
			} ) }
		</div>
	);
}

export default IdentityFieldEditor;
