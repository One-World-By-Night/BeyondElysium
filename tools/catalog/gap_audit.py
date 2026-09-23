#!/usr/bin/env python3
"""Read-only gap audit: every Grapevine menu against the seeded and declared catalog.

    tools/catalog/dump_live.sh                     # local be_dev -> out/live (never production)
    tools/catalog/gap_audit.py [--json out.json] [--catalog DIR ...]

For every GVM menu that carries items, counts how many of its items appear nowhere in the
catalog, twice: RAW (exact lowercase name) and NORMALISED (case, punctuation, "Rite of" /
"The " prefixes, trailing markers, `Base: Spec` / `Base (Spec)` forms, known aliases). The
catalog is the union of the live dump (`out/live/blocks`) and every declared catalog
directory given (default: this branch's `data/catalog/blocks`, read only). Then it classifies
each menu with a residual gap (A-F, see CLASS_RULES) and counts how
many local characters hold one of its missing items today as a custom entry.

Writes nothing but its own report. Reproduces `BE_PROCESS/releases/1.3-catalog-gap-audit.md`.
"""

import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from collections import defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent
CODE = HERE.parents[1]
GVM = CODE / 'beyond-elysium' / 'data' / 'Grapevine Menus XML.gvm'
LIVE = HERE / 'out' / 'live' / 'blocks'
DEFAULT_CATALOGS = [
    CODE / 'beyond-elysium' / 'data' / 'catalog' / 'blocks',
]
CATEGORY = {None: 'generic', '2': 'vampire', '3': 'werewolf', '4': 'mortal', '5': 'changeling', '6': 'wraith',
            '7': 'mage', '8': 'fera', '9': 'various', '10': 'mummy', '11': 'kueijin', '12': 'hunter', '13': 'demon'}
MY = ['mysql', '-h127.0.0.1', '-P3307', '-ube_dev', '-pbe_dev_pw', 'be_dev', '-N', '--raw', '-e']

# Aliases the catalog itself records, plus spelling pairs confirmed by reading both sides.
KNOWN_ALIASES = {'meditiation': 'meditation', 'renhekau': 'nomenclature', 'ushabti': 'effigy',
                 'rddata': 'lore', 'fenrir': 'getoffenris'}


def norm(s):
    s = (s or '').lower().strip()
    s = re.sub(r'[*^†#]+$', '', s).strip()
    s = re.sub(r'\s*\[[^\]]*\]\s*$', '', s)                      # trailing [R2]-style markers
    s = re.sub(r'^(rite|ritual|rites) of (the )?', '', s)
    s = re.sub(r'^(the|a|an) ', '', s)
    s = re.sub(r',\s*(the|a|an)$', '', s)                           # "Touch of Nightshade, A"
    s = s.replace('&', 'and')
    k = re.sub(r'[^a-z0-9]', '', s)
    return KNOWN_ALIASES.get(k, k)


def variants(name):
    """Every key a catalog or GVM name can match under: itself, and the Base of Base: Spec /
    Base (Spec) / "Base, Spec" (a GVM spelling of a specialised trait)."""
    keys = {norm(name)}
    for pat in (r'^(.+?):\s+(.+)$', r'^(.+?)\s*\((.+)\)\s*$', r'^(.+?),\s+(.+)$'):
        m = re.match(pat, name or '')
        if m:
            keys.add(norm(m.group(2)))                            # "Clan: Brujah" -> Brujah
    return {k for k in keys if k}


