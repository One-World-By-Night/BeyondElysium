<?php
/**
 * How MET-Mechanics CSV rows map onto schema blocks.
 *
 * Returns an array with three top-level keys: 'discipline_labels' and
 * 'ritual_labels' each map a CSV Subtype string to a display label;
 * 'background_routing' maps a Background Subtype to the schema block
 * slug(s) it seeds. Read by Seeder::apply_met_csv_overrides().
 *
 * @see BE_PROCESS/workflow-0.10.md
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
