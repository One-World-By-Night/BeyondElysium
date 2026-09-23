"""Emits the Kuei-Jin Discipline files (1.3.1).

    python3 build_kueijin_disciplines.py <snapshot-dir>

Writes four files under data/catalog/blocks/:

- `kueijin-disciplines.json` - the base, Laws of the East (WW05016).
- `owbn-kueijin_disciplines.json` - the OWBN Kuei-Jin Genre Packet's converted
  and custom Disciplines, a `mode: add` variant (format §4b: books are the
  base, packets are selectable).
- `kueijin-techniques.json` - Grapevine's two Technique menus, moved out of
  the Discipline block because they are not ladders (see below), plus
  `owbn-kueijin_techniques.json` for the packet's own fourteen Techniques.

Reads the private research at samples/research/kuei-jin/disciplines.json and
techniques.json (a sibling of code/, never committed) and the be_dev snapshot.
Every ruling, with its evidence, is in rulings/kueijin-disciplines.json.
"""

import json
import sys
from collections import OrderedDict

from catalog_common import (flags_from, SAMPLES_ROOT, envelope, family_record, ladder_from,
                            load_rulings, load_snapshot, meta, partition,
                            write_block)

SLUG = 'kueijin-disciplines'
RESEARCH = SAMPLES_ROOT / 'research' / 'kuei-jin'
TIER_WORD = {'Basic': 'basic', 'Intermediate': 'intermediate', 'Advanced': 'advanced'}
PLACEHOLDER_TIERS = ['basic', 'basic', 'intermediate', 'intermediate', 'advanced']
COSTS = {'basic': 4, 'intermediate': 7, 'advanced': 10}


def research_family(fam):
    """A research family's powers as ladder entries, in printed order."""
    entries = []
    for lv in fam.get('levels', []):
        tier = TIER_WORD.get(lv.get('tier') or '')
        for p in lv.get('powers', []):
            entries.append({'name': p['name'], 'tier': tier})
    return entries


def rated_placeholder_ladder():
    """A family the book rates 1-5 without naming a power per dot - Black
    Wind's three aspects, Demon Shintai. The rung is the rating itself."""
    return [{'name': f'Level {i}', 'tier': t} for i, t in enumerate(PLACEHOLDER_TIERS, start=1)]


def kueijin_meta():
    # No out-of-type modifier appears in Laws of the East's chart at all, so
    # `out_of_type` is genuinely absent (null), not an empty table.
    return meta(['basic', 'intermediate', 'advanced'], COSTS)


