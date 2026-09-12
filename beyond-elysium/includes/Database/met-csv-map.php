<?php
/**
 * How MET-Mechanics CSV rows map onto schema blocks.
 *
 * Returns an array with these top-level keys: 'discipline_labels' and
 * 'ritual_labels' each map a CSV Subtype string to a display label;
 * 'background_routing' maps a Background Subtype to the schema block
 * slug(s) it seeds; 'blood_magic' configures which Discipline Subtypes are
 * real, selectable Blood Magic traditions. Read by
 * Seeder::apply_met_csv_overrides().
 *
 * @see BE_PROCESS/workflow-0.10.md
 * @see BE_PROCESS/0.99.2-workflow.md Blood magic section
 * @see BE_PROCESS/blood-magic-paradigm.md
 */

defined( 'ABSPATH' ) || exit;

return [

	// Subtype -> display label for Discipline/Ritual items; "Hermetic" maps to Thaumaturgy.
	'discipline_labels' => [
		'Hermetic'            => 'Thaumaturgy',
		'Hermetic, Camarilla' => 'Thaumaturgy (Camarilla)',
		'Hermetic, Anarch'    => 'Thaumaturgy (Anarch)',
	],
	'ritual_labels'      => [
		'Hermetic'         => 'Thaumaturgy',
		'Hermetic, Anarch' => 'Thaumaturgy (Anarch)',
	],

	// Which Discipline Subtypes with a real Group value are genuine Blood Magic
	// traditions, versus data that must not become a selectable tradition.
	'blood_magic'        => [
		// Bare "Hermetic" is unlabelled data, not a tradition of its own - every path
		// it carries (Path of Conjuring, Path of Corruption, Path of Curses) already
		// exists under a real tradition elsewhere in the catalog. The four Quietus
		// entries are Assamite castes, not traditions - the same caste/tradition trap
		// D39 avoided populating vampire-clan-disciplines.php. Black Hand and Setite
		// Sorcery are not listed here at all: their sole rows are full Ref
		// cross-reference placeholders with no real level data, already excluded by
		// the same is_met_ref_placeholder() filter every other Discipline row passes
		// through - verified 2026-09-11, not assumed.
		'excluded_subtypes'    => [
			'Hermetic',
			'Quietus, Cruscitus / Warrior',
			'Quietus, Hematus / Vizier',
			'Quietus, Minhit Dume / Vizier',
			'Quietus, Sorcerer',
		],
		// A Group value's trailing " / Segment" is a caste/covenant restriction, not
		// an alternate name, only when it matches one of these (case-insensitive).
		// Verified against the real catalog 2026-09-11 - exactly three real rows match.
		'restriction_keywords' => [ 'Sabbat', 'Warrior Only', 'Loyalist Only' ],
	],

	// Background Subtype -> target schema block slug(s) it routes into.
	'background_routing' => [
		'General'         => [
			'vampire-backgrounds', 'werewolf-backgrounds', 'mage-backgrounds',
			'changeling-backgrounds', 'wraith-backgrounds', 'demon-backgrounds',
			'mummy-backgrounds', 'kueijin-backgrounds', 'mortal-backgrounds',
			'fera-backgrounds',
		],
		'Vampire'         => [ 'vampire-backgrounds' ],
		'Mage'            => [ 'mage-backgrounds' ],
		'Fae'             => [ 'changeling-backgrounds' ],
		'Changing Breeds' => [ 'werewolf-backgrounds', 'fera-backgrounds' ],
		'Wraith'          => [ 'wraith-backgrounds' ],
	],
];
