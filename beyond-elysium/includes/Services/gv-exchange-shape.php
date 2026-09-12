<?php
/**
 * The shared field-order authority for Grapevine's character exchange format
 * (GX-1, gex-export-transfer-design.md §4). Data only, no behaviour - one
 * `return [...]` array, one entry per `GEX_Parser::RACE_TYPE_MAP` race.
 *
 * This is the container SHAPE: exactly which scalars, trait lists, and tail
 * fields each of the 12 real Grapevine character classes reads and writes,
 * in real write order, with every row cited to the `.cls` line it came from.
 * It carries no BeyondElysium block slugs, no field labels, no per-chronicle
 * anything - those live where they already live (`gex-trait-list-map.php`,
 * `gex-identity-map.php`, `field-map.php`). This table is exclusively facts
 * about the file format itself.
 *
 * GX-2 re-points `GEX_Parser`'s and `GEX_Xml_Parser`'s trait-list reads at
 * this table's `trait_lists` arrays (name/abc/neg/atomic/display, in write
 * order); a name mismatch on read is recorded for diagnostics, never fatal
 * (Dialect C tolerance, gex-export-transfer-design.md §2e). The version-gated
 * *scalar* sections of each `parse_character_*` method keep their own inline
 * bodies unchanged - that gating has no write-side analogue (§2b: real
 * Grapevine never version-gates a write) and a writer targeting the current
 * format (3.0) always emits every scalar unconditionally regardless of this
 * table's `min_version` values. `min_version` therefore means two different
 * things depending on where it appears: on a `trait_lists` row it is a real,
 * consumed read-gate (GX-2's shared loop honours it); on a `scalars` row it
 * is documentation only (the version at which the reader added support for
 * that field), consumed by nothing yet - kept because it is real, cited
 * information a future maintainer will want, not because GX-2 or GX-3 read
 * it there.
 *
 * Field types: `string`, `int16`, `int32`, `bool`, `date`, and `single` (a
 * VB6 `Single` - a real, distinct type read via `GV_Binary_Reader::single()`,
 * used only for werewolf/fera's `temp_honor`/`temp_glory`/`temp_wisdom`).
 *
 * `xml_omit` marks a scalar/bool the writer's `WriteAttribute` call omits
 * when the value equals that constant. `xml_omit_if` marks one omitted when
 * it equals another named field's own value (almost always a `temp_*` field
 * omitted when it equals its permanent counterpart, or a `social_max`/
 * `mental_max` omitted when it equals `physical_max`). Neither is read-side
 * behaviour on our side - `GEX_Xml_Parser` already defaults an absent
 * attribute to the same fallback value directly (GX-0) - they exist so GX-3's
 * writer knows when a real Grapevine-shaped file would leave the attribute
 * out.
 *
 * `xml_enum` (wraith's `ethnos` only) maps the binary format's raw int32 to
 * the XML format's own 3-value string, since that field is the one place a
 * plain scalar cannot express the real shape (`WraithClass.cls:340-347`).
 *
 * "Locations" vs "Hangouts": every race's `HangoutList` member is registered
 * under the string `"Locations"` (or, for fera alone, `"Location"` singular -
 * a genuine, verified pre-existing typo in `FeraClass.cls:906` that means
 * fera's own `InputFromFile` Select Case, which matches on `"Locations"`
 * plural, can never route its own writer's output back to `HangoutList` -
 * not a transcription error here, a real upstream Grapevine 3.01 bug, kept
 * faithfully rather than silently corrected). `GEX_Parser.php`'s own trailing
 * `// Hangouts` comments on these `$add()` calls name the PHP/VB6 *variable*
 * (`HangoutList`), never the wire value - confirmed independently against
 * eight of the twelve classes' own `Initialize`/`InputFromFile` dispatch
 * during GX-1's transcription. Every `trait_lists` row below uses the real,
 * on-the-wire name.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-1
 * @see BE_PROCESS/GV-SOURCEMAP.md
 */

defined( 'ABSPATH' ) || exit;

