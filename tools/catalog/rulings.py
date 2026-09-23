"""Every content judgement the 1.3.0 emitter makes, with the evidence that settled it.

Owner rulings in force (2026-09-21/22) that these apply:

* **The ladder is the rule.** A family fills its block's declared 2/2/1 ladder; a short or
  long family is a data defect resolved from source material, never by shortening the ladder.
* **Split aggressively, flag only true conflicts** - and every automatic split records which
  note, tier or source proved the seam. That record is this file.
* **Books are the base; OWBN packets are selectable variants** (format section 4b).
* Creature data carries zero approval content. Classic WoD names.

Where a source prints two powers for one rung - an "Alternative Cantrip", a second Hand, an
edition reprint - the ladder keeps one and the other is recorded as an `alternatives` entry
on that rung rather than being discarded or pushed into `overflow`. That key is new: it is
recorded in `1.3.0-design-workflow.md`'s owner questions and 1.3.2 carry-forward, because the
engine does not read it yet.
"""

# --- Wraith: Arcanoi --------------------------------------------------------------------
#
# Owner ruling 2026-09-21 (1.3.0 D1): costs innate 2 / basic 4 / intermediate 6 / advanced 9
# per Oblivion (WW05400, p. 165, "New Arcanos - Two experience points for Innate Abilities,
# four for Basic Arcanoi, six for Intermediate Arcanoi and nine for Advanced Arcanoi"); the
# ladder is 2/2/1 per the OWBN Arcanoi packet's own structure; the packet's 4/7/10 ships as
# the `owbn-wraith_arcanoi` variant.
#
# Oblivion itself prints 2 Basic / 1 Intermediate / 1 Advanced for sixteen Arcanoi, so the
# book alone cannot fill a 2/2/1 ladder. The packet is the only source that does, so its
# arrangement is taken for those sixteen (and for Fascinate, from the same packet's Risen
# chapter). A book power the packet does not place is kept as an `alternatives` entry on the
# rung of its book tier. Names keep BE's existing spelling where the two are one power.
#
# OWBN0124-Wraith-Arcanoi-2016.pdf, Arcanos sections 1-16 plus "Risen Arcanoi".
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

