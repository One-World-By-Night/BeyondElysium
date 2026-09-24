/**
 * Create/edit form for a world object (item, location, or rote).
 */
import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HtmlEditor from '../shared/HtmlEditor';
import AudiencePicker from '../shared/AudiencePicker';
import LocationLinksPanel from './LocationLinksPanel';
import SecretsPanel from '../shared/SecretsPanel';
import { WORLD_OBJECT_SCHEMAS } from '../../types/world';
import type { ObjectType, WorldObject } from '../../types/world';
import type { AudienceValue, AudienceRules } from '../../types/plot';
import './WorldObjectEditor.css';

export interface WorldObjectEditorProps {
	gameSlug: string;
	/**
	 * One of the catalog types.
	 */
	objectType: ObjectType;
	/**
	 * When set, edits this object.
	 */
	object?: WorldObject | null;
	/**
	 * When set (and `object` is not), pre-fills a fresh create form from an existing object's fields.
	 */
	duplicateFrom?: WorldObject | null;
	onSaved?: ( object: WorldObject ) => void;
	onCancel?: () => void;
}

type PropertyValue =
	| string
	| number
	| Array< { name: string; count?: number; note?: string } >;

/**
 * Renders a create/edit form generated from the object type's property schema (`WORLD_OBJECT_SCHEMAS`): name,
 * description, rarity, cost and limitations fields, plus one field per schema property.
 */
