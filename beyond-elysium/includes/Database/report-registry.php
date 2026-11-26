<?php
/**
 * The Grapevine reports, as a declarative table.
 */

defined( 'ABSPATH' ) || exit;

return [

	// -- table / char -----------------------------------------------------------------

	'character-roster' => [
		'title'   => 'Character Roster',
		'summary' => 'Every character with its player and status.',
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
		'summary' => 'A sheet for players to sign as they arrive.',
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
		'summary' => 'Every approved change and the XP it moved, by character.',
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
		'summary' => 'The characters that match the conditions you set.',
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
		'summary' => 'How a stat is spread across the chronicle: pick Merits, Flaws or Influences.',
		'shape'   => 'statistics',
		'entity'  => 'char',
		'statfields' => null,
		'stattype'   => null,
	],

	'merits-and-flaws-report' => [
		'title'   => 'Merits and Flaws Report',
		'summary' => 'Which Merits and Flaws the chronicle\'s characters hold, and how many of each.',
		'shape'   => 'statistics',
		'entity'  => 'char',
		'statfields' => [ 'merits', 'flaws' ],
		'stattype'   => 'distinct_distribution',
	],

	'influence-report' => [
		'title'   => 'Influence Report',
		'summary' => 'The Influences the chronicle\'s characters hold, added up.',
		'shape'   => 'statistics',
		'entity'  => 'char',
		'statfields' => [ 'influences' ],
		'stattype'   => 'sums',
	],

	'vampire-status-report' => [
		'title'   => 'Vampire Status Report',
		'summary' => 'Each vampire\'s title, clan, Status and boons.',
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

	// -- table / bonds ------------------------------------------------------------------

	'bonds' => [
		'capability' => null,
		'title'      => 'Bonds',
		'summary' => 'Every bond and Guanxi, one row each.',
		'shape'      => 'table',
		'entity'     => 'bond',
		'rows_from'  => 'bonds',
		'empty_note' => 'No bonds are recorded.',
		'columns'    => [
			[ 'Character', 'character', 'bond' ],
			[ 'Player', 'player', 'bond' ],
			[ 'Bonded to', 'bonded_to', 'bond' ],
			[ 'Rating', 'rating', 'bond' ],
			[ 'Kind', 'kind', 'bond' ],
		],
	],

	'player-roster' => [
		'title'   => 'Player Roster',
		'summary' => 'Every player with their position, title and email.',
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
		'summary' => 'Every approved change and the XP it moved, by player.',
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
		'summary' => 'A printable card for each item.',
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
		'summary' => 'A printable card for each rote a character holds.',
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
		'summary' => 'A printable card for each location.',
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
		'summary' => 'What each character carries.',
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
		'summary' => 'Every plot with its entries in date order.',
		'shape'   => 'narrative',
		'entity'  => 'plot',
		'entry_type' => null,
	],

	'master-action-report' => [
		'capability' => 'be_manage_plots',
		'title'   => 'Master Action Report',
		'summary' => 'Every downtime action, by plot and character.',
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
		'summary' => 'Every rumor, with its date and level.',
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
		'summary' => 'Downtime actions and rumors together.',
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
		'summary' => 'The game nights on the chronicle\'s calendar.',
		'shape'      => 'calendar',
		'entity'     => 'none',
		'empty_note' => 'No game nights are on this chronicle\'s calendar.',
	],

	// -- house_rules -----------------------------------------------------------------

	'house-rules' => [
		'capability' => null,
		'title'  => 'House Rules',
		'summary' => 'Every note the chronicle has written on the catalog.',
		'shape'  => 'house_rules',
		'entity' => 'none',
	],
];
