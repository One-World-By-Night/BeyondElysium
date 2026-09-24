/**
 * Wraps the WordPress media library picker (`wp.media`) for selecting a single image attachment.
 */

/**
 * Opens the WordPress media picker restricted to a single image and returns a promise that resolves with the selected
 * attachment's data, or null if the picker is closed without a selection.
 */
export function pickMediaImage(
	title: string
): Promise< WPMediaAttachment | null > {
	return new Promise( ( resolve ) => {
		const frame = wp.media( {
			title,
			multiple: false,
			library: { type: 'image' },
		} );
		let selected = false;
		frame.on( 'select', () => {
			selected = true;
			resolve( frame.state().get( 'selection' ).first().toJSON() );
		} );
		frame.on( 'close', () => {
			setTimeout( () => {
				if ( ! selected ) {
					resolve( null );
				}
			}, 0 );
		} );
		frame.open();
	} );
}
