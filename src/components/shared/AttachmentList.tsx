/**
 * File uploads on a plot, item, or location (1.1.0 §2.6). Lists what's
 * already there with a direct download link for each, and, when the
 * viewer may manage this entity's files, an upload control and a delete
 * button per file. The server is the real authority on limits (10MB, and
 * a per-entity count cap) and on who may actually upload or delete - this
 * component surfaces whatever error it returns rather than re-deriving
 * either rule client-side.
 */
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { Attachment, AttachmentEntityType } from '../../types/attachment';
import './AttachmentList.css';

export interface AttachmentListProps {
	gameSlug: string;
	entityType: AttachmentEntityType;
	entityId: number;
	attachments: Attachment[];
	/** Shows the upload control and each file's delete button. */
	canManage: boolean;
	onChange: ( attachments: Attachment[] ) => void;
}

/** Formats a byte count as a short human-readable size, e.g. "1.4 MB". */
function formatBytes( bytes: number ): string {
	if ( bytes < 1024 ) {
		return `${ bytes } B`;
	}
	const units = [ 'KB', 'MB', 'GB' ];
	let value = bytes / 1024;
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}
	return `${ value.toFixed( 1 ) } ${ units[ unit ] }`;
}

export function AttachmentList( {
	gameSlug,
	entityType,
	entityId,
	attachments,
	canManage,
	onChange,
}: AttachmentListProps ) {
	const fileInput = useRef< HTMLInputElement >( null );
	const [ uploading, setUploading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	async function handleFileChosen(
		e: React.ChangeEvent< HTMLInputElement >
	) {
		const file = e.target.files?.[ 0 ];
		e.target.value = '';
		if ( ! file ) {
			return;
		}
		setUploading( true );
		setError( null );
		try {
			const uploaded = await api
				.attachments( gameSlug )
				.upload( entityType, entityId, file );
			onChange( [ ...attachments, uploaded ] );
		} catch {
			setError( __( 'Failed to upload this file.', 'beyond-elysium' ) );
		} finally {
			setUploading( false );
		}
	}

	async function handleDelete( id: number ) {
		setError( null );
		try {
			await api.attachments( gameSlug ).delete( id );
			onChange( attachments.filter( ( a ) => a.id !== id ) );
		} catch {
			setError( __( 'Failed to remove this file.', 'beyond-elysium' ) );
		}
	}

	return (
		<div className="be-attachment-list">
			{ error && (
				<div className="be-attachment-list__error" role="alert">
					{ error }
				</div>
			) }
			{ attachments.length === 0 ? (
				<p className="be-attachment-list__empty">
					{ __( 'No files attached.', 'beyond-elysium' ) }
				</p>
			) : (
				<ul className="be-attachment-list__items">
					{ attachments.map( ( attachment ) => (
						<li
							key={ attachment.id }
							className="be-attachment-list__item"
						>
							<a
								href={ api
									.attachments( gameSlug )
									.downloadUrl( attachment.id ) }
							>
								{ attachment.original_name }
							</a>
							<span className="be-attachment-list__size">
								{ formatBytes( attachment.bytes ) }
							</span>
							{ canManage && (
								<button
									type="button"
									className="be-st-button be-st-button--quiet"
									onClick={ () =>
										handleDelete( attachment.id )
									}
								>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ canManage && (
				<div className="be-attachment-list__upload">
					<input
						ref={ fileInput }
						type="file"
						onChange={ handleFileChosen }
						disabled={ uploading }
						aria-label={ __( 'Upload a file', 'beyond-elysium' ) }
					/>
					{ uploading && (
						<span>{ __( 'Uploading…', 'beyond-elysium' ) }</span>
					) }
				</div>
			) }
		</div>
	);
}

export default AttachmentList;
