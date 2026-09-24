<?php
/**
 * The Grapevine reports, as a declarative table.
 */

defined( 'ABSPATH' ) || exit;

return [

	// -- table / char -----------------------------------------------------------------

	'character-roster' => [
		'title'   => 'Character Roster',
		'shape'   => 'table',
		'entity'  => 'char',
		'columns' => [
			[ 'Group', 'group', 'unmapped' ],
			[ 'Name', 'name', 'field' ],
			[ 'Player', 'player', 'field' ],
			[ 'Status', 'playstatus', 'field' ],
			[ 'Subgroup', 'subgroup', 'unmapped' ],
		],
		'sort' => 'name',
	],

	'sign-in-sheet' => [
		'title'   => 'Sign-In Sheet',
		'shape'   => 'table',
		'entity'  => 'char',
		'columns' => [
			[ 'Name', 'name', 'field' ],
			[ 'Player', 'player', 'field' ],
			[ 'Signature', 'signature', 'unmapped' ],
		],
		'sort' => 'name',
	],

	'experience-history' => [
		'title'     => 'Experience History',
		'shape'     => 'table',
		'entity'    => 'char',
		'rows_from' => 'ledger',
		'columns'   => [
			[ 'Name', 'name', 'ledger' ],
			[ 'Date', 'date', 'ledger' ],
			[ 'Description', 'changetext', 'ledger' ],
			[ 'Reason', 'reason', 'ledger' ],
			[ 'Earned', 'earned', 'ledger' ],
			[ 'Unspent', 'unspent', 'ledger' ],
		],
	],

	'search-report' => [
		'title'   => 'Search Report',
		'shape'   => 'table',
		'entity'  => 'char',
		'columns' => [
			[ 'Name', 'name', 'field' ],
			[ 'Match', 'matchvalue', 'special' ],
			[ 'Sort Value', 'sortvalue', 'special' ],
		],
		'sort' => 'name',
	],

	'statistics-report' => [
		'title'   => 'Statistics Report',
		'shape'   => 'statistics',
		'entity'  => 'char',
		'statfields' => null,
		'stattype'   => null,
	],

	'merits-and-flaws-report' => [
		'title'   => 'Merits and Flaws Report',
		'shape'   => 'statistics',
		'entity'  => 'char',
		'statfields' => [ 'merits', 'flaws' ],
		'stattype'   => 'distinct_distribution',
	],

	'influence-report' => [
		'title'   => 'Influence Report',
		'shape'   => 'statistics',
		'entity'  => 'char',
		'statfields' => [ 'influences' ],
		'stattype'   => 'sums',
	],

	'vampire-status-report' => [
		'title'   => 'Vampire Status Report',
		'shape'   => 'table',
		'entity'  => 'char',
		// Grapevine's own columns (Templates/Text/Vampire Status Report.txt).
		'columns' => [
			[ 'Name', 'name', 'field' ],
			[ 'Title', 'title', 'field' ],
			[ 'Clan', 'clan', 'field' ],
			[ 'Status', 'status', 'field' ],
			[ 'Boons', 'boons', 'boons' ],
		],
		'sort' => 'name',
	],

	// -- table / player -----------------------------------------------------------------

	'player-roster' => [
		'title'   => 'Player Roster',
		'shape'   => 'table',
		'entity'  => 'player',
		'columns' => [
			[ 'Name', 'name', 'player' ],
			[ 'Position', 'position', 'player' ],
			[ 'Title', 'title', 'player' ],
			[ 'E-Mail', 'email', 'player' ],
			[ 'Phone', 'phone', 'unmapped' ],
		],
		'sort' => 'name',
	],

	'player-point-history' => [
		'title'     => 'Player Point History',
		'shape'     => 'table',
		'entity'    => 'player',
		'rows_from' => 'ledger',
		'columns'   => [
			[ 'Name', 'name', 'ledger' ],
			[ 'Date', 'date', 'ledger' ],
			[ 'Description', 'changetext', 'ledger' ],
			[ 'PP Earned', 'ppearned', 'ledger' ],
			[ 'PP Unspent', 'ppunspent', 'ledger' ],
		],
	],

	// -- card ------------------------------------------------------------------------

	'item-cards' => [
		'capability' => null,
		'title'   => 'Item Cards',
		'shape'   => 'card',
		'entity'  => 'item',
		'columns' => [
			[ 'Name', 'name', 'field' ],
			[ 'Type', 'type', 'field' ],
			[ 'Subtype', 'subtype', 'field' ],
			[ 'Bonus', 'bonus', 'field' ],
			[ 'Damage', 'damagetype', 'field' ],
			[ 'Damage Amount', 'damageamount', 'field' ],
			[ 'Conceal', 'concealability', 'field' ],
			[ 'Appearance', 'appearance', 'field' ],
			[ 'Powers', 'powers', 'field' ],
			[ 'Abilities', 'abilities', 'field' ],
			[ 'Negatives', 'negatives', 'field' ],
			[ 'Uses Left', 'usesleft', 'special' ],
			[ 'Expires', 'expireson', 'special' ],
		],
	],

	'rote-cards' => [
		'capability' => null,
		'title'   => 'Rote Cards',
		'shape'   => 'card',
		'entity'  => 'rote',
		'holder_block' => 'mage-rotes',
		'columns' => [
			[ 'Name', 'name', 'field' ],
			[ 'Level', 'level', 'field' ],
			[ 'Spheres', 'spheres', 'field' ],
			[ 'Duration', 'duration', 'field' ],
			[ 'Description', 'description', 'field' ],
			[ 'Grades', 'grades', 'field' ],
		],
	],

	'location-cards' => [
		'capability' => null,
		'title'   => 'Location Cards',
		'shape'   => 'card',
		'entity'  => 'loc',
		'columns' => [
			[ 'Name', 'name', 'field' ],
			[ 'Type', 'type', 'field' ],
			[ 'Owner', 'owner', 'field' ],
			[ 'Where', 'where', 'field' ],
			[ 'Appearance', 'appearance', 'field' ],
			[ 'Access', 'access', 'field' ],
			[ 'Security', 'security', 'field' ],
			[ 'Security Traits', 'securitytraits', 'field' ],
			[ 'Security Retests', 'securityretests', 'field' ],
			[ 'Gauntlet', 'gauntlet', 'field' ],
			[ 'Umbra', 'umbra', 'field' ],
			[ 'Affinity', 'affinity', 'field' ],
			[ 'Totem', 'totem', 'field' ],
			[ 'Links', 'links', 'field' ],
		],
	],

	// -- table / item (equipment view) -------------------------------------------------

	'character-equipment' => [
		'title'   => 'Character Equipment',
		'shape'   => 'table',
		'entity'  => 'char',
		'columns' => [
			[ 'Character', 'charname', 'special' ],
			[ 'Item', 'equipment', 'equipment' ],
		],
	],

	// -- table / plot ------------------------------------------------------------------

	'plot-report' => [
		'capability' => 'be_manage_plots',
		'title'   => 'Plot Report',
		'shape'   => 'narrative',
		'entity'  => 'plot',
		'entry_type' => null,
	],

	'master-action-report' => [
		'capability' => 'be_manage_plots',
		'title'   => 'Master Action Report',
		'shape'   => 'table',
		'entity'  => 'plot',
		'entry_type' => 'action',
		'columns' => [
			[ 'Date', 'date', 'plot' ],
			[ 'Character', 'name', 'plot' ],
			[ 'Type', 'type', 'plot' ],
			[ 'Action', 'action', 'plot' ],
			[ 'Result', 'result', 'plot' ],
			[ 'Total', 'total', 'plot' ],
			[ 'Growth', 'growth', 'plot' ],
			[ 'Unused', 'unused', 'plot' ],
		],
	],

	'master-rumor-report' => [
		'capability' => 'be_manage_plots',
		'title'   => 'Master Rumor Report',
		'shape'   => 'table',
		'entity'  => 'plot',
		'entry_type' => 'rumor',
		'columns' => [
			[ 'Date', 'date', 'plot' ],
			[ 'Level', 'level', 'unmapped' ],
			[ 'Rumor', 'rumor', 'plot' ],
		],
	],

	'action-and-rumor-report' => [
		'capability' => 'be_manage_plots',
		'title'   => 'Action and Rumor Report',
		'shape'   => 'table',
		'entity'  => 'plot',
		'entry_type' => [ 'action', 'rumor' ],
		'columns' => [
			[ 'Date', 'date', 'plot' ],
			[ 'Character', 'name', 'plot' ],
			[ 'Type', 'type', 'plot' ],
			[ 'Action', 'action', 'plot' ],
			[ 'Rumor', 'rumor', 'plot' ],
			[ 'Result', 'result', 'plot' ],
			[ 'Total', 'total', 'plot' ],
		],
	],

	// -- calendar --------------------------------------------------------------------

	'game-calendar' => [
		'capability' => null,
		'title'      => 'Game Calendar',
		'shape'      => 'calendar',
		'entity'     => 'none',
		'empty_note' => 'Beyond Elysium does not yet model a chronicle game-date schedule - this report will populate once that data exists.',
	],

	// -- house_rules -----------------------------------------------------------------

	'house-rules' => [
		'capability' => null,
		'title'  => 'House Rules',
		'shape'  => 'house_rules',
		'entity' => 'none',
	],
];
