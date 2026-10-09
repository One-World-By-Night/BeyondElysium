/**
 * The pure text behind the demo-chronicle banner.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { DemoStatus } from '../types';

/**
 * Null when the chronicle isn't a demo (or its status hasn't loaded yet) - the banner renders nothing then.
 */
export function demoBannerMessage( status: DemoStatus | null ): string | null {
	if ( ! status?.on ) {
		return null;
	}
	if ( ! status.next_reset ) {
		return __(
			'Demo chronicle: everything here resets on its own schedule.',
			'beyond-elysium'
		);
	}
	return sprintf(
		/* translators: %s: when the chronicle next resets, already localized */
		__(
			'Demo chronicle: everything here resets on its own schedule. Next reset about %s.',
			'beyond-elysium'
		),
		new Date( status.next_reset ).toLocaleString()
	);
}
