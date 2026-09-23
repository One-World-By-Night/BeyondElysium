"""Restructures samples/research/demon/owbn/lores.json (the Demon: The Fallen
Lore/Evocation extraction from the OWBN Fallen Genre Packet, 2021) into
data/catalog/blocks/demon-evocations.json, conforming to
BE_PROCESS/reference/CATALOG-JSON-FORMAT.md §4.2 (tiered_power, the
_meta/levels/elder shape).

Offline, one-time tooling. Not part of the plugin, not run at runtime, not
run by bin/verify. Kept here so the method is reproducible and reviewable,
matching tools/grimoire/'s precedent.

Requires the private research file at samples/research/demon/owbn/lores.json
(never committed, never shipped) - a sibling repo-root directory to code/.

Ladder: every House Lore has 2 Basic + 2 Intermediate + 1 Advanced Evocation
(ladder 2/2/1 = 5, matching every other tiered_power block measured for the
1.2.10 pilot) plus one named "Visage" ultimate power - the House Lore's own
Advanced-tier signature ability, named after a Mesopotamian deity
(e.g. "Bel, the Visage of the Celestials"). The two Common Lores (Fundament,
Humanity) have no Visage: 5 Evocations, no elder pool.

Two of the 23 Lores carry more than the expected 6 (or 5) real named powers -
Lore of the Winds (7: an extra "Immune to Falling Damage") and Lore of the
Beast (8: "Previously Published MET Animal Forms" and "Animal Characteristics"
beyond the normal Advanced slot). These are real content the extraction found
in the book, not noise - confirmed by reading the source PDF directly for
both (OWBN0052-Demon-Fallen Genre-2021.pdf, printed p. 66-69 and 105-109).
Rather than guess which item is "the real" Advanced Evocation and discard or
misfile the rest, the first two Basic / first two Intermediate / first
Advanced items (in the source's own printed order) fill the declared 2/2/1
ladder, the Visage goes to elder.visage as usual, and the genuine extra
item(s) go to elder.bonus - a rank outside the ladder, so no orphan and no
invented ceiling. See the 1.3.0 release report for the full reasoning.
"""

import json
from collections import OrderedDict
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]
SOURCE = REPO_ROOT / 'samples' / 'research' / 'demon' / 'owbn' / 'lores.json'
OUTPUT = Path(__file__).resolve().parents[2] / 'beyond-elysium' / 'data' / 'catalog' / 'blocks' / 'demon-evocations.json'


def main():
    src = json.load(open(SOURCE))
    houses = src['houses']
    powers = src['powers']

    lore_house = {}
    for house, lores in houses.items():
        for lore in lores:
            lore_house[lore] = house

    by_lore = OrderedDict()
    for p in powers:
        by_lore.setdefault(p['lore'], []).append(p)

    out_powers = OrderedDict()
    total_placed = 0
    for lore, plist in by_lore.items():
        house = lore_house[lore]
        visage_items = [p for p in plist if 'visage of' in p['power_name'].lower()]
        non_visage = [p for p in plist if p not in visage_items]

        basics = [p for p in non_visage if p['tier'] == 'basic']
        inters = [p for p in non_visage if p['tier'] == 'intermediate']
        advs = [p for p in non_visage if p['tier'] == 'advanced']

        levels = []
        level_n = 1
        extras = []
        for bucket, tier in ((basics[:2], 'basic'), (inters[:2], 'intermediate'), (advs[:1], 'advanced')):
            for p in bucket:
                levels.append({'level': level_n, 'tier': tier, 'name': p['power_name']})
                level_n += 1
        extras += basics[2:] + inters[2:] + advs[1:]

        elder = {}
        if visage_items:
            elder['visage'] = [p['power_name'] for p in visage_items]
        if extras:
            elder['bonus'] = [p['power_name'] for p in extras]

        total_placed += len(levels) + sum(len(v) for v in elder.values())

        entry = OrderedDict()
        entry['source'] = plist[0]['source']
        entry['levels'] = levels
        entry['elder'] = elder
        entry['traditions'] = None
        entry['restriction'] = None
        entry['description'] = None
        entry['house'] = house
        out_powers[lore] = entry

    assert total_placed == len(powers), f'{total_placed} placed vs {len(powers)} source powers - an item was dropped or duplicated'

    doc = OrderedDict()
    doc['format'] = 1
    doc['slug'] = 'demon-evocations'
    doc['name'] = 'Lores (Evocations)'
    doc['kind'] = 'block'
    doc['section_type'] = 'tiered_power'
    doc['provenance'] = OrderedDict()
    doc['provenance']['sources'] = [
        'OWBN Fallen Genre Packet (2021), OWBN0052-Demon-Fallen Genre-2021.pdf, '
        'Chapter 3 (Subtle Instruments: Lores), printed p. 44-129',
        'MET-POWER-ACQUISITION.md §3 (Demon) - Lore 3/6/9, +1 outside House/Common',
    ]
    doc['provenance']['extracted'] = '2026-09-21'
    doc['provenance']['tool'] = 'tools/catalog/build_demon_evocations.py'

    meta = OrderedDict()
    meta['ranks'] = ['basic', 'intermediate', 'advanced', 'visage', 'bonus']
    meta['ladder'] = {'basic': 2, 'intermediate': 2, 'advanced': 1}
    meta['costs'] = {'basic': 3, 'intermediate': 6, 'advanced': 9, 'visage': None, 'bonus': None}
    meta['out_of_type'] = {'basic': '+1', 'intermediate': '+1', 'advanced': '+1', 'visage': '+1', 'bonus': '+1'}
    meta['levels'] = None
    meta['categories'] = ['house']
    meta['untiered'] = None

    definition = OrderedDict()
    definition['_meta'] = meta
    definition['blood_magic'] = False
    definition['traditions'] = []
    definition['powers'] = out_powers
    doc['definition'] = definition

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT, 'w') as f:
        json.dump(doc, f, indent=2, ensure_ascii=False)
        f.write('\n')

    print(f'wrote {len(out_powers)} families, {total_placed} powers -> {OUTPUT}')


if __name__ == '__main__':
    main()
