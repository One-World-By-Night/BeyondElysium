"""Every content judgement the emitter makes.

Principles these apply:

* **The ladder is the rule.** A family fills its block's declared 2/2/1 ladder; a short or
  long family is a data defect resolved from source material, never by shortening the ladder.
* **Split aggressively, flag only true conflicts** - and every automatic split records which
  note, tier or source proved the seam.
* **Books are the base; OWBN packets are selectable variants.**
* Creature data carries zero approval content. Classic WoD names.

Where a source prints two powers for one rung - an "Alternative Cantrip", a second Hand, an
edition reprint - the ladder keeps one and the other is recorded as an `alternatives` entry
on that rung rather than being discarded or pushed into `overflow`.
"""

# --- Wraith: Arcanoi --------------------------------------------------------------------
#
# Costs: innate 2 / basic 4 / intermediate 6 / advanced 9 (Wraith: The Oblivion). The ladder is
# 2/2/1 per the OWBN Arcanoi packet's structure; the packet's 4/7/10 ships as the
# `owbn-wraith_arcanoi` variant.
#
# Oblivion prints 2 Basic / 1 Intermediate / 1 Advanced for sixteen Arcanoi, so the packet's
# arrangement is taken for those sixteen (and for Fascinate, from the packet's Risen chapter).
# A book power the packet does not place is kept as an `alternatives` entry on the rung of its
# book tier. Names keep BE's existing spelling where the two are one power.
#
# Source: OWBN0124-Wraith-Arcanoi-2016.pdf, Arcanos sections 1-16 plus "Risen Arcanoi".
WRAITH_PACKET = {
    'Argos':       {'innate': ['Orienteering', 'Tempest Peek', 'Tempest Threshold'], 'basic': ['Enshroud', 'Phantom Wings'], 'intermediate': ['Flicker', 'Jump'], 'advanced': ['Oubliette']},
    'Castigate':   {'innate': ['Bulwark', 'Soulsight'], 'basic': ['Coax', 'Dark Secrets'], 'intermediate': ['Purify', 'House Cleaning'], 'advanced': ['Defiance']},
    'Embody':      {'innate': ['Ghostly Touch', 'Maintain the Material Form'], 'basic': ['Whispers', 'Phantom'], 'intermediate': ['Statue', 'Life-in-Death'], 'advanced': ['Materialize']},
    'Fatalism':    {'innate': ['Kismet'], 'basic': ['Fatal Vision', 'Foreshadow'], 'intermediate': ['Interpretation', 'Guesswork'], 'advanced': ['Luck']},
    'Flux':        {'innate': ['Grave Mold', 'Sense Fluxion'], 'basic': ['Rot', 'Strengthen'], 'intermediate': ['Decay', 'Puppet Theater'], 'advanced': ['Automaton']},
    'Inhabit':     {'innate': ['Sense Gremlin', 'Shellride'], 'basic': ['Surge', 'Ride the Electron Highway'], 'intermediate': ['Gremlinize', 'Claim'], 'advanced': ['Empower']},
    'Intimation':  {'innate': ['Twinge', 'Self-Intimation'], 'basic': ['The Gleaming', 'Quash'], 'intermediate': ['Deep Desiring', 'The Craving'], 'advanced': ['Cupitatis']},
    'Keening':     {'innate': ['Perfect Pitch', 'Sotto Voce'], 'basic': ['Dirge', 'Ballad'], 'intermediate': ['Muse', 'Crescendo'], 'advanced': ['Requiem']},
    'Lifeweb':     {'innate': ['Locate Fetter'], 'basic': ['Sense Strand', 'Web Presence'], 'intermediate': ['Splice Strand', 'Sever Strand'], 'advanced': ['Soul Pact']},
    'Mnemosynis':  {'innate': ['Rewind', 'Sense Intellect'], 'basic': ['In Memoriam', 'Mnemotechnics'], 'intermediate': ['Mindspeak', 'Casting the Scene'], 'advanced': ['Onslaught']},
    'Moliate':     {'innate': ['Glow', 'Shapesense', "Return to Death's Visage"], 'basic': ['Imitate', 'Sculpt'], 'intermediate': ['Martialry', 'Rend'], 'advanced': ['Bodyshape']},
    'Outrage':     {'innate': ['Leap of Rage'], 'basic': ['Ping', 'Wraithgrasp'], 'intermediate': ['Stonehand Punch', "Death's Touch"], 'advanced': ['Obliviate']},
    'Pandemonium': {'innate': ['Sense Chaos'], 'basic': ['Weirdness', 'Befuddlement'], 'intermediate': ['Dark Ether', 'Foul Humour'], 'advanced': ['Tempus Fugit']},
    'Phantasm':    {'innate': ['Sleepsense'], 'basic': ['Elysia', 'Lucidity'], 'intermediate': ['Dreams of Sleep', 'Phantasmagoria'], 'advanced': ['Agon']},
    'Puppetry':    {'innate': ['Detect Possession'], 'basic': ['Skinride', 'Sudden Movement'], 'intermediate': ["Master's Voice", 'Rein in the Mind'], 'advanced': ['Obliterate the Soul']},
    'Usury':       {'innate': ['Assessment'], 'basic': ['Transfer', 'Charitable Trust'], 'intermediate': ['Early Withdrawal', 'Exchange Rate'], 'advanced': ['Investment']},
    'Fascinate':   {'innate': ['Tuning In', 'Deja Vu'], 'basic': ['Distraction', 'Remembrance'], 'intermediate': ['Charge of Duty', 'Driving Urge'], 'advanced': ['Target Lock']},
}
WRAITH_PACKET_SPELLING = {
    # packet spelling -> BE spelling, where the two are the same power (compared by norm()).
    'House Cleaning': 'Housecleaning', 'Wraith Grasp': 'Wraithgrasp', 'Foul Humor': 'Foul Humour',
    'Sotto Voice': 'Sotto Voce', "Return Of Death's Visage": "Return to Death's Visage",
    'Tempest Peek': 'Tempestpeek',
}

