<?php
/**
 * Default reason-tier presets offered when an ST creates an approval rule.
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
