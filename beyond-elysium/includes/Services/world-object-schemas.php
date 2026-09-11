<?php
/**
 * Per-`object_type` property schemas for `be_world_objects.properties`.
 * Transcribed field-for-field from each GV class's `GetValue`
 * (`ItemClass`, `LocationClass`, `RoteClass`), plus `boon`, a restructuring
 * of `BoonClass` into a symmetric two-connection object.
 *
 * Types: 'string', 'text' (same storage, a hint for the editor's textarea vs
 * input), 'int', 'date', 'trait_list' (`[{name, count, note?}]`, the same
 * shape as a character sheet's, so the sheet renderers work on it unchanged).
 *
 * @see BE_PROCESS/workflow-0.7.md Step 1b
 * @see BE_PROCESS/GV-SOURCEMAP.md "World Objects"
 */

defined( 'ABSPATH' ) || exit;

return [
	'item'     => [
		'item_type'      => 'string',
		'item_subtype'   => 'string',
		'level'          => 'int',
		'bonus'          => 'int',
		'damage_type'    => 'string',
		'damage_amount'  => 'int',
		'concealability' => 'string',
		'powers'         => 'text',
		'appearance'     => 'text',
		'tempers'        => 'trait_list',
		'negatives'      => 'trait_list',
		'abilities'      => 'trait_list',
		'availability'   => 'trait_list',
	],
	'location' => [
		'location_type'     => 'string',
		'level'             => 'int',
		'owner'             => 'string',
		'where'             => 'string',
		'appearance'        => 'text',
		'access'            => 'string',
		'security'          => 'string',
		'security_traits'   => 'int',
		'security_retests'  => 'int',
		'gauntlet'          => 'int',
		'umbra'             => 'string',
		'affinity'          => 'string',
		'totem'             => 'string',
		'links'             => 'trait_list',
	],
	'rote'     => [
		'level'       => 'int',
		'duration'    => 'string',
		'description' => 'text',
		'grades'      => 'string',
		'spheres'     => 'trait_list',
	],
	// 'status' is new; it records whether a boon has been repaid.
	'boon'     => [
		'boon_level'  => 'string',
		'boon_date'   => 'date',
		'terms'       => 'text',
		'status'      => 'string',
		'repaid_date' => 'date',
	],
];