# Behest is two families sharing Delve and Trace: the Right Hand (Scry, Divine) and the Left Hand
# (Twitch, Murmur). Veer, the capstone, closes both ladders.
WRAITH_SPLIT_FAMILIES = {
    'Behest': [
        ('Behest (Right Hand)', ['Delve', 'Trace', 'Scry', 'Divine', 'Veer'],
         'W:tO 2nd ed. PDF p. 528-531: Delve and Trace open both Hands; Scry and Divine are the Right Hand, Divine "the uppermost such ability for a Moriman dwelling in Stygia"; Veer is the Arcanos\' own capstone and requires Scry'),
        ('Behest (Left Hand)', ['Delve', 'Trace', 'Twitch', 'Murmur', 'Veer'],
         'W:tO 2nd ed. PDF p. 531-532: Twitch is "the first level of the Left Hand", Murmur "the second level of the Left Hand", both gated on a successful Scry; Veer closes the ladder for either Hand'),
    ],
}

# --- Changeling: Arts -------------------------------------------------------------------
#
# Base content is BE's seeded Arts. Where the book cannot fill 2/2/1, the OWBN Changeling
# Mechanics Packet's arrangement (two basics, two intermediate, one advanced) settles it; the
# Dauntain Agendas come from their own sources.
CHANGELING_LADDERS = {
    # Dream Craft per the OWBN packet: Intermediate is "Homestead" and "Attunement". Attunement is
    # new to BE; Anchor and Dream Riding drop to alternatives on those rungs.
    'Dream Craft': [('basic', 'Walk the Silver Path'), ('basic', 'The Merry Dance'), ('intermediate', 'Homestead'),
                    ('intermediate', 'Attunement'), ('advanced', 'Dream Weaving')],
    # Chronos per the OWBN packet: Basic Wyrd, Backwards Glance; Intermediate Dreamtime, Permanency;
    # Advanced Reversal of Fortune. Permanency fills the missing second Intermediate.
    'Chronos': [('basic', 'Wyrd'), ('basic', 'Backward Glance'), ('intermediate', 'Dream Time'),
                ('intermediate', 'Permanency'), ('advanced', 'Reversal of Fortune')],
    # Naming per the OWBN packet: Basic Seek'n'Spell, Rune; Intermediate Runic Circle, Saining;
    # Advanced Reweaving. Runic Circle moves to Intermediate.
    'Naming': [('basic', "Seek'n'Spell"), ('basic', 'Rune'), ('intermediate', 'Runic Circle'),
               ('intermediate', 'Saining'), ('advanced', 'Reweaving')],
    # Oneiromancy per the OWBN packet: Syncope is the second Intermediate and Expiation the single
    # Advanced. Embrace of Morpheus becomes an alternative on a basic rung.
    'Oneiromancy': [('basic', 'Oneirodynia'), ('basic', 'Oneirocritia'), ('intermediate', 'Oneirataxia'),
                    ('intermediate', 'Syncope'), ('advanced', 'Expiation')],
    # The Dauntain Agendas (Burnout, Stultify, Webcraft) come from Laws of the Hunt Revised, which
    # prints all three at 2/2/1.
    'Burnout': [('basic', 'Mindblock'), ('basic', 'Heartbind'), ('intermediate', 'Obsession'),
                ('intermediate', 'Acquisition'), ('advanced', 'Geek Out')],
    'Stultify': [('basic', 'Rosetint'), ('basic', 'Dull Impulse'), ('intermediate', 'Proselytize'),
                 ('intermediate', 'Procedural Addiction'), ('advanced', 'Micro-Management')],
    'Webcraft': [('basic', 'Weave Web'), ('basic', 'Overwhelming Wincing'), ('intermediate', 'Warp Will'),
                 ('intermediate', 'Wend your Way'), ('advanced', 'Cry "Woof"')],
}
CHANGELING_ALTERNATIVES = {
    # Rung -> alternative cantrips the sources print for that slot.
    'Oneiromancy': {1: [('Embrace of Morpheus', 'OWBN0019 p. 35: an invented LARP level, learnable in place of a basic')]},
    # Legerdemain: the book prints two Advanced cantrips (Phantom Shadows, Rattle); the OWBN packet
    # keeps Phantom Shadows as the single Advanced.
    'Legerdemain': {5: [('Rattle', 'The Shining Host p. 135 prints it as a second Advanced; OWBN0019 p. 33 keeps Phantom Shadows alone')]},
    # Primal: the book prints Holly Strike and Elder-Form both Advanced; the OWBN packet folds Holly
    # Strike into Heather Balm and names Primal Form the single Advanced.
    'Primal': {5: [('Elder-Form', 'The Shining Host p. 140 prints it as a second Advanced; OWBN0019 p. 35 names one Advanced (Primal Form)')]},
    # Dream Craft: the packet's two Intermediates are Homestead and Attunement, so Anchor and Dream
    # Riding become alternatives. The packet's own renaming of the basics is recorded as
    # alternatives on rungs 1 and 2 rather than applied, so no held basic is renamed.
    'Dream Craft': {
        1: [('Find the Silver Path', 'OWBN0019 p. 31 names the first Basic this way; BE\'s "Walk the Silver Path" is the same cantrip under The Shining Host\'s name')],
        2: [('Determinism', 'OWBN0019 p. 31 prints this as the second Basic in place of "The Merry Dance"')],
        3: [('Anchor', 'BE\'s seeded third cantrip; OWBN0019 p. 31 does not place it, giving Homestead and Attunement as the two Intermediates')],
        4: [('Dream Riding', 'BE\'s seeded fifth cantrip; OWBN0019 p. 31 does not place it')],
    },
}
# Stigma is not an Art: Stigmas are individual Dauntain marks, each costing a permanent Banality -
# no dots, no ladder, no order. They move to their own unranked trait_list, which carries nine:
# BE's six plus Herd Mentality, Ravage and Shunt.
CHANGELING_STIGMAS = [
    ('Conversion', 229), ('Disbelief', 229), ('Erasure', 230), ('Hatred', 230), ('Herd Mentality', 230),
    ('Iron Ward', 230), ('Numb', 230), ('Ravage', 231), ('Shunt', 231),
]

