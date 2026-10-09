/**
 * "What I Know" - My Chronicle's own tab listing every secret revealed to one of the current player's characters,
 * across every plot, item, location, or NPC it's attached to - plus logging what a character learned and telling
 * another character a secret already known.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import { errorMessage } from '../../lib/errorMessage';
import type {
	MySecretRow,
	MySecretsResponse,
	MyWaitingKnowledgeRow,
	RevealHow,
	SecretPerson,
} from '../../types/secret';
import type { Character, CharacterProfile } from '../../types/character';
import './WhatIKnow.css';
import { highlightStMarkers } from '../../lib/highlightStMarkers';
import { peopleFor, tellerFields } from '../../lib/secretPeople';

export interface WhatIKnowProps {
	gameSlug: string;
}

const ENTITY_LABELS: Record< string, string > = {
	plot: __( 'Plot', 'beyond-elysium' ),
	item: __( 'Item', 'beyond-elysium' ),
	location: __( 'Location', 'beyond-elysium' ),
	character: __( 'Character', 'beyond-elysium' ),
	npc: __( 'NPC', 'beyond-elysium' ),
};

const HOW_OPTIONS: { value: RevealHow; label: string }[] = [
	{ value: 'game', label: __( 'In game', 'beyond-elysium' ) },
	{ value: 'downtime', label: __( 'Downtime', 'beyond-elysium' ) },
	{ value: 'rumor', label: __( 'Rumor', 'beyond-elysium' ) },
	{ value: 'told', label: __( 'Told by someone', 'beyond-elysium' ) },
	{ value: 'other', label: __( 'Other', 'beyond-elysium' ) },
];

const PEOPLE_LIST_ID = 'be-what-i-know-people';

function LogForm( {
	gameSlug,
	characters,
	people,
	onLogged,
}: {
	gameSlug: string;
	characters: Character[];
	people: SecretPerson[];
	onLogged: () => void;
} ) {
	const [ open, setOpen ] = useState( false );
	const [ characterId, setCharacterId ] = useState< number | null >(
		characters[ 0 ]?.id ?? null
	);
	const [ title, setTitle ] = useState( '' );
	const [ details, setDetails ] = useState( '' );
	const [ how, setHow ] = useState< RevealHow >( 'game' );
	const [ tellerId, setTellerId ] = useState< number | '' >( '' );
	const [ tellerName, setTellerName ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ message, setMessage ] = useState< string | null >( null );

	const tellers = peopleFor( people, characterId );

	function submit() {
		if ( ! characterId || title.trim() === '' || details.trim() === '' ) {
			return;
		}
		setSaving( true );
		setError( null );
		api.secrets( gameSlug )
			.logKnowledge( {
				character_id: characterId,
				title: title.trim(),
				details: details.trim(),
				how,
				...tellerFields( tellerId, tellerName, tellers ),
			} )
			.then( () => {
				setMessage(
					__(
						'Logged. A Storyteller will review it.',
						'beyond-elysium'
					)
				);
				setTitle( '' );
				setDetails( '' );
				setTellerId( '' );
				setTellerName( '' );
				onLogged();
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setSaving( false ) );
	}

	if ( ! open ) {
		return (
			<button
				type="button"
				className="be-what-i-know__log-toggle"
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Log something I learned', 'beyond-elysium' ) }
			</button>
		);
	}

	return (
		<div className="be-what-i-know__log-form">
			<h3>{ __( 'Log something I learned', 'beyond-elysium' ) }</h3>

			<label>
				{ __( 'Which character', 'beyond-elysium' ) }
				<select
					value={ characterId ?? '' }
					onChange={ ( e ) => {
						const learner = Number( e.target.value );
						setCharacterId( learner );
						if ( tellerId === learner ) {
							setTellerId( '' );
						}
					} }
				>
					{ characters.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
						</option>
					) ) }
				</select>
			</label>

			<label>
				{ __( 'Title', 'beyond-elysium' ) }
				<input
					type="text"
					value={ title }
					onChange={ ( e ) => setTitle( e.target.value ) }
				/>
			</label>

			<label>
				{ __( 'Details', 'beyond-elysium' ) }
				<textarea
					value={ details }
					onChange={ ( e ) => setDetails( e.target.value ) }
					rows={ 4 }
				/>
			</label>

			<label>
				{ __( 'How', 'beyond-elysium' ) }
				<select
					value={ how }
					onChange={ ( e ) => setHow( e.target.value as RevealHow ) }
				>
					{ HOW_OPTIONS.map( ( o ) => (
						<option key={ o.value } value={ o.value }>
							{ o.label }
						</option>
					) ) }
				</select>
			</label>

			<label>
				{ __( 'From whom (optional)', 'beyond-elysium' ) }
				{ tellers.length > 0 && (
					<select
						value={ tellerId }
						onChange={ ( e ) =>
							setTellerId(
								e.target.value ? Number( e.target.value ) : ''
							)
						}
					>
						<option value="">
							{ __( '— choose a character —', 'beyond-elysium' ) }
						</option>
						{ tellers.map( ( p ) => (
							<option key={ p.id } value={ p.id }>
								{ p.name }
							</option>
						) ) }
					</select>
				) }
				{ ! tellerId && (
					<input
						type="text"
						list={ PEOPLE_LIST_ID }
						placeholder={
							tellers.length > 0
								? __( 'Or type a name', 'beyond-elysium' )
								: __( 'Type a name', 'beyond-elysium' )
						}
						value={ tellerName }
						onChange={ ( e ) => setTellerName( e.target.value ) }
					/>
				) }
				<datalist id={ PEOPLE_LIST_ID }>
					{ tellers.map( ( p ) => (
						<option key={ p.id } value={ p.name } />
					) ) }
				</datalist>
			</label>

			<div className="be-what-i-know__log-actions">
				<button
					type="button"
					disabled={
						saving ||
						! characterId ||
						title.trim() === '' ||
						details.trim() === ''
					}
					onClick={ submit }
				>
					{ saving
						? __( 'Sending…', 'beyond-elysium' )
						: __( 'Send', 'beyond-elysium' ) }
				</button>
				<button type="button" onClick={ () => setOpen( false ) }>
					{ __( 'Cancel', 'beyond-elysium' ) }
				</button>
				{ message && <span>{ message }</span> }
				{ error && (
					<span className="be-what-i-know__error" role="alert">
						{ error }
					</span>
				) }
			</div>
		</div>
	);
}

function TellPanel( {
	gameSlug,
	row,
	profiles,
	onSent,
}: {
	gameSlug: string;
	row: MySecretRow;
	profiles: CharacterProfile[];
	onSent: () => void;
} ) {
	const [ open, setOpen ] = useState( false );
	const [ recipientId, setRecipientId ] = useState< number | '' >( '' );
	const [ note, setNote ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ sent, setSent ] = useState( false );

	const candidates = profiles.filter( ( p ) => p.id !== row.character_id );

	function submit() {
		if ( ! recipientId ) {
			return;
		}
		setSaving( true );
		setError( null );
		api.secrets( gameSlug )
			.pass( row.id, {
				from_character_id: row.character_id,
				to_character_id: recipientId,
				...( note.trim() ? { note: note.trim() } : {} ),
			} )
			.then( () => {
				setSent( true );
				onSent();
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setSaving( false ) );
	}

	if ( sent ) {
		return (
			<p className="be-what-i-know__sent">
				{ __( 'Sent.', 'beyond-elysium' ) }
			</p>
		);
	}

	if ( ! open ) {
		return (
			<button type="button" onClick={ () => setOpen( true ) }>
				{ __( 'Tell someone', 'beyond-elysium' ) }
			</button>
		);
	}

	return (
		<div className="be-what-i-know__tell-panel">
			<select
				value={ recipientId }
				onChange={ ( e ) =>
					setRecipientId(
						e.target.value ? Number( e.target.value ) : ''
					)
				}
			>
				<option value="">
					{ __( '— choose who —', 'beyond-elysium' ) }
				</option>
				{ candidates.map( ( p ) => (
					<option key={ p.id } value={ p.id }>
						{ p.name }
					</option>
				) ) }
			</select>
			<input
				type="text"
				placeholder={ __( 'A note (optional)', 'beyond-elysium' ) }
				value={ note }
				onChange={ ( e ) => setNote( e.target.value ) }
			/>
			<button
				type="button"
				disabled={ saving || ! recipientId }
				onClick={ submit }
			>
				{ saving
					? __( 'Sending…', 'beyond-elysium' )
					: __( 'Send', 'beyond-elysium' ) }
			</button>
			<button type="button" onClick={ () => setOpen( false ) }>
				{ __( 'Cancel', 'beyond-elysium' ) }
			</button>
			{ error && (
				<span className="be-what-i-know__error" role="alert">
					{ error }
				</span>
			) }
		</div>
	);
}

export function WhatIKnow( { gameSlug }: WhatIKnowProps ) {
	const [ data, setData ] = useState< MySecretsResponse | null >( null );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ profiles, setProfiles ] = useState< CharacterProfile[] >( [] );
	const [ people, setPeople ] = useState< SecretPerson[] >( [] );
	const [ error, setError ] = useState< string | null >( null );

	function load() {
		setError( null );
		api.secrets( gameSlug )
			.mine()
			.then( setData )
			.catch( () =>
				setError(
					__( 'Failed to load what you know.', 'beyond-elysium' )
				)
			);
	}

	useEffect( () => {
		setData( null );
		load();
		api.characters( gameSlug )
			.myCharacters()
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
		api.npcs( gameSlug )
			.profiles()
			.then( setProfiles )
			.catch( () => setProfiles( [] ) );
		api.secrets( gameSlug )
			.people()
			.then( setPeople )
			.catch( () => setPeople( [] ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug ] );

	const items = data?.known ?? null;
	const waiting = data?.waiting ?? [];

	return (
		<div className="be-what-i-know">
			<div className="be-help-heading">
				<h2>{ __( 'What I Know', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="what-i-know" />
			</div>

			{ characters.length > 0 && (
				<LogForm
					gameSlug={ gameSlug }
					characters={ characters }
					people={ people }
					onLogged={ load }
				/>
			) }

			{ error && (
				<div className="be-what-i-know__error" role="alert">
					{ error }
				</div>
			) }

			{ ! error && items === null && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }

			{ ! error && waiting.length > 0 && (
				<div className="be-what-i-know__waiting">
					<h3>{ __( 'Waiting', 'beyond-elysium' ) }</h3>
					{ waiting.map( ( row: MyWaitingKnowledgeRow ) => (
						<div
							className="be-what-i-know__waiting-row"
							key={ `${ row.change_type }-${ row.id }` }
						>
							<strong>{ row.title }</strong>
							<span>
								{ row.character_name }
								{ ' — ' }
								{ row.status === 'rejected'
									? __( 'Refused', 'beyond-elysium' )
									: __(
											'Waiting for a Storyteller',
											'beyond-elysium'
										) }
							</span>
							{ row.status === 'rejected' && row.review_notes && (
								<p>{ row.review_notes }</p>
							) }
						</div>
					) ) }
				</div>
			) }

			{ ! error && items !== null && items.length === 0 && (
				<p>
					{ __(
						"You don't know any secrets yet.",
						'beyond-elysium'
					) }
				</p>
			) }

			{ ! error && items !== null && items.length > 0 && (
				<div className="be-what-i-know__list">
					{ items.map( ( row ) => (
						<div
							className="be-what-i-know__item"
							key={ row.reveal_id }
						>
							<div className="be-what-i-know__item-header">
								<strong>{ row.title }</strong>
								{ row.entity_type && (
									<span className="be-st-badge">
										{ ENTITY_LABELS[ row.entity_type ] ??
											row.entity_type }
										{ row.entity_name
											? `: ${ row.entity_name }`
											: '' }
									</span>
								) }
							</div>
							{ row.content && (
								<div
									dangerouslySetInnerHTML={ {
										__html: highlightStMarkers(
											row.content
										),
									} }
								/>
							) }
							{ row.told_by && (
								<p className="be-what-i-know__told-by">
									{ sprintf(
										/* translators: %s: the character who told them */
										__( 'Told by %s', 'beyond-elysium' ),
										row.told_by
									) }
								</p>
							) }
							{ ! row.approved && (
								<p className="be-what-i-know__pending">
									{ __(
										'Waiting for a Storyteller before it can be passed on.',
										'beyond-elysium'
									) }
								</p>
							) }
							<p className="be-what-i-know__learned">
								{ sprintf(
									/* translators: %s: when the secret was learned */
									__( 'Learned %s', 'beyond-elysium' ),
									row.learned_at
								) }
							</p>
							{ row.can_pass && (
								<TellPanel
									gameSlug={ gameSlug }
									row={ row }
									profiles={ profiles }
									onSent={ load }
								/>
							) }
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

export default WhatIKnow;