def catalog_names(dirs):
    raw, normed, where, lore_specs = set(), set(), defaultdict(set), set()

    def add(n, slug, top=True):
        if not isinstance(n, str) or not n:
            return
        if top:                                                   # RAW = the stored name, verbatim
            raw.add(n.lower().strip())
        for k in variants(n):
            normed.add(k)
            where[k].add(slug)
        m = re.match(r'^(.+?):\s+(.+)$', n)                       # "Thaumaturgy: Craft Bloodstone (basic)"
        if m:
            add(m.group(2), slug, False)
        stripped = n
        while re.search(r'\s*\([^()]*\)\s*$', stripped):            # "X (T) (Intermediate)", "X (int)"
            stripped = re.sub(r'\s*\([^()]*\)\s*$', '', stripped)
            if stripped:
                add(stripped, slug, False)
        for part in n.split(' / '):                                   # "A / Scorpion's Sting"
            if part != n:
                add(part, slug, False)

    def walk_levels(lst, slug):
        for x in lst or []:
            if isinstance(x, dict):
                add(x.get('power_name') or x.get('name'), slug)
                for a in x.get('alternatives') or []:
                    add(a.get('power_name'), slug)
            else:
                add(x, slug)

    for d in dirs:
        for f in sorted(Path(d).glob('*.json')):
            data = json.load(open(f))
            slug = data.get('slug') or f.stem
            df = data.get('definition') or {}
            add(data.get('name'), slug)
            for it in df.get('items') or []:
                add(it.get('name'), slug)
                for a in it.get('aliases') or []:
                    add(a, slug)
                # Lore's suggested specializations cover the Lores menus only (D81): "House:
                # Aesin" as a Lore suggestion must not count as populating changeling-identity's
                # empty House field.
                for sp in it.get('specializations') or []:
                    lore_specs.update(variants(sp))
            powers = df.get('powers') or []
            for p in (powers.values() if isinstance(powers, dict) else powers):
                add(p.get('name'), slug)
                for a in p.get('aliases') or []:
                    add(a, slug)
                for alt in (p.get('traditions') or {}).values() if isinstance(p.get('traditions'), dict) else []:
                    add(alt, slug)
                walk_levels(p.get('levels'), slug)
                walk_levels(p.get('overflow'), slug)
                for v in (p.get('elder') or {}).values():
                    walk_levels(v, slug)
            for fld in df.get('fields') or []:
                add(fld.get('name'), slug)
                for o in fld.get('options') or []:
                    add(o, slug)
                for a in (fld.get('option_aliases') or {}):
                    add(a, slug)
            for c, trio in (df.get('clan_disciplines') or {}).items():
                add(c, slug)
            for pool in df.get('pools') or []:
                add(pool.get('name'), slug)
    return raw, normed, where, lore_specs


def gvm():
    return {m.get('name'): m for m in ET.parse(GVM).getroot().iter('menu')}


# --- Classification ---------------------------------------------------------------------------
# First matching rule wins. Each rule: (class, regex on menu name, target, note). Targets name a
# proposed block/field; they are proposals for the owner, not decisions.

