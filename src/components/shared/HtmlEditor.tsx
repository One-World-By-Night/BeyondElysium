/**
 * Rich-text HTML editor field backed by WordPress's classic TinyMCE
 * editor rather than a plain textarea. Renders a textarea that TinyMCE
 * replaces after mount, and reports content changes through `onChange`.
 * Supports a read-only mode that skips TinyMCE entirely.
 */
import { useEffect, useRef } from '@wordpress/element';
import './HtmlEditor.css';

// window.wp.editor's type is declared in types/wp-media.d.ts, alongside window.wp.media.

export interface HtmlEditorProps {
	id: string;
	defaultValue: string;
	onChange: ( html: string ) => void;
	readOnly?: boolean;
	rows?: number;
	/** Shows WordPress's "Add Media" button in the toolbar when true. Off by default. */
	mediaButtons?: boolean;
}

/**
 * Initializes WordPress's classic TinyMCE editor on a textarea identified
 * by `id`, with a bold/italic/lists/link toolbar and optional quicktags
 * and media-button support. Uncontrolled after mount - TinyMCE owns the
 * DOM directly, and `onChange` fires from TinyMCE's own change/input/blur
 * events rather than from a controlled `value` prop. Skips initialization
 * entirely when `readOnly` is true. Tears down the TinyMCE instance on
 * unmount.
 */
export function HtmlEditor( { id, defaultValue, onChange, readOnly, rows = 8, mediaButtons = false }: HtmlEditorProps ) {
	const onChangeRef = useRef( onChange );
	onChangeRef.current = onChange;

	useEffect( () => {
		if ( readOnly || ! wp.editor ) {
			return;
		}

		wp.editor.initialize( id, {
			tinymce: {
				wpautop: true,
				plugins: 'lists link paste',
				toolbar1: 'bold italic bullist numlist link unlink removeformat undo redo',
				menubar: false,
				statusbar: false,
				height: rows * 24,
				setup: ( editor: { on: ( events: string, cb: () => void ) => void; getContent: () => string } ) => {
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
	}, [ id, readOnly, mediaButtons ] );

	return (
		<textarea
			id={ id }
			className="be-html-editor__textarea"
			defaultValue={ defaultValue }
			readOnly={ readOnly }
			rows={ rows }
		/>
	);
}

export default HtmlEditor;
