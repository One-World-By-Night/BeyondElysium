<?php
/**
 * Maps a parsed GEX character's raw scalar identity/resource fields (from
 * `GEX_Parser::parse_character_*()`, distinct from its `trait_lists`) onto
 * each real BE creature stack's `{stack}-identity`/`{stack}-resources`
 * (`-virtues`/`-renown`) block field names.
 *
 * A resource pool's raw value is always a `{permanent, temporary}` pair,
 * matching GV's own `X`/`temp_X` field pairing.
 *
 * `Hunter` and `Various` are not mapped here: no BE creature stack exists
 * for either, so there is no destination block to map onto.
 *
 * `Nature`/`Demeanor` (the shared `met-archetypes` block) are not declared
 * per-stack here - every race whose raw record carries `nature`/`demeanor`
 * keys uses the identical BE field names, so `Import_Controller` maps them
 * once, universally.
 *
 * A raw field with no destination BE field is intentionally absent below -
 * not every stack's resources/identity block defines every possible field.
 *
 * @see BE_PROCESS/workflow-0.8.md Step 4 (identity/resource mapping follow-up)
 * @see BE_PROCESS/DECISIONLOG.md Decision 039
 */

defined( 'ABSPATH' ) || exit;

return [

	'vampire' => [
		'identity' => [
			'block'  => 'vampire-identity',
			'fields' => [
				'Clan'          => 'clan',
				'Sect'          => 'sect',
				'Generation'    => 'generation',
				'Sire'          => 'sire',
				'Title'         => 'title',
				'Morality Path' => 'path',
			],
		],
		'resources' => [
			[
				'block'  => 'vampire-resources',
				'fields' => [
					'Blood'     => [ 'blood', 'temp_blood' ],
					'Willpower' => [ 'willpower', 'temp_willpower' ],
					'Morality'  => [ 'path_traits', 'temp_path_traits' ],
				],
			],
			[
				'block'  => 'vampire-virtues',
				'fields' => [
					'Conscience'   => [ 'conscience', 'temp_conscience' ],
					'Self-Control' => [ 'self_control', 'temp_self_control' ],
					'Courage'      => [ 'courage', 'temp_courage' ],
				],
			],
		],
	],

	'werewolf' => [
		'identity' => [
			'block'  => 'werewolf-identity',
			'fields' => [
				'Tribe'   => 'tribe',
				'Breed'   => 'breed',
				'Auspice' => 'auspice',
				'Rank'    => 'rank',
				'Pack'    => 'pack',
				'Totem'   => 'totem',
			],
		],
		'resources' => [
			[
				'block'  => 'werewolf-resources',
				'fields' => [
					'Rage'      => [ 'rage', 'temp_rage' ],
					'Gnosis'    => [ 'gnosis', 'temp_gnosis' ],
					'Willpower' => [ 'willpower', 'temp_willpower' ],
				],
			],
			[
				'block'  => 'werewolf-renown',
				'fields' => [
					'Honor'  => [ 'honor', 'temp_honor' ],
					'Glory'  => [ 'glory', 'temp_glory' ],
					'Wisdom' => [ 'wisdom', 'temp_wisdom' ],
				],
			],
		],
	],

	'fera' => [
		'identity' => [
			'block'  => 'fera-identity',
			'fields' => [
				'Fera Type' => 'fera',
				'Breed'     => 'breed',
				'Auspice'   => 'auspice',
				'Rank'      => 'rank',
				'Pack'      => 'pack',
				'Totem'     => 'totem',
			],
		],
		// Fera reuses Werewolf's resource/renown blocks; same raw field names as werewolf's own record.
		'resources' => [
			[
				'block'  => 'werewolf-resources',
				'fields' => [
					'Rage'      => [ 'rage', 'temp_rage' ],
					'Gnosis'    => [ 'gnosis', 'temp_gnosis' ],
					'Willpower' => [ 'willpower', 'temp_willpower' ],
				],
			],
			[
				'block'  => 'werewolf-renown',
				'fields' => [
					'Honor'  => [ 'honor', 'temp_honor' ],
					'Glory'  => [ 'glory', 'temp_glory' ],
					'Wisdom' => [ 'wisdom', 'temp_wisdom' ],
				],
			],
		],
	],

	'mage' => [
		'identity' => [
			'block'  => 'mage-identity',
			'fields' => [
				'Tradition' => 'tradition',
				'Essence'   => 'essence',
				'Faction'   => 'faction',
				'Cabal'     => 'cabal',
				'Rank'      => 'rank',
			],
		],
		'resources' => [
			[
				'block'  => 'mage-resources',
				'fields' => [
					'Arete'        => [ 'arete', 'temp_arete' ],
					'Quintessence' => [ 'quintessence', 'temp_quintessence' ],
					'Paradox'      => [ 'paradox', 'temp_paradox' ],
					'Willpower'    => [ 'willpower', 'temp_willpower' ],
				],
			],
		],
	],

	'changeling' => [
		'identity' => [
			'block'  => 'changeling-identity',
			'fields' => [
				'Kith'             => 'kith',
				'Seeming'          => 'seeming',
				'Court'            => 'court',
				'House'            => 'house',
				'Seelie Legacy'    => 'seelie_legacy',
				'Unseelie Legacy'  => 'unseelie_legacy',
			],
		],
		'resources' => [
			[
				'block'  => 'changeling-resources',
				'fields' => [
					'Glamour'   => [ 'glamour', 'temp_glamour' ],
					'Banality'  => [ 'banality', 'temp_banality' ],
					'Willpower' => [ 'willpower', 'temp_willpower' ],
				],
			],
		],
	],

	'wraith' => [
		'identity' => [
			'block'  => 'wraith-identity',
			'fields' => [
				'Ethnos'  => 'ethnos',
				'Guild'   => 'guild',
				'Faction' => 'faction',
				'Legion'  => 'legion',
				// shadow_player has no BE field to receive it; Shadow is the Shadow's own archetype text.
				'Shadow'  => 'shadow_archetype',
			],
		],
		'resources' => [
			[
				'block'  => 'wraith-resources',
				'fields' => [
					'Pathos'    => [ 'pathos', 'temp_pathos' ],
					'Corpus'    => [ 'corpus', 'temp_corpus' ],
					'Willpower' => [ 'willpower', 'temp_willpower' ],
					'Angst'     => [ 'angst', 'temp_angst' ],
				],
			],
		],
	],

	'demon' => [
		'identity' => [
			'block'  => 'demon-identity',
			'fields' => [
				'House'   => 'house',
				'Faction' => 'faction',
			],
		],
		'resources' => [
			[
				'block'  => 'demon-resources',
				'fields' => [
					'Faith'     => [ 'faith', 'temp_faith' ],
					'Torment'   => [ 'torment', 'temp_torment' ],
					'Willpower' => [ 'willpower', 'temp_willpower' ],
				],
			],
		],
	],

	'mummy' => [
		'identity' => [
			'block'  => 'mummy-identity',
			'fields' => [
				'Amenti' => 'amenti',
			],
		],
		'resources' => [
			[
				'block'  => 'mummy-resources',
				'fields' => [
					'Sekhem'    => [ 'sekhem', 'temp_sekhem' ],
					'Balance'   => [ 'balance', 'temp_balance' ],
					'Willpower' => [ 'willpower', 'temp_willpower' ],
				],
			],
		],
	],

	'kueijin' => [
		'identity' => [
			'block'  => 'kueijin-identity',
			'fields' => [
				'Dharma'    => 'dharma',
				'Direction' => 'direction',
				'Balance'   => 'balance',
				'Station'   => 'station',
			],
		],
		'resources' => [
			[
				'block'  => 'kueijin-resources',
				'fields' => [
					'Hun'       => [ 'hun', 'temp_hun' ],
					'Po'        => [ 'po', 'temp_po' ],
					'Yin Chi'   => [ 'yin_chi', 'temp_yin_chi' ],
					'Yang Chi'  => [ 'yang_chi', 'temp_yang_chi' ],
					'Demon Chi' => [ 'demon_chi', 'temp_demon_chi' ],
				],
			],
		],
	],

	'mortal' => [
		'identity' => [
			'block'  => 'mortal-identity',
			'fields' => [
				'Motivation'  => 'motivation',
				'Association' => 'association',
				'Regnant'     => 'regnant',
			],
		],
		'resources' => [
			[
				'block'  => 'mortal-resources',
				'fields' => [
					'Willpower'  => [ 'willpower', 'temp_willpower' ],
					'True Faith' => [ 'true_faith', 'temp_true_faith' ],
					'Humanity'   => [ 'humanity', 'temp_humanity' ],
				],
			],
		],
	],

];