CLASS_RULES = [
    # F - needs an owner or source call; the note says what would settle it
    ('F', r'^(Threshold|Bardic Gift)$', 'changeling kith list?', 'Settle: read The Shining Host / Players Guide - are Thresholds and Bardic Gifts purchasable kith traits or descriptive flavour?'),
    ('F', r'^(Fronds|Psyche|Status, Wraith)$', 'wraith Shadow/Psyche list?', 'Settle: Oblivion\'s Shadow chapter - are Fronds a purchasable Shadow list, and does BE model the Shadow at all?'),
    ('F', r'^Totem Powers$', 'werewolf totem properties?', 'Settle: owner - do Totems become a catalog with powers, or stay a free-text identity value?'),
    ('F', r'^Longing$', '?', 'Settle: identify the source (a mortal Numina/Merit list?) - two unmatched items'),
    ('F', r'^Gangrel Animal Trait$', 'vampire clan flaw list?', 'Settle: 1.3.1 (Vampire line) - clan weakness traits as a Flaw list or a clan-identity property'),
    ('F', r'^Path of Caine$|^Path of (Cathari|Paradox|Typhon|the Warrior|Death and the Soul|Harmony|Honorable Accord|Power and the Inner Voice|Ecstacy)$|^Beast Traits, Path of Blood$|^(Aura|Rage|Control|Courage)$',
     'vampire Path of Enlightenment detail', 'Settle: 1.3.1/owner - does BE model per-Path ethics, Beast traits and frenzy triggers, or only the Path name (vampire-identity.Morality Path already has 103 options)?'),
    ('D', r'^Humanity$', 'mortal-humanity-traits (trait_list) - the named Humanity adjectives, like vampire-statuses', None),
    ('B', r'^Spectre$', 'wraith-identity.Ethnos (Spectre sub-types as options)', None),
    # E - out of scope by design
    ('E', r'^(Abilities, Item|Equipment|Weapons?|Armor|Items?|Locations?|Vehicles?|Fetish(es)?|Talens?|Treasures?|Wonders|Artifacts?|Relics?|Charms, Item|Bullets|Ammunition|Money|Currency)\b', None,
     'World objects - catalogued as World Objects (Decision 105), not character traits'),
    ('E', r'^(Status Traits?|Game|Chronicle|Players?|Experience|Actions?|Downtime|Influence Actions|Challenge|Tests?|Retests?|Months|Days|Colors?|Print|Sheet|Grapevine)\b', None,
     'Grapevine-internal or play-procedure list, not catalog content'),
    ('E', r'^(Health Levels?|Health)\b', None, 'Already seeded as the per-stack {stack}-health blocks (v0.99.23)'),
    ('E', r'^(Nature|Demeanor|Archetypes?)(,.*)?$', None, 'met-archetypes carries Nature/Demeanor'),
    ('E', r'^(Status, (Character|Player)|Position, Player|Access|Availability|Concealability|Damage|Duration|Negative Traits, Item|Location Types|Item (Sub)?[Tt]ypes|Tempers|Boons)$', None,
     'Grapevine record/print field, item property or a BE feature of its own (boons have Boons_Controller) - not a catalog trait'),
    ('E', r'^(Plane|Affinity|Banes|Class|Subclass)$', None,
     'Grapevine\'s "Various" creature record (spirits, banes, fomori NPC stat blocks) - NPC reference data, not a PC catalog'),
    ('E', r'^Doom$', None, 'Dauntain dooms - Dauntain content ruled out of scope 2026-09-20'),
    ('E', r'^Resonance$', None, 'Mage effect descriptor, reference not purchase'),
    ('A', r'^Quirk$', 'met-derangements (trait_list)', 'Grapevine\'s Quirk is a derangement tier; 11 of its 20 are already in met-derangements'),
    ('B', r'^(Station|Balance)$', 'kueijin-identity (Station is Dharma rank; Balance already a field, as Yin/Yang options)', None),
    ('B', r'^Group$', 'mortal-identity.Association (options)', None),
    ('B', r'^Capachoca$', 'mummy-identity.Amenti (Teomallki bloodlines as options)', None),
    ('B', r'^Revenant$', 'mortal-identity (Revenant family, a new field) - ghoul families', None),
    ('B', r'^Path of Caine$|^Path of (Cathari|Paradox|Typhon|the Warrior|Death and the Soul|Harmony|Honorable Accord|Power and the Inner Voice|Ecstacy)$|^Beast Traits, Path of Blood$|^(Aura|Rage|Control|Courage)$', 'vampire-identity (Path of Enlightenment) with per-Path Beast traits / frenzy triggers - Vampire is 1.3.1\'s line', 'Road/Path detail; route to 1.3.1'),
    ('D', r'^(Alchemy|Amulets|Celestial|Effigy|Necromancy|Nomenclature), (Rituals|Spells)$', 'mummy-formulae (trait_list) - Laws of the Resurrection\'s own spells and rituals (owner question 7)', None),
    ('D', r'^(Shamash|Nusku|Mummu|Antu|Mammetum|Adad|Ereshkigal|Nedu|Nergal|Ninsun|Ninurtu|Qingu|Zaltu|Anshar|Aruru|Bel|Dagan|Ellil|Ishhara|Namtar|Kishar)$', 'demon-form-powers (tiered_power picks, per Visage) - cost rule in the Fallen packet ch. 2, unread', 'Demon Visage Form Powers'),
    ('D', r'^(Contaminate(, Great War)?|Corruption|Hive-Mind|Shroud-Rending|Tempest Weaving|Larceny|Maleficence|Tempestos)$', 'wraith-dark-arcanoi (tiered_power, Spectre/NPC) - OWBN0124 prices 4/7/10', 'Dark Arcanoi'),
    ('D', r'^(Fronds|Psyche|Status, Wraith)$', 'wraith Shadow traits (trait_list)', 'Shadow/Psyche lists'),
    ('D', r'^(Glory|Honor|Wisdom)(, Fera)?$|^Status, (Mummy|Changeling)$|^Reputation$', '{werewolf,fera,mummy,changeling,mage}-renown-traits (trait_list) - the named Renown/Status adjectives, like vampire-statuses', 'Renown/Status adjectives'),
    ('D', r'^(Martyrdom|Redemption|Vengeance|Visionary|Defense|Innocence|Judgment|Deviance|Isolation)$', 'hunter-edges (tiered_power) - there is no Hunter stack (2026-09-20 ruling keeps Hunter out)', 'Hunter creed Edges'),
    ('D', r'^(Metis Deformities|Scar)$', 'werewolf-metis-deformities / werewolf-battle-scars (trait_list)', None),
    ('D', r'^Fomori Taints$', 'mortal-fomori (with Fomori Powers; mortal-numina split, owner question 8)', None),
    ('D', r'^Archid Traits$', 'fera-archid-traits (trait_list, Mokole)', None),
    ('D', r'^(Fae Gifts|Fae Marks)$', 'mortal-kinain (trait_list, Kinain gifts/marks)', None),
    ('D', r'^(Synergy|Chi|Longing)$', 'mortal-psychic / mortal-* split of mortal-numina', None),
    ('D', r'^(Devil-Tiger Li|Bone Flower Li|Dark Jade Lover|Resplendent Crane Li|Individual)$', 'kueijin-rites (trait_list) - the Dharma-specific and Individual rites', None),
    ('D', r'^Totem Powers$', 'werewolf totem properties (trait_list, with the Totem option list)', None),
    ('D', r'^Gangrel Animal Trait$', 'vampire clan Flaw-like list (trait_list) - Vampire is 1.3.1\'s line', None),
    ('D', r'^(Threshold|Bardic Gift)$', 'changeling kith lists: Thresholds (Kithain), Bardic Gifts (Satyr) (trait_list)', None),
    # A - per-creature Merits / Flaws / Derangements, D20/D81 shape
    ('A', r'^(Merits|Flaws)(, .+)?$', '{stack}-merits / {stack}-flaws (trait_list), generic + creature menu, as D20/D81', 'per-creature Merits/Flaws never merged into met-merits/met-flaws'),
    ('A', r'^Derangements(, .+)?$', 'met-derangements or {stack}-derangements (trait_list)', 'per-creature derangements'),
    ('A', r'^(Backgrounds|Influences)(, .+)?$', '{stack}-backgrounds (trait_list)', 'D20 already merged these; residual is drift or new items'),
    ('A', r'^(Physical|Social|Mental)(, .+)?$', 'met-*-traits (trait_list)', 'attribute trait lists'),
    ('A', r'^Abilities(, .+)?$', '{stack}-abilities (trait_list)', 'D81'),
    ('A', r'^Lores?(\b.*)?$', '{stack}-abilities Lore specializations', 'D81 carries these as Lore specializations'),
    # B - identity options
    ('B', r'^(Clan|Bloodline)s?(, .+)?$', 'vampire-identity.Clan', None),
    ('B', r'^Sect(, .+)?$', 'vampire-identity.Sect', None),
    ('B', r'^(Title|Titles)(, .+)?$', '{stack}-identity.Title (new field) or position-presets', None),
    ('B', r'^(Position|Positions)(, .+)?$', 'position-presets (already a preset) / {stack}-identity.Position', None),
    ('B', r'^(Rank|Ranks)(, .+)?$', '{stack}-identity.Rank', None),
    ('B', r'^(Tribe|Tribes)(, .+)?$', 'werewolf-identity.Tribe', None),
    ('B', r'^(Auspice|Auspices)(, .+)?$', '{werewolf,fera}-identity.Auspice', None),
    ('B', r'^(Breed|Breeds)(, .+)?$', '{werewolf,fera}-identity.Breed', None),
    ('B', r'^(Camp|Camps)(, .+)?$', 'werewolf-identity.Camp (new field)', None),
    ('B', r'^(Totem|Totems)(, .+)?$', 'werewolf-identity.Totem (a field today with no options)', None),
    ('B', r'^(Pack)(, .+)?$', 'werewolf-identity.Pack', None),
    ('B', r'^(Kith|Seeming|Court|House|Legacy|Legacies|Title, Changeling)(, .+)?$', 'changeling-identity (Kith/Seeming/Court/House/Legacy)', None),
    ('B', r'^(Tradition|Traditions|Convention|Conventions|Technocracy|Disparates|Craft|Crafts|Essence|Faction, Mage|Affiliation)(, .+)?$', 'mage-identity (Tradition/Essence/Faction)', None),
    ('B', r'^(Guild|Guilds|Legion|Legions|Ethnos|Faction, Wraith|Shadow Archetypes?|Circle)(, .+)?$', 'wraith-identity (Guild/Legion/Ethnos/Faction)', None),
    ('B', r'^(House, Demon|Faction, Demon|Houses?, Demon|Visage)(, .+)?$', 'demon-identity (House/Faction)', None),
    ('B', r'^(Dharma|Dharmas|Direction|Directions|P.o Archetype|Court, Kuei-Jin)(, .+)?$', 'kueijin-identity (Dharma/Direction)', None),
    ('B', r'^(Amenti|Dynasty|Dynasties|Cult|Cults|Mallki|Teomallki)(, .+)?$', 'mummy-identity (Amenti/Dynasty/Cult)', None),
    ('B', r'^(Fera|Changing Breeds?|Species)(, .+)?$|^(Ananasi|Bastet|Corax|Gurahl|Kitsune|Mokole|Nagah|Nuwisha|Ratkin|Rokea)(, (Tribes?|Aspects?|Breeds?|Factions?|Varnas?|Crowns?|Courts?|Streams?|Kin|Stirps?))?$', 'fera-identity (Fera Type/Breed/Auspice)', None),
    ('B', r'^(Faction|Factions|Association|Motivation|Creed|Creeds|Conspiracy|Organizations?|Society|Societies|Order|Orders)(, .+)?$', '{stack}-identity (Faction/Association/Motivation)', None),
    ('B', r'^(Generation|Path|Paths|Road|Roads|Virtues?|Morality|Humanity)(, .+)?$', 'vampire-identity (Path of Enlightenment) / vampire-virtues', None),
    # D - genre power/trait lists never seeded
    ('D', r'^(Spirit Charms?|Charms)(, .+)?$', 'werewolf-spirit-charms (trait_list), for Werewolf/Fera spirits', None),
    ('D', r'^(Fomori Powers?|Fomor)(, .+)?$', 'mortal-fomori-powers (trait_list)', None),
    ('D', r'^(Rituals?|Ritae|Auctoritas|Ignoblis|Pisanob Necromancy|Revenant Creation)(, .+)?$', 'vampire-rituals (1.3.1\'s block)', 'owned by 1.3.1'),
    ('D', r'^(Rites?)(, .+)?$', '{werewolf,kueijin}-rites (trait_list)', None),
    ('D', r'^(Spells?|Formula|Formulae|Hekau)(, .+)?$', 'mummy-formulae (trait_list)', None),
    ('D', r'^(Numina|Psychic|Sorcery|Hedge Magic|Theurgy|True Faith|Benandanti)(\b.*)?$', 'mortal-* split of mortal-numina (owner question 8)', None),
    ('D', r'^(Edges?|Virtues, Hunter|Creed Powers)(, .+)?$', 'hunter-edges (tiered_power) - no Hunter stack exists', None),
    ('D', r'^(Redes?|Chimera)(, .+)?$', 'changeling-redes (trait_list)', None),
    ('D', r'^(Rewards?|Fomorian)(\b.*)?$', 'changeling-fomorian-rewards (tiered_power, NPC)', None),
    ('D', r'^(Shintai|Chi Arts|Kata|Hsien)(\b.*)?$', 'kueijin-* power list', None),
    ('D', r'^(Arcanoi|Dark Arcanoi|Spectre|Thorns?|Horrors?)(\b.*)?$', 'wraith-* power list', None),
    ('D', r'^(Gifts?)(, .+)?$', '{werewolf,fera}-gifts', None),
    ('D', r'^(Disciplines?|Combo)(, .+)?$', 'vampire-disciplines / vampire-combo-disciplines (1.3.1 / 1.3.0)', None),
    ('D', r'^(Spheres?|Rotes?|Effects?)(, .+)?$', 'mage-rotes / mage-spheres', None),
    ('D', r'^(Arts?|Realms?|Cantrips?|Treasures, Changeling)(, .+)?$', 'changeling-arts / changeling-realms', None),
    ('D', r'^(Lores, Demon|Evocations?|Apocalyptic|Form Powers?)(\b.*)?$', 'demon-evocations', None),
]


