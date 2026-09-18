/**
 * The Storyteller Toolkit's Factions tab (1.1.0 §3.10, F1): a list-pane/detail-pane manager
 * for chronicle factions - sects, coteries, packs, chantries, courts - matching
 * `WorldObjectManager`'s own two-pane layout. A faction reached here is always seen with a
 * manager's full projection (goals, audience_rules, the member roster with ranks and leader
 * flags) since only `be_manage_factions` reaches this page at all.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import AudiencePicker from '../shared/AudiencePicker';
import HelpButton from '../shared/HelpButton';
import {
	FACTION_TYPE_SUGGESTIONS,
	type Faction,
	type FactionMember,
	type FactionMemberCandidate,
} from '../../types/faction';
import type { AudienceRules, AudienceValue } from '../../types/plot';
import './FactionManager.css';

export interface FactionManagerProps {
	gameSlug: string;
}

export function FactionManager( { gameSlug }: FactionManagerProps ) {
	const [ items, setItems ] = useState< Faction[] >( [] );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ creating, setCreating ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	function refresh() {
		setRefreshKey( ( k ) => k + 1 );
	}

	useEffect( () => {
		api.factions( gameSlug )
			.list()
			.then( setItems )
			.catch( () =>
				setError( __( 'Failed to load factions.', 'beyond-elysium' ) )
			);
	}, [ gameSlug, refreshKey ] );

	return (
		<div className="be-faction-manager">
			<div className="be-faction-manager__list-pane">
				<div className="be-help-heading">
					<h3>{ __( 'Factions', 'beyond-elysium' ) }</h3>
					<HelpButton helpKey="factions" />
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
					{ __( 'New Faction', 'beyond-elysium' ) }
				</button>
				<ul className="be-faction-manager__list">
					{ items.map( ( faction ) => (
						<li key={ faction.id }>
							<button
								type="button"
								className={
									selected === faction.id
										? 'is-active'
										: undefined
								}
								onClick={ () => {
									setSelected( faction.id );
									setCreating( false );
								} }
							>
								{ faction.name }
								<span className="be-faction-manager__type">
									{ faction.faction_type }
								</span>
							</button>
						</li>
					) ) }
					{ items.length === 0 && (
						<li className="be-faction-manager__empty">
							{ __( 'No factions yet.', 'beyond-elysium' ) }
						</li>
					) }
				</ul>
			</div>

			<div className="be-faction-manager__detail-pane">
				{ creating && (
					<FactionEditor
						gameSlug={ gameSlug }
						factions={ items }
						onSaved={ ( saved ) => {
							setCreating( false );
							setSelected( saved.id );
							refresh();
						} }
						onCancel={ () => setCreating( false ) }
					/>
				) }

				{ ! creating && selected !== null && (
					<FactionDetail
						key={ selected }
						gameSlug={ gameSlug }
						id={ selected }
						factions={ items }
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
							'Select a faction, or create a new one.',
							'beyond-elysium'
						) }
					</p>
				) }
			</div>
		</div>
	);
}

function FactionEditor( {
	gameSlug,
	factions,
	faction,
	onSaved,
	onCancel,
}: {
	gameSlug: string;
	factions: Faction[];
	faction?: Faction;
	onSaved: ( saved: Faction ) => void;
	onCancel: () => void;
} ) {
	const [ name, setName ] = useState( faction?.name ?? '' );
	const [ factionType, setFactionType ] = useState(
		faction?.faction_type ?? 'other'
	);
	const [ parentId, setParentId ] = useState< string >(
		faction?.parent_id ? String( faction.parent_id ) : ''
	);
	const [ description, setDescription ] = useState(
		faction?.description ?? ''
	);
	const [ goals, setGoals ] = useState( faction?.goals ?? '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! name.trim() ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			const data = {
				name: name.trim(),
				faction_type: factionType,
				parent_id: parentId ? Number( parentId ) : null,
				description: description || null,
				goals: goals || null,
			};
			const saved = faction
				? await api.factions( gameSlug ).update( faction.id, data )
				: await api.factions( gameSlug ).create( data );
			onSaved( saved );
		} catch {
			setError( __( 'Failed to save this faction.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	return (
		<form className="be-faction-manager__editor" onSubmit={ save }>
			{ error && (
				<div className="be-faction-manager__error" role="alert">
					{ error }
				</div>
			) }
			<label>
				{ __( 'Name', 'beyond-elysium' ) }
				<input
					type="text"
					value={ name }
					onChange={ ( e ) => setName( e.target.value ) }
				/>
			</label>
			<label>
				{ __( 'Type', 'beyond-elysium' ) }
				<input
					type="text"
					list="be-faction-type-suggestions"
					value={ factionType }
					onChange={ ( e ) => setFactionType( e.target.value ) }
				/>
				<datalist id="be-faction-type-suggestions">
					{ FACTION_TYPE_SUGGESTIONS.map( ( t ) => (
						<option key={ t } value={ t } />
					) ) }
				</datalist>
			</label>
			<label>
				{ __( 'Parent faction', 'beyond-elysium' ) }
				<select
					value={ parentId }
					onChange={ ( e ) => setParentId( e.target.value ) }
				>
					<option value="">{ __( 'None', 'beyond-elysium' ) }</option>
					{ factions
						.filter( ( f ) => f.id !== faction?.id )
						.map( ( f ) => (
							<option key={ f.id } value={ f.id }>
								{ f.name }
							</option>
						) ) }
				</select>
			</label>
			<label>
				{ __( 'Description', 'beyond-elysium' ) }
				<textarea
					value={ description }
					onChange={ ( e ) => setDescription( e.target.value ) }
				/>
			</label>
			<label>
				{ __( 'Goals', 'beyond-elysium' ) }
				<textarea
					value={ goals }
					onChange={ ( e ) => setGoals( e.target.value ) }
				/>
			</label>
			<div className="be-faction-manager__actions">
				<button type="submit" disabled={ saving || ! name.trim() }>
					{ __( 'Save', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ onCancel }>
					{ __( 'Cancel', 'beyond-elysium' ) }
				</button>
			</div>
		</form>
	);
}

function FactionDetail( {
	gameSlug,
	id,
	factions,
	onChanged,
	onDeleted,
}: {
	gameSlug: string;
	id: number;
	factions: Faction[];
	onChanged: () => void;
	onDeleted: () => void;
} ) {
	const [ faction, setFaction ] = useState< Faction | null >( null );
	const [ members, setMembers ] = useState< FactionMember[] >( [] );
	const [ candidates, setCandidates ] = useState< FactionMemberCandidate[] >(
		[]
	);
	const [ addingMember, setAddingMember ] = useState( false );
	const [ candidateId, setCandidateId ] = useState( '' );
	const [ editing, setEditing ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	function load() {
		api.factions( gameSlug )
			.get( id )
			.then( setFaction )
			.catch( () =>
				setError(
					__( 'Failed to load this faction.', 'beyond-elysium' )
				)
			);
		api.factions( gameSlug )
			.members( id )
			.then( setMembers )
			.catch( () => setMembers( [] ) );
	}

	useEffect( load, [ gameSlug, id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function loadCandidates() {
		api.factions( gameSlug )
			.memberCandidates( id )
			.then( setCandidates )
			.catch( () => setCandidates( [] ) );
	}

	async function updateAudience(
		audience: AudienceValue,
		audienceRules: AudienceRules | null
	) {
		try {
			await api
				.factions( gameSlug )
				.update( id, { audience, audience_rules: audienceRules } );
			load();
		} catch {
			setError(
				__( 'Failed to update this faction.', 'beyond-elysium' )
			);
		}
	}

	async function addMember( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! candidateId ) {
			return;
		}
		try {
			await api
				.factions( gameSlug )
				.addMember( id, Number( candidateId ) );
			setCandidateId( '' );
			setAddingMember( false );
			load();
			onChanged();
		} catch {
			setError( __( 'Failed to add this member.', 'beyond-elysium' ) );
		}
	}

	async function removeMember( characterId: number ) {
		try {
			await api.factions( gameSlug ).removeMember( id, characterId );
			load();
			onChanged();
		} catch {
			setError(
				__(
					"Couldn't remove this member - a faction needs at least one leader.",
					'beyond-elysium'
				)
			);
		}
	}

	async function updateMemberRank( characterId: number, rank: string ) {
		try {
			await api
				.factions( gameSlug )
				.updateMember( id, characterId, { rank: rank || null } );
			load();
		} catch {
			setError(
				__( "Couldn't update this member's rank.", 'beyond-elysium' )
			);
		}
	}

	async function toggleLeader( characterId: number, isLeader: boolean ) {
		try {
			await api
				.factions( gameSlug )
				.updateMember( id, characterId, { is_leader: isLeader } );
			load();
			onChanged();
		} catch {
			setError(
				__(
					"Couldn't update this member's leader status - a faction needs at least one leader.",
					'beyond-elysium'
				)
			);
		}
	}

	async function deleteFaction() {
		try {
			await api.factions( gameSlug ).remove( id );
			onDeleted();
		} catch {
			setError(
				__( 'Failed to delete this faction.', 'beyond-elysium' )
			);
		}
	}

	if ( ! faction ) {
		return error ? (
			<div className="be-faction-manager__error" role="alert">
				{ error }
			</div>
		) : null;
	}

	if ( editing ) {
		return (
			<FactionEditor
				gameSlug={ gameSlug }
				factions={ factions }
				faction={ faction }
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
			<div className="be-help-heading">
				<h3>{ faction.name }</h3>
				<HelpButton helpKey="factions" />
			</div>
			<p className="be-faction-manager__type">
				{ faction.faction_type }
				{ faction.status === 'disbanded' &&
					` · ${ __( 'Disbanded', 'beyond-elysium' ) }` }
				{ faction.created_via_proposal &&
					` · ${ __( 'Player-proposed', 'beyond-elysium' ) }` }
			</p>
			{ faction.description && <p>{ faction.description }</p> }
			{ faction.goals && (
				<p>
					<strong>{ __( 'Goals:', 'beyond-elysium' ) }</strong>{ ' ' }
					{ faction.goals }
				</p>
			) }

			<AudiencePicker
				gameSlug={ gameSlug }
				audience={ faction.audience }
				audienceRules={ faction.audience_rules ?? null }
				onChange={ updateAudience }
			/>

			<h4>{ __( 'Members', 'beyond-elysium' ) }</h4>
			<ul className="be-faction-manager__members">
				{ members.map( ( member ) => (
					<li key={ member.id }>
						{ member.character_name ?? `#${ member.character_id }` }
						{ member.is_leader && (
							<span className="be-st-badge">
								{ __( 'leader', 'beyond-elysium' ) }
							</span>
						) }
						<input
							type="text"
							className="be-faction-manager__rank-input"
							defaultValue={ member.rank ?? '' }
							placeholder={ __( 'Rank', 'beyond-elysium' ) }
							onBlur={ ( e ) =>
								e.target.value !== ( member.rank ?? '' ) &&
								updateMemberRank(
									member.character_id,
									e.target.value
								)
							}
						/>
						<button
							type="button"
							onClick={ () =>
								toggleLeader(
									member.character_id,
									! member.is_leader
								)
							}
						>
							{ member.is_leader
								? __( 'Demote', 'beyond-elysium' )
								: __( 'Make leader', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () =>
								removeMember( member.character_id )
							}
						>
							{ __( 'Remove', 'beyond-elysium' ) }
						</button>
					</li>
				) ) }
				{ members.length === 0 && (
					<li>{ __( 'No members yet.', 'beyond-elysium' ) }</li>
				) }
			</ul>

			{ addingMember ? (
				<form
					className="be-faction-manager__member-form"
					onSubmit={ addMember }
				>
					<select
						value={ candidateId }
						onChange={ ( e ) => setCandidateId( e.target.value ) }
					>
						<option value="">
							{ __( 'Add a character…', 'beyond-elysium' ) }
						</option>
						{ candidates.map( ( c ) => (
							<option key={ c.id } value={ c.id }>
								{ c.name }
							</option>
						) ) }
					</select>
					<button type="submit" disabled={ ! candidateId }>
						{ __( 'Add', 'beyond-elysium' ) }
					</button>
					<button
						type="button"
						onClick={ () => setAddingMember( false ) }
					>
						{ __( 'Cancel', 'beyond-elysium' ) }
					</button>
				</form>
			) : (
				<button
					type="button"
					onClick={ () => {
						setAddingMember( true );
						loadCandidates();
					} }
				>
					{ __( 'Add Member', 'beyond-elysium' ) }
				</button>
			) }

			<div className="be-faction-manager__actions">
				<button type="button" onClick={ () => setEditing( true ) }>
					{ __( 'Edit', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ deleteFaction }>
					{ __( 'Delete', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default FactionManager;
