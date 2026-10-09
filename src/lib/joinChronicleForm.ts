/**
 * The pure validation rule behind the Join panel's message box. Mirrors the server's own rule in
 * Join_Requests_Controller::create_item().
 */
import { __ } from '@wordpress/i18n';

export const JOIN_MESSAGE_MAX_LENGTH = 1000;

/**
 * Null when the message may be sent as-is; otherwise the message to show instead of sending.
 */
export function validateJoinMessage( message: string ): string | null {
	const trimmed = message.trim();
	if ( trimmed === '' ) {
		return __( 'A short message is required.', 'beyond-elysium' );
	}
	if ( trimmed.length > JOIN_MESSAGE_MAX_LENGTH ) {
		return __(
			'The message is limited to 1,000 characters.',
			'beyond-elysium'
		);
	}
	return null;
}