# --- Mummy: Hekau and formulae ---------------------------------------------------------
#
# A Hekau's ladder is its path rating (five generic rungs) and every named spell/ritual is a
# separate purchase - the `mummy-formulae` trait_list. Hekau cost 3/6/9; a new spell or ritual
# costs one Experience for Basic, three for Intermediate and five for Advanced, +1 outside the
# primary path for both. The GVM holds each path twice (a "Hekau, Levels" rung stub and a
# second-edition formula menu); the "(2nd)" names Ren-Hekau and Ushabti are the Egyptian names
# of Nomenclature and Effigy.
MUMMY_PATHS = ['Alchemy', 'Amulets', 'Celestial', 'Effigy', 'Necromancy', 'Nomenclature']
MUMMY_ALIASES = {'Ren-Hekau': 'Nomenclature', 'Ushabti': 'Effigy'}
MUMMY_RUNGS = [('basic', 'First Basic Path'), ('basic', 'Second Basic Path'),
               ('intermediate', 'First Intermediate Path'), ('intermediate', 'Second Intermediate Path'),
               ('advanced', 'Advanced Path')]
MUMMY_FORMULA_COST = {'basic': '1', 'intermediate': '3', 'advanced': '5'}
# A formula's note names the rung it needs: "first basic" is path rating 1, "second basic" 2,
# "first int." 3, "second int." 4, "adv." 5. A bare "basic"/"int."/"adv." spell note (the
# second-edition dot names such as Ren-Hekau's "Simple Names") needs the first rung of its tier.

# --- Werewolf / Fera Gifts --------------------------------------------------------------
#
# Authored as tiered_power and pick-only: every Gift is bought by name, gaps are legal per
# category and per rank, so `ladder` is declared empty. Ranks basic/intermediate/advanced at
# 3/6/9, +1 out of type, levels 1/3/5.
#
# Tribe name join: werewolf-identity says "Bone Gnawers", the gift menu "Bone Gnawer"; every
# other tribe is plural in both, so the gift side takes the identity spelling. Croatan and White
# Howlers are extinct tribes with no gift menu: legitimately empty.
WEREWOLF_GROUP_RENAME = {'Bone Gnawer': 'Bone Gnawers'}
# `Fenrir` is removed from the Tribe options (the real value is Get of Fenris) and stays as an
# alias so held data still resolves; the singular "Bone Gnawer" and "Silver Fang" are aliased on
# the same rule.
WEREWOLF_TRIBE_ALIASES = {'Fenrir': 'Get of Fenris', 'Bone Gnawer': 'Bone Gnawers', 'Silver Fang': 'Silver Fangs'}
WEREWOLF_TRIBE_DROP = {'Fenrir'}
# Hakken and Hengeyokai (Beast Courts) and the Wyld West / Dark Ages printings are variants, not
# base content.
BEAST_COURTS_GROUPS = {'Hakken', 'Hengeyokai'}