export function WorldObjectEditor( {
	gameSlug,
	objectType,
	object,
	duplicateFrom,
	onSaved,
	onCancel,
}: WorldObjectEditorProps ) {
	const schema = WORLD_OBJECT_SCHEMAS[ objectType ] ?? {};
	const source = object ?? duplicateFrom;
	const isDuplicating = ! object && !! duplicateFrom;

	const [ name, setName ] = useState(
		isDuplicating
			? sprintf(
					/* translators: %s: name of the world object being duplicated */
					__( 'Copy of %s', 'beyond-elysium' ),
					source?.name ?? ''
			  )
			: source?.name ?? ''
	);
	const descriptionDraft = useRef( source?.description ?? '' );
	const [ rarity, setRarity ] = useState( source?.rarity ?? '' );
	const [ cost, setCost ] = useState( source?.cost ?? '' );
	const limitationsDraft = useRef( source?.limitations ?? '' );
	// Duplicating never carries the source's own database id.
	const editorKey = object?.id ?? 'new';
	const [ properties, setProperties ] = useState<
		Record< string, PropertyValue >
	>( ( source?.properties as Record< string, PropertyValue > ) ?? {} );
	// Duplicating never carries the source's own audience forward either.
	const [ audience, setAudience ] = useState< AudienceValue >(
		isDuplicating ? 'everyone' : object?.audience ?? 'everyone'
	);
	const [ audienceRules, setAudienceRules ] =
		useState< AudienceRules | null >(
			isDuplicating ? null : object?.audience_rules ?? null
		);
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	// "Inside of" - locations only.
	const [ parentId, setParentId ] = useState< string >(
		object?.parent_id ? String( object.parent_id ) : ''
	);
	const [ otherLocations, setOtherLocations ] = useState<
		{ id: number; name: string }[]
	>( [] );

	useEffect( () => {
		if ( objectType !== 'location' ) {
			return;
		}
		api.worldObjects( gameSlug )
			.list( { object_type: 'location', per_page: 500 } )
			.then( ( items ) =>
				setOtherLocations(
					items
						.filter( ( l ) => l.id !== object?.id )
						.map( ( l ) => ( { id: l.id, name: l.name } ) )
				)
			)
			.catch( () => setOtherLocations( [] ) );
	}, [ gameSlug, objectType, object?.id ] );

	function setProperty( key: string, value: PropertyValue ) {
		setProperties( ( prev ) => ( { ...prev, [ key ]: value } ) );
	}

	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! name.trim() ) {
			setError( __( 'Name is required.', 'beyond-elysium' ) );
			return;
		}

		setSaving( true );
		setError( null );

		const showsAudience =
			objectType === 'item' || objectType === 'location';
		const payload = {
			name: name.trim(),
			description: descriptionDraft.current.trim() || undefined,
			rarity: rarity || undefined,
			cost: cost || undefined,
			limitations: limitationsDraft.current.trim() || undefined,
			properties,
			...( showsAudience && {
				audience,
				audience_rules:
					audience === 'restricted' ? audienceRules : null,
			} ),
			...( objectType === 'location' && {
				parent_id: parentId ? Number( parentId ) : null,
			} ),
		};

		try {
			const saved = object
				? await api
						.worldObjects( gameSlug )
						.update( object.id, payload )
				: await api
						.worldObjects( gameSlug )
						.create( { object_type: objectType, ...payload } );
			onSaved?.( saved );
		} catch {
			setError(
				__( 'Failed to save this world object.', 'beyond-elysium' )
			);
		} finally {
			setSaving( false );
		}
	}

	return (
		<form className="be-world-editor" onSubmit={ submit }>
			{ error && (
				<div className="be-world-editor__error" role="alert">
					{ error }
				</div>
			) }

			{ object?.based_on && (
				<p className="be-world-editor__based-on">
					{ sprintf(
						/* translators: %s: the source item's name */
						__( 'Based on %s', 'beyond-elysium' ),
						object.based_on.name
					) }
				</p>
			) }

			<label className="be-world-editor__field">
				<span>{ __( 'Name', 'beyond-elysium' ) }</span>
				<input
					type="text"
					maxLength={ 255 }
					value={ name }
					onChange={ ( e ) => setName( e.target.value ) }
					required
				/>
			</label>

			<div className="be-world-editor__field">
				<span>{ __( 'Description', 'beyond-elysium' ) }</span>
				<HtmlEditor
					id={ `be-world-object-description-${ editorKey }` }
					defaultValue={ descriptionDraft.current }
					onChange={ ( html ) => {
						descriptionDraft.current = html;
					} }
					aiAssist={ {
						capability: 'be_manage_world_objects',
						fieldContext: 'world_object_description',
						gameSlug,
					} }
				/>
			</div>

			<div className="be-world-editor__row">
				<label className="be-world-editor__field">
					<span>{ __( 'Rarity', 'beyond-elysium' ) }</span>
					<input
						type="text"
						maxLength={ 20 }
						value={ rarity }
						onChange={ ( e ) => setRarity( e.target.value ) }
					/>
				</label>
				<label className="be-world-editor__field">
					<span>{ __( 'Cost', 'beyond-elysium' ) }</span>
					<input
						type="text"
						maxLength={ 100 }
						value={ cost }
						onChange={ ( e ) => setCost( e.target.value ) }
					/>
				</label>
			</div>

			<div className="be-world-editor__field">
				<span>{ __( 'Limitations', 'beyond-elysium' ) }</span>
				<HtmlEditor
					id={ `be-world-object-limitations-${ editorKey }` }
					defaultValue={ limitationsDraft.current }
					onChange={ ( html ) => {
						limitationsDraft.current = html;
					} }
					aiAssist={ {
						capability: 'be_manage_world_objects',
						fieldContext: 'world_object_limitations',
						gameSlug,
					} }
				/>
			</div>

			{ ( objectType === 'item' || objectType === 'location' ) && (
				<div className="be-world-editor__field">
					<span>{ __( 'Who can see this', 'beyond-elysium' ) }</span>
					<AudiencePicker
						gameSlug={ gameSlug }
						audience={ audience }
						audienceRules={ audienceRules }
						onChange={ ( nextAudience, nextRules ) => {
							setAudience( nextAudience );
							setAudienceRules( nextRules );
						} }
					/>
				</div>
			) }

			{ objectType === 'location' && (
				<label className="be-world-editor__field">
					<span>{ __( 'Inside', 'beyond-elysium' ) }</span>
					<select
						value={ parentId }
						onChange={ ( e ) => setParentId( e.target.value ) }
					>
						<option value="">
							{ __( 'Nowhere (top-level)', 'beyond-elysium' ) }
						</option>
						{ otherLocations.map( ( l ) => (
							<option key={ l.id } value={ l.id }>
								{ l.name }
							</option>
						) ) }
					</select>
				</label>
			) }

			<h4>{ __( 'Properties', 'beyond-elysium' ) }</h4>
			{ Object.entries( schema ).map( ( [ key, type ] ) => (
				<PropertyField
					key={ key }
					fieldKey={ key }
					type={ type }
					value={ properties[ key ] }
					onChange={ ( value ) => setProperty( key, value ) }
					gameSlug={ gameSlug }
				/>
			) ) }

			{ objectType === 'location' && object && (
				<LocationLinksPanel
					gameSlug={ gameSlug }
					locationId={ object.id }
				/>
			) }

			{ ( objectType === 'item' || objectType === 'location' ) &&
				object && (
					<SecretsPanel
						gameSlug={ gameSlug }
						entityType={ objectType }
						entityId={ object.id }
					/>
				) }

			<div className="be-world-editor__actions">
				<button type="submit" disabled={ saving }>
					{ object
						? __( 'Save Changes', 'beyond-elysium' )
						: __( 'Create', 'beyond-elysium' ) }
				</button>
				{ onCancel && (
					<button
						type="button"
						onClick={ onCancel }
						disabled={ saving }
					>
						{ __( 'Cancel', 'beyond-elysium' ) }
					</button>
				) }
			</div>
		</form>
	);
}

