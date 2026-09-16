<?php
/**
 * Which query field is each creature type's "group" and "subgroup" for group and subgroup
 * rumors - Grapevine's own per-class Group() and Subgroup() functions (VampireClass.cls:266-286,
 * and the same pair on every class), pointed at the field-map.php key that holds that field here.
 *
 * A type without one has none: every wraith's Grapevine group is the literal "Wraith", which a
 * race rumor already covers, and a mummy has no subgroup. A Bete shares the Fera's fields.
 *
 * @see BeyondElysium\Services\Rumor_Generator
 * @see GV301Source/Code/APREngineClass.cls AddStandardRumors
 */

defined( 'ABSPATH' ) || exit;

return [
	'vampire'    => [ 'group' => 'clan',        'subgroup' => 'sect' ],
	'werewolf'   => [ 'group' => 'tribe',       'subgroup' => 'auspice' ],
	'mage'       => [ 'group' => 'tradition',   'subgroup' => 'rank' ],
	'changeling' => [ 'group' => 'kith',        'subgroup' => 'seeming' ],
	'wraith'     => [ 'group' => null,          'subgroup' => 'guild' ],
	'mortal'     => [ 'group' => 'association', 'subgroup' => 'motivation' ],
	'mummy'      => [ 'group' => 'amenti',      'subgroup' => null ],
	'kueijin'    => [ 'group' => 'dharma',      'subgroup' => 'kjbalance' ],
	'fera'       => [ 'group' => 'fera',        'subgroup' => 'auspice' ],
	'bete'       => [ 'group' => 'fera',        'subgroup' => 'auspice' ],
	'demon'      => [ 'group' => 'house',       'subgroup' => 'faction' ],
];
