<?php
/**
 * Where each of the 231 qkdata.gvd keys lives in BE.
 *
 * Four source kinds:
 *
 *   column    A real be_characters column.
 *   json      Inside sheet_data. Three shapes:
 *               - block only              a trait_list/tiered_power block, whole list
 *               - block + field           one identity_field, on a stack-specific block
 *               - block + pool + part     one resource_pool value ('permanent'/'temporary')
 *             `field` or `pool` without `block` means the name is not unique to one
 *             stack (e.g. every splat has a "Willpower" pool, several have a "Faction"
 *             identity field) - it identifies the field/pool by name within whichever
 *             block the character's own creature stack defines it on.
 *   derived   Computed, not stored. `note` explains how.
 *   unmapped  No BE equivalent (yet, or ever). `note` explains why. An unmapped key must
 *             fail loudly wherever it is used - GV's qtError silently skips the clause and
 *             widens the result set, which is the one GV behavior BE deliberately does not
 *             reproduce (GV-SOURCEMAP.md).
 *
 * @see BeyondElysium\Services\Field_Registry
 * @see BE_PROCESS/workflow-0.3.md Step 0c
 * @see BE_PROCESS/workflow-0.6.md Step 1e
 */

defined( 'ABSPATH' ) || exit;

return [

	// -- Identity / header -----------------------------------------------------------
	'name'         => [ 'source' => 'column', 'column' => 'name' ],
	'race'         => [ 'source' => 'column', 'column' => 'stack_slug' ],
	'group'        => [ 'source' => 'unmapped', 'note' => 'no BE equivalent; GV faction grouping is not modeled as character data' ],
	'subgroup'     => [ 'source' => 'unmapped', 'note' => 'no BE equivalent; GV faction grouping is not modeled as character data' ],
	'xpearned'     => [ 'source' => 'column', 'column' => 'xp_earned' ],
	'xpunspent'    => [ 'source' => 'column', 'column' => 'xp_unspent' ],
	'nature'       => [ 'source' => 'json', 'block' => 'met-archetypes', 'field' => 'Nature' ],
	'demeanor'     => [ 'source' => 'json', 'block' => 'met-archetypes', 'field' => 'Demeanor' ],
	'willpower'    => [ 'source' => 'json', 'pool' => 'Willpower', 'part' => 'permanent' ],

	// -- Core Metaplot attributes ------------------------------------------------------
	'physical'     => [ 'source' => 'json', 'block' => 'met-physical-traits' ],
	'social'       => [ 'source' => 'json', 'block' => 'met-social-traits' ],
	'mental'       => [ 'source' => 'json', 'block' => 'met-mental-traits' ],
	'physicalneg'  => [ 'source' => 'json', 'block' => 'met-physical-traits-neg' ],
	'socialneg'    => [ 'source' => 'json', 'block' => 'met-social-traits-neg' ],
	'mentalneg'    => [ 'source' => 'json', 'block' => 'met-mental-traits-neg' ],
	'physicalmax'  => [ 'source' => 'derived', 'note' => 'trait cap comes from stack creation rules, not stored per-character' ],
	'socialmax'    => [ 'source' => 'derived', 'note' => 'trait cap comes from stack creation rules, not stored per-character' ],
	'mentalmax'    => [ 'source' => 'derived', 'note' => 'trait cap comes from stack creation rules, not stored per-character' ],

	'abilities'    => [ 'source' => 'json', 'block' => 'met-abilities' ],
	// Every creature stack's Influence entries live inside its own {stack_slug}-backgrounds block, resolved per-character by stack_slug.
	'influences'   => [ 'source' => 'stack_relative_list', 'block_pattern' => '{stack}-backgrounds', 'filter_source' => 'Influences' ],
	'backgrounds'  => [ 'source' => 'unmapped', 'note' => 'block is {stack_slug}-backgrounds, not a fixed slug; importer must resolve it by stack' ],
	'healthlevels' => [ 'source' => 'unmapped', 'note' => 'not modeled as a block; see creature_stacks.stack_definition.display_preferences.health_levels' ],
	'merits'       => [ 'source' => 'json', 'block' => 'met-merits' ],
	'flaws'        => [ 'source' => 'json', 'block' => 'met-flaws' ],
	'equipment'    => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md; not yet linked to characters' ],
	'locations'    => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'other'        => [ 'source' => 'unmapped', 'note' => 'no generic catch-all field on be_characters' ],
	'biography'    => [ 'source' => 'column', 'column' => 'biography' ],
	'random'       => [ 'source' => 'derived', 'note' => "computed at query time, GV's CInt(Rnd() * 100) - qkRandom" ],
	'notes'        => [ 'source' => 'column', 'column' => 'notes' ],
	'player'       => [ 'source' => 'column', 'column' => 'player_name' ],
	'playstatus'   => [ 'source' => 'column', 'column' => 'status' ],
	'id'           => [ 'source' => 'column', 'column' => 'id' ],
	'startdate'    => [ 'source' => 'column', 'column' => 'start_date' ],
	'narrator'     => [ 'source' => 'column', 'column' => 'narrator' ],
	'npc'          => [ 'source' => 'column', 'column' => 'is_npc' ],
	'lastmodified' => [ 'source' => 'column', 'column' => 'updated_at' ],

	// -- Vampire -------------------------------------------------------------------
	'disciplines'   => [ 'source' => 'json', 'block' => 'vampire-disciplines' ],
	'rank'          => [ 'source' => 'json', 'field' => 'Rank' ],
	'rites'         => [ 'source' => 'json', 'block' => 'werewolf-rites' ],
	'title'         => [ 'source' => 'json', 'block' => 'vampire-identity', 'field' => 'Title' ],
	'blood'         => [ 'source' => 'json', 'block' => 'vampire-resources', 'pool' => 'Blood', 'part' => 'permanent' ],
	'conscience'    => [ 'source' => 'json', 'block' => 'vampire-virtues', 'pool' => 'Conscience', 'part' => 'permanent' ],
	'selfcontrol'   => [ 'source' => 'json', 'block' => 'vampire-virtues', 'pool' => 'Self-Control', 'part' => 'permanent' ],
	'courage'       => [ 'source' => 'json', 'block' => 'vampire-virtues', 'pool' => 'Courage', 'part' => 'permanent' ],
	'derangements'  => [ 'source' => 'json', 'block' => 'met-derangements' ],
	'powers'        => [ 'source' => 'unmapped', 'note' => 'superseded by per-stack tiered_power blocks (disciplines, gifts, spheres, ...); no single generic "powers" block' ],
	'status'        => [ 'source' => 'json', 'block' => 'vampire-statuses' ],
	'humanity'      => [ 'source' => 'unmapped', 'note' => 'GV models a "Humanity Traits" list distinct from the Morality rating; not modeled - see vampire-resources.Morality / mortal-resources.Humanity for the rating itself' ],
	'rituals'       => [ 'source' => 'json', 'block' => 'vampire-rituals' ],
	'pathtraits'    => [ 'source' => 'json', 'block' => 'vampire-resources', 'pool' => 'Morality', 'part' => 'permanent' ],

	// -- Changeling ------------------------------------------------------------------
	'seelielegacy'   => [ 'source' => 'json', 'block' => 'changeling-identity', 'field' => 'Seelie Legacy' ],
	'unseelielegacy' => [ 'source' => 'json', 'block' => 'changeling-identity', 'field' => 'Unseelie Legacy' ],
	'kith'           => [ 'source' => 'json', 'block' => 'changeling-identity', 'field' => 'Kith' ],
	'seeming'        => [ 'source' => 'json', 'block' => 'changeling-identity', 'field' => 'Seeming' ],
	'court'          => [ 'source' => 'json', 'block' => 'changeling-identity', 'field' => 'Court' ],
	'house'          => [ 'source' => 'json', 'field' => 'House' ],
	'threshold'      => [ 'source' => 'unmapped', 'note' => 'Wraith Underworld threshold concept not modeled' ],
	'glamour'        => [ 'source' => 'json', 'block' => 'changeling-resources', 'pool' => 'Glamour', 'part' => 'permanent' ],
	'banality'       => [ 'source' => 'json', 'block' => 'changeling-resources', 'pool' => 'Banality', 'part' => 'permanent' ],
	'arts'           => [ 'source' => 'json', 'block' => 'changeling-arts' ],
	'realms'         => [ 'source' => 'json', 'block' => 'changeling-realms' ],
	'oaths'          => [ 'source' => 'unmapped', 'note' => 'Changeling oaths not modeled as a field or block' ],

	// -- Werewolf / Fera ---------------------------------------------------------------
	'tribe'      => [ 'source' => 'json', 'block' => 'werewolf-identity', 'field' => 'Tribe' ],
	'fera'       => [ 'source' => 'unmapped', 'note' => 'superseded by fera-identity.Fera Type' ],
	'breed'      => [ 'source' => 'json', 'field' => 'Breed' ],
	'auspice'    => [ 'source' => 'json', 'field' => 'Auspice' ],
	'pack'       => [ 'source' => 'json', 'field' => 'Pack' ],
	'position'   => [ 'source' => 'unmapped', 'note' => 'chronicle staff/player position, not character data' ],
	'notoriety'  => [ 'source' => 'unmapped', 'note' => 'Garou spirit notoriety not modeled' ],
	'totem'      => [ 'source' => 'json', 'field' => 'Totem' ],
	'camp'       => [ 'source' => 'unmapped', 'note' => 'Garou camp affiliation not modeled' ],
	'honor'      => [ 'source' => 'json', 'block' => 'werewolf-renown', 'pool' => 'Honor', 'part' => 'permanent' ],
	'glory'      => [ 'source' => 'json', 'block' => 'werewolf-renown', 'pool' => 'Glory', 'part' => 'permanent' ],
	'wisdom'     => [ 'source' => 'json', 'block' => 'werewolf-renown', 'pool' => 'Wisdom', 'part' => 'permanent' ],
	'temphonor'  => [ 'source' => 'json', 'block' => 'werewolf-renown', 'pool' => 'Honor', 'part' => 'temporary' ],
	'tempglory'  => [ 'source' => 'json', 'block' => 'werewolf-renown', 'pool' => 'Glory', 'part' => 'temporary' ],
	'tempwisdom' => [ 'source' => 'json', 'block' => 'werewolf-renown', 'pool' => 'Wisdom', 'part' => 'temporary' ],
	'rage'       => [ 'source' => 'json', 'block' => 'werewolf-resources', 'pool' => 'Rage', 'part' => 'permanent' ],
	'gnosis'     => [ 'source' => 'json', 'block' => 'werewolf-resources', 'pool' => 'Gnosis', 'part' => 'permanent' ],
	'features'   => [ 'source' => 'unmapped', 'note' => 'no block for miscellaneous physical features' ],
	'gifts'      => [ 'source' => 'json', 'block' => 'werewolf-gifts' ],
	'honortraits'  => [ 'source' => 'unmapped', 'note' => 'renown is a numeric pool (werewolf-renown.Honor), not a trait list' ],
	'glorytraits'  => [ 'source' => 'unmapped', 'note' => 'renown is a numeric pool (werewolf-renown.Glory), not a trait list' ],
	'wisdomtraits' => [ 'source' => 'unmapped', 'note' => 'renown is a numeric pool (werewolf-renown.Wisdom), not a trait list' ],
	'apocalypticform' => [ 'source' => 'unmapped', 'note' => 'Fera Apocalyptic Form not modeled as its own block' ],

	// -- Kuei-Jin ----------------------------------------------------------------------
	'dharma'      => [ 'source' => 'json', 'block' => 'kueijin-identity', 'field' => 'Dharma' ],
	'kjbalance'   => [ 'source' => 'json', 'block' => 'kueijin-identity', 'field' => 'Balance' ],
	'direction'   => [ 'source' => 'json', 'block' => 'kueijin-identity', 'field' => 'Direction' ],
	'station'     => [ 'source' => 'json', 'block' => 'kueijin-identity', 'field' => 'Station' ],
	'poarchetype' => [ 'source' => 'unmapped', 'note' => "Kuei-Jin P'o archetype not modeled" ],
	'hun'         => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Hun', 'part' => 'permanent' ],
	'po'          => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Po', 'part' => 'permanent' ],
	'yin'         => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Yin Chi', 'part' => 'permanent' ],
	'yang'        => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Yang Chi', 'part' => 'permanent' ],
	'demonchi'    => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Demon Chi', 'part' => 'permanent' ],
	'guanxi'      => [ 'source' => 'unmapped', 'note' => 'Kuei-Jin Guanxi connections not modeled as a block' ],

	// -- Mage ----------------------------------------------------------------------
	'essence'      => [ 'source' => 'json', 'block' => 'mage-identity', 'field' => 'Essence' ],
	'tradition'    => [ 'source' => 'json', 'block' => 'mage-identity', 'field' => 'Tradition' ],
	'cabal'        => [ 'source' => 'json', 'block' => 'mage-identity', 'field' => 'Cabal' ],
	'arete'        => [ 'source' => 'json', 'block' => 'mage-resources', 'pool' => 'Arete', 'part' => 'permanent' ],
	'quintessence' => [ 'source' => 'json', 'block' => 'mage-resources', 'pool' => 'Quintessence', 'part' => 'permanent' ],
	'paradox'      => [ 'source' => 'json', 'block' => 'mage-resources', 'pool' => 'Paradox', 'part' => 'permanent' ],
	'spheres'      => [ 'source' => 'json', 'block' => 'mage-spheres' ],
	'resonance'    => [ 'source' => 'unmapped', 'note' => 'Mage resonance not modeled as a block' ],
	'reputation'   => [ 'source' => 'unmapped', 'note' => 'no reputation block' ],
	'rotes'        => [ 'source' => 'json', 'block' => 'mage-rotes' ],
	'foci'         => [ 'source' => 'unmapped', 'note' => 'Mage foci not modeled' ],

	// -- Mortal --------------------------------------------------------------------
	'motivation'  => [ 'source' => 'json', 'block' => 'mortal-identity', 'field' => 'Motivation' ],
	'association' => [ 'source' => 'json', 'block' => 'mortal-identity', 'field' => 'Association' ],
	'regnant'     => [ 'source' => 'json', 'block' => 'mortal-identity', 'field' => 'Regnant' ],
	'truefaith'   => [ 'source' => 'json', 'block' => 'mortal-resources', 'pool' => 'True Faith', 'part' => 'permanent' ],

	// -- Mummy -----------------------------------------------------------------------
	'amenti'    => [ 'source' => 'json', 'block' => 'mummy-identity', 'field' => 'Amenti' ],
	'memory'    => [ 'source' => 'unmapped', 'note' => 'Mummy Memory not modeled as a resource pool' ],
	'integrity' => [ 'source' => 'unmapped', 'note' => 'Mummy Integrity not modeled' ],
	'joy'       => [ 'source' => 'unmapped', 'note' => 'Mummy Joy not modeled' ],
	'ba'        => [ 'source' => 'unmapped', 'note' => 'Mummy Ba not modeled' ],
	'ka'        => [ 'source' => 'unmapped', 'note' => 'Mummy Ka not modeled' ],
	'sekhem'    => [ 'source' => 'json', 'block' => 'mummy-resources', 'pool' => 'Sekhem', 'part' => 'permanent' ],
	'mbalance'  => [ 'source' => 'json', 'block' => 'mummy-resources', 'pool' => 'Balance', 'part' => 'permanent' ],
	'hekau'     => [ 'source' => 'json', 'block' => 'mummy-hekau' ],
	'spells'    => [ 'source' => 'unmapped', 'note' => 'no separate spells block; Mummy uses Hekau' ],
	'inheritance' => [ 'source' => 'unmapped', 'note' => 'Mummy inheritance not modeled' ],

	// -- Vampire identity (continued) -------------------------------------------------
	'clan'         => [ 'source' => 'json', 'block' => 'vampire-identity', 'field' => 'Clan' ],
	'sect'         => [ 'source' => 'json', 'block' => 'vampire-identity', 'field' => 'Sect' ],
	'coterie'      => [ 'source' => 'unmapped', 'note' => 'Vampire coterie affiliation not modeled' ],
	'sire'         => [ 'source' => 'json', 'block' => 'vampire-identity', 'field' => 'Sire' ],
	'aura'         => [ 'source' => 'unmapped', 'note' => 'Vampire aura not modeled' ],
	'aurabonus'    => [ 'source' => 'unmapped', 'note' => 'Vampire aura not modeled' ],
	'generation'   => [ 'source' => 'json', 'block' => 'vampire-identity', 'field' => 'Generation' ],
	'path'         => [ 'source' => 'json', 'block' => 'vampire-identity', 'field' => 'Morality Path' ],
	'bonds'        => [ 'source' => 'unmapped', 'note' => 'blood bonds not modeled as a block' ],
	'boons'        => [ 'source' => 'unmapped', 'note' => 'world objects (boons) are workflow-0.7.md' ],
	'miscellaneous' => [ 'source' => 'unmapped', 'note' => 'no generic miscellaneous-traits block' ],
	'class'        => [ 'source' => 'unmapped', 'note' => 'no generic "class" field' ],
	'subclass'     => [ 'source' => 'unmapped', 'note' => 'no generic "subclass" field' ],
	'affinity'     => [ 'source' => 'unmapped', 'note' => 'Garou realm affinity not modeled' ],
	'plane'        => [ 'source' => 'unmapped', 'note' => 'Wraith plane not modeled' ],
	'brood'        => [ 'source' => 'unmapped', 'note' => 'Kuei-Jin brood not modeled' ],
	'tempers'      => [ 'source' => 'unmapped', 'note' => 'superseded by the named resource_pool blocks per stack' ],

	// -- Wraith ----------------------------------------------------------------------
	'ethnos'          => [ 'source' => 'json', 'block' => 'wraith-identity', 'field' => 'Ethnos' ],
	'guild'           => [ 'source' => 'json', 'block' => 'wraith-identity', 'field' => 'Guild' ],
	'faction'         => [ 'source' => 'json', 'field' => 'Faction' ],
	'legion'          => [ 'source' => 'json', 'block' => 'wraith-identity', 'field' => 'Legion' ],
	'pathos'          => [ 'source' => 'json', 'block' => 'wraith-resources', 'pool' => 'Pathos', 'part' => 'permanent' ],
	'corpus'          => [ 'source' => 'json', 'block' => 'wraith-resources', 'pool' => 'Corpus', 'part' => 'permanent' ],
	'arcanoi'         => [ 'source' => 'json', 'block' => 'wraith-arcanoi' ],
	'passions'        => [ 'source' => 'unmapped', 'note' => 'Wraith Passions not modeled' ],
	'fetters'         => [ 'source' => 'unmapped', 'note' => 'Wraith Fetters not modeled' ],
	'life'            => [ 'source' => 'unmapped', 'note' => 'Wraith Life not modeled' ],
	'death'           => [ 'source' => 'unmapped', 'note' => 'Wraith Death not modeled' ],
	'haunt'           => [ 'source' => 'unmapped', 'note' => 'Wraith Haunt not modeled' ],
	'regret'          => [ 'source' => 'unmapped', 'note' => 'Wraith Regret not modeled' ],
	'shadowarchetype' => [ 'source' => 'json', 'block' => 'wraith-identity', 'field' => 'Shadow' ],
	'shadowplayer'    => [ 'source' => 'unmapped', 'note' => 'Wraith Shadow player-controlled flag not modeled' ],
	'angst'           => [ 'source' => 'json', 'block' => 'wraith-resources', 'pool' => 'Angst', 'part' => 'permanent' ],
	'darkpassions'    => [ 'source' => 'unmapped', 'note' => 'Wraith Dark Passions not modeled' ],
	'thorns'          => [ 'source' => 'unmapped', 'note' => 'Wraith Thorns not modeled as a block' ],

	// -- Player (no Player entity in BE) ------------------------------------------------
	'ppearned'  => [ 'source' => 'unmapped', 'note' => 'no Player entity modeled in BE' ],
	'ppunspent' => [ 'source' => 'unmapped', 'note' => 'no Player entity modeled in BE' ],
	'email'     => [ 'source' => 'unmapped', 'note' => 'player email comes from the WordPress user account, not character storage' ],
	'phone'     => [ 'source' => 'unmapped', 'note' => 'no Player entity modeled in BE' ],
	'address'   => [ 'source' => 'unmapped', 'note' => 'no Player entity modeled in BE' ],
	'active'    => [ 'source' => 'unmapped', 'note' => 'legacy GV pre-2.3.97 compatibility flag; no BE equivalent' ],

	// -- Items / Rotes / Locations (workflow-0.7.md, not yet built) ------------------------
	'type'             => [ 'source' => 'unmapped', 'note' => 'world objects (items/locations) are workflow-0.7.md' ],
	'subtype'          => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md' ],
	'level'            => [ 'source' => 'unmapped', 'note' => 'world objects (items/rotes/locations) are workflow-0.7.md' ],
	'concealability'   => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md' ],
	'availability'     => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md' ],
	'negatives'        => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md' ],
	'damagetype'       => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md' ],
	'damageamount'     => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md' ],
	'bonus'            => [ 'source' => 'unmapped', 'note' => 'world objects (items) are workflow-0.7.md' ],
	'appearance'       => [ 'source' => 'unmapped', 'note' => 'world objects (items/locations) are workflow-0.7.md' ],
	'duration'         => [ 'source' => 'unmapped', 'note' => 'world objects (rotes) are workflow-0.7.md' ],
	'description'      => [ 'source' => 'unmapped', 'note' => 'world objects (rotes) are workflow-0.7.md' ],
	'grades'           => [ 'source' => 'unmapped', 'note' => 'world objects (rotes) are workflow-0.7.md' ],
	'owner'            => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'where'            => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'access'           => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'links'            => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'security'         => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'securitytraits'   => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'securityretests'  => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'gauntlet'         => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],
	'umbra'            => [ 'source' => 'unmapped', 'note' => 'world objects (locations) are workflow-0.7.md' ],

	// -- Hunter (creature stack not yet seeded) ------------------------------------------
	'creed'       => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'handle'      => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'conviction'  => [ 'source' => 'unmapped', 'note' => 'Hunter/Demon Conviction; Hunter stack not yet seeded and Demon does not define this pool' ],
	'mercy'       => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'vision'      => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'zeal'        => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'edges'       => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],

	// -- Demon -----------------------------------------------------------------------
	'torment' => [ 'source' => 'json', 'block' => 'demon-resources', 'pool' => 'Torment', 'part' => 'permanent' ],
	'faith'   => [ 'source' => 'json', 'block' => 'demon-resources', 'pool' => 'Faith', 'part' => 'permanent' ],
	'lores'   => [ 'source' => 'json', 'block' => 'demon-lores' ],

	// -- Temporary counterparts of the permanent pools above -----------------------------
	'tempwillpower'    => [ 'source' => 'json', 'pool' => 'Willpower', 'part' => 'temporary' ],
	'tempblood'        => [ 'source' => 'json', 'block' => 'vampire-resources', 'pool' => 'Blood', 'part' => 'temporary' ],
	'tempconscience'   => [ 'source' => 'json', 'block' => 'vampire-virtues', 'pool' => 'Conscience', 'part' => 'temporary' ],
	'tempselfcontrol'  => [ 'source' => 'json', 'block' => 'vampire-virtues', 'pool' => 'Self-Control', 'part' => 'temporary' ],
	'tempcourage'      => [ 'source' => 'json', 'block' => 'vampire-virtues', 'pool' => 'Courage', 'part' => 'temporary' ],
	'temppathtraits'   => [ 'source' => 'json', 'block' => 'vampire-resources', 'pool' => 'Morality', 'part' => 'temporary' ],
	'tempglamour'      => [ 'source' => 'json', 'block' => 'changeling-resources', 'pool' => 'Glamour', 'part' => 'temporary' ],
	'tempbanality'     => [ 'source' => 'json', 'block' => 'changeling-resources', 'pool' => 'Banality', 'part' => 'temporary' ],
	'temprage'         => [ 'source' => 'json', 'block' => 'werewolf-resources', 'pool' => 'Rage', 'part' => 'temporary' ],
	'tempgnosis'       => [ 'source' => 'json', 'block' => 'werewolf-resources', 'pool' => 'Gnosis', 'part' => 'temporary' ],
	'temphun'          => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Hun', 'part' => 'temporary' ],
	'temppo'           => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Po', 'part' => 'temporary' ],
	'tempyin'          => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Yin Chi', 'part' => 'temporary' ],
	'tempyang'         => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Yang Chi', 'part' => 'temporary' ],
	'tempdemonchi'     => [ 'source' => 'json', 'block' => 'kueijin-resources', 'pool' => 'Demon Chi', 'part' => 'temporary' ],
	'temparete'        => [ 'source' => 'json', 'block' => 'mage-resources', 'pool' => 'Arete', 'part' => 'temporary' ],
	'tempquintessence' => [ 'source' => 'json', 'block' => 'mage-resources', 'pool' => 'Quintessence', 'part' => 'temporary' ],
	'tempparadox'      => [ 'source' => 'json', 'block' => 'mage-resources', 'pool' => 'Paradox', 'part' => 'temporary' ],
	'temptruefaith'    => [ 'source' => 'json', 'block' => 'mortal-resources', 'pool' => 'True Faith', 'part' => 'temporary' ],
	'tempmemory'       => [ 'source' => 'unmapped', 'note' => 'Mummy Memory not modeled' ],
	'tempintegrity'    => [ 'source' => 'unmapped', 'note' => 'Mummy Integrity not modeled' ],
	'tempjoy'          => [ 'source' => 'unmapped', 'note' => 'Mummy Joy not modeled' ],
	'tempba'           => [ 'source' => 'unmapped', 'note' => 'Mummy Ba not modeled' ],
	'tempka'           => [ 'source' => 'unmapped', 'note' => 'Mummy Ka not modeled' ],
	'tempsekhem'       => [ 'source' => 'json', 'block' => 'mummy-resources', 'pool' => 'Sekhem', 'part' => 'temporary' ],
	'tempmbalance'     => [ 'source' => 'json', 'block' => 'mummy-resources', 'pool' => 'Balance', 'part' => 'temporary' ],
	'temppathos'       => [ 'source' => 'json', 'block' => 'wraith-resources', 'pool' => 'Pathos', 'part' => 'temporary' ],
	'tempcorpus'       => [ 'source' => 'json', 'block' => 'wraith-resources', 'pool' => 'Corpus', 'part' => 'temporary' ],
	'tempangst'        => [ 'source' => 'json', 'block' => 'wraith-resources', 'pool' => 'Angst', 'part' => 'temporary' ],
	'tempconviction'   => [ 'source' => 'unmapped', 'note' => 'Hunter/Demon Conviction; Hunter stack not yet seeded and Demon does not define this pool' ],
	'tempmercy'        => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'tempvision'       => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'tempzeal'         => [ 'source' => 'unmapped', 'note' => 'Hunter creature stack not yet seeded' ],
	'temptorment'      => [ 'source' => 'json', 'block' => 'demon-resources', 'pool' => 'Torment', 'part' => 'temporary' ],
	'tempfaith'        => [ 'source' => 'json', 'block' => 'demon-resources', 'pool' => 'Faith', 'part' => 'temporary' ],
];