def classify(name, cat):
    if cat == 'hunter':
        return ('F', 'a Hunter (Imbued) stack', 'Settle: owner - there is no Hunter stack; Laws of the Reckoning content '
                'lands only if one is scoped (2026-09-20 ruling: "revisit only if a Hunter creature-stack is ever scoped")')
    for cls, pat, target, note in CLASS_RULES:
        if re.search(pat, name):
            return cls, (target or ''), (note or '')
    return None, '', ''


def held_customs():
    """Every custom entry any local character holds, by normalised name, with counts."""
    out = subprocess.check_output(MY + ['select stack_slug, sheet_data from wp_be_characters'],
                                  stderr=subprocess.DEVNULL, text=True)
    held = defaultdict(int)
    for line in out.splitlines():
        stack, _, raw = line.partition('\t')
        try:
            sheet = json.loads(raw)
        except ValueError:
            continue
        if not isinstance(sheet, dict):
            continue
        for key, rows in sheet.items():
            if not isinstance(rows, list):
                continue
            for r in rows:
                if isinstance(r, dict) and r.get('custom'):
                    # Whole names only: a held "Lore: Abyss" is Lore, not a Plane called Abyss.
                    for n in {r.get('name'), r.get('power_name')} - {None}:
                        held[norm(n)] += 1
    return held


