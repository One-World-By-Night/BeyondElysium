"""Converts samples/research/demon/owbn/rituals.json (the Demon: The Fallen
Ritual extraction from the OWBN Fallen Genre Packet, 2021) into
data/catalog/blocks/demon-rituals.json, conforming to
BE_PROCESS/reference/CATALOG-JSON-FORMAT.md §4.1 (trait_list).

Offline, one-time tooling. Not part of the plugin, not run at runtime, not
run by bin/verify. Kept here so the method is reproducible and reviewable,
matching tools/grimoire/'s precedent.

Each ritual's real Primary Lore / Secondary Lore + dot-rating prerequisites
(printed as bullet-dot strings, e.g. "Lore of the Celestials ••")
are parsed into this format's `prerequisites` shape, pointing at
demon-evocations (built by build_demon_evocations.py) by Lore name. Minimum
casting time and Backlash - real mechanical data with no equivalent field in
this format - are folded into `note` rather than dropped.
"""

import json
import re
from collections import OrderedDict
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]
SOURCE = REPO_ROOT / 'samples' / 'research' / 'demon' / 'owbn' / 'rituals.json'
OUTPUT = Path(__file__).resolve().parents[2] / 'beyond-elysium' / 'data' / 'catalog' / 'blocks' / 'demon-rituals.json'

DOT_RE = re.compile(r'^(.*?)\s*([•●∙*]+)$')


def parse_lore_req(text):
    if not text:
        return None
    m = DOT_RE.match(text.strip())
    if m:
        return m.group(1).strip(), len(m.group(2))
    return text.strip(), None


def main():
    src = json.load(open(SOURCE))
    rituals = src['rituals']

    out_items = []
    for r in rituals:
        prereqs = []
        for key in ('primary_lore', 'secondary_lore'):
            val = r.get(key)
            if val:
                parsed = parse_lore_req(val)
                if parsed and parsed[1]:
                    prereqs.append(OrderedDict([
                        ('block_slug', 'demon-evocations'),
                        ('power', parsed[0]),
                        ('min_level', parsed[1]),
                    ]))

        cost = r.get('base_cost') or ''
        cost = cost.replace('xp', '').strip() or None

        note_parts = []
        if r.get('minimum_casting_time'):
            note_parts.append('Minimum casting time: ' + r['minimum_casting_time'])
        if r.get('backlash'):
            note_parts.append('Backlash: ' + r['backlash'])
        note = '; '.join(note_parts) or None

        entry = OrderedDict()
        entry['name'] = r['name']
        entry['cost'] = cost
        entry['tier'] = None
        entry['group'] = r.get('house')
        entry['subgroup'] = None
        entry['source'] = r.get('source')
        entry['note'] = note
        entry['description'] = {'reference': r['description']['reference']} if r.get('description') else None
        entry['approval'] = None
        entry['reason'] = None
        entry['approval_by_value'] = []
        entry['prerequisites'] = prereqs
        out_items.append(entry)

    doc = OrderedDict()
    doc['format'] = 1
    doc['slug'] = 'demon-rituals'
    doc['name'] = 'Rituals'
    doc['kind'] = 'block'
    doc['section_type'] = 'trait_list'
    doc['provenance'] = OrderedDict()
    doc['provenance']['sources'] = [
        'OWBN Fallen Genre Packet (2021), OWBN0052-Demon-Fallen Genre-2021.pdf, '
        'Chapter 5 (A Chorus of Angels: Rituals), printed p. 141-190',
    ]
    doc['provenance']['extracted'] = '2026-09-21'
    doc['provenance']['tool'] = 'tools/catalog/build_demon_rituals.py'

    definition = OrderedDict()
    definition['alphabetize'] = True
    definition['atomic'] = True
    definition['allow_custom'] = False
    definition['allow_multiples'] = False
    definition['has_specializations'] = False
    definition['negative'] = False
    definition['flat_cost'] = True
    definition['categories'] = sorted({x.get('house') for x in rituals if x.get('house')})
    definition['items'] = out_items
    doc['definition'] = definition

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT, 'w') as f:
        json.dump(doc, f, indent=2, ensure_ascii=False)
        f.write('\n')

    no_prereq = [x['name'] for x in out_items if not x['prerequisites']]
    print(f'wrote {len(out_items)} rituals -> {OUTPUT}')
    if no_prereq:
        print('no prerequisites parsed for:', no_prereq)


if __name__ == '__main__':
    main()