# Behest has one ladder with two Hands at dots 3-4: Wraith: The Oblivion 2nd ed. (WW06600)
# p. 465-467, "There are two Hands in the art of Behest... The Right Hand seeks and finds. The
# Left Hand steers and strikes." Delve, Trace; Right Hand Scry, Divine ("Divine is the
# uppermost such ability"); Left Hand Twitch, Murmur, Veer. One Arcanos, not two fused ones:
# the Left Hand powers are alternatives on rungs 3 and 4, Veer is rung 5.
# Owner ruling 2026-09-22 (question 11): the book's "one must exercise both Hands equally" means both are bought, so Behest is **two families sharing Delve and Trace**, not one ladder with the Left Hand as alternatives. Wraith: The Oblivion 2nd ed. (WW06600) PDF p. 528-532 is the evidence for every rung:
#   * Delve (dot 1) and Trace (dot 2) carry no Hand label and open both - "There are two Hands in the art of Behest: location and manipulation".
#   * Scry (3) and Divine (4) are the Right Hand; of Divine: "Divine is the uppermost such ability for a Moriman dwelling in Stygia", with further Right-Hand powers only rumoured "in the Bush of Ghosts".
#   * Twitch and Murmur name themselves - "the first level of the Left Hand", "the second level of the Left Hand" - and both require a successful Scry first.
#   * Veer (dot 5) is labelled by neither Hand: "Through exertion of will and mastery of her Arcanos", and its own system requires Scry, a Right-Hand power. It is the Arcanos' capstone, so it closes both ladders. The other reading - Veer as Left-Hand-only, leaving the Right Hand one rung short of the declared ladder - is recorded as a residual question rather than filled with a guess.
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
# Base content is BE's seeded Arts (the Shining Host / Players Guide MET translations). The
# MET books print several Arts irregularly - "formatted others strangely, such as having one
# basic, one intermediate and two advanced" - and the OWBN Changeling Mechanics Packet
# (OWBN0019, 2017, ch. 5 p. 28-38) re-presents every Art "in the format of two basics, two
# intermediate and one advanced". Where the book cannot fill 2/2/1 the packet's arrangement
# settles it; the Dauntain Agendas come from their own tabletop source.
CHANGELING_LADDERS = {
    # Owner ruling 2026-09-22 (question 3): the OWBN packet decides. OWBN0019 ch. 5 p. 31, "Dreamcraft (Mental)": Intermediate is "Homestead" and "Attunement". Attunement is new to BE and arrives from the packet; Anchor and Dream Riding drop to alternatives on those rungs.
    'Dream Craft': [('basic', 'Walk the Silver Path'), ('basic', 'The Merry Dance'), ('intermediate', 'Homestead'),
                    ('intermediate', 'Attunement'), ('advanced', 'Dream Weaving')],
    # OWBN0019 p. 29-30: "Basic: Wyrd, Backwards Glance. Intermediate: Dreamtime, Permanency.
    # Advanced: Reversal of Fortune." Permanency fills the missing second Intermediate.
    'Chronos': [('basic', 'Wyrd'), ('basic', 'Backward Glance'), ('intermediate', 'Dream Time'),
                ('intermediate', 'Permanency'), ('advanced', 'Reversal of Fortune')],
    # OWBN0019 p. 33-34: "Basic: Seek'n'Spell, Rune. Intermediate: Runic Circle, Saining. Advanced:
    # Reweaving." Runic Circle moves to Intermediate, matching The Shining Host (WW05009) p. 136.
    'Naming': [('basic', "Seek'n'Spell"), ('basic', 'Rune'), ('intermediate', 'Runic Circle'),
               ('intermediate', 'Saining'), ('advanced', 'Reweaving')],
    # OWBN0019 p. 35: "Embrace of Morpheus is an invented LARP level. Storytellers may allow it
    # to be learned in place of another basic level... in order to keep this art at 5 levels,
    # Syncope should be made the second intermediate and Expiation should be left as the single
    # advanced art." Embrace of Morpheus becomes an alternative on a basic rung.
    'Oneiromancy': [('basic', 'Oneirodynia'), ('basic', 'Oneirocritia'), ('intermediate', 'Oneirataxia'),
                    ('intermediate', 'Syncope'), ('advanced', 'Expiation')],
    # The Dauntain Agendas have a MET source, which outranks their tabletop one: Laws of the
    # Hunt Revised (WW05014) p. 232-236 prints all three at 2/2/1 (samples/research/hunter/
    # mortal/resolved.json, dauntain_agendas). The tabletop Autumn People (WW07004) p. 51-52
    # (page-image OCR) agrees dot for dot, translated 1-2 Basic / 3-4 Intermediate / 5 Advanced.
    # Burnout - Mindblock, Heartbind / Obsession, Acquisition / Geek Out (LotH Revised p. 232).
    'Burnout': [('basic', 'Mindblock'), ('basic', 'Heartbind'), ('intermediate', 'Obsession'),
                ('intermediate', 'Acquisition'), ('advanced', 'Geek Out')],
    # Stultify - Rosetint, Dull Impulse / Proselytize, Procedural Addiction / Micro-Management
    # (LotH Revised p. 234; The Autumn People p. 52).
    'Stultify': [('basic', 'Rosetint'), ('basic', 'Dull Impulse'), ('intermediate', 'Proselytize'),
                 ('intermediate', 'Procedural Addiction'), ('advanced', 'Micro-Management')],
    # Webcraft - Weave Web, Overwhelming Wincing / Warp Will, Wend your Way / Cry "Woof" (LotH
    # Revised p. 236; the Changeling Players Guide (WW07100) errata reprints the tabletop text a
    # printer error dropped from The Autumn People).
    'Webcraft': [('basic', 'Weave Web'), ('basic', 'Overwhelming Wincing'), ('intermediate', 'Warp Will'),
                 ('intermediate', 'Wend your Way'), ('advanced', 'Cry "Woof"')],
}
CHANGELING_ALTERNATIVES = {
    # Rung -> alternative cantrips the sources print for that slot.
    'Oneiromancy': {1: [('Embrace of Morpheus', 'OWBN0019 p. 35: an invented LARP level, learnable in place of a basic')]},
    # The Shining Host (WW05009) p. 135 prints two Advanced Legerdemain cantrips (Phantom
    # Shadows, Rattle); OWBN0019 p. 32-33 keeps Phantom Shadows as the single Advanced.
    'Legerdemain': {5: [('Rattle', 'The Shining Host p. 135 prints it as a second Advanced; OWBN0019 p. 33 keeps Phantom Shadows alone')]},
    # The Shining Host p. 139-140 prints Holly Strike and Elder-Form both Advanced; OWBN0019
    # p. 35 folds Holly Strike into Heather Balm and names Primal Form the single Advanced.
    'Primal': {5: [('Elder-Form', 'The Shining Host p. 140 prints it as a second Advanced; OWBN0019 p. 35 names one Advanced (Primal Form)')]},
    # Dream Craft, settled by the packet (owner ruling 2026-09-22, question 3: "check owbn for which cantrips map"). OWBN0019 ch. 5, "Dreamcraft (Mental)" (p. 31) lists the Art in full: Basic "Find the Silver Path", "Determinism"; Intermediate "Homestead", "Attunement"; Advanced "Dreamweaving". So the two Intermediate rungs are Homestead and Attunement - not BE's Anchor - and Anchor and Dream Riding become the alternatives the packet leaves unplaced. The packet's own renaming of the basics is recorded as alternatives on rungs 1 and 2 rather than applied, so no held basic is renamed.
    'Dream Craft': {
        1: [('Find the Silver Path', 'OWBN0019 p. 31 names the first Basic this way; BE\'s "Walk the Silver Path" is the same cantrip under The Shining Host\'s name')],
        2: [('Determinism', 'OWBN0019 p. 31 prints this as the second Basic in place of "The Merry Dance"')],
        3: [('Anchor', 'BE\'s seeded third cantrip; OWBN0019 p. 31 does not place it, giving Homestead and Attunement as the two Intermediates')],
        4: [('Dream Riding', 'BE\'s seeded fifth cantrip; OWBN0019 p. 31 does not place it')],
    },
}
# Stigma is not an Art. The Autumn People p. 65-66: Stigmas are individual Dauntain marks
# gained "at the Storyteller's discretion", each costing a permanent Banality - no dots, no
# ladder, no order (BE's six are alphabetical, which is the tell). They move to their own
# unranked trait_list. Laws of the Hunt Revised p. 229-231 (MET, the higher source) prints
# nine: BE's six plus Herd Mentality, Ravage and Shunt.
CHANGELING_STIGMAS = [
    ('Conversion', 229), ('Disbelief', 229), ('Erasure', 230), ('Hatred', 230), ('Herd Mentality', 230),
    ('Iron Ward', 230), ('Numb', 230), ('Ravage', 231), ('Shunt', 231),
]