# --- Mortal: backgrounds from the resolved research ----------------------------------------
#
# samples/research/hunter/mortal/resolved.json holds 36 backgrounds; 32 are already in
# mortal-backgrounds under the same or a variant spelling (Influence: Politics = BE's
# "Influence: Political", Mana Pool = "Mana-Pool", Influence: Espionage/Military = BE's bare
# "Espionage"/"Military"). These four are absent - all from the OWBN Hunter: Inquisition Packet
# (2021).
MORTAL_BACKGROUND_ADDITIONS = [
    ('Mob', 'OWBN Hunter: Inquisition Packet (2021), p. 7'),
    ('Reliquary', 'OWBN Hunter: Inquisition Packet (2021), p. 7'),
    ('Status', 'OWBN Hunter: Inquisition Packet (2021), p. 8'),
    ('Flock', 'OWBN Hunter: Inquisition Packet (2021), p. 8'),
]

# --- Abilities: one merged block per creature type ---------------------------------------
#
# The shared met-abilities (the generic GVM "Abilities" menu only) becomes one `{stack}-abilities`
# block per stack: the generic menu, that creature's own "Abilities, X" menu with its includes
# resolved, and the met-mechanics.csv Ability rows. Bete reuses Fera's block. "Abilities, Hunter"
# is not a stack and "Abilities, Item" is a weapon-property menu, not character Abilities:
# neither is emitted.
ABILITY_MENUS = {
    'vampire': 'Abilities, Vampire', 'werewolf': 'Abilities, Werewolf', 'fera': 'Abilities, Fera',
    'mage': 'Abilities, Mage', 'changeling': 'Abilities, Changeling', 'wraith': 'Abilities, Wraith',
    'demon': 'Abilities, Demon', 'mummy': 'Abilities, Mummy', 'kueijin': 'Abilities, Kuei-Jin',
    'mortal': 'Abilities, Mortal',
}
ABILITY_BLOCK_FOR_STACK = dict({s: f'{s}-abilities' for s in ABILITY_MENUS}, bete='fera-abilities')

# Every MET book prices an Ability at one XP per Trait; each block cites its own genre's book
# (PDF page numbers). Vampire cites Laws of the Night Revised through met-mechanics.csv's own
# General Ability rows.
ABILITY_COST_SOURCE = {
    'vampire': 'Laws of the Night Revised (WW05013) p. 124 experience chart, as recorded by met-mechanics.csv (General Ability rows, 1 each)',
    'werewolf': 'Laws of the Wild Revised (WW05023) PDF p. 174 - "New Ability Trait: One Experience per Ability for the first five levels"',
    'fera': 'Laws of the Wild Revised (WW05023) PDF p. 174 - "New Ability Trait: One Experience per Ability for the first five levels"',
    'mage': 'Laws of Ascension (WW05022) PDF p. 124 - "New Ability Trait - One Experience per Ability Trait"',
    'changeling': 'The Shining Host (WW05009) PDF p. 119 - "New Ability - One experience point per Ability Trait"',
    'wraith': 'Oblivion (WW05400) PDF p. 167 - "New Ability - One experience point per Ability Trait"',
    'demon': 'OWBN Fallen Genre Packet (OWBN0052, 2021) PDF p. 46 - "New Ability: One Experience per Trait"',
    'mummy': 'Laws of the Resurrection (WW05035) PDF p. 117 - "New Ability Trait - One Experience per Ability Trait up to five Traits"',
    'kueijin': 'Laws of the East (WW05016) PDF p. 127 - "New Ability Trait - One Experience per Ability Trait up to 5" (2 each for 6-10)',
    'mortal': 'Laws of the Hunt (WW05014) PDF p. 159 - "New Ability Trait - One Experience Trait"',
}

# met-mechanics.csv tags each Ability row with a Subtype. "General" rows go to every block;
# the two genre-tagged ones go only to their genre (Primal Urge and the Garou "Rituals" are
# Changing Breeds Abilities, credited to Laws of the Wild Revised).
ABILITY_CSV_SUBTYPE_STACKS = {'Changing Breeds': {'werewolf', 'fera'}, 'Vampire': {'vampire'}}

# A misspelled CSV row, dropped and kept as an alias of the real Ability. (Vamp is real - MET
# Storyteller's Guide, "Sedutor" in pt_BR.)
ABILITY_TYPO_ALIASES = {'Meditiation': 'Meditation'}

