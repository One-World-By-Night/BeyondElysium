<?php
/**
 * Per-`object_type` property schemas for `be_world_objects.properties`.
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
		// uses_left and expires_on derive used_up and expired, which are not stored.
		'uses_max'       => 'int',
		'uses_left'      => 'int',
		'expires_on'     => 'date',
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
	// 'status' records whether a boon has been repaid.
	'boon'     => [
		'boon_level'  => 'string',
		'boon_date'   => 'date',
		'terms'       => 'text',
		'status'      => 'string',
		'repaid_date' => 'date',
		'repaid_note' => 'text',
	],
];
