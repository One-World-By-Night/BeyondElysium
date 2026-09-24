/**
 * A player proposes a coterie, pack, cabal, or motley for their own character.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HtmlEditor from '../shared/HtmlEditor';
import HelpButton from '../shared/HelpButton';
import { PLAYER_PROPOSABLE_FACTION_TYPES } from '../../types/faction';
import type { Character } from '../../types/character';
import './ProposeFaction.css';

export interface ProposeFactionProps {
	gameSlug: string;
	/**
	 * The character the proposal is attached.
	 */
	characterId: number;
}

import { errorMessage } from '../../lib/errorMessage';

export function ProposeFaction( {
	gameSlug,
	characterId,
}: ProposeFactionProps ) {
	const [ factionType, setFactionType ] = useState< string >(
		PLAYER_PROPOSABLE_FACTION_TYPES[ 0 ]
	);
	const [ name, setName ] = useState( '' );
	const [ goals, setGoals ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ submitted, setSubmitted ] = useState( false );
	const [ character, setCharacter ] = useState< Character | null >( null );

	useEffect( () => {
		api.characters( gameSlug )
			.get( characterId )
			.then( setCharacter )
			.catch( () => setCharacter( null ) );
	}, [ gameSlug, characterId ] );

	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! name.trim() ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			await api.changes( gameSlug ).create( characterId, {
				change_type: 'propose_faction',
				category: 'faction',
				change_data: {
					faction_type: factionType,
					name: name.trim(),
					goals,
				},
			} );
			setSubmitted( true );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSaving( false );
		}
	}

	function reset() {
		setSubmitted( false );
		setName( '' );
		setGoals( '' );
	}

	if ( submitted ) {
		return (
			<div className="be-propose-faction">
				<p role="status">
					{ __(
						'Sent to your Storytellers. Your character becomes its leader once someone approves it.',
						'beyond-elysium'
					) }
				</p>
				<button type="button" onClick={ reset }>
					{ __( 'Propose another', 'beyond-elysium' ) }
				</button>
			</div>
		);
	}

	return (
		<form className="be-propose-faction" onSubmit={ submit }>
			<div className="be-help-heading">
				<h2>{ __( 'Propose a group', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="factions" />
			</div>
			<p>
				{ character
					? sprintf(
							/* translators: %s: character name. */
							__(
								'A coterie, pack, cabal, or motley %s leads. A Storyteller reviews it before it becomes real.',
								'beyond-elysium'
							),
							character.name
					  )
					: __(
							'A coterie, pack, cabal, or motley your character leads. A Storyteller reviews it before it becomes real.',
							'beyond-elysium'
					  ) }
			</p>

			{ error && (
				<div className="be-propose-faction__error" role="alert">
					{ error }
				</div>
			) }

			<label>
				{ __( 'What kind of group?', 'beyond-elysium' ) }
				<select
					value={ factionType }
					onChange={ ( e ) => setFactionType( e.target.value ) }
				>
					{ PLAYER_PROPOSABLE_FACTION_TYPES.map( ( t ) => (
						<option key={ t } value={ t }>
							{ t }
						</option>
					) ) }
				</select>
			</label>

			<label>
				{ __( 'Name', 'beyond-elysium' ) }
				<input
					type="text"
					value={ name }
					required
					onChange={ ( e ) => setName( e.target.value ) }
				/>
			</label>

			<div className="be-propose-faction__field">
				<span>{ __( 'Goals', 'beyond-elysium' ) }</span>
				<HtmlEditor
					id={ `be-propose-faction-goals-${ characterId }` }
					defaultValue=""
					rows={ 4 }
					onChange={ setGoals }
				/>
			</div>

			<button type="submit" disabled={ saving || ! name.trim() }>
				{ saving
					? __( 'Sending…', 'beyond-elysium' )
					: __( 'Send for approval', 'beyond-elysium' ) }
			</button>
		</form>
	);
}

export default ProposeFaction;
