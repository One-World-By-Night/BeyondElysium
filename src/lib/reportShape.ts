/**
 * What a report's stored shape is called on screen.
 */
import { __ } from '@wordpress/i18n';

/**
 * The shape of a report in words: a table, cards, statistics, a narrative, a calendar, or the House Rules.
 */
export function reportShapeLabel( shape: string ): string {
	switch ( shape ) {
		case 'table':
			return __( 'Table', 'beyond-elysium' );
		case 'card':
			return __( 'Cards', 'beyond-elysium' );
		case 'statistics':
			return __( 'Statistics', 'beyond-elysium' );
		case 'narrative':
			return __( 'Narrative', 'beyond-elysium' );
		case 'calendar':
			return __( 'Calendar', 'beyond-elysium' );
		case 'house_rules':
			return __( 'House rules', 'beyond-elysium' );
		default:
			return shape;
	}
}
