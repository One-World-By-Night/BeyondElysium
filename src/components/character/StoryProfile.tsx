/**
 * The Who's Who profile in the Story view, saved as you type: a Storyteller's for an NPC (who may see it, with its own
 * portrait) and a player's for their own character (whether it shows at all and whether the player is named).
 */
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import AudiencePicker from '../shared/AudiencePicker';
import HelpButton from '../shared/HelpButton';
import HtmlEditor from '../shared/HtmlEditor';
import SaveStatus from './SaveStatus';
import { errorMessage } from '../../lib/errorMessage';
import { pickMediaImage } from '../../lib/pickMediaImage';
import { useAutosave } from '../../lib/useAutosave';
import type { AudienceRules, AudienceValue } from '../../types/plot';
import type { Character } from '../../types/character';

export interface StoryProfileProps {
	gameSlug: string;
	character: Character;
	/**
	 * A Storyteller editing an NPC's profile, or a player editing their own character's.
	 */
	mode: 'npc' | 'player';
}

interface ProfileValue {
	public_name: string;
	public_description: string;
	public_image_id: number | null;
	profile_audience: AudienceValue;
	profile_audience_rules: AudienceRules | null;
	profile_show_player: boolean;
}

export function StoryProfile( {
	gameSlug,
	character,
	mode,
}: StoryProfileProps ) {
	const id = character.id;
	const [ value, setValue ] = useState< ProfileValue >( () => ( {
		public_name: character.public_name ?? '',
		public_description: character.public_description ?? '',
		public_image_id: character.public_image_id ?? null,
		profile_audience: character.profile_audience ?? 'storytellers',
		profile_audience_rules: character.profile_audience_rules ?? null,
		profile_show_player: !! character.profile_show_player,
	} ) );
	const latest = useRef( value );
	const [ imageUrl, setImageUrl ] = useState< string | null >( null );
	const [ attachment, setAttachment ] = useState< {
		id: number;
		original_name: string;
	} | null >(
		character.attachments?.[ 0 ]
			? {
					id: character.attachments[ 0 ].id,
					original_name: character.attachments[ 0 ].original_name,
				}
			: null
	);
	const [ uploading, setUploading ] = useState( false );
	const [ uploadError, setUploadError ] = useState< string | null >( null );

	const autosave = useAutosave< ProfileValue >( value, async ( next ) => {
		await api.npcs( gameSlug ).updateProfile(
			id,
			mode === 'npc'
				? {
						public_name: next.public_name,
						public_description: next.public_description,
						public_image_id: next.public_image_id,
						profile_audience: next.profile_audience,
						profile_audience_rules: next.profile_audience_rules,
					}
				: {
						public_name: next.public_name,
						public_description: next.public_description,
						profile_audience: next.profile_audience,
						profile_show_player: next.profile_show_player,
					}
		);
	} );

	function update( patch: Partial< ProfileValue >, now = false ) {
		const next = { ...latest.current, ...patch };
		latest.current = next;
		setValue( next );
		autosave.change( next );
		if ( now ) {
			autosave.flush();
		}
	}

	async function choosePublicImage() {
		const picked = await pickMediaImage(
			__( "Choose a Who's Who portrait", 'beyond-elysium' )
		);
		if ( picked ) {
			setImageUrl( picked.url );
			update( { public_image_id: picked.id }, true );
		}
	}

	async function uploadPortrait( file: File ) {
		setUploading( true );
		setUploadError( null );
		try {
			setAttachment(
				await api
					.attachments( gameSlug )
					.upload( 'character', id, file )
			);
		} catch ( error: unknown ) {
			setUploadError(
				errorMessage(
					error,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setUploading( false );
		}
	}

	return (
		<section className="be-story__section" onBlur={ autosave.flush }>
			<div className="be-help-heading">
				<h3>{ __( "Who's Who Profile", 'beyond-elysium' ) }</h3>
				<HelpButton helpKey="whos-who" />
			</div>
			<p className="be-story__hint">
				{ mode === 'npc'
					? __(
							"What a player sees about this NPC in the chronicle's Who's Who directory - separate from the sheet.",
							'beyond-elysium'
						)
					: __(
							"What other players see about your character in the chronicle's Who's Who directory, whether it appears there at all, and whether your own name shows alongside it.",
							'beyond-elysium'
						) }
			</p>

			<label className="be-story__field">
				<span>{ __( 'Display Name', 'beyond-elysium' ) }</span>
				<input
					type="text"
					placeholder={ character.name }
					value={ value.public_name }
					onChange={ ( e ) =>
						update( { public_name: e.target.value } )
					}
				/>
			</label>

			<h4>{ __( 'Description', 'beyond-elysium' ) }</h4>
			<HtmlEditor
				id={ `be-story-public-description-${ id }` }
				defaultValue={ value.public_description }
				onChange={ ( html ) => update( { public_description: html } ) }
				aiAssist={
					mode === 'npc'
						? {
								capability: 'be_manage_characters',
								fieldContext: 'npc_public_description',
								gameSlug,
							}
						: undefined
				}
			/>

			{ mode === 'npc' && (
				<>
					<div className="be-story__field">
						{ imageUrl && (
							<img
								className="be-story__portrait"
								src={ imageUrl }
								alt=""
							/>
						) }
						<button type="button" onClick={ choosePublicImage }>
							{ value.public_image_id
								? __( 'Change portrait…', 'beyond-elysium' )
								: __(
										"Choose a Who's Who portrait…",
										'beyond-elysium'
									) }
						</button>
					</div>
					<h4>
						{ __( 'Who Can See This Profile', 'beyond-elysium' ) }
					</h4>
					<AudiencePicker
						gameSlug={ gameSlug }
						audience={ value.profile_audience }
						audienceRules={ value.profile_audience_rules }
						onChange={ ( audience, rules ) =>
							update(
								{
									profile_audience: audience,
									profile_audience_rules: rules,
								},
								true
							)
						}
					/>
				</>
			) }

			{ mode === 'player' && (
				<>
					<label className="be-story__check">
						<input
							type="checkbox"
							checked={ value.profile_audience === 'everyone' }
							onChange={ ( e ) =>
								update(
									{
										profile_audience: e.target.checked
											? 'everyone'
											: 'storytellers',
									},
									true
								)
							}
						/>
						{ __(
							"Show this character in Who's Who",
							'beyond-elysium'
						) }
					</label>
					<label className="be-story__check">
						<input
							type="checkbox"
							checked={ value.profile_show_player }
							onChange={ ( e ) =>
								update(
									{ profile_show_player: e.target.checked },
									true
								)
							}
						/>
						{ __(
							'Name me as the player behind this character',
							'beyond-elysium'
						) }
					</label>
					<div className="be-story__field">
						{ attachment && (
							<span>{ attachment.original_name }</span>
						) }
						<input
							type="file"
							accept="image/*"
							disabled={ uploading }
							onChange={ ( e ) => {
								const file = e.target.files?.[ 0 ];
								if ( file ) {
									void uploadPortrait( file );
								}
							} }
						/>
						{ uploading && (
							<span>
								{ __( 'Uploading…', 'beyond-elysium' ) }
							</span>
						) }
						{ uploadError && (
							<span className="be-story__failed" role="alert">
								{ uploadError }
							</span>
						) }
					</div>
				</>
			) }

			<SaveStatus
				state={ autosave.state }
				message={ autosave.message }
				onRetry={ autosave.retry }
			/>
		</section>
	);
}

export default StoryProfile;