def markdown(rows):
    """Per-class detail tables, grouped by proposed target."""
    out = []
    names = {'A': 'A. Real catalog gap (D20/D81 shape)', 'B': 'B. Identity options gap',
             'D': 'D. Genre power or trait list never seeded', 'E': 'E. Out of scope by design',
             'F': 'F. Unclear - needs an owner or source call'}
    closed = [r for r in rows if r['raw_missing'] and not r['norm_missing']]
    partial = [r for r in rows if r['drift'] and r['norm_missing']]
    out.append('### C. Spelling or format drift\n')
    out.append(f'**{len(closed)} menus** ({sum(r["raw_missing"] for r in closed)} items) are missing only under raw '
               f'matching - normalisation finds every item - plus **{sum(r["drift"] for r in partial)} drift items** '
               f'inside {len(partial)} menus that also have a real gap (counted under their own class).\n')
    out.append('| Menu | Items closed by normalisation | Example GVM names |')
    out.append('| --- | ---: | --- |')
    for r in sorted(closed + partial, key=lambda r: -r['drift']):
        out.append(f"| {r['menu']} | {r['drift']} | {'; '.join(r['drift_examples'][:3])} |")
    out.append('')
    for c in 'ABDEF':
        group = defaultdict(list)
        for r in rows:
            if r['norm_missing'] and r['class'] == c:
                group[(r['target'] or r['note'])].append(r)
        out.append(f'### {names[c]}\n')
        out.append('| Target / reason | Menus (missing / items) | Missing | GVM-priced | CSV-priced | Held custom | Examples |')
        out.append('| --- | --- | ---: | ---: | ---: | ---: | --- |')
        for tgt, rs in sorted(group.items(), key=lambda kv: -sum(r['norm_missing'] for r in kv[1])):
            menus = ', '.join(f"{r['menu']} ({r['norm_missing']}/{r['items']})" for r in rs)
            ex = '; '.join(rs[0]['examples'][:3])
            note = rs[0]['note'] if c == 'F' else ''
            out.append(f"| {tgt}{(' - ' + note) if note and note != tgt else ''} | {menus} | {sum(r['norm_missing'] for r in rs)} | "
                       f"{sum(r['gvm_costed'] for r in rs)} | {sum(r['csv_priced'] for r in rs)} | {sum(r['held_custom'] for r in rs)} | {ex} |")
        out.append('')
    return '\n'.join(out)


