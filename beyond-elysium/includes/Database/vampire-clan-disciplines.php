<?php
/**
 * Clan/bloodline -> in-clan Discipline list.
 *
 * Keyed exactly by vampire-identity's own Clan option strings
 * (resolve_identity_options()). Each value is the fixed trio of
 * Disciplines that clan or bloodline receives at the in-clan price,
 * consumed by Cost_Engine::is_in_type_pure(). Clans and bloodlines with
 * no fixed trio (Caitiff, Panders, and others where sources disagree or
 * the discipline is a player choice) have no entry here and fall through
 * to the chosen_in_clan mechanism instead.
 */
return [
	// --- Core clans and antitribu ---
	'Brujah'                    => [ 'Celerity', 'Potence', 'Presence' ],
	'Brujah Antitribu'          => [ 'Celerity', 'Potence', 'Presence' ],
	'Gangrel'                   => [ 'Animalism', 'Fortitude', 'Protean' ],
	'Malkavian'                 => [ 'Auspex', 'Dementation', 'Obfuscate' ],
	'Malkavian Antitribu'       => [ 'Auspex', 'Dementation', 'Obfuscate' ],
	'Nosferatu'                 => [ 'Animalism', 'Obfuscate', 'Potence' ],
	'Nosferatu Antitribu'       => [ 'Animalism', 'Obfuscate', 'Potence' ],
	'Toreador'                  => [ 'Auspex', 'Celerity', 'Presence' ],
	'Toreador Antitribu'        => [ 'Auspex', 'Celerity', 'Presence' ],
	'Tremere'                   => [ 'Auspex', 'Dominate', 'Thaumaturgy' ],
	'Tremere Antitribu'         => [ 'Auspex', 'Dominate', 'Thaumaturgy' ],
	'Ventrue'                   => [ 'Dominate', 'Fortitude', 'Presence' ],
	'Ventrue Antitribu'         => [ 'Dominate', 'Fortitude', 'Presence' ],
	'Assamite'                  => [ 'Celerity', 'Obfuscate', 'Quietus' ],
	'Assamites'                 => [ 'Celerity', 'Obfuscate', 'Quietus' ],
	'Assamite Antitribu'        => [ 'Celerity', 'Obfuscate', 'Quietus' ],
	'Followers of Set'          => [ 'Obfuscate', 'Presence', 'Serpentis' ],
	'Giovanni'                  => [ 'Dominate', 'Necromancy', 'Potence' ],
	'Lasombra'                  => [ 'Dominate', 'Obtenebration', 'Potence' ],
	'Lasombra Antitribu'        => [ 'Dominate', 'Obtenebration', 'Potence' ],
	'Ravnos'                    => [ 'Animalism', 'Chimerstry', 'Fortitude' ],
	'Ravnos Antitribu'          => [ 'Animalism', 'Chimerstry', 'Fortitude' ],
	'Tzimisce'                  => [ 'Animalism', 'Auspex', 'Vicissitude' ],
	'Old Clan Tzimisce'         => [ 'Animalism', 'Auspex', 'Dominate' ],
	'Tzimisce, Old Clan'        => [ 'Animalism', 'Auspex', 'Dominate' ],
	'Salubri Antitribu'         => [ 'Auspex', 'Fortitude', 'Valeren' ],
	'Cappadocian'               => [ 'Auspex', 'Fortitude', 'Necromancy' ],
	'Cappadocians'              => [ 'Auspex', 'Fortitude', 'Necromancy' ],

	// --- Classic bloodlines ---
	'Anda'                      => [ 'Animalism', 'Fortitude', 'Protean' ],
	'Baali'                     => [ 'Daimoinon', 'Obfuscate', 'Presence' ],
	'Blood Brothers'            => [ 'Fortitude', 'Potence', 'Sanguinus' ],
	'Daughter of Cacophony'     => [ 'Fortitude', 'Melpominee', 'Presence' ],
	'Daughters of Cacophony'    => [ 'Fortitude', 'Melpominee', 'Presence' ],
	'Harbingers of Skulls'      => [ 'Auspex', 'Fortitude', 'Necromancy' ],
	'Kiasyd'                    => [ 'Dominate', 'Mytherceria', 'Obtenebration' ],
	'Lamia'                     => [ 'Fortitude', 'Necromancy', 'Potence' ],
	'Lhiannan'                  => [ 'Animalism', 'Ogham', 'Presence' ],
	'Nagaraja'                  => [ 'Auspex', 'Dominate', 'Necromancy' ],
	'Niktuku'                   => [ 'Auspex', 'Celerity', 'Potence' ],
	'Noiad'                     => [ 'Animalism', 'Auspex', 'Protean' ],
	'Samedi'                    => [ 'Fortitude', 'Obfuscate', 'Thanatosis' ],
	'Telyavelic Tremere'        => [ 'Auspex', 'Presence', 'Thaumaturgy' ],
	'True Brujah'               => [ 'Potence', 'Presence', 'Temporis' ],
	'Ghost Singer Gangrel'      => [ 'Animalism', 'Auspex', 'Protean' ],

	// --- Followers of Set bloodlines ---
	'Children of Damballah'     => [ 'Auspex', 'Presence', 'Serpentis' ],
	'Serpents of the Light'     => [ 'Obfuscate', 'Presence', 'Serpentis' ],
	'Tlacique'                  => [ 'Obfuscate', 'Presence', 'Protean' ],
	'Setite Warriors'           => [ 'Potence', 'Presence', 'Serpentis' ],
	'Witches of Echidna'        => [ 'Animalism', 'Presence', 'Setite Sorcery' ],
	'Daitya'                    => [ 'Obfuscate', 'Presence', 'Sadhana' ],

	// --- Assamite castes ---
	'Assamite Vizier'           => [ 'Auspex', 'Celerity', 'Quietus' ],
	'Assamite Sorcerers'        => [ 'Assamite Sorcery', 'Obfuscate', 'Quietus' ],

	// --- Laibon legacies ---
	'Akunanse'                  => [ 'Abombwe', 'Animalism', 'Fortitude' ],
	'Guruhi'                    => [ 'Animalism', 'Potence', 'Presence' ],
	'Ishtarri'                  => [ 'Celerity', 'Fortitude', 'Presence' ],
	'Osebo'                     => [ 'Auspex', 'Celerity', 'Potence' ],
	'Shango'                    => [ 'Celerity', 'Dur-An-Ki', 'Obfuscate' ],
	'Impundula'                 => [ 'Fortitude', 'Necromancy', 'Presence' ],
	// "Aizina" is this bloodline's own name for Obtenebration.
	'Ramanga'                   => [ 'Obfuscate', 'Obtenebration', 'Presence' ],

	// --- Brujah/Malkavian variants ---
	'Kairos Brujah'             => [ 'Potence', 'Presence', 'Temporis' ],
	'Epicene Brujah'            => [ 'Potence', 'Presence', 'Temporis' ],
	'Dominate Malkavians'       => [ 'Auspex', 'Dominate', 'Obfuscate' ],

	// --- Gargoyles ---
	'Gargoyles'                 => [ 'Fortitude', 'Potence', 'Visceratika' ],
	'Scout Gargoyles'           => [ 'Auspex', 'Flight', 'Obfuscate' ],
	'Sentinel Gargoyles'        => [ 'Flight', 'Fortitude', 'Potence' ],
	'Warrior Gargoyles'         => [ 'Flight', 'Fortitude', 'Protean' ],
	// Alias of Gargoyles; no separate trio is published for this era.
	'Dark Age Gargoyles'        => [ 'Fortitude', 'Potence', 'Visceratika' ],
	'Gargoyles, Dark Ages'      => [ 'Fortitude', 'Potence', 'Visceratika' ],
];
