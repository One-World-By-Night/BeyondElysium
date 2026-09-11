/**
 * Wraps the WordPress media library picker (`wp.media`) for selecting a single image
 * attachment. Exports `pickMediaImage()`, the only function in this file, which opens
 * the picker restricted to images and resolves once the user makes a selection or
 * cancels.
 */

/**
 * Opens the WordPress media picker restricted to a single image and returns a promise
 * that resolves with the selected attachment's data, or null if the picker is closed
 * without a selection.
 */
export function pickMediaImage( title: string ): Promise<WPMediaAttachment | null> {
	return new Promise( ( resolve ) => {
		const frame = wp.media( { title, multiple: false, library: { type: 'image' } } );
		frame.on( 'select', () => {
			resolve( frame.state().get( 'selection' ).first().toJSON() );
		} );
		frame.open();
	} );
}