return [

	'vampire' => [
		'race_code' => 2, // GEX_Parser.php:52 (RACE_TYPE_MAP[2] = 'vampire')
		'xml_tag'   => 'vampire', // VampireClass.cls (OutputToFile .BeginTag "vampire"); GEX_Xml_Parser.php:83
		'scalars'   => [
			[ 'key' => 'name',       'type' => 'string', 'xml' => 'name' ],       // GEX_Parser.php:1029
			[ 'key' => 'nature',     'type' => 'string', 'xml' => 'nature' ],     // GEX_Parser.php:1030
			[ 'key' => 'demeanor',   'type' => 'string', 'xml' => 'demeanor' ],   // GEX_Parser.php:1031
			[ 'key' => 'clan',       'type' => 'string', 'xml' => 'clan' ],       // GEX_Parser.php:1032
			[ 'key' => 'sect',       'type' => 'string', 'xml' => 'sect' ],       // GEX_Parser.php:1033
			[ 'key' => 'coterie',    'type' => 'string', 'xml' => 'coterie', 'min_version' => 2.395 ], // GEX_Parser.php:1034 (else '')
			[ 'key' => 'sire',       'type' => 'string', 'xml' => 'sire', 'min_version' => 2.399 ],    // GEX_Parser.php:1035 (else '')
			[ 'key' => 'generation', 'type' => 'int16',  'xml' => 'generation' ], // GEX_Parser.php:1036
			[ 'key' => 'title',      'type' => 'string', 'xml' => 'title' ],      // GEX_Parser.php:1037
			[ 'key' => 'blood',             'type' => 'int16', 'xml' => 'blood' ],             // GEX_Parser.php:1040/1059
			[ 'key' => 'temp_blood',        'type' => 'int16', 'xml' => 'tempblood',        'xml_omit_if' => 'blood',        'min_version' => 2.397 ], // GEX_Parser.php:1041 (else mirrors blood, :1066)
			[ 'key' => 'willpower',         'type' => 'int16', 'xml' => 'willpower' ],         // GEX_Parser.php:1042/1060
			[ 'key' => 'temp_willpower',    'type' => 'int16', 'xml' => 'tempwillpower',    'xml_omit_if' => 'willpower',    'min_version' => 2.397 ], // GEX_Parser.php:1043 (else mirrors, :1067)
			[ 'key' => 'conscience',        'type' => 'int16', 'xml' => 'conscience' ],        // GEX_Parser.php:1044/1061
			[ 'key' => 'temp_conscience',   'type' => 'int16', 'xml' => 'tempconscience',   'xml_omit_if' => 'conscience',   'min_version' => 2.397 ], // GEX_Parser.php:1045 (else mirrors, :1068)
			[ 'key' => 'self_control',      'type' => 'int16', 'xml' => 'selfcontrol' ],       // GEX_Parser.php:1046/1062
			[ 'key' => 'temp_self_control', 'type' => 'int16', 'xml' => 'tempselfcontrol', 'xml_omit_if' => 'self_control', 'min_version' => 2.397 ], // GEX_Parser.php:1047 (else mirrors, :1069)
			[ 'key' => 'courage',           'type' => 'int16', 'xml' => 'courage' ],           // GEX_Parser.php:1048/1063
			[ 'key' => 'temp_courage',      'type' => 'int16', 'xml' => 'tempcourage',      'xml_omit_if' => 'courage',      'min_version' => 2.397 ], // GEX_Parser.php:1049 (else mirrors, :1070)
			[ 'key' => 'path',              'type' => 'string', 'xml' => 'path' ],             // GEX_Parser.php:1050/1064
			[ 'key' => 'path_traits',       'type' => 'int16', 'xml' => 'pathtraits' ],        // GEX_Parser.php:1051/1065
			[ 'key' => 'temp_path_traits',  'type' => 'int16', 'xml' => 'temppathtraits',  'xml_omit_if' => 'path_traits',  'min_version' => 2.397 ], // GEX_Parser.php:1052 (else mirrors, :1071)
			[ 'key' => 'aura',       'type' => 'string', 'xml' => 'aura', 'min_version' => 2.399 ], // GEX_Parser.php:1053 (else '')
			[ 'key' => 'aura_bonus', 'type' => 'string', 'xml' => 'aurabonus', 'xml_omit' => '+0', 'min_version' => 2.399 ], // GEX_Parser.php:1054 (else ''); writer emits a real 'aurabonus' attribute rather than reproducing VampireClass.cls's real duplicate-'aura' write bug (design doc §2d)
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // GEX_Parser.php:1055 (else 0, backfilled post-hoc from trait counts at :1139)
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // GEX_Parser.php:1056
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // GEX_Parser.php:1057
			[ 'key' => 'player',       'type' => 'string', 'xml' => 'player' ], // GEX_Parser.php:1079
			[ 'key' => 'status',       'type' => 'string', 'xml' => 'status' ], // GEX_Parser.php:1080
			[ 'key' => 'id',           'type' => 'string', 'xml' => 'id' ],     // GEX_Parser.php:1081
			[ 'key' => 'start_date',   'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // GEX_Parser.php:1083-1089 (pre-2.397 a legacy string is discarded, null)
			[ 'key' => 'narrator',     'type' => 'string', 'xml' => 'narrator' ], // GEX_Parser.php:1091
			[ 'key' => 'is_npc',       'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // GEX_Parser.php:1092
			[ 'key' => 'last_modified', 'type' => 'date',  'xml' => 'lastmodified' ], // GEX_Parser.php:1093
		],
		'experience' => true, // GEX_Parser.php:1095
		// 20 trait lists, write order. Flags from VampireClass.Initialize() (VampireClass.cls:947-971).
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Status',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Bonds',             'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Miscellaneous',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Derangements',      'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 5 ],
			[ 'name' => 'Disciplines',       'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ],
			[ 'name' => 'Rituals',           'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ],
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ],
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ],
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ],
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // GEX_Parser.php:1123-1125
		],
		'boons' => true, // GEX_Parser.php:1127-1133 - vampire only, gated >= 2.399; BoonClass.OutputToFile called from VampireClass.cls:418-424
		'tail'  => [
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // GEX_Parser.php:1135
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ],     // GEX_Parser.php:1136
		],
	],

	'werewolf' => [
		'race_code' => 3, // PublicTypes.bas:34 (gvRaceWerewolf = 3); WerewolfClass.cls:328
		'xml_tag'   => 'werewolf', // WerewolfClass.cls:341 (OutputToFile), :427 (InputFromFile)
		'scalars'   => [
			[ 'key' => 'name',      'type' => 'string', 'xml' => 'name' ],      // WerewolfClass.cls:16,342,544
			[ 'key' => 'nature',    'type' => 'string', 'xml' => 'nature' ],    // :17,343,545
			[ 'key' => 'demeanor',  'type' => 'string', 'xml' => 'demeanor' ],  // :18,344,546
			[ 'key' => 'tribe',     'type' => 'string', 'xml' => 'tribe' ],     // :19,345,547
			[ 'key' => 'breed',     'type' => 'string', 'xml' => 'breed' ],     // :20,346,548
			[ 'key' => 'auspice',   'type' => 'string', 'xml' => 'auspice' ],   // :21,347,549
			[ 'key' => 'rank',      'type' => 'string', 'xml' => 'rank' ],      // :22,348,550
			[ 'key' => 'pack',      'type' => 'string', 'xml' => 'pack' ],      // :23,349,551
			[ 'key' => 'totem',     'type' => 'string', 'xml' => 'totem' ],     // :24,350,552
			[ 'key' => 'camp',      'type' => 'string', 'xml' => 'camp' ],      // :25,351,553
			[ 'key' => 'position',  'type' => 'string', 'xml' => 'position' ],  // :26,352,554
			[ 'key' => 'notoriety', 'type' => 'int16',  'xml' => 'notoriety', 'xml_omit' => 0 ], // :27,353,555
			[ 'key' => 'rage',           'type' => 'int16', 'xml' => 'rage' ],        // :29,354,556; GEX_Parser.php:1212/1219
			[ 'key' => 'temp_rage',      'type' => 'int16', 'xml' => 'temprage',      'xml_omit_if' => 'rage',      'min_version' => 2.397 ], // :33,355,557
			[ 'key' => 'gnosis',         'type' => 'int16', 'xml' => 'gnosis' ],       // :30,356,558; GEX_Parser.php:1214/1220
			[ 'key' => 'temp_gnosis',    'type' => 'int16', 'xml' => 'tempgnosis',    'xml_omit_if' => 'gnosis',    'min_version' => 2.397 ], // :34,357,559
			[ 'key' => 'willpower',      'type' => 'int16', 'xml' => 'willpower' ],    // :31,358,560; GEX_Parser.php:1216/1221
			[ 'key' => 'temp_willpower', 'type' => 'int16', 'xml' => 'tempwillpower', 'xml_omit_if' => 'willpower', 'min_version' => 2.397 ], // :35,359,561
			[ 'key' => 'honor',      'type' => 'int16',  'xml' => 'honor',  'min_version' => 2.395 ], // :37,362,562; GEX_Parser.php:1227-1244 (else derived from a combined Single)
			[ 'key' => 'glory',      'type' => 'int16',  'xml' => 'glory',  'min_version' => 2.395 ], // :38,364,563
			[ 'key' => 'wisdom',     'type' => 'int16',  'xml' => 'wisdom', 'min_version' => 2.395 ], // :39,363,564 - NOTE: XML write order is honor,temphonor,glory,tempglory,wisdom,tempwisdom (:360-365); binary/reader order is honor,glory,wisdom,temphonor,tempglory,tempwisdom (:562-567) - listed here in the binary/reader's positional order since binary framing is what this table's row order must not break for GX-6; a byte-faithful XML writer must reorder these six for that format specifically.
			[ 'key' => 'temp_honor', 'type' => 'single', 'xml' => 'temphonor' ], // :40,361,565; GEX_Parser.php:1231 ($r->single())
			[ 'key' => 'temp_glory', 'type' => 'single', 'xml' => 'tempglory' ], // :41,363,566
			[ 'key' => 'temp_wisdom','type' => 'single', 'xml' => 'tempwisdom' ], // :42,365,567
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // :50,366,568
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :51,367,569
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :52,368,570
			[ 'key' => 'player',       'type' => 'string', 'xml' => 'player' ], // :76,369,571
			[ 'key' => 'status',       'type' => 'string', 'xml' => 'status' ], // :77,370,572
			[ 'key' => 'id',           'type' => 'string', 'xml' => 'id' ],     // :78,371,573
			[ 'key' => 'start_date',   'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // :79,372,574
			[ 'key' => 'narrator',     'type' => 'string', 'xml' => 'narrator' ], // :80,373,575
			[ 'key' => 'is_npc',       'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :81,374,576
			[ 'key' => 'last_modified', 'type' => 'date',  'xml' => 'lastmodified' ], // :83,375,577
		],
		'experience' => true, // WerewolfClass.cls:82,377,579
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // Class_Initialize:893
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :894
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :895
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :896
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :897
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :898
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :900
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :901
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :902
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :903
			[ 'name' => 'Features',          'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5 ], // :905
			[ 'name' => 'Gifts',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :906
			[ 'name' => 'Rites',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :907
			[ 'name' => 'Honor',             'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :908
			[ 'name' => 'Glory',             'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :909
			[ 'name' => 'Wisdom',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :910
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :912
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :913
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :915
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :916
		],
		'boons' => false, // No Boon reference anywhere in WerewolfClass.cls; GEX_Parser.php's sole parse_boon() call site (line 1131) is inside parse_character_vampire only
		'tail'  => [
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // WerewolfClass.cls:73,406,608
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ], // :74,407,609
		],
	],

	'mortal' => [
		'race_code' => 4, // PublicTypes.bas:35 (gvRaceMortal = 4); MortalClass.cls:267
		'xml_tag'   => 'mortal', // MortalClass.cls:331,412
		'scalars'   => [
			[ 'key' => 'name',        'type' => 'string', 'xml' => 'name' ],        // :16,333,514
			[ 'key' => 'nature',      'type' => 'string', 'xml' => 'nature' ],      // :17,334,515
			[ 'key' => 'demeanor',    'type' => 'string', 'xml' => 'demeanor' ],    // :18,335,516
			[ 'key' => 'motivation',  'type' => 'string', 'xml' => 'motivation' ],  // :19,336,517
			[ 'key' => 'association', 'type' => 'string', 'xml' => 'association' ], // :20,337,518
			[ 'key' => 'regnant',     'type' => 'string', 'xml' => 'regnant', 'min_version' => 2.399 ], // :22,338,519
			[ 'key' => 'title',       'type' => 'string', 'xml' => 'title' ],       // :21,339,520
			[ 'key' => 'willpower',         'type' => 'int16', 'xml' => 'willpower' ],        // :24,340,521
			[ 'key' => 'temp_willpower',    'type' => 'int16', 'xml' => 'tempwillpower',    'xml_omit_if' => 'willpower',    'min_version' => 2.397 ], // :32,341
			[ 'key' => 'humanity',          'type' => 'int16', 'xml' => 'humanity' ],        // :26,342,523
			[ 'key' => 'temp_humanity',     'type' => 'int16', 'xml' => 'temphumanity',     'xml_omit_if' => 'humanity',     'min_version' => 2.397 ], // :34,343
			[ 'key' => 'conscience',        'type' => 'int16', 'xml' => 'conscience' ],       // :27,344,525
			[ 'key' => 'temp_conscience',   'type' => 'int16', 'xml' => 'tempconscience',   'xml_omit_if' => 'conscience',   'min_version' => 2.397 ], // :35,345
			[ 'key' => 'self_control',      'type' => 'int16', 'xml' => 'selfcontrol' ],      // :28,346,527
			[ 'key' => 'temp_self_control', 'type' => 'int16', 'xml' => 'tempselfcontrol', 'xml_omit_if' => 'self_control', 'min_version' => 2.397 ], // :36,347
			[ 'key' => 'courage',           'type' => 'int16', 'xml' => 'courage' ],          // :29,348,529
			[ 'key' => 'temp_courage',      'type' => 'int16', 'xml' => 'tempcourage',      'xml_omit_if' => 'courage',      'min_version' => 2.397 ], // :37,349
			[ 'key' => 'blood',             'type' => 'int16', 'xml' => 'blood' ],            // :25,350,531
			[ 'key' => 'temp_blood',        'type' => 'int16', 'xml' => 'tempblood',        'xml_omit_if' => 'blood',        'min_version' => 2.397 ], // :33,351
			[ 'key' => 'true_faith',        'type' => 'int16', 'xml' => 'truefaith' ],        // :30,352,533
			[ 'key' => 'temp_true_faith',   'type' => 'int16', 'xml' => 'temptruefaith',   'xml_omit_if' => 'true_faith',   'min_version' => 2.397 ], // :38,353
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // :46,354
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :47,355
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :48,356
			[ 'key' => 'player',       'type' => 'string', 'xml' => 'player' ],   // :71,357,538
			[ 'key' => 'status',       'type' => 'string', 'xml' => 'status' ],   // :72,358,539
			[ 'key' => 'id',           'type' => 'string', 'xml' => 'id' ],       // :73,359,540
			[ 'key' => 'start_date',   'type' => 'date',   'xml' => 'startdate' ], // :74,360 - XML unconditional; binary wire TYPE changes at 2.397 (string before, native Date from), not a presence gate
			[ 'key' => 'narrator',     'type' => 'string', 'xml' => 'narrator' ], // :75,361,542
			[ 'key' => 'is_npc',       'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :76,362,543
			[ 'key' => 'last_modified', 'type' => 'date',  'xml' => 'lastmodified' ], // :78,363,544
		],
		'experience' => true, // MortalClass.cls:77,365,546
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :847
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :848
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :849
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :850
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :851
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :852
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :854
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :855
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :856
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :857
			[ 'name' => 'Humanity',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :859
			[ 'name' => 'Derangements',      'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 5 ], // :860
			[ 'name' => 'Numina',            'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :861
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :863
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :864
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :866
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :867
		],
		'boons' => false, // No Boon reference anywhere in MortalClass.cls (full file); not reached from parse_character_mortal
		'tail'  => [
			[ 'key' => 'other',     'type' => 'string', 'xml_cdata' => 'other' ],     // MortalClass.cls:67,390 - a third tail field beyond biography/notes
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :68,391
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ],     // :69,392
		],
	],

	'changeling' => [
		'race_code' => 5, // GEX_Parser.php:54 (RACE_TYPE_MAP[5] = 'changeling')
		'xml_tag'   => 'changeling', // ChangelingClass.cls:306,380
		'scalars'   => [
			[ 'key' => 'name',            'type' => 'string', 'xml' => 'name' ],           // :308,476
			[ 'key' => 'seelie_legacy',    'type' => 'string', 'xml' => 'seelie' ],         // :309,477
			[ 'key' => 'unseelie_legacy',  'type' => 'string', 'xml' => 'unseelie' ],       // :310,478
			[ 'key' => 'court',            'type' => 'string', 'xml' => 'court' ],          // :311,479
			[ 'key' => 'kith',             'type' => 'string', 'xml' => 'kith' ],           // :312,480
			[ 'key' => 'seeming',          'type' => 'string', 'xml' => 'seeming' ],        // :313,481
			[ 'key' => 'house',            'type' => 'string', 'xml' => 'house' ],          // :314,482
			[ 'key' => 'threshold',        'type' => 'string', 'xml' => 'threshold' ],      // :315,483
			[ 'key' => 'title',            'type' => 'string', 'xml' => 'title' ],          // :316,484
			[ 'key' => 'glamour',          'type' => 'int16',  'xml' => 'glamour' ],        // :317,485
			[ 'key' => 'temp_glamour',     'type' => 'int16',  'xml' => 'tempglamour',  'xml_omit_if' => 'glamour',  'min_version' => 2.397 ], // :318,486
			[ 'key' => 'banality',         'type' => 'int16',  'xml' => 'banality' ],       // :319,487
			[ 'key' => 'temp_banality',    'type' => 'int16',  'xml' => 'tempbanality', 'xml_omit_if' => 'banality', 'min_version' => 2.397 ], // :320,488
			[ 'key' => 'willpower',        'type' => 'int16',  'xml' => 'willpower' ],      // :321,489
			[ 'key' => 'temp_willpower',   'type' => 'int16',  'xml' => 'tempwillpower', 'xml_omit_if' => 'willpower', 'min_version' => 2.397 ], // :322,490
			[ 'key' => 'physical_max',     'type' => 'int16',  'xml' => 'physicalmax', 'min_version' => 2.397 ], // :323,491
			[ 'key' => 'social_max',       'type' => 'int16',  'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :324,492
			[ 'key' => 'mental_max',       'type' => 'int16',  'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :325,493
			[ 'key' => 'player',           'type' => 'string', 'xml' => 'player' ],         // :326,494
			[ 'key' => 'status',           'type' => 'string', 'xml' => 'status' ],         // :327,495
			[ 'key' => 'id',               'type' => 'string', 'xml' => 'id' ],             // :328,496
			[ 'key' => 'start_date',       'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // :329,497
			[ 'key' => 'narrator',         'type' => 'string', 'xml' => 'narrator' ],       // :330,498
			[ 'key' => 'is_npc',           'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :331,499
			[ 'key' => 'last_modified',    'type' => 'date',   'xml' => 'lastmodified' ],   // :332,500
		],
		'experience' => true, // ChangelingClass.cls:68,334,502
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :749
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :750
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :751
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :752
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :753
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :754
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :756
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :757
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :758
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :759
			[ 'name' => 'Status',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :760
			[ 'name' => 'Arts',              'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :762
			[ 'name' => 'Realms',            'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :763
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :765
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :766
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :768
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :769
		],
		'boons' => false, // No Boon reference anywhere in ChangelingClass.cls (full file, 818 lines)
		'tail'  => [
			[ 'key' => 'oaths',     'type' => 'string', 'xml_cdata' => 'oaths' ],     // :358 - written/read FIRST, before biography
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :359
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ],     // :360
		],
	],

	'wraith' => [
		'race_code' => 6, // PublicTypes.bas:37 (gvRaceWraith = 6); WraithClass.cls:324
		'xml_tag'   => 'wraith', // WraithClass.cls:337,426
		'scalars'   => [
			[ 'key' => 'name', 'type' => 'string', 'xml' => 'name' ], // :22,339,553
			// The XML format maps this int32 enum to/from a 3-value string; the binary format
			// stores the raw int. WraithClass.cls:340-347 (write), :429-433 (read).
			[ 'key' => 'ethnos', 'type' => 'int32', 'xml' => 'ethnos', 'xml_enum' => [ 0 => 'Wraith', 1 => 'Risen', 2 => 'Spectre' ] ], // :16-20 (Enum EthnosType), :554,626
			[ 'key' => 'nature',           'type' => 'string', 'xml' => 'nature' ],   // :24,348,555
			[ 'key' => 'demeanor',         'type' => 'string', 'xml' => 'demeanor' ], // :25,349,556
			[ 'key' => 'guild',            'type' => 'string', 'xml' => 'guild' ],    // :26,350,557
			[ 'key' => 'faction',          'type' => 'string', 'xml' => 'faction' ],  // :27,351,558
			[ 'key' => 'legion',           'type' => 'string', 'xml' => 'legion' ],   // :28,352,559
			[ 'key' => 'rank',             'type' => 'string', 'xml' => 'rank' ],     // :29,353,560
			[ 'key' => 'pathos',           'type' => 'int16',  'xml' => 'pathos' ],   // :31,354,561
			[ 'key' => 'temp_pathos',      'type' => 'int16',  'xml' => 'temppathos', 'xml_omit_if' => 'pathos',   'min_version' => 2.397 ], // :35,355,562
			[ 'key' => 'corpus',           'type' => 'int16',  'xml' => 'corpus' ],   // :32,356,563
			[ 'key' => 'temp_corpus',      'type' => 'int16',  'xml' => 'tempcorpus', 'xml_omit_if' => 'corpus',   'min_version' => 2.397 ], // :36,357,564
			[ 'key' => 'willpower',        'type' => 'int16',  'xml' => 'willpower' ], // :33,358,565
			[ 'key' => 'temp_willpower',   'type' => 'int16',  'xml' => 'tempwillpower', 'xml_omit_if' => 'willpower', 'min_version' => 2.397 ], // :37,359,566
			[ 'key' => 'shadow_archetype', 'type' => 'string', 'xml' => 'shadowarchetype' ], // :65,360,567
			[ 'key' => 'shadow_player',    'type' => 'string', 'xml' => 'shadowplayer' ],    // :66,361,568
			[ 'key' => 'angst',            'type' => 'int16',  'xml' => 'angst' ],   // :67,362,569
			[ 'key' => 'temp_angst',       'type' => 'int16',  'xml' => 'tempangst', 'xml_omit_if' => 'angst', 'min_version' => 2.397 ], // :68,363,570 - unlike pathos/corpus/willpower, pre-2.397 defaults to 0 rather than mirroring angst (a real reader-side asymmetry, not writer-relevant)
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // :45,364,571
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :46,365,572
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :47,366,573
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :76,367,574
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :77,368,575
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :78,369,576
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // :79,370,577
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :80,371,578
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :81,372,579
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :83,373,580
		],
		'experience' => true, // WraithClass.cls:82,375,582
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :849
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :850
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :851
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :852
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :853
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :854
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :856
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :858
			[ 'name' => 'Status',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :859
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :857
			[ 'name' => 'Arcanoi',           'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :861
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :862
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :863
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :866
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :867
			[ 'name' => 'Thorns',            'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :864
		],
		'boons' => false, // No Boon reference anywhere in WraithClass.cls
		// Wraith writes 8 free-text CDATA fields, not 2 - six are genuinely interspersed
		// between specific trait lists (load-bearing position for the BINARY format only;
		// XML's InputFromFile dispatches by tag name, order-independent there). Listed here
		// in true write order; a binary writer must place each at its cited position, not
		// simply append them after trait_lists the way vampire's two-field tail works.
		'tail'  => [
			[ 'key' => 'passions',      'type' => 'string', 'xml_cdata' => 'passions' ],     // :58,389,596 - between Influences and Arcanoi
			[ 'key' => 'fetters',       'type' => 'string', 'xml_cdata' => 'fetters' ],      // :59,390,597
			[ 'key' => 'life',          'type' => 'string', 'xml_cdata' => 'life' ],         // :60,391,598
			[ 'key' => 'death',         'type' => 'string', 'xml_cdata' => 'death' ],        // :61,392,599
			[ 'key' => 'haunt',         'type' => 'string', 'xml_cdata' => 'haunt' ],        // :62,393,600
			[ 'key' => 'regret',        'type' => 'string', 'xml_cdata' => 'regret' ],       // :63,394,601 - last of this block, Arcanoi follows
			[ 'key' => 'dark_passions', 'type' => 'string', 'xml_cdata' => 'darkpassions' ], // :69,403,610 - between Locations and Thorns
			[ 'key' => 'notes',         'type' => 'string', 'xml_cdata' => 'notes' ],        // :74,406,613 - genuinely last, matches vampire's tail shape
		],
	],

	'mage' => [
		'race_code' => 7, // PublicTypes.bas:38 (gvracemage = 7); MageClass.cls:248
		'xml_tag'   => 'mage', // MageClass.cls:313,389
		'scalars'   => [
			[ 'key' => 'name',         'type' => 'string', 'xml' => 'name' ],       // :315,488
			[ 'key' => 'nature',       'type' => 'string', 'xml' => 'nature' ],     // :316,489
			[ 'key' => 'demeanor',     'type' => 'string', 'xml' => 'demeanor' ],   // :317,490
			[ 'key' => 'essence',      'type' => 'string', 'xml' => 'essence' ],    // :318,491
			[ 'key' => 'tradition',    'type' => 'string', 'xml' => 'tradition' ],  // :319,492
			// OutputToFile writes cabal,rank,faction (:320-322); OutputToBinary/GEX_Parser.php
			// write/read faction,cabal,rank (:493-495) - genuine order divergence between the
			// two write methods, field set identical either way. Binary/reader order used here.
			[ 'key' => 'faction',      'type' => 'string', 'xml' => 'faction' ],    // :322,493
			[ 'key' => 'cabal',        'type' => 'string', 'xml' => 'cabal' ],      // :320,494
			[ 'key' => 'rank',         'type' => 'string', 'xml' => 'rank' ],       // :321,495
			[ 'key' => 'willpower',         'type' => 'int16', 'xml' => 'willpower' ],        // :323,496
			[ 'key' => 'temp_willpower',    'type' => 'int16', 'xml' => 'tempwillpower',    'xml_omit_if' => 'willpower',    'min_version' => 2.397 ], // :324,497
			[ 'key' => 'arete',             'type' => 'int16', 'xml' => 'arete' ],            // :325,498
			[ 'key' => 'temp_arete',        'type' => 'int16', 'xml' => 'temparete',        'xml_omit_if' => 'arete',        'min_version' => 2.397 ], // :326,499
			[ 'key' => 'quintessence',      'type' => 'int16', 'xml' => 'quintessence' ],      // :327,500
			[ 'key' => 'temp_quintessence', 'type' => 'int16', 'xml' => 'tempquintessence', 'xml_omit_if' => 'quintessence', 'min_version' => 2.397 ], // :328,501
			[ 'key' => 'paradox',           'type' => 'int16', 'xml' => 'paradox' ],           // :329,502
			[ 'key' => 'temp_paradox',      'type' => 'int16', 'xml' => 'tempparadox',      'xml_omit_if' => 'paradox',      'min_version' => 2.397 ], // :330,503
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // :331,504
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :332,505
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :333,506
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :334,507
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :335,508
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :336,509
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // :337,510
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :338,511
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :339,512
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :340,513
		],
		'experience' => true, // MageClass.cls:71,342,515
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :801
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :802
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :803
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :804
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :805
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :806
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :808
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :809
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :810
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :811
			[ 'name' => 'Resonance',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :813
			[ 'name' => 'Reputation',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :814
			[ 'name' => 'Spheres',           'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :816
			[ 'name' => 'Rotes',             'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5 ], // :815
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :818
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :819
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :821
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :822
		],
		'boons' => false, // No Boon reference anywhere in MageClass.cls (full 871-line file)
		'tail'  => [
			[ 'key' => 'foci',      'type' => 'string', 'xml_cdata' => 'foci' ],      // :367,540 - written FIRST, before biography
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :368,541
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ],     // :369,542
		],
	],

	'fera' => [
		'race_code' => 8, // PublicTypes.bas:39 (gvRaceFera = 8); FeraClass.cls:327
		'xml_tag'   => 'fera', // FeraClass.cls:340,426
		'scalars'   => [
			[ 'key' => 'name',      'type' => 'string', 'xml' => 'name' ],     // :16,342
			[ 'key' => 'nature',    'type' => 'string', 'xml' => 'nature' ],   // :17,343
			[ 'key' => 'demeanor',  'type' => 'string', 'xml' => 'demeanor' ], // :18,344
			[ 'key' => 'fera',      'type' => 'string', 'xml' => 'fera' ],     // :19,345 - the character's breed-type (Bastet/Corax/Ratkin/...), distinct from this array's own outer race key despite the same word
			[ 'key' => 'breed',     'type' => 'string', 'xml' => 'breed' ],    // :20,346
			[ 'key' => 'auspice',   'type' => 'string', 'xml' => 'auspice' ],  // :21,347
			[ 'key' => 'rank',      'type' => 'string', 'xml' => 'rank' ],     // :22,348
			[ 'key' => 'pack',      'type' => 'string', 'xml' => 'pack' ],     // :23,349
			[ 'key' => 'totem',     'type' => 'string', 'xml' => 'totem' ],    // :24,350
			[ 'key' => 'position',  'type' => 'string', 'xml' => 'position' ], // :25,351
			[ 'key' => 'notoriety', 'type' => 'int16',  'xml' => 'notoriety', 'xml_omit' => 0 ], // :26,352
			[ 'key' => 'rage',           'type' => 'int16', 'xml' => 'rage' ],          // :28,353
			[ 'key' => 'temp_rage',      'type' => 'int16', 'xml' => 'temprage',      'xml_omit_if' => 'rage',      'min_version' => 2.397 ], // :32,354
			[ 'key' => 'gnosis',         'type' => 'int16', 'xml' => 'gnosis' ],        // :29,355
			[ 'key' => 'temp_gnosis',    'type' => 'int16', 'xml' => 'tempgnosis',    'xml_omit_if' => 'gnosis',    'min_version' => 2.397 ], // :33,356
			[ 'key' => 'willpower',      'type' => 'int16', 'xml' => 'willpower' ],     // :30,357
			[ 'key' => 'temp_willpower', 'type' => 'int16', 'xml' => 'tempwillpower', 'xml_omit_if' => 'willpower', 'min_version' => 2.397 ], // :34,358
			[ 'key' => 'honor',      'type' => 'int16',  'xml' => 'honor',  'min_version' => 2.395 ], // :36,359
			[ 'key' => 'glory',      'type' => 'int16',  'xml' => 'glory',  'min_version' => 2.395 ], // :37,361
			[ 'key' => 'wisdom',     'type' => 'int16',  'xml' => 'wisdom', 'min_version' => 2.395 ], // :38,363
			[ 'key' => 'temp_honor', 'type' => 'single', 'xml' => 'temphonor' ], // :39,360
			[ 'key' => 'temp_glory', 'type' => 'single', 'xml' => 'tempglory' ], // :40,362
			[ 'key' => 'temp_wisdom','type' => 'single', 'xml' => 'tempwisdom' ], // :41,364
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax' ], // :49,365 - XML unconditional; binary distinct field only >=2.397
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max' ], // :50,366
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max' ], // :51,367
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :76,368
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :77,369
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :78,370
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate' ], // :79,371
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :80,372
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :81,373
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :83,374
		],
		'experience' => true, // FeraClass.cls:82,376,576
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :883
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :884
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :885
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :886
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :887
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :888
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :890
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :891
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :892
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :893
			[ 'name' => 'Features',          'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5 ], // :895
			[ 'name' => 'Gifts',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :896
			[ 'name' => 'Rites',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :897
			[ 'name' => 'Honor',             'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :898
			[ 'name' => 'Glory',             'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :899
			[ 'name' => 'Wisdom',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :900
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :902
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :903
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :905
			// FeraClass.cls:906 registers this list as "Location" (singular) - confirmed this
			// literal .Name string is what LinkedTraitList.OutputToFile/OutputToBinary actually
			// serialize (LinkedTraitList.cls:822,962), NOT "Locations". FeraClass's own
			// InputFromFile Select Case (cls:503) checks for "Locations" (plural) - a genuine,
			// verified pre-existing Grapevine 3.01 bug that means fera's own hangout data can
			// never round-trip through the original tool's XML path. Kept faithfully as
			// "Location" per this table's own rule (byte-faithful to the real format, not to
			// what "should" work) rather than silently corrected.
			[ 'name' => 'Location', 'abc' => true, 'neg' => false, 'atomic' => true, 'display' => 5, 'min_version' => 2.395 ], // :906
		],
		'boons' => false, // No Boon/BoonList reference anywhere in FeraClass.cls (full 959-line file)
		'tail'  => [
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :73,405
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ], // :74,406
		],
	],

	'various' => [
		'race_code' => 9, // PublicTypes.bas:40 (gvRaceVarious = 9); VariousClass.cls:271 (BeginTag), :335 (InputFromFile tag check)
		'xml_tag'   => 'various',
		'scalars'   => [
			[ 'key' => 'name',     'type' => 'string', 'xml' => 'name' ],     // :273,421
			[ 'key' => 'nature',   'type' => 'string', 'xml' => 'nature' ],   // :274,422
			[ 'key' => 'demeanor', 'type' => 'string', 'xml' => 'demeanor' ], // :275,423
			[ 'key' => 'class',    'type' => 'string', 'xml' => 'class' ],    // :276,424
			[ 'key' => 'subclass', 'type' => 'string', 'xml' => 'subclass' ], // :277,425
			[ 'key' => 'affinity', 'type' => 'string', 'xml' => 'affinity' ], // :278,426
			[ 'key' => 'plane',    'type' => 'string', 'xml' => 'plane' ],    // :279,427
			[ 'key' => 'brood',        'type' => 'string', 'xml' => 'brood', 'min_version' => 2.397 ], // :280,428
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // :281,429
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :282,430
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :283,431
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :284,432
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :285,433
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :286,434
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // :287,435
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :288,436
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :289,437
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :290,438
		],
		'experience' => true, // VariousClass.cls:56,632,292,440
		'trait_lists' => [
			[ 'name' => 'Tempers',           'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 2 ], // :634 (ldMultiplierDot)
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :636
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :637
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :638
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :639
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :640
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :641
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :643
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :644
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :645
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :646
			[ 'name' => 'Powers',            'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :648
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :650
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :651
		],
		'boons' => false, // No Boon reference anywhere in VariousClass.cls (full 700-line file)
		'tail'  => [
			[ 'key' => 'other',     'type' => 'string', 'xml_cdata' => 'other' ],     // :313 - written first
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :314
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ],     // :315
		],
	],

	'mummy' => [
		'race_code' => 10, // PublicTypes.bas:41 (gvRaceMummy = 10); MummyClass.cls:327
		'xml_tag'   => 'mummy', // MummyClass.cls:340
		'scalars'   => [
			[ 'key' => 'name',     'type' => 'string', 'xml' => 'name' ],     // :16,342,526
			// InputFromFile:428 also accepts a legacy 'type' attribute as a fallback when
			// 'amenti' is absent - read-side only, the writer always emits 'amenti'.
			[ 'key' => 'amenti',   'type' => 'string', 'xml' => 'amenti' ],   // :17,343,527
			[ 'key' => 'nature',   'type' => 'string', 'xml' => 'nature' ],   // :18,344,528
			[ 'key' => 'demeanor', 'type' => 'string', 'xml' => 'demeanor' ], // :19,345,529
			[ 'key' => 'willpower',      'type' => 'int16', 'xml' => 'willpower' ],      // :21,346,530
			[ 'key' => 'temp_willpower', 'type' => 'int16', 'xml' => 'tempwillpower', 'xml_omit_if' => 'willpower', 'min_version' => 2.397 ], // :30,347,531
			[ 'key' => 'sekhem',         'type' => 'int16', 'xml' => 'sekhem' ],         // :22,348,532
			[ 'key' => 'temp_sekhem',    'type' => 'int16', 'xml' => 'tempsekhem',    'xml_omit_if' => 'sekhem',    'min_version' => 2.397 ], // :31,349,533
			[ 'key' => 'balance',        'type' => 'int16', 'xml' => 'balance' ],        // :23,350,534
			[ 'key' => 'temp_balance',   'type' => 'int16', 'xml' => 'tempbalance',   'xml_omit_if' => 'balance',   'min_version' => 2.397 ], // :32,351,535
			[ 'key' => 'memory',         'type' => 'int16', 'xml' => 'memory' ],         // :24,352,536
			[ 'key' => 'temp_memory',    'type' => 'int16', 'xml' => 'tempmemory',    'xml_omit_if' => 'memory',    'min_version' => 2.397 ], // :33,353,537
			[ 'key' => 'integrity',      'type' => 'int16', 'xml' => 'integrity' ],      // :25,354,538
			[ 'key' => 'temp_integrity', 'type' => 'int16', 'xml' => 'tempintegrity', 'xml_omit_if' => 'integrity', 'min_version' => 2.397 ], // :34,355,539
			[ 'key' => 'joy',            'type' => 'int16', 'xml' => 'joy' ],            // :26,356,540
			[ 'key' => 'temp_joy',       'type' => 'int16', 'xml' => 'tempjoy',       'xml_omit_if' => 'joy',       'min_version' => 2.397 ], // :35,357,541
			[ 'key' => 'ba',             'type' => 'int16', 'xml' => 'ba' ],             // :27,358,542
			[ 'key' => 'temp_ba',        'type' => 'int16', 'xml' => 'tempba',        'xml_omit_if' => 'ba',        'min_version' => 2.397 ], // :36,359,543
			[ 'key' => 'ka',             'type' => 'int16', 'xml' => 'ka' ],             // :28,360,544
			[ 'key' => 'temp_ka',        'type' => 'int16', 'xml' => 'tempka',        'xml_omit_if' => 'ka',        'min_version' => 2.397 ], // :37,361,545
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // :45,362,546
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :46,363,547
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :47,364,548
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :71,365,549
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :72,366,550
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :73,367,551
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // :74,368,552
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :75,369,553
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :76,370,554
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :78,371,555
		],
		'experience' => true, // MummyClass.cls:77,373,557
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :841
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :842
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :843
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :844
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :845
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :846
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :848
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :849
			[ 'name' => 'Humanity',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :853
			[ 'name' => 'Status',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :854
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :850
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :851
			[ 'name' => 'Hekau',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :855
			[ 'name' => 'Spells',            'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :856
			[ 'name' => 'Rituals',           'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :857
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :859
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :860
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :862
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :863
		],
		'boons' => false, // No Boon reference anywhere in MummyClass.cls; parse_character_mummy never reads/writes a boon count
		'tail'  => [
			[ 'key' => 'inheritance', 'type' => 'string', 'xml_cdata' => 'inheritance' ], // :67,401 - a third tail field, written before biography
			[ 'key' => 'biography',   'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :68,402
			[ 'key' => 'notes',       'type' => 'string', 'xml_cdata' => 'notes' ], // :69,403
		],
	],

	'kueijin' => [
		'race_code' => 11, // PublicTypes.bas:42 (gvRaceKueiJin = 11); KueiJinClass.cls:321
		'xml_tag'   => 'kueijin', // KueiJinClass.cls:334,416
		'scalars'   => [
			[ 'key' => 'name',         'type' => 'string', 'xml' => 'name' ],         // :336,515
			[ 'key' => 'nature',       'type' => 'string', 'xml' => 'nature' ],       // :337,516
			[ 'key' => 'demeanor',     'type' => 'string', 'xml' => 'demeanor' ],     // :338,517
			[ 'key' => 'dharma',       'type' => 'string', 'xml' => 'dharma' ],       // :339,518
			[ 'key' => 'balance',      'type' => 'string', 'xml' => 'balance' ],      // :340,519 - written/read before direction despite declaration order; all sources agree balance-then-direction
			[ 'key' => 'direction',    'type' => 'string', 'xml' => 'direction' ],    // :341,520
			[ 'key' => 'station',      'type' => 'string', 'xml' => 'station' ],      // :342,521
			[ 'key' => 'po_archetype', 'type' => 'string', 'xml' => 'poarchetype' ],  // :343,522
			[ 'key' => 'hun',                'type' => 'int16', 'xml' => 'hun' ],               // :344,523
			[ 'key' => 'temp_hun',           'type' => 'int16', 'xml' => 'temphun',           'xml_omit_if' => 'hun',           'min_version' => 2.397 ], // :345,524
			[ 'key' => 'po',                 'type' => 'int16', 'xml' => 'po' ],                // :346,525
			[ 'key' => 'temp_po',            'type' => 'int16', 'xml' => 'temppo',            'xml_omit_if' => 'po',            'min_version' => 2.397 ], // :347,526
			[ 'key' => 'yin_chi',            'type' => 'int16', 'xml' => 'yinchi' ],            // :348,527
			[ 'key' => 'temp_yin_chi',       'type' => 'int16', 'xml' => 'tempyinchi',       'xml_omit_if' => 'yin_chi',       'min_version' => 2.397 ], // :349,528
			[ 'key' => 'yang_chi',           'type' => 'int16', 'xml' => 'yangchi' ],           // :350,529
			[ 'key' => 'temp_yang_chi',      'type' => 'int16', 'xml' => 'tempyangchi',      'xml_omit_if' => 'yang_chi',      'min_version' => 2.397 ], // :351,530
			[ 'key' => 'demon_chi',          'type' => 'int16', 'xml' => 'demonchi' ],          // :352,531
			[ 'key' => 'temp_demon_chi',     'type' => 'int16', 'xml' => 'tempdemonchi',     'xml_omit_if' => 'demon_chi',     'min_version' => 2.397 ], // :353,532
			[ 'key' => 'dharma_traits',      'type' => 'int16', 'xml' => 'dharmatraits' ],      // :354,533
			[ 'key' => 'temp_dharma_traits', 'type' => 'int16', 'xml' => 'tempdharmatraits', 'xml_omit_if' => 'dharma_traits', 'min_version' => 2.397 ], // :355,534
			[ 'key' => 'willpower',          'type' => 'int16', 'xml' => 'willpower' ],          // :356,535
			[ 'key' => 'temp_willpower',     'type' => 'int16', 'xml' => 'tempwillpower',     'xml_omit_if' => 'willpower',     'min_version' => 2.397 ], // :357,536
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax', 'min_version' => 2.397 ], // :358,537
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :359,538
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max', 'min_version' => 2.397 ], // :360,539
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :361,540
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :362,541
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :363,542
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate', 'min_version' => 2.397 ], // :364,543
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :365,544
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :366,545
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :367,546
		],
		'experience' => true, // KueiJinClass.cls:76,825,369,548
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :827
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :828
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :829
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :830
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :831
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :832
			[ 'name' => 'Status',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :838
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :834
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :835
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :836
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :837
			[ 'name' => 'Guanxi',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :840
			[ 'name' => 'Disciplines',       'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :841
			[ 'name' => 'Rites',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :842
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :844
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :845
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :847
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5, 'min_version' => 2.395 ], // :848
		],
		'boons' => false, // No Boon/BoonClass reference anywhere in KueiJinClass.cls
		'tail'  => [
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :395,574
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ], // :396,575
		],
	],

	'hunter' => [
		'race_code' => 12, // PublicTypes.bas:43 (gvRaceHunter = 12)
		'xml_tag'   => 'hunter', // HunterClass.cls:308,381
		'scalars'   => [
			[ 'key' => 'name',   'type' => 'string', 'xml' => 'name' ],   // :16,310,464
			[ 'key' => 'creed',  'type' => 'string', 'xml' => 'creed' ],  // :17,311,465
			[ 'key' => 'nature', 'type' => 'string', 'xml' => 'nature' ], // :18,312,466
			[ 'key' => 'demeanor', 'type' => 'string', 'xml' => 'demeanor' ], // :19,313,467
			[ 'key' => 'camp',   'type' => 'string', 'xml' => 'camp' ],   // :20,314,468
			[ 'key' => 'handle', 'type' => 'string', 'xml' => 'handle' ], // :21,315,469
			[ 'key' => 'conviction',      'type' => 'int16', 'xml' => 'conviction' ],      // :24,316,470
			[ 'key' => 'temp_conviction', 'type' => 'int16', 'xml' => 'tempconviction', 'xml_omit_if' => 'conviction' ], // :30,317,471
			[ 'key' => 'willpower',       'type' => 'int16', 'xml' => 'willpower' ],       // :23,318,472
			[ 'key' => 'temp_willpower',  'type' => 'int16', 'xml' => 'tempwillpower',  'xml_omit_if' => 'willpower' ], // :29,319,473
			[ 'key' => 'mercy',           'type' => 'int16', 'xml' => 'mercy' ],           // :25,320,474
			[ 'key' => 'temp_mercy',      'type' => 'int16', 'xml' => 'tempmercy',      'xml_omit_if' => 'mercy' ], // :31,321,475
			[ 'key' => 'vision',          'type' => 'int16', 'xml' => 'vision' ],          // :26,322,476
			[ 'key' => 'temp_vision',     'type' => 'int16', 'xml' => 'tempvision',     'xml_omit_if' => 'vision' ], // :32,323,477
			[ 'key' => 'zeal',            'type' => 'int16', 'xml' => 'zeal' ],            // :27,324,478
			[ 'key' => 'temp_zeal',       'type' => 'int16', 'xml' => 'tempzeal',       'xml_omit_if' => 'zeal' ], // :33,325,479
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax' ], // :41,326,480
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max' ], // :42,327,481
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max' ], // :43,328,482
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :61,329,483
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :62,330,484
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :63,331,485
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate' ], // :64,332,486
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :65,333,487
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :66,334,488
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :68,335,489
		],
		// No version-gated scalar exists for hunter - every scalar above is read
		// unconditionally in GEX_Parser.php's parse_character_hunter, unlike vampire's
		// coterie/sire/pool-split gates.
		'experience' => true, // HunterClass.cls:67,337,491
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :622
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :623
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :624
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :625
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :626
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :627
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :629
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :630
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :631
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :632
			[ 'name' => 'Derangements',      'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 5 ], // :634
			[ 'name' => 'Edges',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :635
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :637
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :638
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :640
			// Read unconditionally, no version gate (unlike vampire's equivalent >= 2.395 gate).
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5 ], // :641
		],
		'boons' => false, // No Boon reference anywhere in HunterClass.cls (full 688-line file)
		'tail'  => [
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :360,514,577
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ], // :361,515,578
		],
	],

	'demon' => [
		'race_code' => 13, // GEX_Parser.php:63 (RACE_TYPE_MAP[13] = 'demon')
		'xml_tag'   => 'demon', // DemonClass.cls:313,387
		'scalars'   => [
			[ 'key' => 'house',    'type' => 'string', 'xml' => 'house' ],    // :316,472
			[ 'key' => 'name',     'type' => 'string', 'xml' => 'name' ],     // :315,471
			[ 'key' => 'faction',  'type' => 'string', 'xml' => 'faction' ],  // :317,473
			[ 'key' => 'nature',   'type' => 'string', 'xml' => 'nature' ],   // :318,474
			[ 'key' => 'demeanor', 'type' => 'string', 'xml' => 'demeanor' ], // :319,475
			[ 'key' => 'torment',         'type' => 'int16', 'xml' => 'torment' ],         // :320,476
			[ 'key' => 'temp_torment',    'type' => 'int16', 'xml' => 'temptorment',    'xml_omit_if' => 'torment' ],    // :321,477
			[ 'key' => 'faith',           'type' => 'int16', 'xml' => 'faith' ],           // :322,478
			[ 'key' => 'temp_faith',      'type' => 'int16', 'xml' => 'tempfaith',      'xml_omit_if' => 'faith' ],      // :323,479
			[ 'key' => 'willpower',       'type' => 'int16', 'xml' => 'willpower' ],       // :324,480
			[ 'key' => 'temp_willpower',  'type' => 'int16', 'xml' => 'tempwillpower',  'xml_omit_if' => 'willpower' ],  // :325,481
			[ 'key' => 'conscience',      'type' => 'int16', 'xml' => 'conscience' ],      // :326,482
			[ 'key' => 'temp_conscience', 'type' => 'int16', 'xml' => 'tempconscience', 'xml_omit_if' => 'conscience' ], // :327,483
			[ 'key' => 'conviction',      'type' => 'int16', 'xml' => 'conviction' ],      // :328,484
			[ 'key' => 'temp_conviction', 'type' => 'int16', 'xml' => 'tempconviction', 'xml_omit_if' => 'conviction' ], // :329,485
			[ 'key' => 'courage',         'type' => 'int16', 'xml' => 'courage' ],         // :330,486
			[ 'key' => 'temp_courage',    'type' => 'int16', 'xml' => 'tempcourage',    'xml_omit_if' => 'courage' ],    // :331,487
			[ 'key' => 'physical_max', 'type' => 'int16', 'xml' => 'physicalmax' ], // :332,488 - default 11 on read if absent, per InputFromFile
			[ 'key' => 'social_max',   'type' => 'int16', 'xml' => 'socialmax', 'xml_omit_if' => 'physical_max' ], // :333,489
			// mentalmax's omit/default reference is physicalmax, NOT socialmax - confirmed
			// verbatim in both OutputToFile and InputFromFile.
			[ 'key' => 'mental_max',   'type' => 'int16', 'xml' => 'mentalmax', 'xml_omit_if' => 'physical_max' ], // :334,490
			[ 'key' => 'player',     'type' => 'string', 'xml' => 'player' ],     // :335,491
			[ 'key' => 'status',     'type' => 'string', 'xml' => 'status' ],     // :336,492
			[ 'key' => 'id',         'type' => 'string', 'xml' => 'id' ],         // :337,493
			[ 'key' => 'start_date', 'type' => 'date',   'xml' => 'startdate' ], // :338,494
			[ 'key' => 'narrator',   'type' => 'string', 'xml' => 'narrator' ],   // :339,495
			[ 'key' => 'is_npc',     'type' => 'bool',   'xml' => 'npc', 'xml_omit' => false ], // :340,496
			[ 'key' => 'last_modified', 'type' => 'date', 'xml' => 'lastmodified' ], // :341,497
		],
		'experience' => true, // DemonClass.cls:68,343,499,563
		'trait_lists' => [
			[ 'name' => 'Physical',          'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :639
			[ 'name' => 'Social',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :640
			[ 'name' => 'Mental',            'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :641
			[ 'name' => 'Negative Physical', 'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :642
			[ 'name' => 'Negative Social',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :643
			[ 'name' => 'Negative Mental',   'abc' => true,  'neg' => true,  'atomic' => false, 'display' => 1 ], // :644
			[ 'name' => 'Abilities',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :646
			[ 'name' => 'Influences',        'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :647
			[ 'name' => 'Backgrounds',       'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :648
			[ 'name' => 'Health Levels',     'abc' => false, 'neg' => false, 'atomic' => false, 'display' => 1 ], // :649
			[ 'name' => 'Lores',             'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :651
			[ 'name' => 'Apocalyptic Form',  'abc' => false, 'neg' => false, 'atomic' => true,  'display' => 5 ], // :652 (VisageList)
			[ 'name' => 'Merits',            'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 4 ], // :654
			[ 'name' => 'Flaws',             'abc' => true,  'neg' => true,  'atomic' => true,  'display' => 4 ], // :655
			[ 'name' => 'Equipment',         'abc' => true,  'neg' => false, 'atomic' => false, 'display' => 1 ], // :657
			// Read unconditionally, no version gate (unlike vampire's equivalent >= 2.395 gate).
			[ 'name' => 'Locations',         'abc' => true,  'neg' => false, 'atomic' => true,  'display' => 5 ], // :658
		],
		'boons' => false, // No Boon/BoonClass reference anywhere in DemonClass.cls (full 705-line file)
		'tail'  => [
			[ 'key' => 'biography', 'type' => 'string', 'xml_cdata' => 'biography', 'min_version' => 2.397 ], // :366,522,586
			[ 'key' => 'notes',     'type' => 'string', 'xml_cdata' => 'notes' ], // :367,523,587
		],
	],

];
