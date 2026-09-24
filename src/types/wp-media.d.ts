/**
 * Ambient global type declarations for the WordPress admin environment this plugin runs in.
 */

/**
 * A single attachment as returned by the WordPress media picker's selection model, reduced to the two fields this
 * plugin actually reads: the attachment id and its resolved URL.
 */
interface WPMediaAttachment {
	id: number;
	url: string;
}

/**
 * The media picker frame returned by wp.media().
 */
interface WPMediaFrame {
	open(): void;
	on( event: 'select' | 'close', callback: () => void ): void;
	state(): {
		get( key: 'selection' ): {
			first(): { toJSON(): WPMediaAttachment };
		};
	};
}

/**
 * Options accepted when opening the media picker: the frame title, the selection button's label, whether multiple
 * attachments may be selected, and a library filter such as restricting to images.
 */
interface WPMediaOptions {
	title?: string;
	button?: { text?: string };
	multiple?: boolean;
	library?: { type?: string };
}

/**
 * Augments the global Window with the WordPress globals this plugin relies on: the wp.media picker, the classic
 * TinyMCE editor helper, and this plugin's own localized config object.
 */
interface Window {
	wp: {
		media: ( options?: WPMediaOptions ) => WPMediaFrame;
		/**
		 * Classic TinyMCE editor helper, used by HtmlEditor.tsx.
		 */
		editor?: {
			initialize: (
				id: string,
				settings: Record< string, unknown >
			) => void;
			remove: ( id: string ) => void;
		};
	};
	beyondElysium?: BeyondElysiumGlobal;
	/**
	 * The raw TinyMCE library global (distinct from wp.editor, its WordPress wrapper).
	 */
	tinymce?: {
		get: ( id: string ) => {
			setContent: ( html: string ) => void;
			getContent: () => string;
		} | null;
	};
}

/**
 * This plugin's own localized config, handed to the client by the server.
 */
interface BeyondElysiumGlobal {
	restUrl: string;
	/**
	 * This site's own `home_url()`, trailing-slashed.
	 */
	homeUrl?: string;
	nonce: string;
	version: string;
	/**
	 * UI affordance only; every REST route re-checks the real capability server-side.
	 */
	capabilities?: Record< string, boolean >;
	/**
	 * The site's own WordPress locale (e.g. "pt_BR").
	 */
	locale?: string;
	/**
	 * The wp-admin Import page's own URL.
	 */
	importPageUrl?: string;
}

/**
 * The global wp object, typed to the wp.media surface declared above.
 */
declare const wp: Window[ 'wp' ];

/**
 * The raw TinyMCE global, as declared on Window above.
 */
declare const tinymce: Window[ 'tinymce' ];
