<?php
/**
 * Which query field is each creature type's "group" and "subgroup" for group and subgroup rumors.
 *
 * @see BeyondElysium\Services\Rumor_Generator
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
