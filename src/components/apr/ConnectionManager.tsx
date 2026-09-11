/**
 * ST tool for managing an entity's connections to other characters, plots, world objects,
 * or freeform tags. Renders a picker form for adding a new connection and a list of the
 * entity's existing connections with remove controls. Generic across entity types, so the
 * same component mounts from a plot, a world object card, and a character sheet.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { Character } from '../../types/character';
import type { Connection, EntityType, Plot } from '../../types/plot';
import type { WorldObject } from '../../types/world';
import './ConnectionManager.css';

export interface ConnectionManagerProps {
	gameSlug: string;
	/** The entity this manager is attached to - e.g. a plot's own connections tab. */
	entityType: EntityType;
	entityId: number;
}

/** The connection target kind selectable in the form; "external" maps to a tag connection under the hood. */
type PickMode = 'character' | 'external' | 'plot' | 'world_object' | 'tag';

const PICK_MODES: { value: PickMode; label: string }[] = [
	{ value: 'character', label: __( 'Character in this chronicle', 'beyond-elysium' ) },
	{ value: 'external', label: __( 'External (name only)', 'beyond-elysium' ) },
	{ value: 'plot', label: __( 'Plot', 'beyond-elysium' ) },
	{ value: 'world_object', label: __( 'World object', 'beyond-elysium' ) },
	{ value: 'tag', label: __( 'Tag', 'beyond-elysium' ) },
];

/**
 * Lets an ST add and remove connections between one entity and characters, plots, world
 * objects, or freeform tags. Offers a name-based picker for in-system entities and a
 * free-text "External" mode for people with no record in this system, and lists the
 * entity's existing connections with a remove control for each.
 */