# --- Mummy: Hekau and formulae (1.3.0 D5, D6) ------------------------------------------
#
# Laws of the Resurrection (WW05035) p. 120, "Working Magic": "A path rating of 1 or 2 lets
# the character learn Basic effects in that Hekau; a path rating of 3 or 4 lets the character
# learn Intermediate effects; a path rating of 5 lets the character learn Advanced effects...
# there are separate experience costs for raising path ratings and learning rituals and
# spells." p. 116: Hekau 3/6/9, "New spell or ritual - One Experience for Basic, three for
# Intermediate and five for Advanced", +1 outside the primary path for both.
#
# So a Hekau's ladder is its path rating (five generic rungs) and every named spell/ritual is a
# separate purchase - the `mummy-formulae` trait_list. The GVM holds each path twice (a
# "Hekau, Levels" rung stub and a second-edition formula menu); the "(2nd)" names Ren-Hekau and
# Ushabti are the Egyptian names of Nomenclature and Effigy (research/mummy/NOTES.md, core
# book: "the magical Hekau path of Effigy (called Ushabti in ancient Egypt)").
MUMMY_PATHS = ['Alchemy', 'Amulets', 'Celestial', 'Effigy', 'Necromancy', 'Nomenclature']
MUMMY_ALIASES = {'Ren-Hekau': 'Nomenclature', 'Ushabti': 'Effigy'}
MUMMY_RUNGS = [('basic', 'First Basic Path'), ('basic', 'Second Basic Path'),
               ('intermediate', 'First Intermediate Path'), ('intermediate', 'Second Intermediate Path'),
               ('advanced', 'Advanced Path')]
