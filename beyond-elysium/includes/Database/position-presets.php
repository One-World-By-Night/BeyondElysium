<?php
/**
 * Title preset groups for the position title picker.
 *
 * @return array<string,string[]> preset group label => titles.
 */

defined( 'ABSPATH' ) || exit;

return [
	'Camarilla court'     => [ 'Prince', 'Seneschal', 'Sheriff', 'Scourge', 'Keeper of Elysium', 'Harpy', 'Whip', 'Primogen' ],
	'Sabbat'              => [ 'Archbishop', 'Bishop', 'Priscus', 'Templar', 'Paladin', 'Ductus', 'Pack Priest' ],
	'Anarch'              => [ 'Baron', 'Sweeper' ],
	'Garou sept'          => [ 'Grand Elder', 'Warder', 'Master of the Rite', 'Gatekeeper', 'Keeper of the Land', 'Talesinger', 'Truthcatcher', 'Guardian', 'Den Mother', 'Den Father' ],
	'Changeling freehold' => [ 'Duke', 'Duchess', 'Count', 'Countess', 'Baron', 'Baroness', 'Seneschal', 'Herald', 'Knight' ],
	'Mage chantry'        => [ 'Deacon', 'Custos' ],
	'Wraith necropolis'   => [ 'Anacreon', 'Marshal' ],
];
