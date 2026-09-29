/**
 * ST tool for managing an entity's connections to other characters, plots, world objects, or freeform tags.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import { SearchableSelect } from '../shared/SearchableSelect';
import { groupCatalogItems } from '../../lib/catalogGroups';
import { pickerNames } from '../../lib/pickerNames';
import { otherEnd } from '../../lib/connectionEnds';
import { idOf } from '../../lib/ids';
import type { Character } from '../../types/character';
import type { Connection, EntityType, Plot } from '../../types/plot';
import type { ObjectType, WorldObject } from '../../types/world';
import './ConnectionManager.css';

export interface ConnectionManagerProps {
	gameSlug: string;
	/**
	 * The entity this manager is attached.
	 */
	entityType: EntityType;
	entityId: number;
	/**
	 * Narrows the world-object picker to one object type (e.g. "item" on a character sheet's item-connection affordance).
	 */
	objectTypeFilter?: ObjectType;
}

/**
 * The connection target kind selectable in the form.
 */
type PickMode = 'character' | 'external' | 'plot' | 'world_object' | 'tag';

const PICK_MODES: { value: PickMode; label: string }[] = [
	{
		value: 'character',
		label: __( 'Character in this chronicle', 'beyond-elysium' ),
	},
	{
		value: 'external',
		label: __( 'External (name only)', 'beyond-elysium' ),
	},
	{ value: 'plot', label: __( 'Plot', 'beyond-elysium' ) },
	{ value: 'world_object', label: __( 'World object', 'beyond-elysium' ) },
	{ value: 'tag', label: __( 'Tag', 'beyond-elysium' ) },
];

/**
 * What each searchable pick mode's box says before anything is typed.
 */
const SEARCH_PLACEHOLDERS: Partial< Record< PickMode, string > > = {
	character: __( 'Search characters…', 'beyond-elysium' ),
	plot: __( 'Search plots…', 'beyond-elysium' ),
	world_object: __( 'Search world objects…', 'beyond-elysium' ),
};

/**
 * Lets an ST add and remove connections between one entity and characters, plots, world objects, or freeform tags.
 */