MUMMY_FORMULA_COST = {'basic': '1', 'intermediate': '3', 'advanced': '5'}
# A formula's note names the rung it needs: "first basic" is path rating 1, "second basic" 2,
# "first int." 3, "second int." 4, "adv." 5 (the book's "prerequisite of 2 or 4" for "almost
# Intermediate/Advanced" effects, p. 120). A bare "basic"/"int."/"adv." spell note (the
# second-edition dot names such as Ren-Hekau's "Simple Names") needs the first rung of its tier.

# --- Werewolf / Fera Gifts --------------------------------------------------------------
#
# Authored as tiered_power (owner ruling 2026-09-21) and pick-only: every Gift is bought by
# name, gaps are legal per category and per rank (1.2.10 section B), so `ladder` is declared
# empty. Ranks basic/intermediate/advanced at 3/6/9, +1 out of type, levels 1/3/5
# (MET-POWER-ACQUISITION.md; 1.2.10 acceptance table).
#
# D3 - tribe name join. werewolf-identity says "Bone Gnawers", the gift menu "Bone Gnawer";
# every other tribe is plural in both, so the gift side takes the identity spelling.
# werewolf-identity also offers "Fenrir" beside "Get of Fenris" - the tribe's own name for
# itself, one tribe - recorded as an option alias rather than removed, so a held "Fenrir" keeps
# rendering. Croatan and White Howlers are extinct tribes with no gift menu: legitimately empty.
WEREWOLF_GROUP_RENAME = {'Bone Gnawer': 'Bone Gnawers'}
# Owner ruling 2026-09-22 (question 6, option b): `Fenrir` is removed from the Tribe options - the real value is Get of Fenris - and stays as an alias so held data still resolves. Measured on local be_dev the same day: **0 characters hold "Fenrir"**, but 30 hold "Bone Gnawer" and 18 "Silver Fang", singular forms that match no option either, so they are aliased on the same rule and migrate with it (1.3.2 carry-forward, forward-only).
WEREWOLF_TRIBE_ALIASES = {'Fenrir': 'Get of Fenris', 'Bone Gnawer': 'Bone Gnawers', 'Silver Fang': 'Silver Fangs'}
WEREWOLF_TRIBE_DROP = {'Fenrir'}
# Hakken and Hengeyokai (Beast Courts) and the Wyld West / Dark Ages printings are variants
# (format section 4b), not base content.
BEAST_COURTS_GROUPS = {'Hakken', 'Hengeyokai'}

# --- Mortal: backgrounds from the resolved research (C6, the Mortal slice) -----------------
#
# samples/research/hunter/mortal/resolved.json holds 36 backgrounds; 32 are already in
# mortal-backgrounds under the same or a variant spelling (Influence: Politics = BE's
# "Influence: Political", Mana Pool = "Mana-Pool", Influence: Espionage/Military = BE's bare
# "Espionage"/"Military"). These four are genuinely absent - all from the OWBN Hunter:
# Inquisition Packet (2021), which outranks every book (section 2).
MORTAL_BACKGROUND_ADDITIONS = [
    ('Mob', 'OWBN Hunter: Inquisition Packet (2021), p. 7'),
    ('Reliquary', 'OWBN Hunter: Inquisition Packet (2021), p. 7'),
    ('Status', 'OWBN Hunter: Inquisition Packet (2021), p. 8'),
    ('Flock', 'OWBN Hunter: Inquisition Packet (2021), p. 8'),
]

# --- Abilities: one merged block per creature type (D81) ---------------------------------
#
# Owner ruling 2026-09-22, D20's Backgrounds precedent: the single shared met-abilities (the
# generic GVM "Abilities" menu only) becomes one `{stack}-abilities` block per stack - the
# generic menu, that creature's own "Abilities, X" menu with its includes resolved, and the
# met-mechanics.csv Ability rows. Bete reuses Fera's block, as D20 did for backgrounds.
# "Abilities, Hunter" is not a stack (the real list has eleven, no hunter) and "Abilities,
# Item" is a weapon-property menu, not character Abilities: neither is emitted.
ABILITY_MENUS = {
    'vampire': 'Abilities, Vampire', 'werewolf': 'Abilities, Werewolf', 'fera': 'Abilities, Fera',
    'mage': 'Abilities, Mage', 'changeling': 'Abilities, Changeling', 'wraith': 'Abilities, Wraith',
    'demon': 'Abilities, Demon', 'mummy': 'Abilities, Mummy', 'kueijin': 'Abilities, Kuei-Jin',
    'mortal': 'Abilities, Mortal',
}
ABILITY_BLOCK_FOR_STACK = dict({s: f'{s}-abilities' for s in ABILITY_MENUS}, bete='fera-abilities')

