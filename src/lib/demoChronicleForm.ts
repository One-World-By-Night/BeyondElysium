/**
 * The pure validation rule behind the Games screen's "Demo chronicle" section.
 */
import { __ } from '@wordpress/i18n';

/**
 * Null when the form may be saved as-is; otherwise the message to show instead of saving.
 */
export function validateDemoChronicleForm( {
	on,
	storytellerId,
	playerId,
}: {
	on: boolean;
	storytellerId: number | undefined;
	playerId: number | undefined;
} ): string | null {
	if ( ! on ) {
		return null;
	}
	if ( ! storytellerId || ! playerId ) {
		return __(
			'Both the storyteller and player account are required to turn this on.',
			'beyond-elysium'
		);
	}
	return null;
}
