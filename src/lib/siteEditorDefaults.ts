/**
 * Reads the site's real, filter-applied TinyMCE settings, printed once per page by the hidden editor instance
 * `Editor_Defaults` renders server-side. `tiny_mce_before_init`/`mce_buttons*` and the rest of WordPress's editor
 * customization filters only ever run inside `wp_editor()`'s own PHP rendering path, never for a dynamically
 * client-initialized editor on its own - this is how that filtered configuration reaches one.
 */

export const SITE_EDITOR_DEFAULTS_ID = 'be-html-editor-defaults';

interface TinyMcePreInit {
	tinyMCEPreInit?: {
		mceInit?: Record< string, Record< string, unknown > >;
	};
}

/**
 * Returns the hidden instance's real tinymce init object, or null before it has rendered (a logged-out viewer, or
 * a race before the page's inline bootstrap script has run).
 */
export function siteTinymceDefaults(): Record< string, unknown > | null {
	const preInit = ( window as unknown as TinyMcePreInit ).tinyMCEPreInit;
	return preInit?.mceInit?.[ SITE_EDITOR_DEFAULTS_ID ] ?? null;
}

/**
 * Removes the table plugin and toolbar button from a copy of a settings object returned by
 * siteTinymceDefaults(), for a caller that did not ask for table support.
 */
export function withoutTables(
	settings: Record< string, unknown >
): Record< string, unknown > {
	const stripped: Record< string, unknown > = { ...settings };

	for ( const key of [ 'toolbar1', 'toolbar2', 'toolbar3', 'toolbar4' ] ) {
		const value = stripped[ key ];
		if ( typeof value === 'string' ) {
			stripped[ key ] = value
				.split( ',' )
				.filter( ( token ) => token !== 'table' )
				.join( ',' );
		}
	}

	const externalPlugins = stripped.external_plugins;
	if ( externalPlugins && typeof externalPlugins === 'object' ) {
		const rest = { ...( externalPlugins as Record< string, string > ) };
		delete rest.table;
		stripped.external_plugins = rest;
	}

	return stripped;
}
