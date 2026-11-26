/**
 * A chronicle's private note about one player, saved as you type. Only the chronicle's HST and AST read it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import Modal from '../shared/Modal';
import SaveStatus from '../character/SaveStatus';
import { useAutosave } from '../../lib/useAutosave';
import { formatStoredTime } from '../../lib/storedTime';
import type { PlayerNote } from '../../types';
import '../character/CharacterStory.css';

const MAX_LENGTH = 10000;

export interface PlayerNoteDialogProps {
	gameSlug: string;
	wpUserId: number;
	name: string;
	onClose: () => void;
	/**
	 * Told when the note is saved, with whether anything is left in it.
	 */
	onSaved: ( hasNote: boolean ) => void;
}

interface EditorProps extends PlayerNoteDialogProps {
	note: PlayerNote;
}

function NoteEditor( { gameSlug, wpUserId, note, onSaved }: EditorProps ) {
	const [ text, setText ] = useState( note.notes );
	const [ meta, setMeta ] = useState( note );
	const autosave = useAutosave< string >( note.notes, async ( next ) => {
		const saved = await api
			.chroniclePlayers( gameSlug )
			.saveNote( wpUserId, next );
		setMeta( saved );
		onSaved( saved.notes !== '' );
	} );

	return (
		<>
			<p className="be-story__hint">
				{ __(
					"Only this chronicle's HST and AST see this.",
					'beyond-elysium'
				) }
			</p>
			<textarea
				className="be-story__rp-notes"
				rows={ 10 }
				maxLength={ MAX_LENGTH }
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
			{ meta.updated_at && (
				<p className="be-story__hint">
					{ sprintf(
						/* translators: 1: who last changed the note, 2: the date */
						__( 'Last changed by %1$s, %2$s', 'beyond-elysium' ),
						meta.updated_by ?? __( 'someone', 'beyond-elysium' ),
						formatStoredTime( meta.updated_at )
					) }
				</p>
			) }
		</>
	);
}

/**
 * Renders the dialog for one player's note.
 */
export function PlayerNoteDialog( props: PlayerNoteDialogProps ) {
	const { gameSlug, wpUserId, name, onClose } = props;
	const [ note, setNote ] = useState< PlayerNote | null >( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		api.chroniclePlayers( gameSlug )
			.note( wpUserId )
			.then( setNote )
			.catch( () => setFailed( true ) );
	}, [ gameSlug, wpUserId ] );

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: a player's name */
				__( 'Notes on %s', 'beyond-elysium' ),
				name
			) }
			onClose={ onClose }
		>
			{ failed && (
				<p role="alert">
					{ __( 'Failed to load the note.', 'beyond-elysium' ) }
				</p>
			) }
			{ ! failed && ! note && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }
			{ note && <NoteEditor { ...props } note={ note } /> }
		</Modal>
	);
}

export default PlayerNoteDialog;
