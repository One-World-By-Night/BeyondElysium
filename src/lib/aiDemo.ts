/**
 * What a viewer on a public demo chronicle is told when they use anything that would call an AI provider.
 */
import { __ } from '@wordpress/i18n';

/**
 * The explanation shown in place of the drafting controls on a demo chronicle.
 */
export function aiDemoMessage(): string {
	return __(
		'AI drafting is switched off on the public demo, so nothing was sent. On a real chronicle, a Storyteller turns on AI Assist in its settings and this button drafts for you.',
		'beyond-elysium'
	);
}