# Grapevine offers Lore as a submenu (`<submenu name="Lore" link="Lores, Vampire"/>`). It becomes
# ONE item per block, priced as any Ability, with the genre's Lores menu as suggested
# `specializations`. Flattening: a Lores menu's own items are specializations as written
# ("Kindred"); a nested submenu contributes "<Submenu>: <item>" for each item of the menu it
# links ("Clan: Brujah"), plus "<Submenu>: <name>" for any submenu one level further down
# ("Fera: Bastet") - the form Grapevine writes on a sheet ("Lore: Clan: Assamite"). Mage's
# second submenu "RD Data" links the same Lores menu: it is the Technocracy's name for Lore, so
# it is recorded as an alias, not a second item.
LORE_ALIASES = {'mage': ['RD Data']}

# --- Merits and Flaws: one merged block per creature type ------------------------------
#
# The shared met-merits / met-flaws (the generic GVM menus plus every met-mechanics.csv
# Merit/Flaw row) become `{stack}-merits` / `{stack}-flaws`. The Vampire clan menus (Assamite,
# Brujah, Giovanni, Lasombra, Malkavian, Nosferatu, Tremere, Tzimisce, Ventrue) and Gangrel
# Animal Traits ride in on Merits/Flaws, Vampire as submenus and land in vampire-merits /
# vampire-flaws with the clan in `group`. Includes are resolved as Grapevine declares them
# (Fera includes Werewolf's, Kuei-Jin includes Vampire's); submenus are flattened for the
# stack's own menu only, so Kuei-Jin does not inherit Vampire's clan lists.
MERIT_MENUS = {s: (f'Merits, {m}', f'Flaws, {m}') for s, m in {
    'vampire': 'Vampire', 'werewolf': 'Werewolf', 'fera': 'Fera', 'mage': 'Mage', 'changeling': 'Changeling',
    'wraith': 'Wraith', 'mummy': 'Mummy', 'kueijin': 'Kuei-Jin', 'mortal': 'Mortal'}.items()}
MERIT_MENUS['demon'] = ('Merits', 'Flaws')          # no Demon menu in the GVM: generic only
MERIT_BLOCK_FOR_STACK = dict({s: s for s in MERIT_MENUS}, bete='fera')

# A submenu the generic Flaws menu links is not a flaw list: Derangements are their own
# section (met-derangements) on every stack.
MERIT_SKIP_SUBMENUS = {'Derangement'}

# met-mechanics.csv's 812 Merit/Flaw rows are the Vampire research overlay - every source but two
# is a Vampire book or OWbN clan packet. Routed by source: a row whose name is a generic-menu
# item only adds its source and (where the GVM has none) its cost to that generic item; the two
# Laws of the Wild Revised rows go to Werewolf and Fera; everything else goes to Vampire.
MERIT_CSV_SOURCE_STACKS = {'Laws of the Wild Revised': {'werewolf', 'fera'}}

# Research files whose captured costs price a GVM item the GVM leaves blank, per stack (same
# genre first). Matched by normalised name; the file and its own book citation go on the note.
MERIT_RESEARCH = {
    'werewolf': ['fera/resolved.json#garou'], 'fera': ['fera/resolved.json'],
    'mortal': ['hunter/mortal/resolved.json'], 'mummy': ['mummy/core/merits-flaws.json', 'mummy/core/players-guide.json'],
    'kueijin': ['kuei-jin/merits-flaws.json'], 'changeling': ['changeling/owbn/mechanics-packet-merits-flaws.json'],
    'demon': ['demon/owbn/merits-flaws.json'], 'wraith': ['wraith/owbn/risen-packet.json'],
    'vampire': ['vampire/*/*merits-flaws*.json', 'vampire/*/*merits*.json'], 'mage': [],
}
# After the genre's own files, every other captured Merits/Flaws file, in this order. The citation
# says which book a cost came from.
MERIT_RESEARCH_ANY_GENRE = [
    'hunter/mortal/resolved.json', 'fera/resolved.json', 'kuei-jin/merits-flaws.json',
    'changeling/owbn/mechanics-packet-merits-flaws.json', 'mummy/core/merits-flaws.json',
    'mummy/core/players-guide.json', 'demon/owbn/merits-flaws.json', 'wraith/owbn/risen-packet.json',
    'vampire/*/*merits-flaws*.json',
]

# Costs read from the books for GVM items nothing else prices (PDF pages).
MERIT_BOOK_COSTS = {
    'Blase': ('3', 'Changeling: The Dreaming 2nd ed. (WW07300) PDF p. 188 - "Blasé (3-point merit)"'),
    'Charach': ('1', 'OWBN Changing Breeds: Child of Gaia (OWBN0023, 2009) PDF p. 19 - "Charach 1 pt flaw"'),
    'Taibhsear': ('1', 'The Shining Host Players Guide (WW05030) PDF p. 163 - "Taibhsear (1 Trait Fae Gift)"'),
    'Enchanted Blood': ('1', 'The Shining Host Players Guide (WW05030) PDF p. 163 - "Enchanted Blood (1 Trait Fae Mark)"'),
    'Improperly Buried': ('1', 'Wraith Players Guide (WW06007) PDF p. 28 - "Improperly Buried (1 point Flaw)"'),
    "Changeling's Eyes": ('1', 'Changeling Players Guide (WW07100) PDF p. 29 - "Changeling\'s Eyes (1 point Flaw)"'),
}

