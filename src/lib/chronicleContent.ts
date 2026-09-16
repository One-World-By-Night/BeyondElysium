/**
 * What deleting a chronicle would destroy, in the words the Games screen's one confirmation
 * uses - counted by `GET /games/{slug}/content` (1.0.0-review F-036).
 */
import { _n, sprintf } from '@wordpress/i18n';
import type { ChronicleContentCounts } from '../types';

/**
 * Lists every non-zero count as a phrase, e.g. "12 characters, 3 plots, 1 verification code".
 */
export function describeChronicleContent(
	counts: ChronicleContentCounts
): string {
	const phrases: string[] = [];

	if ( counts.characters > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of characters */
				_n(
					'%d character',
					'%d characters',
					counts.characters,
					'beyond-elysium'
				),
				counts.characters
			)
		);
	}
	if ( counts.plots > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of plots */
				_n( '%d plot', '%d plots', counts.plots, 'beyond-elysium' ),
				counts.plots
			)
		);
	}
	if ( counts.world_objects > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of world objects (items, locations, rotes, and boons) */
				_n(
					'%d world object',
					'%d world objects',
					counts.world_objects,
					'beyond-elysium'
				),
				counts.world_objects
			)
		);
	}
	if ( counts.templates > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of the chronicle's own sheet templates */
				_n(
					'%d sheet template',
					'%d sheet templates',
					counts.templates,
					'beyond-elysium'
				),
				counts.templates
			)
		);
	}
	if ( counts.schema_blocks > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of customized schema blocks */
				_n(
					'%d customized schema block',
					'%d customized schema blocks',
					counts.schema_blocks,
					'beyond-elysium'
				),
				counts.schema_blocks
			)
		);
	}
	if ( counts.saved_queries > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of saved queries */
				_n(
					'%d saved query',
					'%d saved queries',
					counts.saved_queries,
					'beyond-elysium'
				),
				counts.saved_queries
			)
		);
	}
	if ( counts.attestations > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of verification codes */
				_n(
					'%d verification code',
					'%d verification codes',
					counts.attestations,
					'beyond-elysium'
				),
				counts.attestations
			)
		);
	}
	if ( counts.transfers > 0 ) {
		phrases.push(
			sprintf(
				/* translators: %d: number of character transfers */
				_n(
					'%d transfer',
					'%d transfers',
					counts.transfers,
					'beyond-elysium'
				),
				counts.transfers
			)
		);
	}

	return phrases.join( ', ' );
}