def main():
    args = sys.argv[1:]
    json_out = None
    catalogs = list(DEFAULT_CATALOGS)
    if '--json' in args:
        json_out = args[args.index('--json') + 1]
    if '--catalog' in args:
        catalogs = [Path(a) for a in args[args.index('--catalog') + 1:] if not a.startswith('--')]
    dirs = [LIVE] + [d for d in catalogs if d.exists()]
    raw, normed, _, lore_specs = catalog_names(dirs)
    held = held_customs()
    import csv
    csv_cost = {}
    for r in csv.reader(open(CODE / 'beyond-elysium' / 'data' / 'met-mechanics.csv')):
        if len(r) > 14 and r[14].strip():
            csv_cost.setdefault(norm(r[0]), r[14].strip())
    menus = gvm()
    rows = []
    for name, m in menus.items():
        items = [i.get('name') for i in m.findall('item') if i.get('name')]
        subs = [s.get('name') for s in m.findall('submenu')]
        if not items:
            continue
        raw_missing = [i for i in items if i.lower().strip() not in raw]
        pool = normed | lore_specs if name.startswith('Lores') else normed
        norm_missing = [i for i in items if not (variants(i) & pool)]
        costed = sum(1 for i in m.findall('item') if i.get('cost') and i.get('name') in norm_missing)
        csv_priced = sum(1 for i in norm_missing if norm(i) in csv_cost)
        cat = CATEGORY.get(m.get('category'), m.get('category'))
        cls, target, note = classify(name, cat) if norm_missing else ('', '', '')
        held_n = sum(held.get(norm(i), 0) for i in norm_missing)
        rows.append({'menu': name, 'category': cat, 'items': len(items), 'submenus': len(subs),
                     'raw_missing': len(raw_missing), 'norm_missing': len(norm_missing),
                     'drift': len(raw_missing) - len(norm_missing), 'gvm_costed': costed, 'csv_priced': csv_priced,
                     'class': cls or ('F' if norm_missing else ''), 'target': target, 'note': note,
                     'held_custom': held_n, 'examples': norm_missing[:6],
                     'drift_examples': [i for i in raw_missing if i not in norm_missing][:4]})
    rows.sort(key=lambda r: (-r['norm_missing'], r['menu']))
    if json_out:
        Path(json_out).write_text(json.dumps(rows, indent=1, ensure_ascii=False))
    if '--markdown' in args:
        print(markdown(rows))
        return
    tot = defaultdict(lambda: [0, 0, 0, 0])
    for r in rows:
        if r['norm_missing']:
            t = tot[r['class']]
            t[0] += 1
            t[1] += r['norm_missing']
            t[2] += r['held_custom']
            t[3] += r['raw_missing']
    print('menus with items:', len(rows), '| raw missing:', sum(r['raw_missing'] for r in rows),
          '| normalised missing:', sum(r['norm_missing'] for r in rows))
    for c in sorted(tot):
        print(f'  {c}: {tot[c][0]} menus, {tot[c][1]} items normalised ({tot[c][3]} raw), held custom {tot[c][2]}')


if __name__ == '__main__':
    main()
