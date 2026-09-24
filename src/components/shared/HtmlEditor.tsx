/**
 * Rich-text HTML editor field backed by WordPress's classic TinyMCE editor.
 */
import { useEffect, useRef } from '@wordpress/element';
import AiAssistButton from './AiAssistButton';
import './HtmlEditor.css';

// window.wp.editor's type is declared in types/wp-media.d.ts, alongside window.wp.media.

export interface HtmlEditorProps {
	id: string;
	defaultValue: string;
	onChange: ( html: string ) => void;
	readOnly?: boolean;
	rows?: number;
	/**
	 * Shows WordPress's "Add Media" button in the toolbar when true.
	 */
	mediaButtons?: boolean;
	/**
	 * Loads TinyMCE's table plugin and toolbar button when true.
	 */
	tables?: boolean;
	/**
	 * Adds an AI Assist button above the editor.
	 */
	aiAssist?: { capability: string; fieldContext: string; gameSlug?: string };
}

/**
 * Initializes WordPress's classic TinyMCE editor on a textarea identified by `id`, with a bold/italic/lists/link
 * toolbar and optional quicktags and media-button support.
 */
export function HtmlEditor( {
	id,
	defaultValue,
	onChange,
	readOnly,
	rows = 8,
	mediaButtons = false,
	tables = false,
	aiAssist,
}: HtmlEditorProps ) {
	const onChangeRef = useRef( onChange );
	onChangeRef.current = onChange;

	useEffect( () => {
		if ( readOnly || ! wp.editor ) {
			return;
		}

		wp.editor.initialize( id, {
			tinymce: {
				wpautop: true,
				plugins: tables ? 'lists link paste table' : 'lists link paste',
				toolbar1: tables
					? 'bold italic bullist numlist link unlink removeformat table undo redo'
					: 'bold italic bullist numlist link unlink removeformat undo redo',
				menubar: false,
				statusbar: false,
				height: rows * 24,
				setup: ( editor: {
					on: ( events: string, cb: () => void ) => void;
					getContent: () => string;
				} ) => {
					editor.on( 'change input blur', () => {
						onChangeRef.current( editor.getContent() );
					} );
				},
			},
			quicktags: true,
			mediaButtons,
		} );

		return () => {
			wp.editor?.remove( id );
		};
		// Mounts once per id; remount with a new id/key to load different content.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id, readOnly, mediaButtons, tables ] );

	return (
		<>
			{ aiAssist && ! readOnly && (
				<AiAssistButton
					capability={ aiAssist.capability }
					fieldContext={ aiAssist.fieldContext }
					gameSlug={ aiAssist.gameSlug }
					// TinyMCE owns this field's real content after mount.
					currentValue={ () =>
						tinymce?.get( id )?.getContent() ?? defaultValue
					}
					onAccept={ ( suggestion ) => {
						tinymce?.get( id )?.setContent( suggestion );
						onChange( suggestion );
					} }
				/>
			) }
			<textarea
				id={ id }
				className="be-html-editor__textarea"
				defaultValue={ defaultValue }
				readOnly={ readOnly }
				rows={ rows }
			/>
		</>
	);
}

export default HtmlEditor;
