"""Emits data/catalog/blocks/mage-spheres.json (1.3.1).

    python3 build_mage_spheres.py <snapshot-dir>

Ten Sphere families, each the five Laws of Ascension ranks. Two corrections
against the seeded snapshot, both from the book:

- **Costs 4/4/8/8/12** (1.3.0's D2, owner ruling 2026-09-21: the book is the
  base). The snapshot carries 5/5/10/10/15, which is the *non-specialty*
  column. The non-specialty surcharge stays in `out_of_type` as the scaling
  +1/+2/+3 the book prints. Forward-only: nothing is refunded or migrated.
- **Rank order Initiate, Apprentice** (Laws of the Ascension, WW05022, lists
  the five ranks Initiate, Apprentice, Disciple, Adept, Master). The GVM
  printed the first two swapped. Both are basic at the same cost, so no price
  moves; only the rung label does. See rulings/mage-spheres.json.
"""

import sys
from collections import OrderedDict

from catalog_common import (flags_from, envelope, family_record, ladder_from, load_rulings,
                            load_snapshot, meta, partition, write_block)

SLUG = 'mage-spheres'


def main(snapshot_dir):
    snap = load_snapshot(snapshot_dir, SLUG)
    rulings = load_rulings(SLUG)
    order = rulings['rank_order']['names']

    powers = OrderedDict()
    for fam in snap['powers']:
        by_name = {lv['power_name']: lv for lv in fam['levels']}
        if sorted(by_name) != sorted(order) or fam.get('elder') or fam.get('overflow'):
            raise SystemExit(f"{fam['name']}: not the five-rank shape this builder expects")
        entries = [{'name': n, 'tier': by_name[n]['tier']} for n in order]
        levels, leftovers = ladder_from(entries)
        assert not leftovers, fam['name']
        name = fam['name']
        powers[name] = family_record(fam.get('source') or name, levels)

    definition = OrderedDict()
    definition['_meta'] = meta(
        ['basic', 'intermediate', 'advanced'],
        {'basic': 4, 'intermediate': 8, 'advanced': 12},
        {'basic': '+1', 'intermediate': '+2', 'advanced': '+3'},
    )
    definition.update(flags_from(snap))
    definition['blood_magic'] = False
    definition['traditions'] = []
    definition['powers'] = powers

    doc = envelope(SLUG, 'Spheres', 'tiered_power', OrderedDict([
        ('sources', [
            "Laws of Ascension (WW05022) - Sphere 4/4/8/8/12 across Initiate, Apprentice, Disciple, "
            "Adept, Master; non-specialty 5/10/15 (MET-POWER-ACQUISITION.md, Mage)",
            'GVM: Spheres / Sphere Levels (family list and rank names)',
        ]),
        ('extracted', '2026-09-22'),
        ('tool', 'tools/catalog/build_mage_spheres.py + rulings/mage-spheres.json'),
    ]), definition)
    path = write_block(doc)
    print(path, 'families=%d rungs=%d picks=%d overflow=%d' % partition(definition))


if __name__ == '__main__':
    main(sys.argv[1])
