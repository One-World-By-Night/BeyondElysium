/**
 * A Storyteller's chronicle-wide list of every secret, searchable by title, each showing what it's attached to and
 * who it's been revealed to - plus a form to start a brand new one, optionally unattached to anything.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { SecretRow } from './SecretsPanel';
import './SecretsPanel.css';
import './SecretsList.css';
import HelpButton from './HelpButton';
import HtmlEditor from './HtmlEditor';
import { SearchableSelect } from './SearchableSelect';
import { useSecretEntityNames } from '../../lib/useSecretEntityNames';
import { useSecretsSearch } from '../../lib/useSecretsSearch';
import type { Secret, SecretEntityType } from '../../types/secret';

export interface SecretsListProps {
	gameSlug: string;
}

const ENTITY_LABELS: Record< string, string > = {
	plot: __( 'Plot', 'beyond-elysium' ),
	item: __( 'Item', 'beyond-elysium' ),
	location: __( 'Location', 'beyond-elysium' ),
	character: __( 'Character', 'beyond-elysium' ),
	npc: __( 'NPC', 'beyond-elysium' ),
};

const SECRET_ENTITY_TYPES: SecretEntityType[] = [
	'plot',
	'item',
	'location',
	'character',
	'npc',
];

export function SecretsList( { gameSlug }: SecretsListProps ) {
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );
	const { secrets, refresh } = useSecretsSearch( gameSlug, searchInput, () =>
		setError( __( 'Failed to load secrets.', 'beyond-elysium' ) )
	);
	const { characters, namesByType, nameFor } =
		useSecretEntityNames( gameSlug );

	const [ creating, setCreating ] = useState( false );
	const [ newTitle, setNewTitle ] = useState( '' );
	const [ newContent, setNewContent ] = useState( '' );
	const [ newEntityType, setNewEntityType ] = useState< string >( '' );
	const [ newEntityQuery, setNewEntityQuery ] = useState( '' );
	const [ newEntityId, setNewEntityId ] = useState< number | undefined >(
		undefined
	);

	async function createSecret( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! newTitle.trim() ) {
			return;
		}
		setCreating( true );
		setError( null );
		try {
			await api.secrets( gameSlug ).create( {
				...( newEntityType && newEntityId
					? {
							entity_type: newEntityType as SecretEntityType,
							entity_id: newEntityId,
						}
					: {} ),
				title: newTitle.trim(),
				content: newContent || undefined,
			} );
			setNewTitle( '' );
			setNewContent( '' );
			setNewEntityType( '' );
			setNewEntityQuery( '' );
			setNewEntityId( undefined );
			refresh();
		} catch {
			setError( __( 'Failed to create this secret.', 'beyond-elysium' ) );
		} finally {
			setCreating( false );
		}
	}

	async function deleteSecret( id: number ) {
		try {
			await api.secrets( gameSlug ).remove( id );
			refresh();
		} catch {
			setError( __( 'Failed to delete this secret.', 'beyond-elysium' ) );
		}
	}

	async function updateAudience(
		secret: Secret,
		audience: Secret[ 'audience' ],
		rules: Secret[ 'audience_rules' ]
	) {
		try {
			await api.secrets( gameSlug ).update( secret.id, {
				audience,
				audience_rules: rules,
			} );
			refresh();
		} catch {
			setError( __( 'Failed to update this secret.', 'beyond-elysium' ) );
		}
	}

	const newEntityNames =
		newEntityType && newEntityType in namesByType
			? namesByType[ newEntityType as SecretEntityType ]
			: new Map< string, number >();

	return (
		<div className="be-secrets-list">
			<div className="be-help-heading">
				<h2>{ __( 'Secrets', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="secrets" />
			</div>

			{ error && (
				<p className="be-secrets-list__error" role="alert">
					{ error }
				</p>
			) }

			<form
				className="be-secrets-list__create-form"
				onSubmit={ createSecret }
			>
				<input
					type="text"
					placeholder={ __( 'New secret title…', 'beyond-elysium' ) }
					value={ newTitle }
					onChange={ ( e ) => setNewTitle( e.target.value ) }
				/>
				<select
					value={ newEntityType }
					onChange={ ( e ) => {
						setNewEntityType( e.target.value );
						setNewEntityQuery( '' );
						setNewEntityId( undefined );
					} }
				>
					<option value="">
						{ __(
							'— not attached to anything —',
							'beyond-elysium'
						) }
					</option>
					{ SECRET_ENTITY_TYPES.map( ( t ) => (
						<option key={ t } value={ t }>
							{ ENTITY_LABELS[ t ] }
						</option>
					) ) }
				</select>
				{ newEntityType && (
					<SearchableSelect
						options={ Array.from( newEntityNames.keys() ) }
						value={ newEntityQuery }
						onChange={ ( display ) => {
							setNewEntityQuery( display );
							setNewEntityId( newEntityNames.get( display ) );
						} }
						placeholder={ __( 'Search…', 'beyond-elysium' ) }
						ariaLabel={ __( 'Which one', 'beyond-elysium' ) }
					/>
				) }
				<HtmlEditor
					id="be-secrets-list-new-content"
					defaultValue={ newContent }
					onChange={ setNewContent }
				/>
				<button
					type="submit"
					disabled={
						creating ||
						! newTitle.trim() ||
						( !! newEntityType && ! newEntityId )
					}
				>
					{ __( 'Add Secret', 'beyond-elysium' ) }
				</button>
			</form>

			<input
				type="search"
				placeholder={ __( 'Search by title…', 'beyond-elysium' ) }
				value={ searchInput }
				onChange={ ( e ) => setSearchInput( e.target.value ) }
			/>

			{ secrets === null ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : secrets.length === 0 ? (
				<p>{ __( 'No secrets found.', 'beyond-elysium' ) }</p>
			) : (
				<div className="be-secrets-list__rows">
					{ secrets.map( ( secret ) => {
						const attachedLabel =
							secret.entity_type === null
								? __(
										'Not attached to anything',
										'beyond-elysium'
									)
								: sprintf(
										/* translators: 1: entity type label (Plot, Item, Location, Character, NPC), 2: that entity's own name, or its id when the name hasn't loaded yet */
										__(
											'Attached to %1$s: %2$s',
											'beyond-elysium'
										),
										ENTITY_LABELS[ secret.entity_type ] ??
											secret.entity_type,
										nameFor(
											secret.entity_type,
											secret.entity_id as number
										) ??
											sprintf(
												'#%d',
												secret.entity_id as number
											)
									);
						return (
							<div
								className="be-secrets-list__entry"
								key={ secret.id }
							>
								<p className="be-secrets-list__attached-to">
									{ attachedLabel }
								</p>
								<SecretRow
									gameSlug={ gameSlug }
									secret={ secret }
									characters={ characters }
									onAudienceChange={ ( audience, rules ) =>
										updateAudience(
											secret,
											audience,
											rules
										)
									}
									onDelete={ () => deleteSecret( secret.id ) }
									onRevealsChanged={ refresh }
								/>
							</div>
						);
					} ) }
				</div>
			) }
		</div>
	);
}

export default SecretsList;