# Grapevine placeholder rows, not content.
MERIT_DROP = {'New Item'}

# GVM Merits/Flaws items that neither the GVM, the CSV, captured research nor a book read so far
# prices. Each is named with its reason.
_TT = 'UNPRICED: no captured source prices it and the GVM carries no cost; its tabletop book is unread'
MERIT_UNPRICED = {n: _TT for n in [
    'Alcohol Tolerance', 'Distant Sire', 'Struggling', 'Unusually Fertile', 'Conniver', 'Naive', 'Vegan',
    'Life-Blind', 'Cast No Shadow', 'Defective Sense', 'Ineptitude', 'Spiritual Duty', 'Technobabbler',
    'Touch of Chaos', 'Green Thumb', 'Parlor Trick', 'Personal Talisman', 'Unobtrusive', 'Common Visage',
    'Floral Kingdom', 'Damned', 'Distinctive Appearance', 'Kenning-Wise', 'Vow', 'Walkurie', 'Poverty',
    'Wandering Spirit', 'Yulan-Jin',
]}
MERIT_UNPRICED.update({n: 'UNPRICED: a Fomori Taint (Mortal Flaws submenu); the GVM prices 5 of its 14 and Laws of the Hunt, where Fomori are statted, is not captured'
                       for n in ['Infections', 'Inner Volcano', 'Mental De-evolution', 'Severe Allergy', 'Special Diet',
                                 'Teledementia', 'Ugly as Sin', 'Worms']})

# --- Identity dropdowns from their GVM menus ----------------------------------------------
#
# Each listed field becomes a `select` built from its GVM menu, with `allow_custom: true`, so a
# value that matches no option (Kuei-Jin "Balanced"/"Wrathful", Mummy "Aided of Anpu", free-text
# Motivations, a chronicle's own Titles) stays valid as a custom entry.
#
# Not converted:
#   * `werewolf-identity.Rank` and `fera-identity.Rank` are **number** fields holding 1-5, while
#     the GVM menus hold names (Cliath, Fostern...) or the generated "Rank 1".."Rank 5" per species.
#   * `Pack` is a pack's own name - the GVM has no Pack menu.
#   * `wraith-identity.Ethnos` is not given the Spectre sub-types (Malfean, Mortwight...): they
#     would mix two different axes in one field.
#   * Wraith Rank, Kuei-Jin Station's own menu and Werewolf Camp need *new* fields. Station's
#     field already exists, so it is converted.
IDENTITY_OPTION_MENUS = {
    ('werewolf-identity', 'Totem'): ['Totems'],
    ('fera-identity', 'Totem'): ['Totems'],
    ('fera-identity', 'Auspice'): ['Auspice, Fera'],
    ('wraith-identity', 'Legion'): ['Legion'],
    ('wraith-identity', 'Faction'): ['Faction, Wraith'],
    ('wraith-identity', 'Shadow'): ['Shadow Archetypes'],
    ('mage-identity', 'Faction'): ['Faction, Mage'],
    ('mummy-identity', 'Amenti'): ['Amenti', 'Capachoca'],          # the menu's own Capachoca submenu
    ('mortal-identity', 'Motivation'): ['Motivation'],
    ('mortal-identity', 'Association'): ['Group'],                  # its Revenant / Tradition Sorceror
                                                                    # submenus are other fields' lists
    ('changeling-identity', 'House'): ['House, Changeling'],
    ('vampire-identity', 'Title'): ['Title, Vampire'],
    ('kueijin-identity', 'Balance'): ['Balance'],
    ('kueijin-identity', 'Station'): ['Station'],
}
# The GVM writes the Legions with a leading article ("The Emerald Legion"); the article is dropped
# so an "Emerald Legion" matches its own option.
IDENTITY_OPTION_STRIP_ARTICLE = {('wraith-identity', 'Legion')}

