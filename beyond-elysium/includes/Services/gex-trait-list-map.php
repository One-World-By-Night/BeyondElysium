<?php
/**
 * Which BE schema block (if any) a GV exchange-file `LinkedTraitList.Name` maps to, per stack.
 */

defined( 'ABSPATH' ) || exit;

return [

	// Present on every one of the 12 character classes.
	'shared' => [
		'Physical'          => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-physical-traits' ],
		'Social'            => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-social-traits' ],
		'Mental'            => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-mental-traits' ],
		'Negative Physical' => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-physical-traits-neg' ],
		'Negative Social'   => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-social-traits-neg' ],
		'Negative Mental'   => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-mental-traits-neg' ],
		'Abilities'         => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-abilities' ],
		'Merits'            => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-merits' ],
		'Flaws'             => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-flaws' ],
		'Derangements'      => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-derangements' ],
		// Influences and Backgrounds are two separate GV lists that fold into one BE block per stack.
		'Influences'        => [ 'outcome' => 'sheet_block', 'block_slug' => '{stack}-backgrounds' ],
		'Backgrounds'       => [ 'outcome' => 'sheet_block', 'block_slug' => '{stack}-backgrounds' ],
		// Per-character box-count data (e.g. Bruised:3, Wounded:2).
		'Health Levels'     => [ 'outcome' => 'sheet_block', 'block_slug' => '{stack}-health' ],
		'Equipment'         => [ 'outcome' => 'world_object', 'object_type' => 'item' ],
		// FeraClass alone spells this singular ("Location"); everyone else pluralizes it.
		'Locations'         => [ 'outcome' => 'world_object', 'object_type' => 'location' ],
		'Location'          => [ 'outcome' => 'world_object', 'object_type' => 'location' ],
	],

	'vampire' => [
		'Status'      => [ 'outcome' => 'sheet_block', 'block_slug' => 'vampire-statuses' ],
		// Combo Disciplines/Ritae are not separate GEX lists.
		'Disciplines' => [ 'outcome' => 'sheet_block', 'block_slug' => 'vampire-disciplines', 'blood_magic_block_slug' => 'vampire-blood-magic', 'combo_block_slug' => 'vampire-combo-disciplines' ],
		'Rituals'     => [ 'outcome' => 'sheet_block', 'block_slug' => 'vampire-rituals', 'combo_block_slug' => 'vampire-ritae' ],
		'Bonds'       => [ 'outcome' => 'preserve_as_note' ],
		'Miscellaneous' => [ 'outcome' => 'preserve_as_note' ],
	],

	'werewolf' => [
		'Gifts'  => [ 'outcome' => 'sheet_block', 'block_slug' => 'werewolf-gifts' ],
		'Rites'  => [ 'outcome' => 'sheet_block', 'block_slug' => 'werewolf-rites' ],
		// Honor/Glory/Wisdom are three separate GV lists; BE models Renown as one resource_pool block holding all three.
		'Honor'  => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Glory'  => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Wisdom' => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
	],

	// Fera and Bete share every block: their own fera-gifts and fera-backgrounds, and werewolf's rites, health, renown and resources.
	'fera' => [
		'Gifts'         => [ 'outcome' => 'sheet_block', 'block_slug' => 'fera-gifts' ],
		'Rites'         => [ 'outcome' => 'sheet_block', 'block_slug' => 'werewolf-rites' ],
		'Health Levels' => [ 'outcome' => 'sheet_block', 'block_slug' => 'werewolf-health' ],
		'Honor'         => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Glory'         => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Wisdom'        => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Features'      => [ 'outcome' => 'preserve_as_note' ],
	],
	'bete' => [
		'Influences'    => [ 'outcome' => 'sheet_block', 'block_slug' => 'fera-backgrounds' ],
		'Backgrounds'   => [ 'outcome' => 'sheet_block', 'block_slug' => 'fera-backgrounds' ],
		'Gifts'         => [ 'outcome' => 'sheet_block', 'block_slug' => 'fera-gifts' ],
		'Rites'         => [ 'outcome' => 'sheet_block', 'block_slug' => 'werewolf-rites' ],
		'Health Levels' => [ 'outcome' => 'sheet_block', 'block_slug' => 'werewolf-health' ],
		'Honor'         => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Glory'         => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Wisdom'        => [ 'outcome' => 'needs_design', 'note' => 'werewolf-renown holds Honor/Glory/Wisdom together; no merge logic written yet' ],
		'Features'      => [ 'outcome' => 'preserve_as_note' ],
	],

	'mage' => [
		'Resonance'   => [ 'outcome' => 'preserve_as_note' ],
		'Reputation'  => [ 'outcome' => 'preserve_as_note' ],
		'Rotes'       => [ 'outcome' => 'sheet_block', 'block_slug' => 'mage-rotes' ],
		'Spheres'     => [ 'outcome' => 'sheet_block', 'block_slug' => 'mage-spheres' ],
	],

	'changeling' => [
		'Status' => [ 'outcome' => 'preserve_as_note' ],
		'Arts'   => [ 'outcome' => 'sheet_block', 'block_slug' => 'changeling-arts' ],
		'Realms' => [ 'outcome' => 'sheet_block', 'block_slug' => 'changeling-realms' ],
	],

	'wraith' => [
		'Status'  => [ 'outcome' => 'preserve_as_note' ],
		'Arcanoi' => [ 'outcome' => 'sheet_block', 'block_slug' => 'wraith-arcanoi' ],
		'Thorns'  => [ 'outcome' => 'preserve_as_note' ],
	],

	'mortal' => [
		'Humanity'  => [ 'outcome' => 'needs_design', 'note' => 'mortal-resources likely holds this pool; not yet confirmed against the block definition' ],
		'Numina'    => [ 'outcome' => 'preserve_as_note' ],
	],

	'mummy' => [
		'Humanity' => [ 'outcome' => 'needs_design', 'note' => 'mummy-resources likely holds this pool; not yet confirmed against the block definition' ],
		'Status'   => [ 'outcome' => 'preserve_as_note' ],
		'Hekau'    => [ 'outcome' => 'sheet_block', 'block_slug' => 'mummy-hekau' ],
		'Spells'   => [ 'outcome' => 'preserve_as_note' ],
		'Rituals'  => [ 'outcome' => 'preserve_as_note' ],
	],

	'kueijin' => [
		'Status'      => [ 'outcome' => 'preserve_as_note' ],
		'Guanxi'      => [ 'outcome' => 'preserve_as_note' ],
		'Disciplines' => [ 'outcome' => 'sheet_block', 'block_slug' => 'kueijin-disciplines' ],
		'Rites'       => [ 'outcome' => 'preserve_as_note' ],
	],

	'various' => [
		'Tempers' => [ 'outcome' => 'preserve_as_note' ],
		'Powers'  => [ 'outcome' => 'preserve_as_note' ],
		// VariousClass is GV's generic template character.
		'_unresolved_note' => 'gvRaceVarious has no corresponding BE creature stack yet',
	],

	'hunter' => [
		'Derangements' => [ 'outcome' => 'sheet_block', 'block_slug' => 'met-derangements' ],
		'Edges'        => [ 'outcome' => 'preserve_as_note' ],
		'_unresolved_note' => 'gvRaceHunter has no corresponding BE creature stack yet',
	],

	'demon' => [
		'Lores'             => [ 'outcome' => 'preserve_as_note' ],
		'Apocalyptic Form'  => [ 'outcome' => 'preserve_as_note' ],
	],
];