export function ConnectionManager( { gameSlug, entityType, entityId }: ConnectionManagerProps ) {
	const [ items, setItems ] = useState<Connection[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	const [ characters, setCharacters ] = useState<Character[]>( [] );
	const [ plots, setPlots ] = useState<Plot[]>( [] );
	const [ worldObjects, setWorldObjects ] = useState<WorldObject[]>( [] );

	const [ mode, setMode ] = useState<PickMode>( 'character' );
	const [ targetId, setTargetId ] = useState( '' );
	const [ externalName, setExternalName ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ notes, setNotes ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );

	/**
	 * Fetches the current list of connections for this entity from the API and stores
	 * the result. Sets an error message instead if the request fails, and is re-run
	 * after every create or delete so the list stays current.
	 */
	function load() {
		setLoading( true );
		setError( null );
		api
			.connections( gameSlug )
			.forEntity( entityType, entityId )
			.then( ( result ) => {
				setItems( result );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load connections.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, entityType, entityId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Loaded once per game; backs both the picker dropdowns and the connections list's id-to-name resolution.
	useEffect( () => {
		api.characters( gameSlug ).list( { per_page: 100 } ).then( setCharacters ).catch( () => setCharacters( [] ) );
		api.plots( gameSlug ).list( { per_page: 100 } ).then( setPlots ).catch( () => setPlots( [] ) );
		api.worldObjects( gameSlug ).list( { per_page: 100 } ).then( setWorldObjects ).catch( () => setWorldObjects( [] ) );
	}, [ gameSlug ] );

	/**
	 * Resolves a display name for a connection's target entity, given its type and id.
	 * Looks up the id in the already-loaded characters, plots, or world objects list and
	 * falls back to a generic "#id" label when the entity is not found in that list.
	 */
	function resolveName( type: EntityType, id: number | null ): string {
		if ( id === null ) {
			return '';
		}
		if ( type === 'character' ) {
			return characters.find( ( c ) => c.id === id )?.name ?? sprintf( __( 'character #%d', 'beyond-elysium' ), id );
		}
		if ( type === 'plot' ) {
			return plots.find( ( p ) => p.id === id )?.title ?? sprintf( __( 'plot #%d', 'beyond-elysium' ), id );
		}
		if ( type === 'world_object' ) {
			return (
				worldObjects.find( ( w ) => w.id === id )?.name ?? sprintf( __( 'world object #%d', 'beyond-elysium' ), id )
			);
		}
		return `#${ id }`;
	}

	function resetForm() {
		setTargetId( '' );
		setExternalName( '' );
		setLabel( '' );
		setNotes( '' );
	}

	/**
	 * Submits the new-connection form. Maps the selected pick mode to a wire-level entity
	 * type, treating "external" as a tag connection with the typed name as its label, then
	 * creates the connection via the API, resets the form, and reloads the list.
	 */
	async function addConnection( e: React.FormEvent ) {
		e.preventDefault();

		const isExternal = mode === 'external';
		const wireType: EntityType = isExternal ? 'tag' : mode;

		if ( isExternal && ! externalName.trim() ) {
			return;
		}
		if ( ! isExternal && wireType !== 'tag' && ! targetId ) {
			return;
		}

		setSubmitting( true );
		setError( null );
		try {
			await api.connections( gameSlug ).create( {
				source_type: entityType,
				source_id: entityId,
				target_type: wireType,
				target_id: wireType === 'tag' ? undefined : Number( targetId ),
				// An external connection's typed name is stored as its label.
				label: isExternal ? externalName.trim() : ( label || undefined ),
				notes: notes || undefined,
			} );
			resetForm();
			load();
		} catch {
			setError( __( 'Failed to create this connection.', 'beyond-elysium' ) );
		} finally {
			setSubmitting( false );
		}
	}

	/**
	 * Deletes one connection by id via the API. Reloads the connections list on success
	 * so the removed item disappears immediately, or sets an error message if the
	 * delete request fails.
	 */
	async function remove( id: number ) {
		try {
			await api.connections( gameSlug ).delete( id );
			load();
		} catch {
			setError( __( 'Failed to remove this connection.', 'beyond-elysium' ) );
		}
	}

	/**
	 * Returns the other end of a connection relative to this component's own entity.
	 * A connection stores a source and a target; this checks which side matches the
	 * current entity and returns the opposite side's type, id, and label.
	 */
	function otherEnd( connection: Connection ): { type: EntityType; id: number | null; label: string | null } {
		const isSource = connection.source_type === entityType && connection.source_id === entityId;
		return isSource
			? { type: connection.target_type, id: connection.target_id, label: connection.label }
			: { type: connection.source_type, id: connection.source_id, label: connection.label };
	}

	const pickerList: { value: string; text: string }[] =
		mode === 'character'
			? characters.map( ( c ) => ( { value: String( c.id ), text: c.name } ) )
			: mode === 'plot'
			? plots.map( ( p ) => ( { value: String( p.id ), text: p.title } ) )
			: mode === 'world_object'
			? worldObjects.map( ( w ) => ( { value: String( w.id ), text: w.name } ) )
			: [];

	return (
		<div className="be-connection-manager">
			<form className="be-connection-manager__form" onSubmit={ addConnection }>
				<select
					value={ mode }
					onChange={ ( e ) => {
						setMode( e.target.value as PickMode );
						setTargetId( '' );
					} }
				>
					{ PICK_MODES.map( ( m ) => (
						<option key={ m.value } value={ m.value }>
							{ m.label }
						</option>
					) ) }
				</select>

				{ mode === 'external' ? (
					<input
						type="text"
						value={ externalName }
						onChange={ ( e ) => setExternalName( e.target.value ) }
						placeholder={ __( 'Name…', 'beyond-elysium' ) }
					/>
				) : mode === 'tag' ? null : (
					<select value={ targetId } onChange={ ( e ) => setTargetId( e.target.value ) }>
						<option value="">{ __( 'Select…', 'beyond-elysium' ) }</option>
						{ pickerList.map( ( o ) => (
							<option key={ o.value } value={ o.value }>
								{ o.text }
							</option>
						) ) }
					</select>
				) }

				<input
					type="text"
					value={ label }
					onChange={ ( e ) => setLabel( e.target.value ) }
					placeholder={
						mode === 'external'
							? __( 'Role (e.g. visiting player, contact)', 'beyond-elysium' )
							: __( 'Label (e.g. sister, involved in)', 'beyond-elysium' )
					}
					disabled={ mode === 'external' }
					title={
						mode === 'external'
							? __( 'Set via the name field above for an external connection', 'beyond-elysium' )
							: undefined
					}
				/>
				<input
					type="text"
					value={ notes }
					onChange={ ( e ) => setNotes( e.target.value ) }
					placeholder={ __( 'Notes (optional)', 'beyond-elysium' ) }
				/>
				<button
					type="submit"
					className="be-st-button"
					disabled={ submitting || ( mode === 'external' ? ! externalName.trim() : mode !== 'tag' && ! targetId ) }
				>
					{ __( 'Add connection', 'beyond-elysium' ) }
				</button>
			</form>

			{ error && (
				<div className="be-connection-manager__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : items.length === 0 ? (
				<p>{ __( 'No connections yet.', 'beyond-elysium' ) }</p>
			) : (
				<ul className="be-connection-manager__items">
					{ items.map( ( connection ) => {
						const other = otherEnd( connection );
						const isExternalTag = other.type === 'tag';
						const name = isExternalTag
							? ( other.label || __( 'external', 'beyond-elysium' ) )
							: resolveName( other.type, other.id );
						return (
							<li key={ connection.id }>
								<span className="be-connection-manager__other">
									{ name }
									{ ! isExternalTag && <span className="be-st-badge">{ other.type }</span> }
								</span>
								{ ! isExternalTag && connection.label && (
									<span className="be-connection-manager__label">{ connection.label }</span>
								) }
								<button type="button" className="be-st-button be-st-button--quiet" onClick={ () => remove( connection.id ) }>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							</li>
						);
					} ) }
				</ul>
			) }
		</div>
	);
}

export default ConnectionManager;
