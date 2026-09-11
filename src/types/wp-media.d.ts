/**
 * Ambient global type declarations for the WordPress admin
 * environment this plugin runs in: the wp.media() picker frame,
 * the classic editor helper, and the wp_localize_script() payload
 * the server hands to the client as window.beyondElysium.
 */

/**
 * A single attachment as returned by the WordPress media picker's
 * selection model, reduced to the two fields this plugin actually
 * reads: the attachment id and its resolved URL.
 */
interface WPMediaAttachment {
	id: number;
	url: string;
}

/**
 * The media picker frame returned by wp.media(). Covers only the
 * subset of the real Backbone-based frame API this plugin calls:
 * opening it, listening for a selection, and reading back the
 * selected attachment.
 */
interface WPMediaFrame {
	open(): void;
	on( event: 'select', callback: () => void ): void;
	state(): {
		get( key: 'selection' ): {
			first(): { toJSON(): WPMediaAttachment };
		};
	};
}

/**
 * Options accepted when opening the media picker: the frame
 * title, the selection button's label, whether multiple
 * attachments may be selected, and a library filter such as
 * restricting to images.
 */
interface WPMediaOptions {
	title?: string;
	button?: { text?: string };
	multiple?: boolean;
	library?: { type?: string };
}

/**
 * Augments the global Window with the WordPress globals this
 * plugin relies on: the wp.media picker, the classic TinyMCE
 * editor helper, and this plugin's own localized config object.
 */
interface Window {
	wp: {
		media: ( options?: WPMediaOptions ) => WPMediaFrame;
		/** Classic TinyMCE editor helper, used by HtmlEditor.tsx. */
		editor?: {
			initialize: ( id: string, settings: Record<string, unknown> ) => void;
			remove: ( id: string ) => void;
		};
	};
	beyondElysium?: BeyondElysiumGlobal;
}

/**
 * This plugin's own localized config, handed to the client by the
 * server. Carries the REST API base URL, the request nonce, the
 * plugin version, and a UI-only map of which capabilities the
 * current user appears to hold.
 */
interface BeyondElysiumGlobal {
	restUrl: string;
	nonce: string;
	version: string;
	/** UI affordance only; every REST route re-checks the real capability server-side. */
	capabilities?: Record< string, boolean >;
}

/**
 * The global wp object, typed to the wp.media surface declared
 * above. Lets components call wp.media() without importing
 * anything, matching how WordPress itself exposes it as a global.
 */
declare const wp: Window[ 'wp' ];
