/**
 * The Story view: the portrait, Background and Notes, the Who's Who profile, a Storyteller's own RP notes and a
 * player's Request XP. Everything saves as you type.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HtmlEditor from '../shared/HtmlEditor';
import HelpButton from '../shared/HelpButton';
import RequestXpPanel from './RequestXpPanel';
import SaveStatus from './SaveStatus';
import StoryProfile from './StoryProfile';
import { changedFields } from '../../lib/changedFields';
import { errorMessage } from '../../lib/errorMessage';
import { pickMediaImage } from '../../lib/pickMediaImage';
import { useAutosave } from '../../lib/useAutosave';
import type { Character } from '../../types/character';
import './CharacterStory.css';

export interface CharacterStoryProps {
	characterId: number;
	gameSlug: string;
}

type Prose = {
	biography: string;
	notes: string;
};

/**
 * Background and Notes, saved a moment after typing stops.
 */
function StoryProse( {
	gameSlug,
	character,
	readOnly,
	register,
}: {
	gameSlug: string;
	character: Character;
	readOnly: boolean;
	register: ( unsaved: () => boolean ) => void;
} ) {
	const saved = useRef< Prose >( {
		biography: character.biography ?? '',
		notes: character.notes ?? '',
	} );
	const held = useRef< Prose >( { ...saved.current } );
	const autosave = useAutosave< Prose >( saved.current, async ( next ) => {
		const edited = changedFields( next, saved.current );
		if ( Object.keys( edited ).length > 0 ) {
			await api.characters( gameSlug ).update( character.id, edited );
			saved.current = { ...saved.current, ...edited };
		}
	} );
	register( autosave.hasUnsaved );

	function change( patch: Partial< Prose > ) {
		held.current = { ...held.current, ...patch };
		autosave.change( held.current );
	}

	return (
		<section className="be-story__section" onBlur={ autosave.flush }>
			<h3>{ __( 'Background', 'beyond-elysium' ) }</h3>
			<HtmlEditor
				id={ `be-story-biography-${ character.id }` }
				defaultValue={ held.current.biography }
				onChange={ ( html ) => change( { biography: html } ) }
				readOnly={ readOnly }
				aiAssist={ {
					capability: 'be_manage_characters',
					fieldContext: 'character_biography',
					gameSlug,
				} }
			/>
			<h3>{ __( 'Notes', 'beyond-elysium' ) }</h3>
			<HtmlEditor
				id={ `be-story-notes-${ character.id }` }
				defaultValue={ held.current.notes }
				onChange={ ( html ) => change( { notes: html } ) }
				readOnly={ readOnly }
				aiAssist={ {
					capability: 'be_manage_characters',
					fieldContext: 'character_notes',
					gameSlug,
				} }
			/>
			{ ! readOnly && (
				<SaveStatus
					state={ autosave.state }
					message={ autosave.message }
					onRetry={ autosave.retry }
				/>
			) }
		</section>
	);
}

/**
 * A Storyteller's own notes on a character, in plain text no player ever sees.
 */
function StoryRpNotes( {
	gameSlug,
	character,
	register,
}: {
	gameSlug: string;
	character: Character;
	register: ( unsaved: () => boolean ) => void;
} ) {
	const [ text, setText ] = useState( character.rp_notes ?? '' );
	const autosave = useAutosave< string >( text, async ( next ) => {
		await api
			.characters( gameSlug )
			.update( character.id, { rp_notes: next } );
	} );
	register( autosave.hasUnsaved );

	return (
		<section className="be-story__section">
			<h3>{ __( 'RP notes', 'beyond-elysium' ) }</h3>
			<p className="be-story__hint">
				{ __(
					'Only Storytellers see these; the player never does.',
					'beyond-elysium'
				) }
			</p>
			<textarea
				className="be-story__rp-notes"
				rows={ 6 }
				value={ text }
				onChange={ ( e ) => {
					setText( e.target.value );
					autosave.change( e.target.value );
				} }
				onBlur={ autosave.flush }
			/>
			<SaveStatus
				state={ autosave.state }
				message={ autosave.message }
				onRetry={ autosave.retry }
			/>
		</section>
	);
}

