/**
 * Top-level dashboard for world objects: combines the catalog list, a
 * detail view, and a create/edit form into one list-pane/detail-pane
 * layout. Owns which object is selected and which view (list, create,
 * edit) is active, and refreshes the list after a save.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { ObjectType, WorldObject } from '../../types/world';
import { WorldObjectList } from './WorldObjectList';
import { WorldObjectCard } from './WorldObjectCard';
import { WorldObjectEditor } from './WorldObjectEditor';
import './WorldObjectManager.css';

export interface WorldObjectManagerProps {
	gameSlug: string;
	defaultType?: ObjectType;
	/** Whether to show create/edit controls; the REST API enforces permissions regardless. */
	showEditor?: boolean;
}

type View = { mode: 'list' } | { mode: 'create'; type: ObjectType } | { mode: 'edit'; id: number };

/**
 * Renders the world object catalog in a list pane alongside a detail
 * pane that shows either the selected object, a create form, or an edit
 * form depending on the current view. Switches views and refreshes the
 * list after a create or edit is saved.
 */
export function WorldObjectManager( { gameSlug, defaultType, showEditor }: WorldObjectManagerProps ) {
	const [ selected, setSelected ] = useState<number | null>( null );
	const [ view, setView ] = useState<View>( { mode: 'list' } );
	const [ activeType, setActiveType ] = useState<ObjectType>( defaultType ?? 'item' );
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	function refresh() {
		setRefreshKey( ( k ) => k + 1 );
	}

	return (
		<div className="be-world-manager">
			<div className="be-world-manager__list-pane">
				{ showEditor && (
					<button type="button" onClick={ () => setView( { mode: 'create', type: activeType } ) }>
						{ sprintf( __( 'New %1$s', 'beyond-elysium' ), activeType ) }
					</button>
				) }
				<WorldObjectList
					key={ refreshKey }
					gameSlug={ gameSlug }
					defaultType={ defaultType }
					onTypeChange={ setActiveType }
					onSelect={ ( id ) => {
						setSelected( id );
						setView( { mode: 'list' } );
					} }
				/>
			</div>

			<div className="be-world-manager__detail-pane">
				{ view.mode === 'create' && (
					<WorldObjectEditor
						gameSlug={ gameSlug }
						objectType={ view.type }
						onSaved={ ( saved ) => {
							setSelected( saved.id );
							setView( { mode: 'list' } );
							refresh();
						} }
						onCancel={ () => setView( { mode: 'list' } ) }
					/>
				) }

				{ view.mode === 'edit' && (
					<EditWrapper
						gameSlug={ gameSlug }
						id={ view.id }
						onSaved={ () => {
							setView( { mode: 'list' } );
							refresh();
						} }
						onCancel={ () => setView( { mode: 'list' } ) }
					/>
				) }

				{ view.mode === 'list' && selected !== null && (
					<>
						<WorldObjectCard gameSlug={ gameSlug } objectId={ selected } />
						{ showEditor && (
							<button type="button" onClick={ () => setView( { mode: 'edit', id: selected } ) }>
								{ __( 'Edit', 'beyond-elysium' ) }
							</button>
						) }
					</>
				) }

				{ view.mode === 'list' && selected === null && <p>{ __( 'Select an item to view its details.', 'beyond-elysium' ) }</p> }
			</div>
		</div>
	);
}

/**
 * Loads the object being edited by ID, then renders it inside the
 * schema-driven editor once loaded. Shows a loading message while the
 * fetch is in flight and an error message if it fails.
 */
function EditWrapper( {
	gameSlug,
	id,
	onSaved,
	onCancel,
}: {
	gameSlug: string;
	id: number;
	onSaved: () => void;
	onCancel: () => void;
} ) {
	const [ object, setObject ] = useState<WorldObject | null>( null );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		setError( null );
		api
			.worldObjects( gameSlug )
			.get( id )
			.then( setObject )
			.catch( () => {
				// Reports a load failure instead of leaving the view stuck on "Loading…".
				setError( __( 'Failed to load this item. Try again.', 'beyond-elysium' ) );
			} );
	}, [ gameSlug, id ] );

	if ( error ) {
		return (
			<p className="be-world-manager__error" role="alert">
				{ error }
			</p>
		);
	}

	if ( ! object ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	return (
		<WorldObjectEditor
			gameSlug={ gameSlug }
			objectType={ object.object_type }
			object={ object }
			onSaved={ onSaved }
			onCancel={ onCancel }
		/>
	);
}

export default WorldObjectManager;
