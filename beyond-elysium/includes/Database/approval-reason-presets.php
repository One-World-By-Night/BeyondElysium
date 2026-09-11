<?php
/**
 * Default reason-tier presets offered when an ST creates an approval rule.
 *
 * Each preset is a real-world review-process label, not a Beyond Elysium
 * workflow state - every rule created through this engine still resolves
 * to the plugin's own single Storyteller review step (or, if explicitly
 * marked, the existing 'coordinator' level). The preset only seeds the
 * rule's reason text, shown to the reviewing Storyteller so they know what
 * kind of real-world approval the player still needs to obtain.
 *
 * 'Unregulated' and 'Varies' are deliberately absent: an unregulated trait
 * needs no rule at all, and 'varies' names no concrete process worth
 * recording as a reason.
 *
 * @return string[]
 */

defined( 'ABSPATH' ) || exit;

return [
	'Coordinator Approval',
	'Coordinator Notify',
	'Majority Vote',
	'2/3 Majority Vote',
	'Storyteller Approval',
	'Disallowed',
];
