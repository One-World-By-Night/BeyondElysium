/**
 * The words the creation tally uses for a limit flag.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { CreationTallyLimitFlag } from '../types/character';

const LIMIT_REASON_LABEL: Record< string, string > = {
	max_points: __( 'over its point limit', 'beyond-elysium' ),
	max_rating: __( 'above its maximum rating', 'beyond-elysium' ),
	min_rating: __( 'below its minimum rating', 'beyond-elysium' ),
	ceiling: __( 'above what it may not exceed', 'beyond-elysium' ),
};

export function limitFlagText( flag: CreationTallyLimitFlag ): string {
	const reason = LIMIT_REASON_LABEL[ flag.reason ] ?? flag.reason;
	if ( flag.reason === 'max_rating' || flag.reason === 'max_points' ) {
		return sprintf(
			/* translators: 1: what is flagged, 2: why, 3: its value, 4: the limit it broke */
			__( '%1$s is %2$s (%3$s, limit %4$s).', 'beyond-elysium' ),
			flag.target_label,
			reason,
			String( flag.value ),
			String( flag.max ?? '' )
		);
	}
	if ( flag.reason === 'min_rating' ) {
		return sprintf(
			/* translators: 1: what is flagged, 2: why, 3: its value, 4: the minimum it fell below */
			__( '%1$s is %2$s (%3$s, minimum %4$s).', 'beyond-elysium' ),
			flag.target_label,
			reason,
			String( flag.value ),
			String( flag.min ?? '' )
		);
	}
	return sprintf(
		/* translators: 1: what is flagged, 2: why, 3: its value, 4: the entry it may not exceed */
		__( '%1$s is %2$s (%3$s, above %4$s).', 'beyond-elysium' ),
		flag.target_label,
		reason,
		String( flag.value ),
		String( flag.ceiling ?? '' )
	);
}
