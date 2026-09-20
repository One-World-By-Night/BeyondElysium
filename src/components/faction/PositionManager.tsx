/**
 * The Storyteller Toolkit's Positions tab (1.1.0 §3.10, F2): a list-pane/detail-pane manager
 * for chronicle-wide offices - Prince, Sheriff, Archbishop, and the like - each optionally
 * scoped to a faction, with a title preset picker and a holder-history timeline. Only
 * `be_manage_factions` reaches this page, so every position here is seen with its full,
 * manager-only projection regardless of its own `holder_public`.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import HelpButton from '../shared/HelpButton';
import HtmlEditor from '../shared/HtmlEditor';
import type {
	Faction,
	Position,
	PositionHistoryRow,
	PositionPresets,
} from '../../types/faction';
import type { Character } from '../../types/character';
import './FactionManager.css';

export interface PositionManagerProps {
	gameSlug: string;
	factions: Faction[];
}

export function PositionManager( {
	gameSlug,
	factions,
}: PositionManagerProps ) {
	const [ items, setItems ] = useState< Position[] >( [] );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ creating, setCreating ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ presets, setPresets ] = useState< PositionPresets >( {} );

	function refresh() {
		setRefreshKey( ( k ) => k + 1 );
	}

	useEffect( () => {
		api.positions( gameSlug )
			.list()
			.then( setItems )
			.catch( () =>
				setError( __( 'Failed to load positions.', 'beyond-elysium' ) )
			);
	}, [ gameSlug, refreshKey ] );

	useEffect( () => {
		everyPage( ( page ) =>
			api.characters( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
		api.positions( gameSlug )
			.presets()
			.then( setPresets )
			.catch( () => setPresets( {} ) );
	}, [ gameSlug ] );

	function characterName( id: number | null ): string {
		if ( id === null ) {
			return __( 'Vacant', 'beyond-elysium' );
		}
		return characters.find( ( c ) => c.id === id )?.name ?? `#${ id }`;
	}

	function factionName( id: number | null ): string {
		if ( id === null ) {
			return '';
		}
		return factions.find( ( f ) => f.id === id )?.name ?? `#${ id }`;
	}

	return (
		<div className="be-faction-manager">
			<div className="be-faction-manager__list-pane">
				<div className="be-help-heading">
					<h3>{ __( 'Positions', 'beyond-elysium' ) }</h3>
					<HelpButton helpKey="positions" />
				</div>
				{ error && (
					<div className="be-faction-manager__error" role="alert">
						{ error }
					</div>
				) }
				<button
					type="button"
					onClick={ () => {
						setCreating( true );
						setSelected( null );
					} }
				>
					{ __( 'New Position', 'beyond-elysium' ) }
				</button>
				<ul className="be-faction-manager__list">
					{ items.map( ( position ) => (
						<li key={ position.id }>
							<button
								type="button"
								className={
									selected === position.id
										? 'is-active'
										: undefined
								}
								onClick={ () => {
									setSelected( position.id );
									setCreating( false );
								} }
							>
								{ position.title }
								<span className="be-faction-manager__type">
									{ position.held
										? characterName( position.character_id )
										: __( 'Vacant', 'beyond-elysium' ) }
								</span>
							</button>
						</li>
					) ) }
					{ items.length === 0 && (
						<li className="be-faction-manager__empty">
							{ __( 'No positions yet.', 'beyond-elysium' ) }
						</li>
					) }
				</ul>
			</div>

			<div className="be-faction-manager__detail-pane">
				{ creating && (
					<PositionEditor
						gameSlug={ gameSlug }
						factions={ factions }
						characters={ characters }
						presets={ presets }
						onSaved={ ( saved ) => {
							setCreating( false );
							setSelected( saved.id );
							refresh();
						} }
						onCancel={ () => setCreating( false ) }
					/>
				) }

				{ ! creating && selected !== null && (
					<PositionDetail
						key={ selected }
						gameSlug={ gameSlug }
						id={ selected }
						factions={ factions }
						characters={ characters }
						presets={ presets }
						characterName={ characterName }
						factionName={ factionName }
						onChanged={ refresh }
						onDeleted={ () => {
							setSelected( null );
							refresh();
						} }
					/>
				) }

				{ ! creating && selected === null && (
					<p>
						{ __(
							'Select a position, or create a new one.',
							'beyond-elysium'
						) }
					</p>
				) }
			</div>
		</div>
	);
}

function PositionEditor( {
	gameSlug,
	factions,
	characters,
	presets,
	position,
	onSaved,
	onCancel,
}: {
	gameSlug: string;
	factions: Faction[];
	characters: Character[];
	presets: PositionPresets;
	position?: Position;
	onSaved: ( saved: Position ) => void;
	onCancel: () => void;
} ) {
	const [ title, setTitle ] = useState( position?.title ?? '' );
	const [ factionId, setFactionId ] = useState< string >(
		position?.faction_id ? String( position.faction_id ) : ''
	);
	const [ characterId, setCharacterId ] = useState< string >(
		position?.character_id ? String( position.character_id ) : ''
	);
	const [ holderPublic, setHolderPublic ] = useState(
		position?.holder_public ?? true
	);
	const notesDraft = useRef( position?.notes ?? '' );
	const editorKey = position?.id ?? 'new';
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! title.trim() ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			const data = {
				title: title.trim(),
				faction_id: factionId ? Number( factionId ) : null,
				character_id: characterId ? Number( characterId ) : null,
				holder_public: holderPublic,
				notes: notesDraft.current || null,
			};
			const saved = position
				? await api.positions( gameSlug ).update( position.id, data )
				: await api.positions( gameSlug ).create( data );
			onSaved( saved );
		} catch {
			setError( __( 'Failed to save this position.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	const presetTitles = Object.values( presets ).flat();

	return (
		<form className="be-faction-manager__editor" onSubmit={ save }>
			{ error && (
				<div className="be-faction-manager__error" role="alert">
					{ error }
				</div>
			) }
			<label>
				{ __( 'Title', 'beyond-elysium' ) }
				<input
					type="text"
					list="be-position-title-suggestions"
					value={ title }
					onChange={ ( e ) => setTitle( e.target.value ) }
				/>
				<datalist id="be-position-title-suggestions">
					{ presetTitles.map( ( t ) => (
						<option key={ t } value={ t } />
					) ) }
				</datalist>
			</label>
			<label>
				{ __( 'Faction', 'beyond-elysium' ) }
				<select
					value={ factionId }
					onChange={ ( e ) => setFactionId( e.target.value ) }
				>
					<option value="">
						{ __(
							'Chronicle-wide (no faction)',
							'beyond-elysium'
						) }
					</option>
					{ factions.map( ( f ) => (
						<option key={ f.id } value={ f.id }>
							{ f.name }
						</option>
					) ) }
				</select>
			</label>
			<label>
				{ __( 'Holder', 'beyond-elysium' ) }
				<select
					value={ characterId }
					onChange={ ( e ) => setCharacterId( e.target.value ) }
				>
					<option value="">
						{ __( 'Vacant', 'beyond-elysium' ) }
					</option>
					{ characters.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
						</option>
					) ) }
				</select>
			</label>
			<label>
				<input
					type="checkbox"
					checked={ holderPublic }
					onChange={ ( e ) => setHolderPublic( e.target.checked ) }
				/>{ ' ' }
				{ __(
					'Publicly known who holds this position',
					'beyond-elysium'
				) }
			</label>
			<div className="be-faction-manager__field">
				<span>{ __( 'Notes', 'beyond-elysium' ) }</span>
				<HtmlEditor
					id={ `be-position-notes-${ editorKey }` }
					defaultValue={ notesDraft.current }
					onChange={ ( html ) => {
						notesDraft.current = html;
					} }
				/>
			</div>
			<div className="be-faction-manager__actions">
				<button type="submit" disabled={ saving || ! title.trim() }>
					{ __( 'Save', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ onCancel }>
					{ __( 'Cancel', 'beyond-elysium' ) }
				</button>
			</div>
		</form>
	);
}

function PositionDetail( {
	gameSlug,
	id,
	factions,
	characters,
	presets,
	characterName,
	factionName,
	onChanged,
	onDeleted,
}: {
	gameSlug: string;
	id: number;
	factions: Faction[];
	characters: Character[];
	presets: PositionPresets;
	characterName: ( id: number | null ) => string;
	factionName: ( id: number | null ) => string;
	onChanged: () => void;
	onDeleted: () => void;
} ) {
	const [ position, setPosition ] = useState< Position | null >( null );
	const [ history, setHistory ] = useState< PositionHistoryRow[] >( [] );
	const [ editing, setEditing ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	function load() {
		api.positions( gameSlug )
			.list()
			.then( ( all ) =>
				setPosition( all.find( ( p ) => p.id === id ) ?? null )
			)
			.catch( () =>
				setError(
					__( 'Failed to load this position.', 'beyond-elysium' )
				)
			);
		api.positions( gameSlug )
			.history( id )
			.then( setHistory )
			.catch( () => setHistory( [] ) );
	}

	useEffect( load, [ gameSlug, id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function deletePosition() {
		try {
			await api.positions( gameSlug ).remove( id );
			onDeleted();
		} catch {
			setError(
				__( 'Failed to delete this position.', 'beyond-elysium' )
			);
		}
	}

	if ( ! position ) {
		return error ? (
			<div className="be-faction-manager__error" role="alert">
				{ error }
			</div>
		) : null;
	}

	if ( editing ) {
		return (
			<PositionEditor
				gameSlug={ gameSlug }
				factions={ factions }
				characters={ characters }
				presets={ presets }
				position={ position }
				onSaved={ () => {
					setEditing( false );
					load();
					onChanged();
				} }
				onCancel={ () => setEditing( false ) }
			/>
		);
	}

	return (
		<div className="be-faction-manager__detail">
			{ error && (
				<div className="be-faction-manager__error" role="alert">
					{ error }
				</div>
			) }
			<h3>{ position.title }</h3>
			{ position.faction_id && (
				<p className="be-faction-manager__type">
					{ factionName( position.faction_id ) }
				</p>
			) }
			<p>
				<strong>{ __( 'Held by:', 'beyond-elysium' ) }</strong>{ ' ' }
				{ position.held
					? characterName( position.character_id )
					: __( 'Vacant', 'beyond-elysium' ) }
				{ ! position.holder_public && (
					<span className="be-st-badge">
						{ __( 'not public', 'beyond-elysium' ) }
					</span>
				) }
			</p>
			{ position.notes && (
				<div
					// eslint-disable-next-line react/no-danger
					dangerouslySetInnerHTML={ { __html: position.notes } }
				/>
			) }

			<h4>{ __( 'History', 'beyond-elysium' ) }</h4>
			<ul className="be-faction-manager__members">
				{ history.map( ( row ) => (
					<li key={ row.id }>
						{ row.character_name ??
							__( 'Vacant', 'beyond-elysium' ) }
						{ ' — ' }
						{ row.started }
						{ row.ended
							? ` – ${ row.ended }`
							: ` (${ __( 'current', 'beyond-elysium' ) })` }
					</li>
				) ) }
				{ history.length === 0 && (
					<li>{ __( 'No history yet.', 'beyond-elysium' ) }</li>
				) }
			</ul>

			<div className="be-faction-manager__actions">
				<button type="button" onClick={ () => setEditing( true ) }>
					{ __( 'Edit', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ deletePosition }>
					{ __( 'Delete', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default PositionManager;