export function ConnectionManager( {
	gameSlug,
	entityType,
	entityId,
	objectTypeFilter,
}: ConnectionManagerProps ) {
	const [ items, setItems ] = useState< Connection[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ plots, setPlots ] = useState< Plot[] >( [] );
	const [ worldObjects, setWorldObjects ] = useState< WorldObject[] >( [] );

	const [ mode, setMode ] = useState< PickMode >( 'character' );
	const [ targetId, setTargetId ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	// Counts form resets; the search box remounts empty on each.
	const [ formRound, setFormRound ] = useState( 0 );
	const [ externalName, setExternalName ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ notes, setNotes ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );

	/**
	 * Fetches the current list of connections for this entity from the API and stores the result.
	 */
	function load() {
		setLoading( true );
		setError( null );
		api.connections( gameSlug )
			.forEntity( entityType, entityId )
			.then( ( result ) => {
				setItems( result );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load connections.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, entityType, entityId ] );

	// Loaded once per game; backs both the pickers and the connections list's id-to-name resolution.
	useEffect( () => {
		// Every page of player characters and of NPCs, each character once.
		Promise.all( [
			everyPage( ( page ) =>
				api
					.characters( gameSlug )
					.listPaginated( { page, per_page: 100 } )
			),
			everyPage( ( page ) =>
				api
					.characters( gameSlug )
					.listPaginated( { page, per_page: 100, is_npc: true } )
			),
		] )
			.then( ( lists ) =>
				setCharacters(
					Array.from(
						new Map(
							lists.flat().map( ( c ) => [ c.id, c ] )
						).values()
					)
				)
			)
			.catch( () => setCharacters( [] ) );
		everyPage( ( page ) =>
			api.plots( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setPlots )
			.catch( () => setPlots( [] ) );
		everyPage( ( page ) =>
			api.worldObjects( gameSlug ).listPaginated( {
				page,
				per_page: 100,
				...( objectTypeFilter
					? { object_type: objectTypeFilter }
					: {} ),
			} )
		)
			.then( setWorldObjects )
			.catch( () => setWorldObjects( [] ) );
	}, [ gameSlug, objectTypeFilter ] );

	/**
	 * Resolves a display name for a connection's target entity, given its type and id.
	 */
	function resolveName( type: EntityType, id: number | null ): string {
		if ( id === null ) {
			return '';
		}
		if ( type === 'character' ) {
			return (
				characters.find( ( c ) => idOf( c.id ) === id )?.name ??
				sprintf(
					/* translators: %d: the character's numeric id, shown when its name can't be resolved */
					__( 'character #%d', 'beyond-elysium' ),
					id
				)
			);
		}
		if ( type === 'plot' ) {
			return (
				plots.find( ( p ) => idOf( p.id ) === id )?.title ??
				sprintf(
					/* translators: %d: the plot's numeric id, shown when its title can't be resolved */
					__( 'plot #%d', 'beyond-elysium' ),
					id
				)
			);
		}
		if ( type === 'world_object' ) {
			return (
				worldObjects.find( ( w ) => idOf( w.id ) === id )?.name ??
				sprintf(
					/* translators: %d: the world object's numeric id, shown when its name can't be resolved */
					__( 'world object #%d', 'beyond-elysium' ),
					id
				)
			);
		}
		return `#${ id }`;
	}

	function resetForm() {
		setTargetId( '' );
		setQuery( '' );
		setFormRound( ( round ) => round + 1 );
		setExternalName( '' );
		setLabel( '' );
		setNotes( '' );
	}

	/**
	 * Submits the new-connection form.
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
				label: isExternal ? externalName.trim() : label || undefined,
				notes: notes || undefined,
			} );
			resetForm();
			load();
		} catch {
			setError(
				__( 'Failed to create this connection.', 'beyond-elysium' )
			);
		} finally {
			setSubmitting( false );
		}
	}

	/**
	 * Deletes one connection by id via the API.
	 */
	async function remove( id: number ) {
		try {
			await api.connections( gameSlug ).delete( id );
			load();
		} catch {
			setError(
				__( 'Failed to remove this connection.', 'beyond-elysium' )
			);
		}
	}

	const characterNames = useMemo(
		() =>
			pickerNames( characters, ( c ) =>
				c.is_npc
					? sprintf(
							/* translators: %s: a non-player character's name */
							__( '%s (NPC)', 'beyond-elysium' ),
							c.name
						)
					: c.name
			),
		[ characters ]
	);
	const plotNames = useMemo(
		() => pickerNames( plots, ( p ) => p.title ),
		[ plots ]
	);
	const worldObjectDisplayToId = useMemo(
		() => pickerNames( worldObjects, ( w ) => w.name ),
		[ worldObjects ]
	);
	const searchNames =
		mode === 'character'
			? characterNames
			: mode === 'plot'
				? plotNames
				: worldObjectDisplayToId;

	const worldObjectGroups = useMemo( () => {
		const byId = new Map( worldObjects.map( ( w ) => [ w.id, w ] ) );
		const types = new Set( worldObjects.map( ( w ) => w.object_type ) );
		if ( types.size < 2 ) {
			return [];
		}
		return groupCatalogItems(
			Array.from( worldObjectDisplayToId, ( [ display, id ] ) => ( {
				name: display,
				group: byId.get( id )?.object_type,
			} ) )
		);
	}, [ worldObjects, worldObjectDisplayToId ] );

	return (
		<div className="be-connection-manager">
			<form
				className="be-connection-manager__form"
				onSubmit={ addConnection }
			>
				<select
					value={ mode }
					onChange={ ( e ) => {
						setMode( e.target.value as PickMode );
						setTargetId( '' );
						setQuery( '' );
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
					<SearchableSelect
						key={ `${ mode }-${ formRound }` }
						{ ...( mode === 'world_object' &&
						worldObjectGroups.length > 0
							? { groups: worldObjectGroups }
							: { options: Array.from( searchNames.keys() ) } ) }
						value={ query }
						onChange={ ( display ) => {
							setQuery( display );
							const id = searchNames.get( display );
							setTargetId( id !== undefined ? String( id ) : '' );
						} }
						placeholder={ SEARCH_PLACEHOLDERS[ mode ] }
						ariaLabel={ SEARCH_PLACEHOLDERS[ mode ] }
					/>
				) }

				<input
					type="text"
					value={ label }
					onChange={ ( e ) => setLabel( e.target.value ) }
					placeholder={
						mode === 'external'
							? __(
									'Role (e.g. visiting player, contact)',
									'beyond-elysium'
								)
							: __(
									'Label (e.g. sister, involved in)',
									'beyond-elysium'
								)
					}
					disabled={ mode === 'external' }
					title={
						mode === 'external'
							? __(
									'Set via the name field above for an external connection',
									'beyond-elysium'
								)
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
					disabled={
						submitting ||
						( mode === 'external'
							? ! externalName.trim()
							: mode !== 'tag' && ! targetId )
					}
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
						const other = otherEnd(
							connection,
							entityType,
							entityId
						);
						const isExternalTag = other.type === 'tag';
						const name = isExternalTag
							? other.label || __( 'external', 'beyond-elysium' )
							: resolveName( other.type, other.id );
						return (
							<li key={ connection.id }>
								<div className="be-connection-manager__row">
									<span className="be-connection-manager__other">
										{ name }
										{ ! isExternalTag && (
											<span className="be-st-badge">
												{ other.type }
											</span>
										) }
									</span>
									{ ! isExternalTag && connection.label && (
										<span className="be-connection-manager__label">
											{ connection.label }
										</span>
									) }
									<button
										type="button"
										className="be-st-button be-st-button--quiet"
										onClick={ () =>
											remove( connection.id )
										}
									>
										{ __( 'Remove', 'beyond-elysium' ) }
									</button>
								</div>
								{ connection.notes && (
									<p className="be-connection-manager__notes">
										{ connection.notes }
									</p>
								) }
							</li>
						);
					} ) }
				</ul>
			) }
		</div>
	);
}

export default ConnectionManager;
