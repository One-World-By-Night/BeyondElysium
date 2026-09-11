<?php
/**
 * Per-Morality-Path virtue name substitutions.
 *
 * Returns an array keyed by the three vampire-virtues pools (Conscience,
 * Self-Control, Courage). Each value maps a Morality Path name to the
 * virtue name that Path substitutes for that pool - for example Path of
 * Caine substitutes Conviction for Conscience. A Path with no entry for a
 * pool keeps that pool's default name.
 *
 * @see BE_PROCESS/DECISIONLOG.md Decision 044
 */

defined( 'ABSPATH' ) || exit;

return [
	'Conscience'   => [
		'Path of Caine' => 'Conviction',
		'Road of Kings' => 'Conviction',
		'Path of Power and the Inner Voice' => 'Conviction',
		'Path of Death and the Soul' => 'Conviction',
		'Path of Metamorphosis' => 'Conviction',
		'Path of Cathari' => 'Conviction',
		'Path of the Scorched Heart' => 'Conviction',
		// Dark Ages-specific variant of Road of Lilith.
		'Road of Lilith' => 'Conviction',
	],
	'Self-Control' => [
		'Path of Caine' => 'Instinct',
		'Path of Harmony' => 'Instinct',
		'Path of Power and the Inner Voice' => 'Instinct',
		'Path of Metamorphosis' => 'Instinct',
		'Path of Cathari' => 'Instinct',
		// Dark Ages-specific variant of Road of Lilith.
		'Road of Lilith' => 'Instinct',
		// Road of Kings and Path of Death and the Soul keep the default Self-Control name.
	],
	// No Path renames Courage.
	'Courage'      => [],
];