# --- Rites: the category menus and the Fera species menus ----------------------------------
#
# `werewolf-rites` held only the eight tribal menus; Grapevine's "Rites, Werewolf" also lists ten
# category submenus, and "Rites, Fera" lists twelve species menus (two of them - Bastet and
# Mokole - nesting their own categories).
#
# **Fera gets its own block.** `fera-rites` is a new block, the Fera and Bete stacks and
# templates point at it, and the Garou rites stay Garou.
RITE_CATEGORY_MENUS = {        # werewolf-rites: submenu label -> menu, category = the label
    'Accord': 'Rites, Werewolf, Accord', 'Caern': 'Rites, Werewolf, Caern', 'Death': 'Rites, Werewolf, Death',
    'Frontier': 'Rites, Werewolf, Frontier', 'Minor': 'Rites, Werewolf, Minor', 'Mystic': 'Rites, Werewolf, Mystic',
    'Punishment': 'Rites, Werewolf, Punishment', 'Pure Ones': 'Rites, Werewolf, Pure Ones',
    'Renown': 'Rites, Werewolf, Renown', 'Seasonal': 'Rites, Werewolf, Seasonal',
}
# fera-rites: species -> its menu. Bastet and Mokole nest categories; Corax nests Buzzard,
# which is its own Fera type in the gift data, so it becomes its own group.
FERA_RITE_MENUS = ['Ananasi', 'Bastet', 'Corax', 'Gurahl', 'Hengeyokai', 'Kitsune', 'Mokole',
                   'Nagah', 'Nuwisha', 'Ratkin', 'Rokea']
FERA_RITE_OWN_GROUP = {'Buzzard'}
# LotW Revised's Rites chart names eight categories; Frontier (Wyld West) and Pure Ones (the
# Pure tribes) are Grapevine's own two extra groupings, kept as categories.
RITE_CATEGORIES = ['Accord', 'Caern', 'Death', 'Mystic', 'Punishment', 'Renown', 'Seasonal', 'Minor',
                   'Frontier', 'Pure Ones', 'Wallow', 'Zhong Lung', 'Gumagan', 'Kuasha', 'Taghairm',
                   'Moon', 'Need', 'Hishtpah']

# A rite the GVM leaves unpriced that is not a Minor rite (Minor rites are unpriced - bought with
# the Rites Background).
RITE_UNPRICED = {
    'Rite of Crash Space': 'UNPRICED: the GVM carries no cost and the Ratkin book is not captured - 1.3.0 owner question 16',
}

# --- Backgrounds bought per individual -----------------------------------------------------
#
# `allow_multiples` is a per-item flag with the block-level value as the default: when it is on,
# the editor asks "Who or what?" and keeps each answer as its own row via `specialization`. It
# marks a Background that names a specific person, place or thing, so a character can hold
# several.
BACKGROUND_MULTIPLES = [
    # The five core named-entity Backgrounds.
    'Allies', 'Contacts', 'Retainers', 'Mentor', 'Herd',
    # People and creatures, each one named: companions, patrons, followers, households.
    'Companion', 'Familiar', 'Familiar/Companion', 'Spirit Companion', 'Jamak', 'Nushi', 'Patron',
    'Sempai', 'Guide', 'Spies', 'Backup', 'Followers', 'Flock', 'Dreamers', 'Household',
    'Living Family', 'Wraith Family', 'Spirit Slaves', 'Mob', 'Kinfolk',
    # Places, each one a specific site.
    'Haunt', 'Sanctum', 'Sanctum/Laboratory', 'Laboratory', 'Chantry', 'Chantry/Construct', 'Construct',
    'Node', 'Demesne', 'Den-Realm', 'Domain', 'Trod', 'Wallow', 'Umbral Glade', 'Tomb', 'Holding',
    'Holdings', 'Library', 'Cenaculum', 'Sanctuary', 'Colony',
    # Things, each one an object with a name.
    'Artifact', 'Magic Artifact', 'Relic', 'Reliquary', 'Fetish', 'Jade Talisman', 'Trinket', 'Treasure',
    'Wonder', 'Devices', 'Vessel', 'Chimera', 'Alternate Identity',
]
# Not marked - each is one rating, not a set of named things: Ancestors, Past Life/Past Lives,
# Memory, Mnesis, Totem, Rank, Status, Prestige, Arsenal, Equipment, Requisitions, Secret Weapons,
# Information Network, Journal, Husk, Dross, Legacy, Generation, Influence and every Influence: X
# (an Influence rating is one track per sphere, and the sphere is already the item).

# --- Abilities bought per field of study ---------------------------------------------------
#
# A **specialization labels one holding** (`Brawl 5 (Wrestling)` is still one Brawl, and a
# different focus must not buy a second Brawl), while **`allow_multiples` makes the label part of
# the holding's identity** (`Lore: Clan: Assamite` and `Lore: Nod` are separate purchases).
# Identity rule: name alone, unless the item carries `allow_multiples`, in which case name + label.
ABILITY_MULTIPLES = [
    # The seven core per-field Abilities. "City Secrets" is in no GVM menu; it is listed here for
    # when it is added to the catalog.
    'Lore', 'Crafts', 'Science', 'Performance', 'Linguistics', 'Hobby/Professional/Expert', 'City Secrets',
    # Genre equivalents, by the same test - each label is its own Ability, bought separately.
    'City Knowledge',   # Mage's own City Secrets, one per city
    'Culture',          # Mage, one per culture studied
    'Cosmology',        # Mage / Mortal / Mummy, one per cosmology or realm
    'History',          # Vampire / Mortal / Mummy, one per era or region, like Academics-per-field
    'Theology',         # Vampire / Mortal, one per faith
    'Metaphysics',      # Mortal / Mummy, one per system
    'Gremayre',         # Changeling fae lore, one per subject
    'Mythlore',         # Changeling, one per mythology
    'Martial Arts',     # Kuei-Jin, one per style
]
# Not marked - each reads as one holding with a focus label, which is what `has_specializations`
# is for: Academics, Occult, Medicine, Medical Knowledge, Thanatology, Divination, Technology,
# Research, Instruction, Pilot, Do, Kenning, Rituals, Seamanship, Herbalism, Commerce, Expression
# (and Mummy's "Expression, Kipu", which is already one named entry), Black Hand Knowledge,
# Masquerade, Koldunism, Soulforging, Primal Urge. Mage's **Esoterica** is in no GVM menu.

