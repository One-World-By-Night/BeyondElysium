/**
 * How a plot's title reads to a person.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The title a character's own plot is stored under: the name, the character's id in brackets, then "Plot".
 */
const OWN_PLOT = /^(.+) \[\d+\] Plot$/;

/**
 * A plot's title as a reader sees it. A character's own plot reads "<name>'s plot", without the stored id; any other
 * title reads as it was written.
 */
export function plotDisplayTitle( title: string ): string {
	const own = OWN_PLOT.exec( title );
	if ( ! own ) {
		return title;
	}
	return sprintf(
		/* translators: %s: a character's name, shown as the title of that character's own plot */
		__( "%s's plot", 'beyond-elysium' ),
		own[ 1 ]
	);
}

/**
 * The display title of the plot with this id, or null when the list holds no such plot.
 */
export function plotTitleById(
	plots: ReadonlyArray< { id: number | string; title: string } >,
	id: number | string
): string | null {
	const plot = plots.find( ( p ) => String( p.id ) === String( id ) );
	return plot ? plotDisplayTitle( plot.title ) : null;
}
