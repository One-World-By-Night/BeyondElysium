/**
 * A Storyteller's own view of the secrets attached to one entity - a plot, item, location,
 * or NPC (1.1.0 §3.11): create/edit/delete a secret, set its own audience, and reveal it to
 * characters (optionally held for a release batch). Embedded in the plot, item, location,
 * and NPC editors alike - one shared component rather than four near-identical copies.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import AudiencePicker from './AudiencePicker';
import HtmlEditor from './HtmlEditor';
import HelpButton from './HelpButton';
import type { AudienceRules, AudienceValue } from '../../types/plot';
import type { Character } from '../../types/character';
import type {
	Secret,
	SecretEntityType,
	SecretReveal,
	RevealHow,
} from '../../types/secret';
import './SecretsPanel.css';

export interface SecretsPanelProps {
	gameSlug: string;
	entityType: SecretEntityType;
	entityId: number;
}

const HOW_OPTIONS: { value: RevealHow; label: string }[] = [
	{ value: 'game', label: __( 'In game', 'beyond-elysium' ) },
	{ value: 'downtime', label: __( 'Downtime', 'beyond-elysium' ) },
	{ value: 'rumor', label: __( 'Rumor', 'beyond-elysium' ) },
	{ value: 'other', label: __( 'Other', 'beyond-elysium' ) },
];

export function SecretsPanel( {
	gameSlug,
	entityType,
	entityId,
}: SecretsPanelProps ) {
	const [ items, setItems ] = useState< Secret[] >( [] );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ error, setError ] = useState< string | null >( null );

	const [ newTitle, setNewTitle ] = useState( '' );
	const [ creating, setCreating ] = useState( false );

	function load() {
		api.secrets( gameSlug )
			.list( entityType, entityId )
			.then( setItems )
			.catch( () =>
				setError( __( 'Failed to load secrets.', 'beyond-elysium' ) )
			);
	}

	useEffect( load, [ gameSlug, entityType, entityId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		everyPage( ( page ) =>
			api.characters( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
	}, [ gameSlug ] );

	const [ contentDraft, setContentDraft ] = useState( '' );

	async function createSecret( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! newTitle.trim() ) {
			return;
		}
		setCreating( true );
		setError( null );
		try {
			await api.secrets( gameSlug ).create( {
				entity_type: entityType,
				entity_id: entityId,
				title: newTitle.trim(),
				content: contentDraft || undefined,
			} );
			setNewTitle( '' );
			setContentDraft( '' );
			load();
		} catch {
			setError( __( 'Failed to create this secret.', 'beyond-elysium' ) );
		} finally {
			setCreating( false );
		}
	}

	async function updateAudience(
		secret: Secret,
		audience: AudienceValue,
		audienceRules: AudienceRules | null
	) {
		try {
			await api.secrets( gameSlug ).update( secret.id, {
				audience,
				audience_rules: audienceRules,
			} );
			load();
		} catch {
			setError( __( 'Failed to update this secret.', 'beyond-elysium' ) );
		}
	}

	async function deleteSecret( id: number ) {
		try {
			await api.secrets( gameSlug ).remove( id );
			load();
		} catch {
			setError( __( 'Failed to delete this secret.', 'beyond-elysium' ) );
		}
	}

	return (
		<div className="be-secrets-panel">
			<div className="be-help-heading">
				<h4>{ __( 'Secrets', 'beyond-elysium' ) }</h4>
				<HelpButton helpKey="secrets" />
			</div>
			{ error && (
				<div className="be-secrets-panel__error" role="alert">
					{ error }
				</div>
			) }

			{ items.length === 0 ? (
				<p>{ __( 'No secrets yet.', 'beyond-elysium' ) }</p>
			) : (
				<div className="be-secrets-panel__list">
					{ items.map( ( secret ) => (
						<SecretRow
							key={ secret.id }
							gameSlug={ gameSlug }
							secret={ secret }
							characters={ characters }
							onAudienceChange={ ( audience, rules ) =>
								updateAudience( secret, audience, rules )
							}
							onDelete={ () => deleteSecret( secret.id ) }
							onRevealsChanged={ load }
						/>
					) ) }
				</div>
			) }

			<form className="be-secrets-panel__form" onSubmit={ createSecret }>
				<input
					type="text"
					placeholder={ __( 'New secret title…', 'beyond-elysium' ) }
					value={ newTitle }
					onChange={ ( e ) => setNewTitle( e.target.value ) }
				/>
				<HtmlEditor
					id={ `be-secret-new-${ entityType }-${ entityId }` }
					defaultValue={ contentDraft }
					onChange={ setContentDraft }
				/>
				<button
					type="submit"
					disabled={ creating || ! newTitle.trim() }
				>
					{ __( 'Add Secret', 'beyond-elysium' ) }
				</button>
			</form>
		</div>
	);
}

function SecretRow( {
	gameSlug,
	secret,
	characters,
	onAudienceChange,
	onDelete,
	onRevealsChanged,
}: {
	gameSlug: string;
	secret: Secret;
	characters: Character[];
	onAudienceChange: (
		audience: AudienceValue,
		rules: AudienceRules | null
	) => void;
	onDelete: () => void;
	onRevealsChanged: () => void;
} ) {
	const [ expanded, setExpanded ] = useState( false );
	const [ reveals, setReveals ] = useState< SecretReveal[] | null >( null );
	const [ characterId, setCharacterId ] = useState( '' );
	const [ how, setHow ] = useState< RevealHow >( 'game' );
	const [ held, setHeld ] = useState( false );
	const [ revealing, setRevealing ] = useState( false );

	function loadReveals() {
		api.secrets( gameSlug )
			.reveals( secret.id )
			.then( setReveals )
			.catch( () => setReveals( [] ) );
	}

	useEffect( () => {
		if ( expanded ) {
			loadReveals();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ expanded ] );

	async function addReveal( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! characterId ) {
			return;
		}
		setRevealing( true );
		try {
			await api.secrets( gameSlug ).createReveal( secret.id, {
				character_id: Number( characterId ),
				how,
				held,
			} );
			setCharacterId( '' );
			loadReveals();
			onRevealsChanged();
		} catch {
			// Surfaced via the parent's own error banner on next load failure; kept local and
			// silent here since a 409 (already revealed) is self-explanatory from the list.
		} finally {
			setRevealing( false );
		}
	}

	async function removeReveal( revealId: number ) {
		await api.secrets( gameSlug ).deleteReveal( secret.id, revealId );
		loadReveals();
		onRevealsChanged();
	}

	function characterName( id: number ): string {
		return characters.find( ( c ) => c.id === id )?.name ?? `#${ id }`;
	}

	return (
		<div className="be-secrets-panel__row">
			<div className="be-secrets-panel__row-header">
				<button
					type="button"
					className="be-secrets-panel__title-button"
					onClick={ () => setExpanded( ( e ) => ! e ) }
				>
					{ secret.title }
				</button>
				<button type="button" onClick={ onDelete }>
					{ __( 'Delete', 'beyond-elysium' ) }
				</button>
			</div>

			{ expanded && (
				<div className="be-secrets-panel__row-body">
					{ secret.content && (
						<div
							// eslint-disable-next-line react/no-danger
							dangerouslySetInnerHTML={ {
								__html: secret.content,
							} }
						/>
					) }

					<AudiencePicker
						gameSlug={ gameSlug }
						audience={ secret.audience }
						audienceRules={ secret.audience_rules }
						onChange={ onAudienceChange }
					/>

					<h5>{ __( 'Revealed to', 'beyond-elysium' ) }</h5>
					{ reveals && reveals.length > 0 && (
						<ul className="be-secrets-panel__reveals">
							{ reveals.map( ( reveal ) => (
								<li key={ reveal.id }>
									{ characterName( reveal.character_id ) }
									{ reveal.held && (
										<span className="be-st-badge">
											{ __( 'held', 'beyond-elysium' ) }
										</span>
									) }
									<button
										type="button"
										onClick={ () =>
											removeReveal( reveal.id )
										}
									>
										{ __( 'Remove', 'beyond-elysium' ) }
									</button>
								</li>
							) ) }
						</ul>
					) }

					<form
						className="be-secrets-panel__reveal-form"
						onSubmit={ addReveal }
					>
						<select
							value={ characterId }
							onChange={ ( e ) =>
								setCharacterId( e.target.value )
							}
						>
							<option value="">
								{ __( 'Reveal to…', 'beyond-elysium' ) }
							</option>
							{ characters.map( ( c ) => (
								<option key={ c.id } value={ c.id }>
									{ c.name }
								</option>
							) ) }
						</select>
						<select
							value={ how }
							onChange={ ( e ) =>
								setHow( e.target.value as RevealHow )
							}
						>
							{ HOW_OPTIONS.map( ( o ) => (
								<option key={ o.value } value={ o.value }>
									{ o.label }
								</option>
							) ) }
						</select>
						<label>
							<input
								type="checkbox"
								checked={ held }
								onChange={ ( e ) =>
									setHeld( e.target.checked )
								}
							/>{ ' ' }
							{ __(
								'Hold for a release batch',
								'beyond-elysium'
							) }
						</label>
						<button
							type="submit"
							disabled={ revealing || ! characterId }
						>
							{ __( 'Reveal', 'beyond-elysium' ) }
						</button>
					</form>
				</div>
			) }
		</div>
	);
}

export default SecretsPanel;