# Every MET book prices an Ability at one XP per Trait; each block cites its own genre's book.
# PDF page numbers. Laws of the Night Revised's chart (p. 124) does not extract, so Vampire
# cites it through met-mechanics.csv's own General Ability rows, which record it.
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

# A misspelled CSV row, dropped and kept as an alias of the real Ability so a held
# "Meditiation" re-keys. (Vamp is real - MET Storyteller's Guide, "Sedutor" in pt_BR.)
ABILITY_TYPO_ALIASES = {'Meditiation': 'Meditation'}

# Grapevine offers Lore as a submenu (`<submenu name="Lore" link="Lores, Vampire"/>`), which
# the seeder dropped - so Lore vanished from every catalog. It becomes ONE item per block,
# priced as any Ability, with the genre's Lores menu as suggested `specializations`.
# Flattening: a Lores menu's own items are specializations as written ("Kindred"); a nested
# submenu contributes "<Submenu>: <item>" for each item of the menu it links ("Clan: Brujah"),
# plus "<Submenu>: <name>" for any submenu one level further down ("Fera: Bastet") - which is
# exactly the form Grapevine writes on a sheet ("Lore: Clan: Assamite", Hitchens' .gex).
# Mage's second submenu "RD Data" links the same Lores menu: it is the Technocracy's name for
# Lore, so it is recorded as an alias, not a second item.
LORE_ALIASES = {'mage': ['RD Data']}

# --- Merits and Flaws: one merged block per creature type (D82) ------------------------
#
# Owner ruling 2026-09-22 on the gap audit: the shared met-merits / met-flaws (the generic GVM
# menus plus every met-mechanics.csv Merit/Flaw row) become `{stack}-merits` / `{stack}-flaws`,
# exactly as D81 did Abilities. The Vampire clan menus (Assamite, Brujah, Giovanni, Lasombra,
# Malkavian, Nosferatu, Tremere, Tzimisce, Ventrue) and Gangrel Animal Traits ride in on
# Merits/Flaws, Vampire as submenus and land in vampire-merits / vampire-flaws with the clan in
# `group` - merits are this release's blocks, not 1.3.1's. Includes are resolved as Grapevine
# declares them (Fera includes Werewolf's, Kuei-Jin includes Vampire's); submenus are flattened
# for the stack's own menu only, so Kuei-Jin does not inherit Vampire's clan lists.
MERIT_MENUS = {s: (f'Merits, {m}', f'Flaws, {m}') for s, m in {
    'vampire': 'Vampire', 'werewolf': 'Werewolf', 'fera': 'Fera', 'mage': 'Mage', 'changeling': 'Changeling',
    'wraith': 'Wraith', 'mummy': 'Mummy', 'kueijin': 'Kuei-Jin', 'mortal': 'Mortal'}.items()}
MERIT_MENUS['demon'] = ('Merits', 'Flaws')          # no Demon menu in the GVM: generic only
MERIT_BLOCK_FOR_STACK = dict({s: s for s in MERIT_MENUS}, bete='fera')

# A submenu the generic Flaws menu links is not a flaw list: Derangements are their own
# section (met-derangements) on every stack.
MERIT_SKIP_SUBMENUS = {'Derangement'}

# met-mechanics.csv's 812 Merit/Flaw rows are the Vampire research overlay - every source but
# two is a Vampire book or OWbN clan packet - and the old shared block handed all of them to
# every creature type. Routed by source: a row whose name is a generic-menu item only adds its
# source and (where the GVM has none) its cost to that generic item; the two Laws of the Wild
# Revised rows go to Werewolf and Fera; everything else goes to Vampire.
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
# After the genre's own files, every other captured Merits/Flaws file, in this order: a generic
# Merit (Blase, Lightning Calculator) prints at one cost across the MET line, so another genre's
# book is still evidence. The citation says which book it came from.
MERIT_RESEARCH_ANY_GENRE = [
    'hunter/mortal/resolved.json', 'fera/resolved.json', 'kuei-jin/merits-flaws.json',
    'changeling/owbn/mechanics-packet-merits-flaws.json', 'mummy/core/merits-flaws.json',
    'mummy/core/players-guide.json', 'demon/owbn/merits-flaws.json', 'wraith/owbn/risen-packet.json',
    'vampire/*/*merits-flaws*.json',
]

