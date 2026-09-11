/**
 * Create/edit form for a world object (item, location, or rote). Builds
 * its fields from the object type's property schema, so scalar and
 * trait-list properties both render and save without component-specific
 * code per type. Submits a create or update request depending on
 * whether an existing object was passed in.
 */
import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { WORLD_OBJECT_SCHEMAS } from '../../types/world';
import type { ObjectType, WorldObject } from '../../types/world';
import './WorldObjectEditor.css';

export interface WorldObjectEditorProps {
	gameSlug: string;
	/** One of the catalog types - boons are created through BoonLedger, not here. */
	objectType: ObjectType;
	/** When set, edits this object instead of creating a new one. */
	object?: WorldObject | null;
	onSaved?: ( object: WorldObject ) => void;
	onCancel?: () => void;
}

type PropertyValue = string | number | Array<{ name: string; count?: number; note?: string }>;

/**
 * Renders a create/edit form generated from the object type's property
 * schema (`WORLD_OBJECT_SCHEMAS`): name, description, rarity, cost and
 * limitations fields, plus one field per schema property. Trait-list
 * properties get a free-text repeatable-row editor rather than a
 * catalog picker, since world-object trait lists have no fixed catalog.
 */
export function WorldObjectEditor( { gameSlug, objectType, object, onSaved, onCancel }: WorldObjectEditorProps ) {
	const schema = WORLD_OBJECT_SCHEMAS[ objectType ] ?? {};

	const [ name, setName ] = useState( object?.name ?? '' );
	const [ description, setDescription ] = useState( object?.description ?? '' );
	const [ rarity, setRarity ] = useState( object?.rarity ?? '' );
	const [ cost, setCost ] = useState( object?.cost ?? '' );
	const [ limitations, setLimitations ] = useState( object?.limitations ?? '' );
	const [ properties, setProperties ] = useState<Record<string, PropertyValue>>(
		( object?.properties as Record<string, PropertyValue> ) ?? {}
	);
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

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

		const payload = {
			name: name.trim(),
			description: description || undefined,
			rarity: rarity || undefined,
			cost: cost || undefined,
			limitations: limitations || undefined,
			properties,
		};

		try {
			const saved = object
				? await api.worldObjects( gameSlug ).update( object.id, payload )
				: await api.worldObjects( gameSlug ).create( { object_type: objectType, ...payload } );
			onSaved?.( saved );
		} catch {
			setError( __( 'Failed to save this world object.', 'beyond-elysium' ) );
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

			<label className="be-world-editor__field">
				<span>{ __( 'Name', 'beyond-elysium' ) }</span>
				<input type="text" value={ name } onChange={ ( e ) => setName( e.target.value ) } required />
			</label>

			<label className="be-world-editor__field">
				<span>{ __( 'Description', 'beyond-elysium' ) }</span>
				<textarea value={ description } onChange={ ( e ) => setDescription( e.target.value ) } />
			</label>

			<div className="be-world-editor__row">
				<label className="be-world-editor__field">
					<span>{ __( 'Rarity', 'beyond-elysium' ) }</span>
					<input type="text" value={ rarity } onChange={ ( e ) => setRarity( e.target.value ) } />
				</label>
				<label className="be-world-editor__field">
					<span>{ __( 'Cost', 'beyond-elysium' ) }</span>
					<input type="text" value={ cost } onChange={ ( e ) => setCost( e.target.value ) } />
				</label>
			</div>

			<label className="be-world-editor__field">
				<span>{ __( 'Limitations', 'beyond-elysium' ) }</span>
				<textarea value={ limitations } onChange={ ( e ) => setLimitations( e.target.value ) } />
			</label>

			<h4>{ __( 'Properties', 'beyond-elysium' ) }</h4>
			{ Object.entries( schema ).map( ( [ key, type ] ) => (
				<PropertyField
					key={ key }
					fieldKey={ key }
					type={ type }
					value={ properties[ key ] }
					onChange={ ( value ) => setProperty( key, value ) }
				/>
			) ) }

			<div className="be-world-editor__actions">
				<button type="submit" disabled={ saving }>
					{ object ? __( 'Save Changes', 'beyond-elysium' ) : __( 'Create', 'beyond-elysium' ) }
				</button>
				{ onCancel && (
					<button type="button" onClick={ onCancel } disabled={ saving }>
						{ __( 'Cancel', 'beyond-elysium' ) }
					</button>
				) }
			</div>
		</form>
	);
}

/**
 * Renders one schema-driven property field: a repeatable trait-list
 * editor, a textarea for long text, or a text/number/date input
 * otherwise. Derives its visible label from the property's key.
 */
function PropertyField( {
	fieldKey,
	type,
	value,
	onChange,
}: {
	fieldKey: string;
	type: 'string' | 'text' | 'int' | 'date' | 'trait_list';
	value: PropertyValue | undefined;
	onChange: ( value: PropertyValue ) => void;
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
					entries={ ( value as Array<{ name: string; count?: number; note?: string }> ) ?? [] }
					onChange={ onChange }
					labelId={ fieldId }
				/>
			</div>
		);
	}

	if ( type === 'text' ) {
		return (
			<label className="be-world-editor__field">
				<span>{ label }</span>
				<textarea value={ ( value as string ) ?? '' } onChange={ ( e ) => onChange( e.target.value ) } />
			</label>
		);
	}

	return (
		<label className="be-world-editor__field">
			<span>{ label }</span>
			<input
				type={ type === 'int' ? 'number' : type === 'date' ? 'date' : 'text' }
				value={ ( value as string | number ) ?? '' }
				onChange={ ( e ) => onChange( type === 'int' ? Number( e.target.value ) : e.target.value ) }
			/>
		</label>
	);
}

/**
 * Repeatable-row editor for a trait-list property: each row has name,
 * count and note inputs plus a remove button, with a button to append a
 * new blank row. Reports the full updated entry array on every change.
 */
function TraitListField( {
	entries,
	onChange,
	labelId,
}: {
	entries: Array<{ name: string; count?: number; note?: string }>;
	onChange: ( entries: Array<{ name: string; count?: number; note?: string }> ) => void;
	labelId: string;
} ) {
	function updateRow( index: number, patch: Partial<{ name: string; count?: number; note?: string }> ) {
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
				<div className="be-world-editor__trait-row" role="group" aria-labelledby={ labelId } key={ index }>
					<input
						type="text"
						placeholder={ __( 'Name', 'beyond-elysium' ) }
						aria-label={ __( 'Name', 'beyond-elysium' ) }
						value={ entry.name }
						onChange={ ( e ) => updateRow( index, { name: e.target.value } ) }
					/>
					<input
						type="number"
						placeholder={ __( 'Count', 'beyond-elysium' ) }
						aria-label={ __( 'Count', 'beyond-elysium' ) }
						value={ entry.count ?? 1 }
						onChange={ ( e ) => updateRow( index, { count: Number( e.target.value ) } ) }
					/>
					<input
						type="text"
						placeholder={ __( 'Note', 'beyond-elysium' ) }
						aria-label={ __( 'Note', 'beyond-elysium' ) }
						value={ entry.note ?? '' }
						onChange={ ( e ) => updateRow( index, { note: e.target.value } ) }
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
