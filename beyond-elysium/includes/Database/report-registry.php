<?php
/**
 * The 19 GV301 reports (`GV-SOURCEMAP.md` "Output / Template Engine"), as a
 * declarative table rather than 19 hand-written classes - one row per report,
 * structurally parallel to `gv-exchange-shape.php` and
 * `vampire-clan-disciplines.php`.
 *
 * Each column tuple is `[ label, key, source ]`. `source` tells
 * `Services\Report_Document` how to resolve it:
 *
 *   field     Field_Registry::resolve_value() against the report's own
 *             `entity` (char/item/loc/rote) - the same key a GV301 report
 *             token and the query builder both already resolve
 *             (GV-SOURCEMAP.md: "the field registry serves both the
 *             template engine and the query engine").
 *   special   A GV301 "special keyword" (GV-SOURCEMAP.md's own table) -
 *             resolved once per document/row from the game or the row's own
 *             name, never from Field_Registry.
 *   ledger    Derived from `Models\Change` (XP history), matching
 *             `Sheet_Document::build_xp_history()`'s own source.
 *   player    From the WordPress user account (`Game_Member` + `WP_User`),
 *             not character storage - `field-map.php`'s own
 *             `email`/`phone`/`address` entries are `unmapped` for exactly
 *             this reason.
 *   plot      From `Models\Plot`/`Plot_Entry`, filtered by entry type.
 *   unmapped  No BE equivalent yet. Rendered as an explicit `—` with the
 *             report's own footnote, never guessed (point-calculator-design.md
 *             §4.5's honesty contract, applied here).
 *
 * @see BE_PROCESS/reports-cards-batch-design.md
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
		'sort' => 'sortvalue',
	],

	'statistics-report' => [
		'title'   => 'Statistics Report',
		'shape'   => 'statistics',
		'entity'  => 'char',
		// Unlike merits-and-flaws-report/influence-report (fixed statfields), the
		// generic Statistics Report takes its field/stat_type from the request
		// (Reports_Controller, matching Query_Fields_Controller's own already-open
		// field list) - GV301's own frmStatistics.frm lets the ST pick either.
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
		'columns' => [
			[ 'Name', 'name', 'field' ],
			[ 'Status', 'status', 'field' ],
			[ 'Group', 'group', 'unmapped' ],
			[ 'Date', 'date', 'ledger' ],
			[ 'Description', 'description', 'unmapped' ],
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
		],
	],

	'rote-cards' => [
		'title'   => 'Rote Cards',
		'shape'   => 'card',
		'entity'  => 'rote',
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
			[ 'Item', 'equipment', 'unmapped' ],
		],
	],

	// -- table / plot ------------------------------------------------------------------
	//
	// `entry_type: 'action'` means real `Plot_Entry` rows with entry_type 'action'
	// (Action_Allocator's own storage). `entry_type: 'rumor'` is NOT a Plot_Entry
	// value at all - Plot_Entry::ENTRY_TYPES has no 'rumor' - it means a `be_plots`
	// row itself tagged via the `apr_rumor` Connection (Rumor_Generator::tag_as_rumor()),
	// read from `plots.description`/`game_date` directly. Report_Document's plot
	// resolver branches on this registry value, not on a real shared column.

	'plot-report' => [
		'title'   => 'Plot Report',
		'shape'   => 'narrative',
		'entity'  => 'plot',
		'entry_type' => null,
	],

	'master-action-report' => [
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
			[ 'Total', 'total', 'unmapped' ],
			[ 'Growth', 'growth', 'unmapped' ],
			[ 'Unused', 'unused', 'unmapped' ],
		],
	],

	'master-rumor-report' => [
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
			[ 'Total', 'total', 'unmapped' ],
		],
	],

	// -- calendar (no real data source yet - §5 of the design doc) ---------------------

	'game-calendar' => [
		'title'      => 'Game Calendar',
		'shape'      => 'calendar',
		'entity'     => 'none',
		'empty_note' => 'Beyond Elysium does not yet model a chronicle game-date schedule - this report will populate once that data exists.',
	],

	// -- house_rules (Decision 094's own catalog-item description field, added
	// v0.99.18 - every schema block's items/tiered_power levels/families that
	// carry one, gathered into one report; not entity-scoped like every other
	// report above, since it reads the whole catalog rather than one row per
	// character/plot/etc.) ------------------------------------------------------------

	'house-rules' => [
		'title'  => 'House Rules',
		'shape'  => 'house_rules',
		'entity' => 'none',
	],
];