# --- mortal-numina splits into real blocks -------------------------------------------------
#
# Grapevine's `Numina` menu is a container of fourteen submenus, and every one of the 356 live
# families maps to exactly one of them. Five of the fourteen are **copies of other stacks'
# catalogues** - Gifts, Disciplines, Arts, Realms and their 1,285 overflow levels - and those are
# not re-emitted: the Mortal stack references the real blocks instead. The rest is real Mortal
# content that never had a block of its own.
NUMINA_REFERENCED_BLOCKS = {          # container submenu -> the block that really owns it
    'Gifts': ['werewolf-gifts', 'fera-gifts'],
    'Disciplines': ['vampire-disciplines'],
    'Arts': ['changeling-arts'],
    'Realms': ['changeling-realms'],
}
# The path blocks built from the menus themselves. Each entry: slug, display name, the GVM
# container menus in precedence order (a later menu only fills a path the earlier one lacks),
# and the stack that owns the section.
NUMINA_PATH_BLOCKS = [
    ('mortal-psychic', 'Psychic Phenomena', ['Psychic Phenomena'], 'mortal'),
    ('mortal-hedge-magic', 'Hedge Magic', ['Sorcery', 'Sorcery, Revised'], 'mortal'),
    ('mortal-theurgy', 'Theurgy', ['Theurgy, Revised', 'Theurgy'], 'mortal'),
    ('mortal-martial-arts', 'Martial Arts', ['Martial Arts'], 'mortal'),
    ('kueijin-shintai', 'Shintai', ['Shintai'], 'kueijin'),
]
# Flat lists, straight to a trait_list.
NUMINA_FLAT_BLOCKS = [
    ('mortal-fomori', 'Fomori Powers', 'Fomori Powers', 'mortal'),
    ('mortal-bioenhancements', 'Bioenhancements', 'Bioenhancements', 'mortal'),
]
# Sorcery's own generic rung names, from the shared "Sorcery, Levels" menu: five path levels at
# 3/3/6/6/9 - the same shape as Mummy's Hekau - plus ten spell and ritual slots that are
# separate purchases and go to the formulae list.
NUMINA_GENERIC_RUNGS = ['Apprentice Level', 'Initiate Level', 'Adept Level', 'Disciple Level', 'Master Level']
# Benandanti Rituals are four named workings with no path ladder of their own: they join the
# formulae list under their own group.
NUMINA_FORMULAE_EXTRA = {'Benandanti Rituals': 'Benandanti'}

# What each container section becomes on its stack. A section is replaced in place, keeping its
# display order band, and the copies become references to the blocks that really own them - a
# ghoul buys Disciplines, a kinfolk Gifts, a kinain Arts.
STACK_SECTION_REPLACEMENTS = {
    'mortal': {'mortal-numina': [
        ('mortal-psychic', 'Psychic Phenomena'), ('mortal-hedge-magic', 'Hedge Magic'),
        ('mortal-hedge-magic-formulae', 'Spells and Rituals'), ('mortal-theurgy', 'Theurgy'),
        ('mortal-martial-arts', 'Martial Arts'), ('mortal-fomori', 'Fomori Powers'),
        ('mortal-bioenhancements', 'Bioenhancements'),
        ('vampire-disciplines', 'Disciplines (ghouls)'), ('werewolf-gifts', 'Gifts (kinfolk)'),
        ('fera-gifts', 'Gifts (Fera kinfolk)'), ('changeling-arts', 'Arts (kinain)'),
        ('changeling-realms', 'Realms (kinain)'),
    ]},
    # The Demon stack's `demon-lores` (nine flat Lores) is replaced by `demon-evocations` (139 Evocations
    # across 23 Lores) and `demon-rituals` (65 Rituals).
    'demon': {'demon-lores': [('demon-evocations', 'Lores (Evocations)'), ('demon-rituals', 'Rituals')]},
    'kueijin': {},
}
# kueijin-shintai is new content: the section is added rather than replacing anything.
STACK_EXTRA_SECTIONS = {'kueijin': [('kueijin-shintai', 'Shintai', 62)]}
