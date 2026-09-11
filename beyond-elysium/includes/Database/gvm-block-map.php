<?php
/**
 * Which GVM menu backs which schema block.
 *
 * Declared as data so it can be diffed against the real menu file by a test
 * (tests/unit/SeederMapTest.php) rather than embedded as scattered conditional
 * checks. Returns an array keyed by schema block slug; each value is a map
 * entry describing where that block's content comes from.
 *
 * Source keys:
 *   menu       one menu, read for its items
 *   merge      several menus, items concatenated in order
 *   container  a menu of submenus; shape (named vs shared_levels) is derived at runtime
 *   pattern    every menu whose name matches, optionally filtered by category
 *   none       no GVM source; the block seeds empty
 *
 * @see BE_PROCESS/workflow-0.2.2.md
 * @see BE_PROCESS/GV-SOURCEMAP.md
 */

defined( 'ABSPATH' ) || exit;

return [

	// --- Shared MET blocks -------------------------------------------------------

	'met-physical-traits'     => [ 'source' => 'menu', 'menu' => 'Physical' ],
	'met-physical-traits-neg' => [ 'source' => 'menu', 'menu' => 'Physical, Negative', 'negative' => true ],
	'met-social-traits'       => [ 'source' => 'menu', 'menu' => 'Social' ],
	'met-social-traits-neg'   => [ 'source' => 'menu', 'menu' => 'Social, Negative', 'negative' => true ],
	'met-mental-traits'       => [ 'source' => 'menu', 'menu' => 'Mental' ],
	'met-mental-traits-neg'   => [ 'source' => 'menu', 'menu' => 'Mental, Negative', 'negative' => true ],

	'met-abilities'           => [ 'source' => 'menu', 'menu' => 'Abilities' ],
	// 'atomic' is a per-creature-class constant, not menu-file data.
	'met-merits'              => [ 'source' => 'menu', 'menu' => 'Merits', 'atomic' => true ],
	// Flaws grant points rather than cost them; 'negative' inverts the sign at runtime.
	'met-flaws'               => [ 'source' => 'menu', 'menu' => 'Flaws', 'negative' => true, 'atomic' => true ],
	'met-derangements'        => [ 'source' => 'menu', 'menu' => 'Derangements', 'atomic' => true ],

	// met-archetypes is built separately below; its shape (Nature + Demeanor) is bespoke.

	// --- Vampire -----------------------------------------------------------------

	// Each stack merges its generic, creature-specific and Influences backgrounds into one block.
	'vampire-backgrounds'       => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Vampire', 'Influences' ] ],
	'vampire-disciplines'       => [ 'source' => 'container', 'menu' => 'Disciplines', 'atomic' => true ],
	// Rituals are named formulas tagged with a tier, listed as a flat trait_list, not tiered_power.
	'vampire-rituals'           => [ 'source' => 'merge', 'menus' => [
		'Rituals, Basic', 'Rituals, Intermediate', 'Rituals, Advanced', 'Rituals, Superior',
		'Rituals, Assamite', 'Rituals, Dark Thaumaturgy', 'Rituals, Mortis', 'Rituals, Gargoyle',
		'Rituals, Necromancy', 'Pisanob Necromancy', 'Revenant Creation',
		'Rituals, Sabbat, Basic', 'Rituals, Sabbat, Intermediate', 'Rituals, Sabbat, Advanced', 'Rituals, Sabbat, Superior',
	],
	// Renames each merged item to "<Type>: <name> (<tier>)", keyed by its source menu.
	'labels' => [
		'Rituals, Basic' => 'Thaumaturgy', 'Rituals, Intermediate' => 'Thaumaturgy',
		'Rituals, Advanced' => 'Thaumaturgy', 'Rituals, Superior' => 'Thaumaturgy',
		'Rituals, Assamite' => 'Assamite', 'Rituals, Dark Thaumaturgy' => 'Dark Thaumaturgy',
		'Rituals, Mortis' => 'Mortis', 'Rituals, Gargoyle' => 'Gargoyle',
		'Rituals, Necromancy' => 'Necromancy', 'Pisanob Necromancy' => 'Pisanob Necromancy',
		'Revenant Creation' => 'Revenant Creation',
		'Rituals, Sabbat, Basic' => 'Sabbat', 'Rituals, Sabbat, Intermediate' => 'Sabbat',
		'Rituals, Sabbat, Advanced' => 'Sabbat', 'Rituals, Sabbat, Superior' => 'Sabbat',
	],
	// A matching note word overrides the source-menu label above and is dropped from the tier.
	'note_overrides' => [
		'necro' => 'Necromancy',
		'assamite' => 'Assamite',
	],
	'atomic' => true ],
	// Combo Disciplines are largely chronicle-specific; allow_custom stays on for additions.
	'vampire-combo-disciplines' => [ 'source' => 'menu', 'menu' => 'Disciplines, Long Night Combo', 'partial' => true ],
	// Sabbat ritae are split by rank across two menus, neither named "ritae".
	'vampire-ritae'             => [ 'source' => 'merge', 'menus' => [ 'Auctoritas', 'Ignoblis' ] ],
	'vampire-statuses'          => [ 'source' => 'menu', 'menu' => 'Status' ],

	// --- Werewolf ----------------------------------------------------------------

	'werewolf-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Werewolf', 'Influences' ] ],
	// Matches only "Gifts, Werewolf"; other Changing Breeds have their own "Gifts, X" containers (see fera-gifts).
	'werewolf-gifts' => [ 'source' => 'pattern', 'pattern' => '/^Gifts, Werewolf$/', 'atomic' => true, 'label' => 'source' ],
	'werewolf-rites' => [ 'source' => 'pattern', 'pattern' => '/, Rites$/', 'category' => 3, 'atomic' => true ],

	// --- Fera (non-Garou Changing Breeds sharing the 'fera'/'bete' stacks) --------

	// Every "Gifts, X" container except Werewolf's; each item is labeled with its breed and faction.
	'fera-gifts' => [ 'source' => 'pattern', 'pattern' => '/^Gifts, (?!Werewolf$)/', 'atomic' => true, 'label' => 'splat' ],

	// --- Mage --------------------------------------------------------------------

	'mage-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Mage', 'Influences' ] ],
	'mage-spheres' => [ 'source' => 'container', 'menu' => 'Spheres', 'atomic' => true ],
	// No Rotes menu exists; Rotes ship in Rotes.gex and are populated by the importer instead.
	'mage-rotes'   => [ 'source' => 'none', 'deferred_to' => 'workflow-0.8 (Rotes.gex)', 'atomic' => true ],

	// --- Changeling --------------------------------------------------------------

	'changeling-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Changeling', 'Influences' ] ],
	'changeling-arts'   => [ 'source' => 'container', 'menu' => 'Arts', 'atomic' => true ],
	'changeling-realms' => [ 'source' => 'container', 'menu' => 'Realms', 'atomic' => true ],

	// --- Wraith ------------------------------------------------------------------

	'wraith-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Wraith', 'Influences' ] ],
	'wraith-arcanoi' => [ 'source' => 'container', 'menu' => 'Arcanoi', 'atomic' => true ],

	// --- Demon -------------------------------------------------------------------

	'demon-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Demon', 'Influences' ] ],
	'demon-lores' => [ 'source' => 'menu', 'menu' => 'Lores (Knowledge), Demon', 'atomic' => true ],

	// --- Mummy -------------------------------------------------------------------

	'mummy-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Mummy', 'Influences' ] ],
	'mummy-hekau' => [ 'source' => 'container', 'menu' => 'Hekau', 'atomic' => true ],

	// --- Kuei-Jin ----------------------------------------------------------------

	'kueijin-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Kuei-Jin', 'Influences' ] ],
	'kueijin-disciplines' => [ 'source' => 'container', 'menu' => 'Disciplines, Kuei-Jin', 'atomic' => true ],

	// --- Mortal ------------------------------------------------------------------

	'mortal-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Mortal', 'Influences' ] ],
	'mortal-numina' => [ 'source' => 'container', 'menu' => 'Numina', 'atomic' => true ],

	// --- Fera (also used by the 'bete' legacy alias stack) ------------------------

	'fera-backgrounds' => [ 'source' => 'merge', 'menus' => [ 'Backgrounds', 'Backgrounds, Fera', 'Influences' ] ],
];
