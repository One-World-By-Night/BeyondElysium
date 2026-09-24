<?php
/**
 * Query-time field maps for every queryable inventory beyond `char`, plus each inventory's storage descriptor.
 *
 * @see BeyondElysium\Services\Field_Registry
 */

defined( 'ABSPATH' ) || exit;

return [
	'char' => [
		'storage'        => 'characters',
		'result_columns' => [ 'name', 'stack_slug', 'status' ],
		'fields'         => null,
	],

	'item' => [
		'storage'        => 'world_objects',
		'object_type'    => 'item',
		'result_columns' => [ 'name', 'item_type', 'level' ],
		'fields'         => [
			'name'           => [ 'source' => 'column', 'column' => 'name' ],
			'notes'          => [ 'source' => 'column', 'column' => 'description' ],
			'lastmodified'   => [ 'source' => 'column', 'column' => 'updated_at' ],
			'random'         => [ 'source' => 'derived', 'note' => "GV's CInt(Rnd() * 100) - qkRandom" ],
			'type'           => [ 'source' => 'properties', 'property' => 'item_type' ],
			'subtype'        => [ 'source' => 'properties', 'property' => 'item_subtype' ],
			'level'          => [ 'source' => 'properties', 'property' => 'level' ],
			'bonus'          => [ 'source' => 'properties', 'property' => 'bonus' ],
			'damagetype'     => [ 'source' => 'properties', 'property' => 'damage_type' ],
			'damageamount'   => [ 'source' => 'properties', 'property' => 'damage_amount' ],
			'concealability' => [ 'source' => 'properties', 'property' => 'concealability' ],
			'appearance'     => [ 'source' => 'properties', 'property' => 'appearance' ],
			// Stored as a string although qkdata.gvd types it `list`.
			'powers'         => [ 'source' => 'properties', 'property' => 'powers', 'type' => 'field' ],
			// Item Abilities is not atomic.
			'abilities'      => [ 'source' => 'properties', 'property' => 'abilities', 'atomic' => false ],
			// Item Negatives is not atomic.
			'negatives'      => [ 'source' => 'properties', 'property' => 'negatives', 'atomic' => false ],
			// Item Availability is not atomic.
			'availability'   => [ 'source' => 'properties', 'property' => 'availability', 'atomic' => false ],
		],
	],

	'loc' => [
		'storage'        => 'world_objects',
		'object_type'    => 'location',
		'result_columns' => [ 'name', 'location_type', 'level' ],
		'fields'         => [
			'name'            => [ 'source' => 'column', 'column' => 'name' ],
			'notes'           => [ 'source' => 'column', 'column' => 'description' ],
			'lastmodified'    => [ 'source' => 'column', 'column' => 'updated_at' ],
			'random'          => [ 'source' => 'derived', 'note' => "GV's CInt(Rnd() * 100) - qkRandom" ],
			'type'            => [ 'source' => 'properties', 'property' => 'location_type' ],
			'level'           => [ 'source' => 'properties', 'property' => 'level' ],
			'owner'           => [ 'source' => 'properties', 'property' => 'owner' ],
			'where'           => [ 'source' => 'properties', 'property' => 'where' ],
			'appearance'      => [ 'source' => 'properties', 'property' => 'appearance' ],
			'access'          => [ 'source' => 'properties', 'property' => 'access' ],
			'security'        => [ 'source' => 'properties', 'property' => 'security' ],
			'securitytraits'  => [ 'source' => 'properties', 'property' => 'security_traits' ],
			'securityretests' => [ 'source' => 'properties', 'property' => 'security_retests' ],
			'gauntlet'        => [ 'source' => 'properties', 'property' => 'gauntlet' ],
			'umbra'           => [ 'source' => 'properties', 'property' => 'umbra' ],
			'affinity'        => [ 'source' => 'properties', 'property' => 'affinity' ],
			'totem'           => [ 'source' => 'properties', 'property' => 'totem' ],
			// Location Links is atomic.
			'links'           => [ 'source' => 'properties', 'property' => 'links', 'atomic' => true ],
		],
	],

	'rote' => [
		'storage'        => 'world_objects',
		'object_type'    => 'rote',
		'result_columns' => [ 'name', 'level', 'duration' ],
		'fields'         => [
			'name'         => [ 'source' => 'column', 'column' => 'name' ],
			'lastmodified' => [ 'source' => 'column', 'column' => 'updated_at' ],
			'random'       => [ 'source' => 'derived', 'note' => "GV's CInt(Rnd() * 100) - qkRandom" ],
			'level'        => [ 'source' => 'properties', 'property' => 'level' ],
			'duration'     => [ 'source' => 'properties', 'property' => 'duration' ],
			// Rote descriptions live in properties.description.
			'description'  => [ 'source' => 'properties', 'property' => 'description' ],
			'grades'       => [ 'source' => 'properties', 'property' => 'grades' ],
			// Rote Spheres is atomic.
			'spheres'      => [ 'source' => 'properties', 'property' => 'spheres', 'atomic' => true ],
		],
	],
];
