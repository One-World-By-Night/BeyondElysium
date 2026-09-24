"""Backfills `group` on the 134 previously-ungrouped mage-rotes items and
emits data/catalog/blocks/mage-rotes.json, a trait_list block.

Offline tooling: not part of the plugin, not run at runtime, not run by
bin/verify.

Input: a JSON export of the live `mage-rotes` schema_blocks row's `definition`
column, taken as an external input rather than embedding database access.
Produce it with, e.g.:

    mysql --raw -N -B -ube_dev -pbe_dev_pw -h127.0.0.1 -P3307 be_dev \\
      -e "SELECT definition FROM wp_be_schema_blocks WHERE slug='mage-rotes';" \\
      > /tmp/mage-rotes-live.json

--raw is required: without it the MySQL client double-escapes an already-
escaped internal quote in one real item name ("Ap-Sobk, \\"Last Judgment of
Sobk\\"") and produces invalid JSON.

Of the 134 ungrouped items, 45 are classified here from captured effect text in
samples/research/mage/mage-rotes.csv (the Laws of Ascension / Laws of Ascension
Companion MET corebooks, via Rotes.gex) against the same 17-category scheme
code/tools/catalog/source/grimoire-rotes.csv already uses for the other 670.
This is an editorial classification, not a restatement of a captured source
category. 89 items remain ungrouped: samples/research/mage/mage-rotes.csv
carries only a bare page citation for them ("Laws of Ascension Companion p.
NNN"), not effect prose.
"""

import csv
import json
import sys
from collections import OrderedDict
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]
RESEARCH_CSV = REPO_ROOT / 'samples' / 'research' / 'mage' / 'mage-rotes.csv'
OUTPUT = Path(__file__).resolve().parents[2] / 'beyond-elysium' / 'data' / 'catalog' / 'blocks' / 'mage-rotes.json'

CANON_GROUPS = {
    'Uncanny Influence', 'Movement and Communication', 'Transformations',
    'Summoning, Binding and Warding', 'Healing and Harming', 'Energy-Work',
    'Divination and Fate', 'Elemental Magick', 'Mystic Perception',
    'Inanimate Objects', 'Enhanced Combat', 'Computers',
    'Space-Time Management', 'Blessings and Curses', 'Obfuscation',
    'Necromancy', 'Miscellaneous',
}

# Classified from effect prose in samples/research/mage/mage-rotes.csv's
# Description column (Laws of Ascension core-book entries only).
BACKFILL = {
    "Affix Gauntlet": "Summoning, Binding and Warding",
    "Alloy": "Inanimate Objects",
    "Alter Weight": "Inanimate Objects",
    "Astral Projection": "Movement and Communication",
    "Battery Man": "Energy-Work",
    "Beginner's Luck": "Blessings and Curses",
    "Better Body": "Transformations",
    "Binding Oath": "Blessings and Curses",
    "Blight of Aging": "Healing and Harming",
    "Bubble of Reality": "Space-Time Management",
    "Consecration": "Inanimate Objects",
    "Create Mind": "Miscellaneous",
    "Create Talismans and Artifacts": "Inanimate Objects",
    "Darksight": "Mystic Perception",
    "Enchant Life": "Energy-Work",
    "Find Reality Flaws": "Mystic Perception",
    "Fragments of Dream": "Mystic Perception",
    "Gremlins": "Summoning, Binding and Warding",
    "Heal Self": "Healing and Harming",
    "Heart's Blood": "Energy-Work",
    "Hermes Portal": "Movement and Communication",
    "Holy Stroke": "Enhanced Combat",
    "Lambs to the Slaughter": "Energy-Work",
    "Life Scan": "Mystic Perception",
    "Paradox Ward": "Summoning, Binding and Warding",
    "Perfect Metamorphosis": "Transformations",
    "Perfect Time": "Mystic Perception",
    "Possession": "Uncanny Influence",
    "Prayer of Healing Revelation": "Healing and Harming",
    "Probe Thoughts": "Uncanny Influence",
    "Programmed Event": "Space-Time Management",
    "Sense Connection": "Mystic Perception",
    "Slay Machine": "Inanimate Objects",
    "Spatial Mutations": "Space-Time Management",
    "Spirit Sight": "Mystic Perception",
    "Storm Watch": "Mystic Perception",
    "Straw into Gold": "Inanimate Objects",
    "Telekinesis": "Movement and Communication",
    "Telepathy": "Movement and Communication",
    "Time Sense": "Mystic Perception",
    "Time Travel": "Space-Time Management",
    "Time Ward": "Summoning, Binding and Warding",
    "Ward": "Summoning, Binding and Warding",
    "Wellspring": "Energy-Work",
    "Whereami?": "Mystic Perception",
}

assert set(BACKFILL.values()) <= CANON_GROUPS, set(BACKFILL.values()) - CANON_GROUPS


def main():
    if len(sys.argv) != 2:
        print(f'usage: {sys.argv[0]} <live-mage-rotes-definition.json>', file=sys.stderr)
        sys.exit(1)

    db = json.load(open(sys.argv[1]))
    items = db['items']

    out_items = []
    backfilled = 0
    still_ungrouped = 0
    for it in items:
        name = it['name']
        group = it.get('group')
        if not group and name in BACKFILL:
            group = BACKFILL[name]
            backfilled += 1
        elif not group:
            still_ungrouped += 1
        entry = OrderedDict()
        entry['name'] = name
        entry['cost'] = None
        entry['tier'] = None
        entry['group'] = group or None
        entry['subgroup'] = None
        entry['source'] = it.get('source')
        entry['note'] = it.get('note')
        entry['description'] = None
        entry['approval'] = None
        entry['reason'] = None
        entry['approval_by_value'] = []
        entry['prerequisites'] = []
        out_items.append(entry)

    doc = OrderedDict()
    doc['format'] = 1
    doc['slug'] = 'mage-rotes'
    doc['name'] = 'Rotes'
    doc['kind'] = 'block'
    doc['section_type'] = 'trait_list'
    doc['provenance'] = OrderedDict()
    doc['provenance']['sources'] = [
        'Live seeded catalog (be_dev wp_be_schema_blocks, slug mage-rotes) - 670 of 804 '
        'items already carry a group backfilled at v0.99.17 from '
        'code/tools/catalog/source/grimoire-rotes.csv (Enlightened Grimoire: A Guide for '
        'Mage 20th Anniversary Edition, Charles Siegel, 2018)',
        '45 of the 134 previously ungrouped items classified this pass from real captured '
        'effect text in samples/research/mage/mage-rotes.csv (Laws of Ascension / Laws of '
        'Ascension Companion, MET corebooks) against the same 17-category scheme',
        '89 of the 134 remain ungrouped - see this script\'s module docstring',
    ]
    doc['provenance']['extracted'] = '2026-09-21'
    doc['provenance']['tool'] = 'tools/catalog/build_mage_rotes.py'

    definition = OrderedDict()
    definition['alphabetize'] = True
    definition['atomic'] = True
    definition['allow_custom'] = True
    definition['allow_multiples'] = False
    definition['has_specializations'] = False
    definition['negative'] = False
    definition['flat_cost'] = False
    definition['categories'] = sorted(CANON_GROUPS)
    definition['items'] = out_items
    doc['definition'] = definition

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT, 'w') as f:
        json.dump(doc, f, indent=2, ensure_ascii=False)
        f.write('\n')

    print(f'total {len(out_items)}, backfilled {backfilled}, still ungrouped {still_ungrouped} -> {OUTPUT}')


if __name__ == '__main__':
    main()
