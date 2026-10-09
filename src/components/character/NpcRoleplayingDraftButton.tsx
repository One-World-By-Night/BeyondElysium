/**
 * Drafts a non-player character's own Storyteller-only roleplaying notes from that NPC's own data, filling
 * only its currently-empty fields.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import Modal from '../shared/Modal';
import HelpButton from '../shared/HelpButton';
import { AiDemoModal } from '../shared/AiDemoNotice';
import { useIsDemo } from '../shared/useIsDemo';
import '../shared/AiAssistButton.css';
import './NpcRoleplayingDraftButton.css';

export interface NpcRoleplayingDraftButtonProps {
	gameSlug: string;
	characterId: number;
	characterName: string;
	currentData: Record< string, string >;
	fieldNames: string[];
	onApply: ( merged: Record< string, string > ) => void;
}

/**
 * The names of every field that's blank, whitespace-only or has no value yet. `fieldNames` lists the fields the notes
 * block declares; with none given, the fields `currentData` already holds.
 */
export function emptyFieldsOf(
	currentData: Record< string, string >,
	fieldNames: string[] = Object.keys( currentData )
): string[] {
	return fieldNames.filter(
		( name ) => ( currentData[ name ] ?? '' ).trim() === ''
	);
}

/**
 * Only the drafted values for the given field names, dropping any blank or missing ones.
 */
export function mergeDrafted(
	emptyFields: string[],
	drafted: Record< string, string >
): Record< string, string > {
	const merged: Record< string, string > = {};
	for ( const key of emptyFields ) {
		const value = drafted[ key ];
		if ( typeof value === 'string' && value.trim() !== '' ) {
			merged[ key ] = value;
		}
	}
	return merged;
}

export function NpcRoleplayingDraftButton( {
	gameSlug,
	characterId,
	characterName,
	currentData,
	fieldNames,
	onApply,
}: NpcRoleplayingDraftButtonProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ drafted, setDrafted ] = useState< Record< string, string > | null >(
		null
	);
	const isDemo = useIsDemo( gameSlug );

	const canUse = !! window.beyondElysium?.capabilities?.be_manage_characters;
	if ( ! canUse ) {
		return null;
	}

	const emptyFields = emptyFieldsOf( currentData, fieldNames );

	function open() {
		setDrafted( null );
		setError( null );
		setIsOpen( true );
	}

	async function generate() {
		if ( isDemo ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const response = await api.aiAssist( gameSlug ).draftNpc( {
				character_id: characterId,
			} );
			setDrafted( response.data );
		} catch ( err: unknown ) {
			const message =
				typeof err === 'object' && err !== null && 'message' in err
					? String( ( err as { message?: unknown } ).message )
					: __( 'Something went wrong.', 'beyond-elysium' );
			setError( message );
		} finally {
			setLoading( false );
		}
	}

	function accept() {
		if ( drafted ) {
			onApply( mergeDrafted( emptyFields, drafted ) );
		}
		setIsOpen( false );
	}

	return (
		<>
			<button
				type="button"
				className="be-ai-assist-button"
				onClick={ open }
				disabled={ ! isDemo && emptyFields.length === 0 }
			>
				{ __( 'Draft roleplaying notes', 'beyond-elysium' ) }
			</button>
			<HelpButton helpKey="writing-assist" />
			{ isOpen && isDemo && (
				<AiDemoModal
					title={ __( 'Draft roleplaying notes', 'beyond-elysium' ) }
					onClose={ () => setIsOpen( false ) }
				/>
			) }
			{ isOpen && ! isDemo && (
				<Modal
					title={ __( 'Draft roleplaying notes', 'beyond-elysium' ) }
					onClose={ () => setIsOpen( false ) }
					footer={
						drafted ? (
							<>
								<button
									type="button"
									onClick={ generate }
									disabled={ loading }
								>
									{ __( 'Regenerate', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="button-primary"
									onClick={ accept }
								>
									{ __(
										'Apply to empty fields',
										'beyond-elysium'
									) }
								</button>
							</>
						) : (
							<>
								<button
									type="button"
									onClick={ () => setIsOpen( false ) }
								>
									{ __( 'Cancel', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="button-primary"
									onClick={ generate }
									disabled={
										loading || emptyFields.length === 0
									}
								>
									{ loading
										? __( 'Drafting…', 'beyond-elysium' )
										: __( 'Draft', 'beyond-elysium' ) }
								</button>
							</>
						)
					}
				>
					{ error && (
						<p className="be-npc-draft-button__error" role="alert">
							{ error }
						</p>
					) }
					{ drafted ? (
						<div className="be-npc-draft-button__preview">
							<p className="description">
								{ __(
									"Review before applying - nothing is saved until you click Apply, and the fields still need the section's own Save afterward.",
									'beyond-elysium'
								) }
							</p>
							{ emptyFields.map( ( key ) =>
								( drafted[ key ] ?? '' ).trim() !== '' ? (
									<div
										key={ key }
										className="be-npc-draft-button__field"
									>
										<strong>{ key }</strong>
										<p>{ drafted[ key ] }</p>
									</div>
								) : null
							) }
						</div>
					) : (
						<p className="description">
							{ emptyFields.length === 0
								? __(
										'Every field already has text - there is nothing empty to draft.',
										'beyond-elysium'
									)
								: sprintf(
										/* translators: 1: character name, 2: comma-separated list of empty field names */
										__(
											"Sends %1$s's own name, creature type, identity fields, public profile, biography and notes, and asks for a draft of these empty fields only: %2$s. Nothing from another character is sent.",
											'beyond-elysium'
										),
										characterName,
										emptyFields.join( ', ' )
									) }
						</p>
					) }
				</Modal>
			) }
		</>
	);
}

export default NpcRoleplayingDraftButton;