def main(snapshot_dir):
    snap = load_snapshot(snapshot_dir, SLUG)
    rulings = load_rulings(SLUG)
    research = json.load(open(RESEARCH / 'disciplines.json', encoding='utf-8'))
    lote = {f['name']: f for f in research['laws_of_the_east']}

    families = rulings['families']
    powers = OrderedDict()
    techniques = []

    for fam in snap['powers']:
        name = fam['name']
        rule = families.get(name, {'action': 'keep'})
        action = rule['action']

        if action == 'keep':
            entries = [{'name': lv['power_name'], 'tier': lv['tier']}
                       for lv in fam['levels'] + fam.get('overflow', [])]
            levels, leftovers = ladder_from(entries)
            if leftovers or len(levels) != 5:
                raise SystemExit(f'{name}: needs a ruling - {leftovers}')
            powers[name] = family_record(fam.get('source') or name, levels)

        elif action == 'split_rated':
            # Black Wind: one Grapevine menu, three separately rated powers.
            for new_name in rule['into']:
                levels, _ = ladder_from(rated_placeholder_ladder())
                powers[new_name] = family_record(
                    name, levels, description=None,
                    extra={'split_from': name},
                )

        elif action == 'rated_with_options':
            # Demon Shintai: five rated levels, each choosing one of the book's
            # form characteristics. The characteristics are choices made at a
            # level, not rungs and not picks above the ladder.
            levels, _ = ladder_from(rated_placeholder_ladder())
            options = [c['name'] for c in lote[name]['form_characteristics']]
            snap_names = [lv['power_name'] for lv in fam['levels'] + fam.get('overflow', [])]
            missing = [n for n in snap_names if n not in options]
            if missing:
                raise SystemExit(f'{name}: snapshot characteristics absent from the book list: {missing}')
            powers[name] = family_record(name, levels, extra={
                'options': options,
                'options_note': rule['options_note'],
            })

        elif action == 'move_to_techniques':
            for lv in fam['levels'] + fam.get('overflow', []):
                techniques.append(OrderedDict([
                    ('name', lv['power_name']),
                    ('cost', lv.get('cost')),
                    ('tier', None),
                    ('group', rule['group']),
                    ('subgroup', None),
                    ('source', 'Grapevine Menus: ' + name),
                    ('note', lv.get('note')),
                    ('description', None),
                    ('approval', None),
                    ('reason', None),
                    ('approval_by_value', []),
                    ('prerequisites', []),
                    ('moved_from', OrderedDict([('block', SLUG), ('name', name)])),
                ]))
        else:
            raise SystemExit(f'{name}: unknown action {action}')

    for name in rulings.get('add_from_laws_of_the_east', {}):
        levels, leftovers = ladder_from(research_family(lote[name]))
        assert not leftovers and len(levels) == 5, name
        powers[name] = family_record('Laws of the East', levels)

    definition = OrderedDict()
    definition['_meta'] = kueijin_meta()
    definition.update(flags_from(snap))
    definition['blood_magic'] = False
    definition['traditions'] = []
    definition['powers'] = powers
    base = envelope(SLUG, 'Disciplines', 'tiered_power', OrderedDict([
        ('sources', [
            "Laws of the East (WW05016), p. 125 (Discipline 4/7/10, no out-of-type modifier), "
            "p. 133-160 (Disciplines)",
            'GVM: Disciplines, Kuei-Jin (family list and power names)',
            'Owner rulings 2026-09-17 (samples/research/kuei-jin/NOTES.md): Equilibrium added; '
            'Black Wind is three separately rated powers',
        ]),
        ('extracted', '2026-09-22'),
        ('tool', 'tools/catalog/build_kueijin_disciplines.py + rulings/kueijin-disciplines.json'),
    ]), definition)
    print(write_block(base), 'families=%d rungs=%d picks=%d overflow=%d' % partition(definition))

    # --- OWBN packet variant -------------------------------------------------
    owbn = OrderedDict()
    skipped = []
    for section in ('owbn_converted', 'owbn_custom_powers'):
        for fam in research[section]:
            entries = research_family(fam)
            if not entries:
                skipped.append(fam['name'])
                continue
            levels, leftovers = ladder_from(entries)
            assert not leftovers and len(levels) == 5, fam['name']
            extra = {'group': fam.get('group')}
            owbn[fam['name']] = family_record(fam['source'], levels, extra=extra)
    vdef = OrderedDict()
    vdef['_meta'] = kueijin_meta()
    vdef.update(flags_from(snap))
    vdef['blood_magic'] = False
    vdef['traditions'] = []
    vdef['powers'] = owbn
    variant = envelope('owbn-kueijin_disciplines', 'Disciplines (OWBN)', 'tiered_power', OrderedDict([
        ('sources', [
            'OWBN Kuei-Jin Genre Packet (2022) - converted Disciplines and the three OWBN custom '
            'Disciplines, tiers as printed; priced by Laws of the East p. 125 (4/7/10)',
        ]),
        ('extracted', '2026-09-22'),
        ('tool', 'tools/catalog/build_kueijin_disciplines.py'),
    ]), vdef, variant=OrderedDict([
        ('of', SLUG), ('id', 'owbn'), ('label', 'OWBN Kuei-Jin Genre Packet (2022)'), ('mode', 'add'),
    ]))
    print(write_block(variant), 'families=%d rungs=%d picks=%d overflow=%d' % partition(vdef),
          'not emitted (no powers printed):', skipped)

    # --- Techniques (trait_list) --------------------------------------------
    tdef = OrderedDict([
        ('alphabetize', False), ('atomic', True), ('allow_custom', True),
        ('allow_multiples', False), ('has_specializations', False), ('negative', False),
        ('flat_cost', False), ('categories', None), ('items', techniques),
    ])
    tech = envelope('kueijin-techniques', 'Techniques', 'trait_list', OrderedDict([
        ('sources', [
            'GVM: Bone Flower Techniques, Resplendent Crane Techniques (moved out of '
            'kueijin-disciplines - a Technique is one power with prerequisites, not a rated ladder)',
        ]),
        ('extracted', '2026-09-22'),
        ('tool', 'tools/catalog/build_kueijin_disciplines.py'),
    ]), tdef)
    print(write_block(tech), 'items=%d' % len(techniques))

    owbn_tech = []
    for t in json.load(open(RESEARCH / 'techniques.json', encoding='utf-8')):
        owbn_tech.append(OrderedDict([
            ('name', t['name']), ('cost', None), ('tier', None), ('group', 'OWBN'),
            ('subgroup', None), ('source', t['source']), ('note', 'Prerequisites: ' + t['prerequisites']),
            ('description', None), ('approval', None), ('reason', None),
            ('approval_by_value', []), ('prerequisites', []),
        ]))
    otdef = OrderedDict(tdef)
    otdef['items'] = owbn_tech
    otech = envelope('owbn-kueijin_techniques', 'Techniques (OWBN)', 'trait_list', OrderedDict([
        ('sources', ['OWBN Kuei-Jin Genre Packet (2022), p. 48-50 - Techniques, prerequisites as printed; '
                     'the packet prints no XP cost, so cost is null rather than guessed']),
        ('extracted', '2026-09-22'),
        ('tool', 'tools/catalog/build_kueijin_disciplines.py'),
    ]), otdef, variant=OrderedDict([
        ('of', 'kueijin-techniques'), ('id', 'owbn'), ('label', 'OWBN Kuei-Jin Genre Packet (2022)'),
        ('mode', 'add'),
    ]))
    print(write_block(otech), 'items=%d' % len(owbn_tech))


if __name__ == '__main__':
    main(sys.argv[1])