export function CharacterStory( {
	characterId,
	gameSlug,
}: CharacterStoryProps ) {
	const [ character, setCharacter ] = useState< Character | null >( null );
	const [ failed, setFailed ] = useState< string | null >( null );
	const [ portrait, setPortrait ] = useState< string | null >( null );
	const [ portraitError, setPortraitError ] = useState< string | null >(
		null
	);
	const unsaved = useRef< Array< () => boolean > >( [] );

	useEffect( () => {
		let live = true;
		api.characters( gameSlug )
			.get( characterId )
			.then( ( loaded ) => {
				if ( live ) {
					setCharacter( loaded );
					setPortrait( loaded.image_url ?? null );
				}
			} )
			.catch( ( error: unknown ) => {
				if ( live ) {
					setFailed(
						errorMessage(
							error,
							__(
								'Failed to load the character sheet.',
								'beyond-elysium'
							)
						)
					);
				}
			} );
		return () => {
			live = false;
		};
	}, [ gameSlug, characterId ] );

	useEffect( () => {
		const guard = ( e: BeforeUnloadEvent ) => {
			if ( unsaved.current.some( ( pending ) => pending() ) ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', guard );
		return () => window.removeEventListener( 'beforeunload', guard );
	}, [] );

	function register( pending: () => boolean ) {
		if ( ! unsaved.current.includes( pending ) ) {
			unsaved.current = [ ...unsaved.current, pending ];
		}
	}

	async function choosePortrait() {
		const picked = await pickMediaImage(
			__( 'Choose a character portrait', 'beyond-elysium' )
		);
		if ( ! picked ) {
			return;
		}
		setPortraitError( null );
		try {
			await api
				.characters( gameSlug )
				.update( characterId, { image_id: picked.id } );
			setPortrait( picked.url );
		} catch ( error: unknown ) {
			setPortraitError(
				errorMessage(
					error,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		}
	}

	if ( failed ) {
		return <p role="alert">{ failed }</p>;
	}
	if ( ! character ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	const canEdit = !! character.can_edit;
	const canManage = !! character.can_manage;

	return (
		<div className="be-story">
			<div className="be-help-heading">
				<h3>{ __( 'Story', 'beyond-elysium' ) }</h3>
				<HelpButton helpKey="character-editor" />
			</div>

			{ canEdit && (
				<section className="be-story__section">
					{ portrait && (
						<img
							className="be-story__portrait"
							src={ portrait }
							alt=""
						/>
					) }
					<button type="button" onClick={ choosePortrait }>
						{ portrait
							? __( 'Change portrait…', 'beyond-elysium' )
							: __( 'Add a portrait…', 'beyond-elysium' ) }
					</button>
					{ portraitError && (
						<p className="be-story__failed" role="alert">
							{ portraitError }
						</p>
					) }
				</section>
			) }

			<StoryProse
				gameSlug={ gameSlug }
				character={ character }
				readOnly={ ! canEdit }
				register={ register }
			/>

			{ canManage && character.is_npc && (
				<StoryProfile
					gameSlug={ gameSlug }
					character={ character }
					mode="npc"
				/>
			) }
			{ ! canManage && canEdit && ! character.is_npc && (
				<StoryProfile
					gameSlug={ gameSlug }
					character={ character }
					mode="player"
				/>
			) }

			{ canManage && (
				<StoryRpNotes
					gameSlug={ gameSlug }
					character={ character }
					register={ register }
				/>
			) }

			{ canEdit && ! canManage && (
				<section className="be-story__section">
					<RequestXpPanel
						gameSlug={ gameSlug }
						characterId={ character.id }
					/>
				</section>
			) }
		</div>
	);
}

export default CharacterStory;