# Costs read from the books for GVM items nothing else prices (PDF pages; the OCR'd tabletop
# text reads "l"/"t" for 1).
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
# prices. Each is named with its reason, so none is silently unpriced (1.3.0 owner question 16).
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

# --- Identity dropdowns from their GVM menus (gap-audit class B) --------------------------
#
# Owner ruling 2026-09-22: each listed field becomes a `select` built from its GVM menu, with
# `allow_custom: true` so every value a chronicle has already typed stays valid. Measured on
# local be_dev before converting - no held value is lost, and the ones that do not match an
# option (Kuei-Jin "Balanced"/"Wrathful", Mummy "Aided of Anpu", free-text Motivations, a
# chronicle's own Titles) survive as custom entries.
#
# NOT converted, deliberately:
#   * `werewolf-identity.Rank` and `fera-identity.Rank` are **number** fields holding 1-5 on 81
#     local characters, while the GVM menus hold names (Cliath, Fostern...) or the generated
#     "Rank 1".."Rank 5" per species. Converting would strand every held value. Owner questions.
#   * `Pack` is a pack's own name - the GVM has no Pack menu at all, which settles it.
#   * `wraith-identity.Ethnos` is not given the Spectre sub-types (Malfean, Mortwight...): they
#     would mix two different axes in one field. Owner question.
#   * Wraith Rank, Kuei-Jin Station's own menu and Werewolf Camp need *new* fields; new fields
#     are 1.3.2 (the owner's own split for Camp and Position). Station's field already exists,
#     so it is converted.
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
# The GVM writes the Legions with a leading article ("The Emerald Legion"); every other source
# and the held data write them without it (OWBN0125 Necropolis Creation names the eight Legions
# bare). The article is dropped so a held "Emerald Legion" matches its own option.
IDENTITY_OPTION_STRIP_ARTICLE = {('wraith-identity', 'Legion')}

# --- Rites: the category menus and the Fera species menus (gap-audit class D) --------------
#
# Owner ruling 2026-09-22, pulled forward from 1.3.2. `werewolf-rites` held only the eight
# tribal menus; Grapevine's "Rites, Werewolf" also lists ten category submenus nobody seeded,
# and "Rites, Fera" lists twelve species menus (two of them - Bastet and Mokole - nesting
# their own categories).
#
# **Fera gets its own block.** Decision 046 split fera-gifts from werewolf-gifts precisely
# because the two catalogs are lists a character can never pick from at once, and the Fera
# stack's own Rites section pointed at Werewolf's only because nothing else existed. So
# `fera-rites` is a new block, the Fera and Bete stacks and templates point at it, and the
# Garou rites stay Garou. Recorded as an owner question all the same (the other reading is one
# shared block with a species `group`, which is what the Rites, Fera menu itself does by
# including Rites, Werewolf as its "Garou" submenu).
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
# Pure tribes) are Grapevine's own two extra groupings and are kept as categories, since the
# tier and cost sit on the item either way.
RITE_CATEGORIES = ['Accord', 'Caern', 'Death', 'Mystic', 'Punishment', 'Renown', 'Seasonal', 'Minor',
                   'Frontier', 'Pure Ones', 'Wallow', 'Zhong Lung', 'Gumagan', 'Kuasha', 'Taghairm',
                   'Moon', 'Need', 'Hishtpah']

# A rite the GVM leaves unpriced that is not a Minor rite (Minor rites are correctly unpriced -
# bought with the Rites Background, LotW Revised p. 174's own rule).
RITE_UNPRICED = {
    'Rite of Crash Space': 'UNPRICED: the GVM carries no cost and the Ratkin book is not captured - 1.3.0 owner question 16',
}

