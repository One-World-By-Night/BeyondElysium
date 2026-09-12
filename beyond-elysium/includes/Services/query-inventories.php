<?php
/**
 * Query-time field maps for every queryable inventory beyond `char`, plus
 * each inventory's storage descriptor. `char` itself stays mapped by
 * `field-map.php` - this file's `char` entry declares `fields => null`
 * specifically so that stays true.
 *
 * A flat `field-map.php`-style map keyed by bare field name cannot express
 * this: nine of the 31 non-character keys collide with a character key
 * (`notes`, `abilities`, `powers`, `totem`, `affinity`, `spheres`, `type`,
 * `lastmodified`, `random`), six of those nine meaning genuinely different
 * storage, and `type` collides between two NON-character inventories
 * (`item.item_type` vs `location.location_type`) - a case no char/non-char
 * split can fix. Every entry here is therefore keyed by (inventory, key).
 *
 * Every `properties` entry is transcribed against two independent sources:
 * the matching GV class's `GetValue` (ItemClass/LocationClass/RoteClass),
 * and `Import_Controller::apply_import()`'s own already-shipped field->
 * storage mapping for the same three inventories - not invented, and not a
 * second implementation of the importer's own knowledge (verified against
 * it in QueryInventoryMapTest, not duplicated by hand a second time).
 *
 * @see BE_PROCESS/query-beyond-characters-design.md §6
 * @see BeyondElysium\Services\Field_Registry
 */

defined( 'ABSPATH' ) || exit;

return [
	'char' => [
		'storage'        => 'characters',
		'result_columns' => [ 'name', 'stack_slug', 'status' ],
		// null means "use the existing 231-key map in field-map.php" - nothing about the
		// character query path moves as part of this file existing.
		'fields'         => null,
	],

	'item' => [
		'storage'        => 'world_objects',
		'object_type'    => 'item',
		'result_columns' => [ 'name', 'item_type', 'level' ],
		'fields'         => [
			'name'           => [ 'source' => 'column', 'column' => 'name' ],
			// ItemClass.cls:133; Import_Controller.php:289's own comment: "The item's
			// free-text Notes field maps to the world object's description column."
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
			// ItemClass.cls:131 returns a String even though qkdata.gvd types this `list` -
			// Grapevine's own data file and Grapevine's own code disagree here. Storage
			// (world-object-schemas.php's 'text') matches the code. Overriding the
			// registry type to 'field' is what keeps evaluate_list() from ever seeing a
			// string (an uncaught TypeError, not a wrong answer - §4d).
			'powers'         => [ 'source' => 'properties', 'property' => 'powers', 'type' => 'field' ],
			// ItemClass.cls:313 - Item Abilities is NOT atomic.
			'abilities'      => [ 'source' => 'properties', 'property' => 'abilities', 'atomic' => false ],
			// ItemClass.cls:314 - Item Negatives is NOT atomic.
			'negatives'      => [ 'source' => 'properties', 'property' => 'negatives', 'atomic' => false ],
			// ItemClass.cls:315 - Item Availability is NOT atomic.
			'availability'   => [ 'source' => 'properties', 'property' => 'availability', 'atomic' => false ],
		],
	],

	'loc' => [
		'storage'        => 'world_objects',
		'object_type'    => 'location',
		'result_columns' => [ 'name', 'location_type', 'level' ],
		'fields'         => [
			'name'            => [ 'source' => 'column', 'column' => 'name' ],
			// LocationClass.cls:112; Import_Controller.php:312.
			'notes'           => [ 'source' => 'column', 'column' => 'description' ],
			'lastmodified'    => [ 'source' => 'column', 'column' => 'updated_at' ],
			'random'          => [ 'source' => 'derived', 'note' => "GV's CInt(Rnd() * 100) - qkRandom" ],
			// The collision that proves a flat map can't work: 'type' means
			// item_type for an item and location_type for a location.
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
			// LocationClass.cls:301 - Location Links IS atomic.
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
			// Deliberately NOT the description column: Import_Controller.php:336 passes
			// null for it and writes the real value to properties.description (:343). The
			// column is never populated for a rote - reading it would return null forever.
			'description'  => [ 'source' => 'properties', 'property' => 'description' ],
			'grades'       => [ 'source' => 'properties', 'property' => 'grades' ],
			// RoteClass.cls:255 - Rote Spheres IS atomic.
			'spheres'      => [ 'source' => 'properties', 'property' => 'spheres', 'atomic' => true ],
		],
	],
];