/**
 * Renders one schema-driven property field: a repeatable trait-list editor, a textarea for long text, or a
 * text/number/date input.
 */
function PropertyField( {
	fieldKey,
	type,
	value,
	onChange,
	gameSlug,
}: {
	fieldKey: string;
	type: 'string' | 'text' | 'int' | 'date' | 'trait_list';
	value: PropertyValue | undefined;
	onChange: ( value: PropertyValue ) => void;
	gameSlug: string;
} ) {
	const fieldId = useId();
	const label = fieldKey
		.split( '_' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );

	if ( type === 'trait_list' ) {
		return (
			<div className="be-world-editor__field">
				<span id={ fieldId }>{ label }</span>
				<TraitListField
					entries={
						( value as Array< {
							name: string;
							count?: number;
							note?: string;
						} > ) ?? []
					}
					onChange={ onChange }
					labelId={ fieldId }
				/>
			</div>
		);
	}

	if ( type === 'text' ) {
		const textValue = ( value as string ) ?? '';
		return (
			<div className="be-world-editor__field">
				<span>{ label }</span>
				<HtmlEditor
					id={ `be-world-object-property-${ fieldKey }` }
					defaultValue={ textValue }
					onChange={ onChange }
					aiAssist={ {
						capability: 'be_manage_world_objects',
						fieldContext: 'world_object_property',
						gameSlug,
					} }
				/>
			</div>
		);
	}

	return (
		<label className="be-world-editor__field">
			<span>{ label }</span>
			<input
				type={
					type === 'int'
						? 'number'
						: type === 'date'
						? 'date'
						: 'text'
				}
				value={ ( value as string | number ) ?? '' }
				onChange={ ( e ) =>
					onChange(
						type === 'int'
							? Number( e.target.value )
							: e.target.value
					)
				}
			/>
		</label>
	);
}

/**
 * Repeatable-row editor for a trait-list property: each row has name, count and note inputs plus a remove button,
 * with a button to append a new blank row.
 */
function TraitListField( {
	entries,
	onChange,
	labelId,
}: {
	entries: Array< { name: string; count?: number; note?: string } >;
	onChange: (
		entries: Array< { name: string; count?: number; note?: string } >
	) => void;
	labelId: string;
} ) {
	function updateRow(
		index: number,
		patch: Partial< { name: string; count?: number; note?: string } >
	) {
		const next = [ ...entries ];
		next[ index ] = { ...next[ index ], ...patch };
		onChange( next );
	}

	function removeRow( index: number ) {
		onChange( entries.filter( ( _, i ) => i !== index ) );
	}

	function addRow() {
		onChange( [ ...entries, { name: '', count: 1 } ] );
	}

	return (
		<div className="be-world-editor__trait-list">
			{ entries.map( ( entry, index ) => (
				<div
					className="be-world-editor__trait-row"
					role="group"
					aria-labelledby={ labelId }
					key={ index }
				>
					<input
						type="text"
						placeholder={ __( 'Name', 'beyond-elysium' ) }
						aria-label={ __( 'Name', 'beyond-elysium' ) }
						value={ entry.name }
						onChange={ ( e ) =>
							updateRow( index, { name: e.target.value } )
						}
					/>
					<input
						type="number"
						placeholder={ __( 'Count', 'beyond-elysium' ) }
						aria-label={ __( 'Count', 'beyond-elysium' ) }
						value={ entry.count ?? 1 }
						onChange={ ( e ) =>
							updateRow( index, {
								count: Number( e.target.value ),
							} )
						}
					/>
					<input
						type="text"
						placeholder={ __( 'Note', 'beyond-elysium' ) }
						aria-label={ __( 'Note', 'beyond-elysium' ) }
						value={ entry.note ?? '' }
						onChange={ ( e ) =>
							updateRow( index, { note: e.target.value } )
						}
					/>
					<button type="button" onClick={ () => removeRow( index ) }>
						{ __( 'Remove', 'beyond-elysium' ) }
					</button>
				</div>
			) ) }
			<button type="button" onClick={ addRow }>
				{ __( 'Add', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default WorldObjectEditor;
