/**
 * Top-level dashboard for world objects: combines the catalog list, a
 * detail view, and a create/edit form into one list-pane/detail-pane
 * layout. Owns which object is selected and which view (list, create,
 * edit) is active, and refreshes the list after a save.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import type {
	ItemTransferHow,
	ObjectType,
	WorldObject,
} from '../../types/world';
import type { Character } from '../../types/character';
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

type View =
	| { mode: 'list' }
	| { mode: 'create'; type: ObjectType }
	| { mode: 'edit'; id: number }
	| { mode: 'duplicate'; id: number }
	| { mode: 'copy-for-character'; id: number }
	| { mode: 'transfer'; id: number };

/**
 * Renders the world object catalog in a list pane alongside a detail
 * pane that shows either the selected object, a create form, or an edit
 * form depending on the current view. Switches views and refreshes the
 * list after a create or edit is saved.
 */
export function WorldObjectManager( {
	gameSlug,
	defaultType,
	showEditor,
}: WorldObjectManagerProps ) {
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ view, setView ] = useState< View >( { mode: 'list' } );
	const [ activeType, setActiveType ] = useState< ObjectType >(
		defaultType ?? 'item'
	);
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	function refresh() {
		setRefreshKey( ( k ) => k + 1 );
	}

	return (
		<div className="be-world-manager">
			<div className="be-world-manager__list-pane">
				{ showEditor && (
					<button
						type="button"
						onClick={ () =>
							setView( { mode: 'create', type: activeType } )
						}
					>
						{ sprintf(
							/* translators: %1$s: the object type, e.g. "item" or "location" */
							__( 'New %1$s', 'beyond-elysium' ),
							activeType
						) }
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

				{ view.mode === 'duplicate' && (
					<DuplicateWrapper
						gameSlug={ gameSlug }
						id={ view.id }
						onSaved={ ( saved ) => {
							setSelected( saved.id );
							setView( { mode: 'list' } );
							refresh();
						} }
						onCancel={ () => setView( { mode: 'list' } ) }
					/>
				) }

				{ view.mode === 'copy-for-character' && (
					<CopyForCharacterWrapper
						gameSlug={ gameSlug }
						id={ view.id }
						onSaved={ ( saved ) => {
							setSelected( saved.id );
							setView( { mode: 'edit', id: saved.id } );
							refresh();
						} }
						onCancel={ () => setView( { mode: 'list' } ) }
					/>
				) }

				{ view.mode === 'transfer' && (
					<TransferItemWrapper
						gameSlug={ gameSlug }
						id={ view.id }
						onSaved={ ( saved ) => {
							setSelected( saved.id );
							setView( { mode: 'list' } );
							refresh();
						} }
						onCancel={ () => setView( { mode: 'list' } ) }
					/>
				) }

				{ view.mode === 'list' && selected !== null && (
					<>
						<WorldObjectCard
							gameSlug={ gameSlug }
							objectId={ selected }
						/>
						{ showEditor && (
							<div className="be-world-manager__actions">
								<button
									type="button"
									onClick={ () =>
										setView( {
											mode: 'edit',
											id: selected,
										} )
									}
								>
									{ __( 'Edit', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									onClick={ () =>
										setView( {
											mode: 'duplicate',
											id: selected,
										} )
									}
								>
									{ __( 'Duplicate', 'beyond-elysium' ) }
								</button>
								{ activeType === 'item' && (
									<button
										type="button"
										onClick={ () =>
											setView( {
												mode: 'copy-for-character',
												id: selected,
											} )
										}
									>
										{ __(
											'Copy for a character',
											'beyond-elysium'
										) }
									</button>
								) }
								{ activeType === 'item' && (
									<button
										type="button"
										onClick={ () =>
											setView( {
												mode: 'transfer',
												id: selected,
											} )
										}
									>
										{ __( 'Transfer', 'beyond-elysium' ) }
									</button>
								) }
								{ activeType === 'item' && (
									<RevokeCardsButton
										gameSlug={ gameSlug }
										id={ selected }
									/>
								) }
							</div>
						) }
					</>
				) }

				{ view.mode === 'list' && selected === null && (
					<p>
						{ __(
							'Select an item to view its details.',
							'beyond-elysium'
						) }
					</p>
				) }
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
	const [ object, setObject ] = useState< WorldObject | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setError( null );
		api.worldObjects( gameSlug )
			.get( id )
			.then( setObject )
			.catch( () => {
				// Reports a load failure instead of leaving the view stuck on "Loading…".
				setError(
					__(
						'Failed to load this item. Try again.',
						'beyond-elysium'
					)
				);
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

/**
 * Loads the object being duplicated by ID, then renders a create-mode
 * editor pre-filled from it - same load pattern as `EditWrapper`, but the
 * loaded object is passed as `duplicateFrom` rather than `object`, so the
 * editor always submits a create.
 */
function DuplicateWrapper( {
	gameSlug,
	id,
	onSaved,
	onCancel,
}: {
	gameSlug: string;
	id: number;
	onSaved: ( saved: WorldObject ) => void;
	onCancel: () => void;
} ) {
	const [ object, setObject ] = useState< WorldObject | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setError( null );
		api.worldObjects( gameSlug )
			.get( id )
			.then( setObject )
			.catch( () => {
				setError(
					__(
						'Failed to load this item. Try again.',
						'beyond-elysium'
					)
				);
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
			duplicateFrom={ object }
			onSaved={ onSaved }
			onCancel={ onCancel }
		/>
	);
}

/**
 * A small character-picker form for "Copy for a character" (1.1.0 §3.12 item 1) - picks a
 * character, optionally renames the copy, and posts the copy route. On success the new copy
 * opens in the editor (`onSaved`), matching the design's own "returns the copy for the
 * editor."
 */
function CopyForCharacterWrapper( {
	gameSlug,
	id,
	onSaved,
	onCancel,
}: {
	gameSlug: string;
	id: number;
	onSaved: ( saved: WorldObject ) => void;
	onCancel: () => void;
} ) {
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ characterId, setCharacterId ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		everyPage( ( page ) =>
			api.characters( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
	}, [ gameSlug ] );

	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! characterId ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			const saved = await api
				.worldObjects( gameSlug )
				.copyForCharacter( id, {
					character_id: Number( characterId ),
					name: name.trim() || undefined,
				} );
			onSaved( saved );
		} catch {
			setError( __( 'Failed to copy this item.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	return (
		<form className="be-world-manager__copy-form" onSubmit={ submit }>
			<h4>{ __( 'Copy for a character', 'beyond-elysium' ) }</h4>
			{ error && (
				<p className="be-world-manager__error" role="alert">
					{ error }
				</p>
			) }
			<select
				value={ characterId }
				onChange={ ( e ) => setCharacterId( e.target.value ) }
			>
				<option value="">
					{ __( 'Choose a character…', 'beyond-elysium' ) }
				</option>
				{ characters.map( ( c ) => (
					<option key={ c.id } value={ c.id }>
						{ c.name }
					</option>
				) ) }
			</select>
			<input
				type="text"
				value={ name }
				onChange={ ( e ) => setName( e.target.value ) }
				placeholder={ __(
					'Name for the copy (optional)…',
					'beyond-elysium'
				) }
			/>
			<div className="be-world-manager__actions">
				<button type="submit" disabled={ saving || ! characterId }>
					{ __( 'Copy', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ onCancel }>
					{ __( 'Cancel', 'beyond-elysium' ) }
				</button>
			</div>
		</form>
	);
}

const TRANSFER_HOW_OPTIONS: { value: ItemTransferHow; label: string }[] = [
	{ value: 'given', label: __( 'Given', 'beyond-elysium' ) },
	{ value: 'traded', label: __( 'Traded', 'beyond-elysium' ) },
	{ value: 'stolen', label: __( 'Stolen', 'beyond-elysium' ) },
	{ value: 'lost', label: __( 'Lost', 'beyond-elysium' ) },
];

/**
 * A small form for transferring an item to a new character, or losing it (1.1.0 §3.12 item
 * 4) - a recipient picker (hidden for `how: 'lost'`, which clears every holder connection
 * with nobody new), a `how` select, and an optional note.
 */
function TransferItemWrapper( {
	gameSlug,
	id,
	onSaved,
	onCancel,
}: {
	gameSlug: string;
	id: number;
	onSaved: ( saved: WorldObject ) => void;
	onCancel: () => void;
} ) {
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ characterId, setCharacterId ] = useState( '' );
	const [ how, setHow ] = useState< ItemTransferHow >( 'given' );
	const [ note, setNote ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		everyPage( ( page ) =>
			api.characters( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
	}, [ gameSlug ] );

	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( how !== 'lost' && ! characterId ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			const saved = await api.worldObjects( gameSlug ).transfer( id, {
				to_character_id: how === 'lost' ? null : Number( characterId ),
				how,
				note: note.trim() || undefined,
			} );
			onSaved( saved );
		} catch {
			setError( __( 'Failed to transfer this item.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	return (
		<form className="be-world-manager__copy-form" onSubmit={ submit }>
			<h4>{ __( 'Transfer', 'beyond-elysium' ) }</h4>
			{ error && (
				<p className="be-world-manager__error" role="alert">
					{ error }
				</p>
			) }
			<select
				value={ how }
				onChange={ ( e ) =>
					setHow( e.target.value as ItemTransferHow )
				}
			>
				{ TRANSFER_HOW_OPTIONS.map( ( o ) => (
					<option key={ o.value } value={ o.value }>
						{ o.label }
					</option>
				) ) }
			</select>
			{ how !== 'lost' && (
				<select
					value={ characterId }
					onChange={ ( e ) => setCharacterId( e.target.value ) }
				>
					<option value="">
						{ __( 'Choose a character…', 'beyond-elysium' ) }
					</option>
					{ characters.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
						</option>
					) ) }
				</select>
			) }
			<input
				type="text"
				value={ note }
				onChange={ ( e ) => setNote( e.target.value ) }
				placeholder={ __( 'Note (optional)…', 'beyond-elysium' ) }
			/>
			<div className="be-world-manager__actions">
				<button
					type="submit"
					disabled={ saving || ( how !== 'lost' && ! characterId ) }
				>
					{ __( 'Transfer', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ onCancel }>
					{ __( 'Cancel', 'beyond-elysium' ) }
				</button>
			</div>
		</form>
	);
}

/**
 * Revokes every verification code ever printed for one item (1.1.0 §3.13) - a single
 * confirm-then-call action, not a form; nothing about the item itself changes.
 */
function RevokeCardsButton( {
	gameSlug,
	id,
}: {
	gameSlug: string;
	id: number;
} ) {
	async function revoke() {
		if (
			! window.confirm(
				__(
					'Revoke every verification code ever printed for this item?',
					'beyond-elysium'
				)
			)
		) {
			return;
		}
		try {
			await api.worldObjects( gameSlug ).revokeCards( id );
		} catch {
			window.alert(
				__(
					'Failed to revoke this item’s verification codes.',
					'beyond-elysium'
				)
			);
		}
	}

	return (
		<button type="button" onClick={ revoke }>
			{ __( 'Revoke Cards', 'beyond-elysium' ) }
		</button>
	);
}

export default WorldObjectManager;