# --- Backgrounds bought per individual (owner ruling 2026-09-22) ---------------------------
#
# `allow_multiples` becomes a per-item flag with the block-level value as the default: when it
# is on, the editor asks "Who or what?" and keeps each answer as its own row via
# `specialization`. The code is 1.3.2; 1.3.0 only marks the catalog. The test is the owner's:
# a Background that names a specific person, place or thing, so a character can hold several.
BACKGROUND_MULTIPLES = [
    # The five the ruling names.
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
# Deliberately NOT marked - each is one rating, not a set of named things, or the call is the
# owner's: Ancestors, Past Life/Past Lives, Memory, Mnesis, Totem, Rank, Status, Prestige,
# Arsenal, Equipment, Requisitions, Secret Weapons, Information Network, Journal, Husk, Dross,
# Legacy, Generation, Influence and every Influence: X (an Influence rating is one track per
# sphere, and the sphere is already the item).

# --- Abilities bought per field of study (owner ruling 2026-09-22) -------------------------
#
# The owner's distinction, which this project had blurred: a **specialization labels one
# holding** (`Brawl 5 (Wrestling)` is still one Brawl, and a different focus must not buy a
# second Brawl), while **`allow_multiples` makes the label part of the holding's identity**
# (`Lore: Clan: Assamite` and `Lore: Nod` are separate purchases). Identity rule: name alone,
# unless the item carries `allow_multiples`, in which case name + label. The editor fix that
# reads it is 1.2.11; 1.3.0 only marks the catalog.
#
# Evidence, local be_dev: Hitchens holds 24 separate Lores, two Crafts (Body Crafts, Shadow
# Crafting) and two City Secrets (Boston, NYC) - but exactly one Brawl, one Melee, one Dodge.
ABILITY_MULTIPLES = [
    # The owner's seven. "City Secrets" is in no GVM menu - Hitchens holds it as a custom row -
    # so it is listed here and will take effect if it is ever added to the catalog.
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
# Deliberately NOT marked - each reads as one holding with a focus label, which is what
# `has_specializations` is for, or the call is the owner's (1.3.0 owner question 23):
# Academics, Occult, Medicine, Medical Knowledge, Thanatology, Divination, Technology, Research,
# Instruction, Pilot, Do, Kenning, Rituals, Seamanship, Herbalism, Commerce, Expression (and
# Mummy's "Expression, Kipu", which is already one named entry), Black Hand Knowledge,
# Masquerade, Koldunism, Soulforging, Primal Urge. Mage's **Esoterica**, which the owner named,
# is in no GVM menu at all - it arrives with C6's Mage research, and gets marked then.

# --- mortal-numina splits into real blocks (owner ruling 2026-09-22, decision 8) -----------
#
# Grapevine's `Numina` menu is a container of fourteen submenus, and every one of the 356 live
# families maps to exactly one of them (measured: zero unmapped). Five of the fourteen are
# **copies of other stacks' catalogues** - Gifts, Disciplines, Arts, Realms and their 1,285
# overflow levels - and those are not re-emitted: the Mortal stack references the real blocks
# instead, which is what a ghoul, a kinfolk or a kinain is actually buying. The rest is real
# Mortal content that never had a block of its own.
NUMINA_REFERENCED_BLOCKS = {          # container submenu -> the block that really owns it
    'Gifts': ['werewolf-gifts', 'fera-gifts'],
    'Disciplines': ['vampire-disciplines'],          # 1.3.1's block
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
# formulae list under their own group rather than inventing a one-family block.
NUMINA_FORMULAE_EXTRA = {'Benandanti Rituals': 'Benandanti'}

# What each container section becomes on its stack. A section is replaced in place, keeping its
# display order band, and the copies become references to the blocks that really own them
# (decision 8) - a ghoul buys Disciplines, a kinfolk Gifts, a kinain Arts.
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
    # Decision 9: the Demon stack pointed at the nine flat `demon-lores` while the real content -
    # 139 Evocations across 23 Lores, and 65 Rituals - sat in files with no section.
    'demon': {'demon-lores': [('demon-evocations', 'Lores (Evocations)'), ('demon-rituals', 'Rituals')]},
    'kueijin': {},
}
# kueijin-shintai is new content on a stack whose own power block belongs to 1.3.1; the section
# is added rather than replacing anything.
STACK_EXTRA_SECTIONS = {'kueijin': [('kueijin-shintai', 'Shintai', 62)]}
