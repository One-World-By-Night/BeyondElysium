<?php
/**
 * Title preset groups for the position title picker (1.1.0 §3.10, F2) - data only, no
 * creature-specific code. Each group is a list of titles the picker offers together;
 * titles stay free text on the actual `be_positions.title` column regardless, so picking
 * from a group is a convenience, never an enforced enum.
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
