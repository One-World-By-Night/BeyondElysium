/**
 * The notes a chronicle holds about people who have left it: open one to read it, or delete it.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import api from '../../api/client';
import ConfirmDialog from '../shared/ConfirmDialog';
import PlayerNoteDialog from './PlayerNoteDialog';
import type { FormerNote } from '../../types';

export interface FormerNotesProps {
	gameSlug: string;
	notes: FormerNote[];
	/**
	 * Asks for the list again after a note is saved or deleted.
	 */
	onChange: () => void;
}

function nameOf( note: FormerNote ): string {
	return (
		note.display_name ??
		sprintf(
			/* translators: %d: an account's number */
			__( 'Account %d', 'beyond-elysium' ),
			note.wp_user_id
		)
	);
}

/**
 * Renders the notes about people who left, when there are any.
 */
export function FormerNotes( { gameSlug, notes, onChange }: FormerNotesProps ) {
	const [ opened, setOpened ] = useState< FormerNote | null >( null );
	const [ doomed, setDoomed ] = useState< FormerNote | null >( null );

	if ( notes.length === 0 ) {
		return null;
	}

	return (
		<section className="be-chronicle-players__section">
			<h3>
				{ sprintf(
					/* translators: %d: how many notes the chronicle holds about people who left */
					__( 'Notes on people who left (%d)', 'beyond-elysium' ),
					notes.length
				) }
			</h3>
			<ul className="be-chronicle-players__list">
				{ notes.map( ( note ) => (
					<li key={ note.wp_user_id }>
						<span className="be-chronicle-players__name">
							{ nameOf( note ) }
						</span>
						<span className="be-chronicle-players__actions">
							<button
								type="button"
								className="be-chronicle-players__button"
								onClick={ () => setOpened( note ) }
							>
								{ __( 'Open note', 'beyond-elysium' ) }
							</button>
							<button
								type="button"
								className="be-chronicle-players__button be-chronicle-players__button--remove"
								onClick={ () => setDoomed( note ) }
							>
								{ __( 'Delete', 'beyond-elysium' ) }
							</button>
						</span>
					</li>
				) ) }
			</ul>
			{ opened && (
				<PlayerNoteDialog
					gameSlug={ gameSlug }
					wpUserId={ opened.wp_user_id }
					name={ nameOf( opened ) }
					onClose={ () => {
						setOpened( null );
						onChange();
					} }
					onSaved={ () => undefined }
				/>
			) }
			<ConfirmDialog
				open={ doomed !== null }
				title={ __( 'Delete this note?', 'beyond-elysium' ) }
				message={
					doomed
						? sprintf(
								/* translators: %s: a person's name */
								__(
									'The note about %s will be gone for good.',
									'beyond-elysium'
								),
								nameOf( doomed )
							)
						: ''
				}
				confirmLabel={ __( 'Delete', 'beyond-elysium' ) }
				onCancel={ () => setDoomed( null ) }
				onConfirm={ () => {
					const target = doomed;
					setDoomed( null );
					if ( target ) {
						api.chroniclePlayers( gameSlug )
							.deleteNote( target.wp_user_id )
							.then( onChange )
							.catch( onChange );
					}
				} }
			/>
		</section>
	);
}

export default FormerNotes;
