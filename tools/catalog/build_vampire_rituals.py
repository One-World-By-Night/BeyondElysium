"""Emits data/catalog/blocks/vampire-rituals.json.

    python3 build_vampire_rituals.py <snapshot-dir>

The emission is conservative:

- **Names are not touched.** Every seeded item is `"<Tradition>: <Ritual>
  (<tier>)"`, and held character data matches on that string.
- **`group` and `tier` are filled from the name**, which already carries both,
  by parsing rather than research.
- **`cost` stays the seeded string** except where rulings/vampire-rituals.json
  corrects one with evidence. Anomalies without evidence are reported, not
  guessed.
"""

import re
import sys
from collections import Counter, OrderedDict

from catalog_common import envelope, flags_from, load_rulings, load_snapshot, write_block

SLUG = 'vampire-rituals'
NAME = re.compile(r'^(?P<group>.*?):\s*(?P<ritual>.*?)\s*\((?P<tier>[^()]*)\)\s*$')
TIERS = {'basic': 'basic', 'int': 'intermediate', 'intermediate': 'intermediate', 'adv': 'advanced',
         'advanced': 'advanced', 'elder': 'elder', 'master': 'master', 'asc': 'ascended',
         'methuselah': 'methuselah', 'variable': 'variable', 'unknown': None}


def main(snapshot_dir):
    snap = load_snapshot(snapshot_dir, SLUG)
    rulings = load_rulings(SLUG)
    group_fix = rulings.get('group_spelling', {}).get('map', {})
    cost_fix = rulings.get('costs', {}).get('items', {})

    items, stats = [], Counter()
    for raw in snap['items']:
        m = NAME.match(raw['name'])
        if not m:
            raise SystemExit(f"unparsed ritual name: {raw['name']!r}")
        group = m.group('group').strip()
        group = group_fix.get(group, group)
        if group == 'Unknown':
            group = None
        tier = TIERS.get(m.group('tier').strip().lower(), 'unparsed')
        if tier == 'unparsed':
            raise SystemExit(f"unparsed tier in {raw['name']!r}")
        cost = raw.get('cost')
        if raw['name'] in cost_fix:
            cost = cost_fix[raw['name']]['cost']
            stats['cost_corrected'] += 1
        note = raw.get('note')
        if note and note.strip().lower().rstrip('.') in TIERS:
            note = None  # the note was only ever the tier word
        items.append(OrderedDict([
            ('name', raw['name']),
            ('cost', cost),
            ('tier', tier),
            ('group', group),
            ('subgroup', None),
            ('source', raw.get('source')),
            ('note', note),
            ('description', raw.get('description')),
            ('approval', None),
            ('reason', None),
            ('approval_by_value', []),
            ('prerequisites', []),
        ]))
        stats['items'] += 1

    definition = OrderedDict()
    definition.update(flags_from(snap))
    definition['allow_multiples'] = snap.get('allow_multiples', False)
    definition['items'] = items
    doc = envelope(SLUG, 'Rituals', 'trait_list', OrderedDict([
        ('sources', [
            'GVM ritual menus (Rituals, Basic/Intermediate/Advanced/Superior, Assamite, Dark Thaumaturgy, '
            'Mortis, Gargoyle, Necromancy, Pisanob Necromancy, Revenant Creation, Sabbat) and '
            'met-mechanics.csv, merged and labelled by gvm-block-map.php - as seeded by 1.2.10 (be_dev snapshot)',
            'Laws of the Night Revised (WW05013) - Rituals 2/4/6 (MET-POWER-ACQUISITION.md, Vampire)',
        ]),
        ('extracted', '2026-09-22'),
        ('tool', 'tools/catalog/build_vampire_rituals.py + rulings/vampire-rituals.json'),
    ]), definition)
    print(write_block(doc), dict(stats))


if __name__ == '__main__':
    main(sys.argv[1])
