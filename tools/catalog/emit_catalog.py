#!/usr/bin/env python3
"""Emits 1.3.0's declared catalog files from the local seeded catalog plus the rulings in
`rulings.py`.

    tools/catalog/dump_live.sh            # local be_dev -> tools/catalog/out/live
    tools/catalog/emit_catalog.py         # -> beyond-elysium/data/catalog/**

Offline tooling (not shipped, not run by bin/verify), same standing as tools/grimoire/.
`bin/validate-catalog` is the acceptance gate for everything it writes.

What it does NOT write, deliberately:

* the five blocks 1.3.1 authors in parallel (`catalog_io.NOT_OURS`);
* `mortal-numina` - a container that re-surfaces other blocks' menus, 1,729 levels of which
  are overflow no ruling here can empty. It is built into `out/` with its D6/D7 cleanup
  applied and measured, and stays out of `data/catalog/` until it can validate;
* any stack or template that references a block file which does not exist yet (the
  Vampire, Mage and Kuei-Jin lines, whose power blocks are 1.3.1's). They are written to
  `out/pending/` and emitted by re-running this script after 1.3.1 merges.
"""

import copy
import json
import re
import sys
from collections import Counter, OrderedDict
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import catalog_io as io  # noqa: E402
import rulings as R  # noqa: E402

DUMP = Path(sys.argv[1]) if len(sys.argv) > 1 else HERE / 'out' / 'live'
OUT = HERE / 'out'
REPORT = OrderedDict()
LIVE_SOURCE = ('Live seeded catalog, be_dev wp_be_schema_blocks (1.2.10 seeder: Grapevine Menus XML.gvm '
               '+ met-mechanics.csv), dumped by tools/catalog/dump_live.sh')


def live(slug):
    return io.load_dump(DUMP, 'blocks', slug)


def all_live_slugs():
    return sorted(p.stem for p in (DUMP / 'blocks').glob('*.json'))


# --- Generic emitters ----------------------------------------------------------------------

def emit_trait_list(slug, items=None, extra=None, sources=None, notes=None, name=None):
    blk = live(slug)
    d = blk['definition']
    its = items if items is not None else [io.trait_item(i) for i in d['items']]
    io.write('blocks', slug, name or blk['name'], 'block', io.trait_definition(d, its, extra),
             sources or [LIVE_SOURCE], section_type='trait_list', notes=notes)
    REPORT[slug] = {'items': len(its)}


POOL_ORDER = ['name', 'value_type', 'default_start', 'min', 'max', 'max_lookup', 'name_lookup',
              'cost_per_dot', 'free_dots', 'sliding_cost']
FIELD_ORDER = ['name', 'field_type', 'required', 'options', 'options_ref', 'allow_custom', 'default', 'min', 'max']


def emit_identity(slug, field_patch=None, sources=None, notes=None, menus=None):
    blk = live(slug)
    d = copy.deepcopy(blk['definition'])
    fields, option_sources = [], []
    for f in d['fields']:
        f = dict(f)
        f.pop('approval_by_option', None)  # zero approval content in creature data
        if field_patch and f['name'] in field_patch:
            f.update(field_patch[f['name']])
        drop = f.pop('drop_options', None)
        if drop:
            f['options'] = [o for o in f.get('options') or [] if o not in drop]
        menu_names = R.IDENTITY_OPTION_MENUS.get((slug, f['name']))
        if menu_names:
            options, seen = [], set()
            strip = (slug, f['name']) in R.IDENTITY_OPTION_STRIP_ARTICLE
            for mn in menu_names:
                for it in menus[mn].findall('item'):
                    name = re.sub(r'^The ', '', it.get('name')) if strip else it.get('name')
                    if io.norm(name) not in seen:
                        seen.add(io.norm(name))
                        options.append(name)
            f['field_type'] = 'select'
            f['options'] = options
            f['allow_custom'] = True   # every value a chronicle already typed stays valid
            option_sources.append(f"{f['name']}: GVM menu(s) " + ', '.join(f'"{m}"' for m in menu_names) + f' ({len(options)} options)')
        fields.append(io.ordered(f, FIELD_ORDER))
    out = OrderedDict([('fields', fields)])
    for k, v in d.items():
        if k != 'fields':
            out[k] = v
    io.write('blocks', slug, blk['name'], 'block', out, (sources or [LIVE_SOURCE]) + option_sources,
             section_type='identity_field',
             notes=notes or ('1.3.0 gap-audit class B: options from the Grapevine menus, `allow_custom` on so held free-text values stay valid.' if option_sources else None))
    REPORT[slug] = {'fields': len(fields), 'option_lists': option_sources}


def emit_pools(slug, pool_patch=None, sources=None, notes=None):
    blk = live(slug)
    pools = []
    for p in blk['definition']['pools']:
        p = dict(p)
        if pool_patch and p['name'] in pool_patch:
            p.update(pool_patch[p['name']])
        pools.append(io.ordered(p, POOL_ORDER))
    out = OrderedDict([('pools', pools)])
    for k, v in blk['definition'].items():
        if k != 'pools':
            out[k] = v
    io.write('blocks', slug, blk['name'], 'block', out, sources or [LIVE_SOURCE],
             section_type='resource_pool', notes=notes)
    REPORT[slug] = {'pools': len(pools)}


def tiered_def(live_def, meta, powers, extra=None):
    d = OrderedDict()
    d['_meta'] = meta
    for k, v in live_def.items():
        if k in ('_meta', 'powers', 'approval_rules', 'out_of_type_cost_modifier'):
            continue  # out_of_type_cost_modifier is deprecated: _meta.out_of_type replaces it
        d[k] = v
    if extra:
        d.update(extra)
    d['powers'] = powers
    return d


def measure(powers):
    """rungs / picks / overflow / unknown for the before-and-after table."""
    rungs = sum(len(p.get('levels') or []) for p in powers)
    picks = sum(len(v) for p in powers for v in (p.get('elder') or {}).values())
    over = sum(len(p.get('overflow') or []) for p in powers)
    unknown = sum(1 for p in powers
                  for x in (p.get('levels') or []) + (p.get('overflow') or [])
                  + [y for v in (p.get('elder') or {}).values() for y in v]
                  if x.get('tier') == 'unknown')
    return {'families': len(powers), 'rungs': rungs, 'picks': picks, 'overflow': over, 'unknown': unknown}


# --- Werewolf rites (D4) --------------------------------------------------------------------

def emit_werewolf_rites():
    rpath = io.SAMPLES / 'research' / 'fera' / 'resolved.json'
    fera = json.load(open(rpath)) if rpath.exists() else None
    rite_type = {}
    if fera:
        for r in fera['garou']['rites']:
            if r.get('rite_type'):
                t = r['rite_type'].replace('Mystical', 'Mystic')
                rite_type[io.norm(r['name'])] = t
    tiers = {'basic': 'basic', 'int.': 'intermediate', 'adv.': 'advanced', 'minor': None}
    blk = live('werewolf-rites')
    items, cat_known = [], 0
    for i in blk['definition']['items']:
        note = (i.get('note') or '').strip()
        head = note.split(' ')[0] if note else ''
        tier = tiers.get(note, tiers.get(head))
        group = re.sub(r', Rites$', '', i.get('source') or '') or None
        group = R.WEREWOLF_GROUP_RENAME.get(group, group)
        category = 'Minor' if note == 'minor' else rite_type.get(io.norm(i['name']))
        if category:
            cat_known += 1
        extra_note = note[len(head):].strip() if head in tiers and note != head else None
        it = io.trait_item(i, tier=tier, group=group,
                           note=extra_note or (note if tier is None and note != 'minor' else None))
        it['category'] = category
        if note == 'minor':
            it['cost'] = None  # bought with the Rites Background, two per dot (LotW Revised)
        items.append(it)
    # The ten category submenus of "Rites, Werewolf" - never seeded, priced in the GVM except
    # Minor, which is correctly unpriced (bought with the Rites Background).
    menus = gvm_menus()
    tier_of = {'basic': 'basic', 'int.': 'intermediate', 'adv.': 'advanced'}
    by_key = {(io.norm(i['name']), i['group']): i for i in items}

    def rite_item(it, group, category, source):
        note = (it.get('note') or '').strip()
        head = note.split(',')[0].split(' ')[0]
        tier = tier_of.get(head)
        rest = note[len(head):].strip(' ,') or None
        key = (io.norm(it.get('name')), group)
        if key in by_key:
            row = by_key[key]
            row['category'] = row.get('category') or category
            return
        # Section 5.1 rule 1: the same rite under a tribal menu and a category menu, at the same
        # cost and tier, is one entry - the category joins it and the tribe stays its group.
        same = [r for (n, _), r in by_key.items() if n == io.norm(it.get('name'))
                and r['cost'] == it.get('cost') and r['tier'] == tier_of.get((it.get('note') or '').split(',')[0].split(' ')[0])]
        if same:
            for r in same:
                r['category'] = r.get('category') or category
                if source not in (r['source'] or ''):
                    r['source'] = f"{r['source']}; {source}"
            return
        row = io.trait_item({'name': it.get('name')}, cost=it.get('cost'), tier=tier, group=group,
                            source=source, note=rest)
        row['category'] = category
        by_key[key] = row
        items.append(row)

    for label, menu in R.RITE_CATEGORY_MENUS.items():
        for it in menus[menu].findall('item'):
            rite_item(it, None, label, f'GVM: {menu}')
    emit_trait_list('werewolf-rites', items=items,
                    extra={'categories': R.RITE_CATEGORIES[:10]},
                    sources=[LIVE_SOURCE,
                             'Grapevine Menus XML.gvm: the ten category submenus of "Rites, Werewolf" (Accord, Caern, Death, Frontier, Minor, Mystic, Punishment, Pure Ones, Renown, Seasonal) - cost and tier from each item\'s own GVM cost and note; Minor rites stay unpriced, bought with the Rites Background',
                             'tier: parsed from each rite\'s own note (basic / int. / adv. / minor)',
                             'group: the tribe, parsed from the GVM source menu ("Black Furies, Rites")',
                             'category: samples/research/fera/resolved.json garou.rites[].rite_type (Laws of the Wild Revised and the OWBN Changing Breeds packets), where the rite is there by name'],
                    notes=f'{cat_known} of {len(items)} rites carry a category; the rest are tribebook rites no captured source categorises (1.3.0 doc, D4).')
    REPORT['werewolf-rites']['category_known'] = sum(1 for i in items if i.get('category'))
    REPORT['werewolf-rites']['from_category_menus'] = sum(1 for i in items if (i['source'] or '').startswith('GVM: Rites, Werewolf'))

    # fera-rites: one block of its own, group = species, category where the menu nests one.
    fera_items, fera_keys = [], set()

    def fera_add(menu, group, category):
        for it in menus[menu].findall('item'):
            note = (it.get('note') or '').strip()
            head = note.split(',')[0].split(' ')[0]
            tier = tier_of.get(head)
            rest = note[len(head):].strip(' ,') or None
            key = (io.norm(it.get('name')), group)
            if key in fera_keys:
                continue
            fera_keys.add(key)
            row = io.trait_item({'name': it.get('name')}, cost=it.get('cost'), tier=tier, group=group,
                                subgroup=category, source=f'GVM: {menu}', note=rest)
            row['category'] = category if category in R.RITE_CATEGORIES else None
            fera_items.append(row)

    for species in R.FERA_RITE_MENUS:
        menu = f'Rites, {species}'
        fera_add(menu, species, None)
        for sub in menus[menu].findall('submenu'):
            label, link = sub.get('name'), sub.get('link') or sub.get('name')
            fera_add(link, label if label in R.FERA_RITE_OWN_GROUP else species,
                     None if label in R.FERA_RITE_OWN_GROUP else label)
    unruled = [i['name'] for i in fera_items + items
               if not i['cost'] and (i.get('category') or '') != 'Minor' and (i['note'] or '') != 'minor'
               and i['name'] not in R.RITE_UNPRICED]
    if unruled:
        raise SystemExit('Rite with no cost and no ruling - rule it in rulings.RITE_UNPRICED: ' + ', '.join(unruled))
    for i in fera_items + items:
        if not i['cost'] and i['name'] in R.RITE_UNPRICED:
            i['note'] = ((i['note'] + '; ') if i['note'] else '') + R.RITE_UNPRICED[i['name']]
    fera_items.sort(key=lambda i: ((i['group'] or ''), i['name'].lower()))
    io.write('blocks', 'fera-rites', 'Rites', 'block',
             io.trait_definition({'alphabetize': False, 'atomic': True, 'allow_custom': True, 'allow_multiples': False,
                                  'categories': R.RITE_CATEGORIES}, fera_items),
             ['Grapevine Menus XML.gvm: the twelve species menus under "Rites, Fera" (Bastet and Mokole nest their own categories; Corax nests Buzzard, its own Fera type)',
              'Cost and tier from each item\'s own GVM cost and note (basic 2 / int. 4 / adv. 6)'],
             section_type='trait_list',
             notes='1.3.0, gap-audit class D. A block of its own rather than more groups inside werewolf-rites, following Decision 046\'s fera-gifts split - see the owner question.')
    REPORT['fera-rites'] = {'items': len(fera_items),
                            'by_group': dict(Counter(i['group'] for i in fera_items)),
                            'priced': sum(1 for i in fera_items if i['cost'])}


# --- Changeling ------------------------------------------------------------------------------

def emit_changeling():
    blk = live('changeling-arts')
    d = blk['definition']
    costs = {'basic': 3, 'intermediate': 6, 'advanced': 9}
    before = measure(d['powers'])
    powers, stig_src = [], None
    for p in d['powers']:
        name = p['name']
        if name == 'Stigma':
            stig_src = p
            continue
        existing = {io.norm(x['power_name']): x for x in p['levels'] + (p.get('overflow') or [])}
        if name in R.CHANGELING_LADDERS:
            plan = R.CHANGELING_LADDERS[name]
        else:
            plan = [(x['tier'], x['power_name']) for x in p['levels']]
        levels = []
        for n, (tier, pname) in enumerate(plan, 1):
            src = existing.get(io.norm(pname))
            alts = []
            for alt_name, why in R.CHANGELING_ALTERNATIVES.get(name, {}).get(n, []):
                alts.append(OrderedDict([('power_name', alt_name), ('note', why)]))
            levels.append(io.level(n, tier, src['power_name'] if src else pname, costs[tier],
                                   note=None if src else 'added to fill the ladder - see provenance',
                                   alternatives=alts or None))
        powers.append(io.family(name, levels, source=p.get('source')))
    meta = io.meta(['basic', 'intermediate', 'advanced'], {'basic': 2, 'intermediate': 2, 'advanced': 1},
                   costs, out_of_type={'basic': '+1', 'intermediate': '+1', 'advanced': '+1'})
    io.write('blocks', 'changeling-arts', blk['name'], 'block', tiered_def(d, meta, powers),
             [LIVE_SOURCE,
              'OWBN0019 Changeling Mechanics Packet (2017) ch. 5 p. 28-38 - every Art in 2/2/1',
              'The Shining Host (WW05009) p. 130-140; Changeling Players Guide (WW07100) Autumn People errata',
              'Laws of the Hunt Revised (WW05014) p. 232-236 - the Dauntain Agendas Burnout, Stultify, Webcraft at 2/2/1',
              'The Autumn People (WW07004) p. 51-52 (Burnout, Stultify) and p. 65-66 (Stigmas)',
              'Laws of the Wild-style dot mapping: 1-2 Basic, 3-4 Intermediate, 5 Advanced; Arts 3/6/9 (OWBN0016 p. 7)'],
             section_type='tiered_power',
             notes='Every correction and its evidence: tools/catalog/rulings.py (CHANGELING_*).')
    REPORT['changeling-arts'] = {'before': before, 'after': measure(powers)}

    # Stigmas: out of the Art ladder into their own unranked list.
    st_items = []
    for s, page in R.CHANGELING_STIGMAS:
        st_items.append(io.trait_item({'name': s}, source='Laws of the Hunt Revised',
                                      description={'reference': f'Laws of the Hunt Revised (WW05014), p. {page}'},
                                      note='Dauntain Stigma - gained at Storyteller discretion; each adds one permanent Banality'))
    defn = OrderedDict([('alphabetize', True), ('atomic', True), ('allow_custom', True),
                        ('allow_multiples', False), ('has_specializations', False), ('negative', False),
                        ('flat_cost', False), ('items', st_items)])
    io.write('blocks', 'changeling-stigmas', 'Stigmas', 'block', defn,
             ['Laws of the Hunt Revised (WW05014) p. 229-231, via samples/research/hunter/mortal/resolved.json (dauntain_stigmas)',
              'The Autumn People (WW07004) p. 65-66, "Stigmas" (page-image OCR) - the tabletop source, seven of the nine',
              'Moved out of changeling-arts, where GVM filed six of them alphabetically as a fake five-rung Art'],
             section_type='trait_list')
    REPORT['changeling-stigmas'] = {'items': len(st_items), 'moved_from_arts': len(stig_src['levels']) + len(stig_src.get('overflow') or [])}

    # Realms (D8): an untiered track, flat 2 per level - declared, not reached by fallback.
    blk = live('changeling-realms')
    d = blk['definition']
    before = measure(d['powers'])
    powers = []
    for p in d['powers']:
        levels = [io.level(x['level'], None, x['power_name'], 2) for x in p['levels']]
        for lv in levels:
            del lv['tier']  # an untiered track has no tier - `unknown` resolved by declaration
        powers.append(io.family(p['name'], levels, source=p.get('source')))
    meta = io.meta([], {}, {}, untiered={'cost_per_level': 2})
    io.write('blocks', 'changeling-realms', blk['name'], 'block', tiered_def(d, meta, powers),
             [LIVE_SOURCE, 'Realms cost 2 per level, no tier vocabulary (1.2.10 S7; 1.3.0 D8)'],
             section_type='tiered_power')
    REPORT['changeling-realms'] = {'before': before, 'after': measure(powers)}


# --- Wraith (D1) ---------------------------------------------------------------------------

def wraith_family(p, packet, spelling, costs):
    """One Arcanos laid out on the packet's 2/2/1 with every book power kept."""
    book = {}
    for x in p['levels'] + (p.get('overflow') or []):
        book[io.norm(x['power_name'])] = (x['tier'], x['power_name'], x.get('note'))
    innate_book = [x['power_name'] for x in (p.get('elder') or {}).get('innate', [])]

    def be_name(n):
        n = spelling.get(n, n)
        for pool in (list(book.values()), [(None, i, None) for i in innate_book]):
            for _, bn, _ in pool:
                if io.norm(bn) == io.norm(n):
                    return bn
        return n

    plan = [('basic', packet['basic'][0]), ('basic', packet['basic'][1]),
            ('intermediate', packet['intermediate'][0]), ('intermediate', packet['intermediate'][1]),
            ('advanced', packet['advanced'][0])]
    placed = {io.norm(be_name(n)) for _, n in plan} | {io.norm(be_name(n)) for n in packet['innate']}
    first_rung = {'basic': 1, 'intermediate': 3, 'advanced': 5}
    alts = {}
    for key, (tier, bn, note) in book.items():
        if key not in placed:
            why = 'the book-sourced seed prints it at this tier; the OWBN Arcanoi packet does not place it'
            if note and 'great war' in note:
                why = 'Wraith: The Great War printing; the OWBN Arcanoi packet does not place it'
            alts.setdefault(first_rung[tier], []).append(OrderedDict([('power_name', bn), ('note', why)]))
    book_innate = {io.norm(n) for n in innate_book}
    levels = []
    for n, (t, nm) in enumerate(plan, 1):
        moved = 'the book-sourced seed files this as an Innate Ability; the OWBN Arcanoi packet makes it a rung' \
            if io.norm(be_name(nm)) in book_innate else None
        levels.append(io.level(n, t, be_name(nm), costs[t], note=moved, alternatives=alts.get(n)))
    innates = []
    for n in packet['innate']:
        innates.append(io.pick('innate', be_name(n), costs['innate']))
    on_ladder = {io.norm(lv['power_name']) for lv in levels}
    for n in innate_book:  # a book innate the packet dropped stays an innate
        if io.norm(n) not in {io.norm(i['power_name']) for i in innates} | on_ladder:
            innates.append(io.pick('innate', n, costs['innate'], note='book-sourced innate; not in the OWBN Arcanoi packet'))
    return io.family(p['name'], levels, elder={'innate': innates}, source=p.get('source'))


def emit_wraith():
    blk = live('wraith-arcanoi')
    d = blk['definition']
    before = measure(d['powers'])
    ranks = ['innate', 'basic', 'intermediate', 'advanced']
    ladder = {'basic': 2, 'intermediate': 2, 'advanced': 1}
    book_costs = {'innate': 2, 'basic': 4, 'intermediate': 6, 'advanced': 9}
    guild = {'innate': '+0', 'basic': '-1', 'intermediate': '-1', 'advanced': '-1'}

    def build(costs, literal_packet):
        powers = []
        for p in d['powers']:
            name = p['name']
            if name in R.WRAITH_PACKET:
                if literal_packet:
                    pk = R.WRAITH_PACKET[name]
                    plan = [('basic', pk['basic'][0]), ('basic', pk['basic'][1]),
                            ('intermediate', pk['intermediate'][0]), ('intermediate', pk['intermediate'][1]),
                            ('advanced', pk['advanced'][0])]
                    levels = [io.level(n, t, nm, costs[t]) for n, (t, nm) in enumerate(plan, 1)]
                    innates = [io.pick('innate', nm, costs['innate']) for nm in pk['innate']]
                    powers.append(io.family(name, levels, elder={'innate': innates}, source=p.get('source')))
                else:
                    powers.append(wraith_family(p, R.WRAITH_PACKET[name], R.WRAITH_PACKET_SPELLING, costs))
                continue
            by_name_all = {io.norm(x['power_name']): x for x in p['levels'] + (p.get('overflow') or [])}
            if name in R.WRAITH_SPLIT_FAMILIES:
                # Owner ruling: two families sharing their opening rungs, not one ladder.
                for fam_name, rungs, evidence in R.WRAITH_SPLIT_FAMILIES[name]:
                    missing = [r for r in rungs if io.norm(r) not in by_name_all]
                    if missing:
                        raise SystemExit(f'{fam_name}: no seeded cantrip for {missing} - rule it rather than leave a short ladder')
                    levels = []
                    for n, r in enumerate(rungs, 1):
                        x = by_name_all[io.norm(r)]
                        levels.append(io.level(n, ('basic' if n <= 2 else 'intermediate' if n <= 4 else 'advanced'),
                                               x['power_name'], costs['basic' if n <= 2 else 'intermediate' if n <= 4 else 'advanced'],
                                               note=(evidence if n == 1 else None)))
                    innates = [io.pick('innate', x['power_name'], costs['innate']) for x in (p.get('elder') or {}).get('innate', [])]
                    powers.append(io.family(fam_name, levels, elder={'innate': innates}, source=p.get('source'),
                                            aliases=[name]))
                continue
            # Not in the packet: the book (or Dark Kingdom) ladder is already 2/2/1.
            alt_rungs = {}
            alt_names = {io.norm(a) for v in alt_rungs.values() for a in v}
            rungs = [x for x in p['levels'] + (p.get('overflow') or []) if io.norm(x['power_name']) not in alt_names]
            by_name = {io.norm(x['power_name']): x for x in p['levels'] + (p.get('overflow') or [])}
            levels = []
            for n, x in enumerate(rungs, 1):
                alts = [OrderedDict([('power_name', by_name[io.norm(a)]['power_name']),
                                     ('note', 'Left Hand - Wraith: The Oblivion 2nd ed. p. 465-467')])
                        for a in alt_rungs.get(n, [])]
                levels.append(io.level(n, x['tier'], x['power_name'], costs[x['tier']], alternatives=alts or None))
            innates = [io.pick('innate', x['power_name'], costs['innate']) for x in (p.get('elder') or {}).get('innate', [])]
            powers.append(io.family(name, levels, elder={'innate': innates}, source=p.get('source')))
        return powers

    base = build(book_costs, False)
    meta = io.meta(ranks, ladder, book_costs, out_of_type=guild)
    io.write('blocks', 'wraith-arcanoi', blk['name'], 'block', tiered_def(d, meta, base),
             [LIVE_SOURCE,
              'Oblivion (WW05400) p. 165 - Innate 2, Basic 4, Intermediate 6, Advanced 9; Guild apprentices -1 except Innate',
              'OWBN0124 Wraith Arcanoi packet (2016) - the 2/2/1 arrangement for 16 Arcanoi plus Risen Fascinate',
              'Wraith: The Oblivion 2nd ed. (WW06600) p. 465-467 - Behest\'s Right and Left Hands'],
             section_type='tiered_power',
             notes='Owner ruling 2026-09-21 (1.3.0 D1): the book is the base, the packet ships as the owbn-wraith_arcanoi variant. Evidence per family: tools/catalog/rulings.py (WRAITH_*).')
    REPORT['wraith-arcanoi'] = {'before': before, 'after': measure(base)}

    # The OWBN variant: the packet literally, at the packet's prices. Innate Abilities come
    # "at no additional cost" with the first Basic (OWBN0124 PDF p. 3) - a real 0, not a stand-in.
    pk_costs = {'innate': 0, 'basic': 4, 'intermediate': 7, 'advanced': 10}
    variant = build(pk_costs, True)
    meta = io.meta(ranks, ladder, pk_costs, out_of_type=guild)
    io.write('blocks', 'owbn-wraith_arcanoi', 'Arcanoi (OWBN)', 'block', tiered_def(d, meta, variant),
             ['OWBN0124 Wraith Arcanoi packet (2016), Christian DeBats - Basic 4 / Intermediate 7 / Advanced 10; Innate Abilities free with the first Basic',
              'Arcanoi the packet does not cover are carried from the base file unchanged'],
             section_type='tiered_power',
             variant=OrderedDict([('of', 'wraith-arcanoi'), ('id', 'owbn'),
                                  ('label', 'OWBN Arcanoi packet (2016)'), ('mode', 'replace')]))
    REPORT['owbn-wraith_arcanoi'] = measure(variant)


# --- Mummy (D5, D6) --------------------------------------------------------------------------

def emit_mummy():
    blk = live('mummy-hekau')
    d = blk['definition']
    before = measure(d['powers'])
    costs = {'basic': 3, 'intermediate': 6, 'advanced': 9}
    note_rung = [('first basic', 1), ('second basic', 2), ('first int', 3), ('second int', 4), ('adv', 5)]
    tier_of = {1: 'basic', 2: 'basic', 3: 'intermediate', 4: 'intermediate', 5: 'advanced'}
    formulae, seen = [], set()
    dot_names, masters = {}, {}
    for p in d['powers']:
        path = R.MUMMY_ALIASES.get(p['name'], p['name'])
        if p.get('source') == 'Hekau, Levels':
            continue  # the generic rung stub - the default ladder below is exactly this
        for x in p['levels'] + (p.get('overflow') or []) + [y for v in (p.get('elder') or {}).values() for y in v]:
            pname, note = x['power_name'], (x.get('note') or '').lower()
            if re.fullmatch(r'(first|second) (basic|intermediate)|advanced', pname.lower()):
                continue  # a generic rung name inside the second-edition menu
            if pname == 'Intermediate Weather Magic I' and note == 'int.':
                # Mis-noted in the GVM: its siblings are "Basic Weather Magic I/II" (first/second
                # basic ritual), "Intermediate Weather Magic II" (second int. ritual) and
                # "Advanced Weather Magic" (adv. ritual) - Laws of the Resurrection p. 141-149
                # prints Weather Magic as Celestial rituals at each tier. It is the first int. ritual.
                note = 'first int. ritual'
            if note in ('basic', 'int.', 'adv.'):
                # A second-edition dot name (Ren-Hekau's "Simple Names", Celestial's "Touch the
                # Air"): priced 3/6/9 and noted by tier alone - rung-shaped, exactly the 40
                # 1.2.10 A4 calls "the real Hekau ladder". It names that path's rung.
                dot_names.setdefault(path, []).append(({'basic': 'basic', 'int.': 'intermediate', 'adv.': 'advanced'}[note], pname))
                continue
            if note == 'master':
                masters.setdefault(path, []).append(pname)
                continue
            if 'ritual' in note or 'formula' in note:
                kind = 'ritual' if 'ritual' in note else 'formula'
                rung = next((r for k, r in note_rung if note.startswith(k)), None)
            else:
                raise SystemExit(f'mummy: unclassifiable {p["name"]} / {pname} / {note!r}')
            tier = tier_of[rung]
            key = (path, io.norm(pname))
            if key in seen:
                continue
            seen.add(key)
            prereq = [OrderedDict([('block_slug', 'mummy-hekau'), ('power', path), ('min_level', rung)])]
            formulae.append(io.trait_item({'name': pname}, cost=R.MUMMY_FORMULA_COST[tier], tier=tier, group=path,
                                          subgroup=kind, source=p.get('source'), prerequisites=prereq))
    powers = []
    for path in R.MUMMY_PATHS:
        order = {'basic': 0, 'intermediate': 1, 'advanced': 2}
        named = sorted(dot_names.get(path, []), key=lambda x: order[x[0]]) or None
        plan = named if named and [t for t, _ in named] == [t for t, _ in R.MUMMY_RUNGS] else R.MUMMY_RUNGS
        if named and plan is R.MUMMY_RUNGS:
            raise SystemExit(f'mummy: {path} dot names do not fill 2/2/1: {named}')
        levels = [io.level(n, t, nm, costs[t]) for n, (t, nm) in enumerate(plan, 1)]
        elder = {'master': [io.pick('master', m, 12, note='master-level working') for m in masters[path]]} if path in masters else {}
        aliases = [a for a, c in R.MUMMY_ALIASES.items() if c == path]
        f = io.family(path, levels, elder=elder, source='Laws of the Resurrection, Hekau', aliases=aliases or None)
        if not aliases:
            del f['aliases']
        powers.append(f)
    meta = io.meta(['basic', 'intermediate', 'advanced', 'master'], {'basic': 2, 'intermediate': 2, 'advanced': 1},
                   dict(costs, master=12), out_of_type={'basic': '+1', 'intermediate': '+1', 'advanced': '+1', 'master': '+1'})
    io.write('blocks', 'mummy-hekau', blk['name'], 'block', tiered_def(d, meta, powers),
             [LIVE_SOURCE,
              'Laws of the Resurrection (WW05035) p. 116 - Hekau 3/6/9, +1 outside the primary path',
              'Laws of the Resurrection p. 120, "Working Magic" - the path rating is the ladder; spells and rituals are bought separately (mummy-formulae)',
              'Ren-Hekau = Nomenclature, Ushabti = Effigy (research/mummy/NOTES.md; core book: "Effigy (called Ushabti in ancient Egypt)")'],
             section_type='tiered_power',
             notes='1.3.0 D5/D6. The formula menus split out to mummy-formulae; the 40 unknown-tier rungs resolve to basic/basic/intermediate/intermediate/advanced by the book\'s path-rating rule.')
    REPORT['mummy-hekau'] = {'before': before, 'after': measure(powers)}

    fdef = OrderedDict([('alphabetize', False), ('atomic', True), ('allow_custom', True), ('allow_multiples', False),
                        ('has_specializations', False), ('negative', False), ('flat_cost', False),
                        ('categories', R.MUMMY_PATHS), ('items', formulae)])
    io.write('blocks', 'mummy-formulae', 'Spells and Rituals', 'block', fdef,
             [LIVE_SOURCE + ' - the second-edition formula menus inside mummy-hekau',
              'Laws of the Resurrection (WW05035) p. 116 - "New spell or ritual - One Experience for Basic, three for Intermediate and five for Advanced", +1 outside the primary path',
              'Laws of the Resurrection p. 120 - a formula needs the path rating its note names ("first basic" 1 ... "adv." 5)'],
             section_type='trait_list',
             notes='1.3.0 D5. group = Hekau path, subgroup = spell / ritual / formula as the note names it. Costs repriced from the GVM\'s 2/4/6 to the book\'s 1/3/5 - forward-only, nothing refunded.')
    REPORT['mummy-formulae'] = {'items': len(formulae), 'by_tier': dict(Counter(i['tier'] for i in formulae))}


# --- Gifts (tiered_power, pick-only) -----------------------------------------------------------

GIFT_TIERS = {'basic': 'basic', 'int.': 'intermediate', 'adv.': 'advanced', 'legend': 'legend'}


def gift_tier(raw):
    """(rank, variant id, qualifier) from a GVM tier/note string like 'int., wyld west'."""
    raw = (raw or '').strip().lower()
    if not raw:
        return None, None, None
    head = re.split(r'[ ,]', raw, 1)[0]
    rank = GIFT_TIERS.get(head)
    rest = raw[len(head):].strip(' ,')
    variant = {'wyld west': 'wyld-west', 'dark ages': 'dark-ages'}.get(rest)
    qualifier = None if variant or not rest else rest
    return rank, variant, qualifier


def gifts_from(items, family_of, category_of, resolved_tier=None):
    base, variants = OrderedDict(), {}
    costs = {'basic': 3, 'intermediate': 6, 'advanced': 9, 'legend': 12}
    unresolved = []
    for i in items:
        rank, variant, qualifier = gift_tier(i.get('tier') or i.get('note'))
        if rank is None and resolved_tier:
            rank = resolved_tier(i)
        if rank is None:
            raise SystemExit(f'gift with no resolvable rank - nothing is dropped silently: {i}')
        fam = family_of(i)
        if fam is None:
            variant = variant or 'beast-courts'
        bucket = base if variant is None else variants.setdefault(variant, OrderedDict())
        fam_name = fam or i.get('group')
        entry = bucket.setdefault(fam_name, {'category_values': category_of(i), 'source': i.get('source'), 'elder': OrderedDict()})
        entry['elder'].setdefault(rank, []).append(
            io.pick(rank, i['name'], costs[rank], note=qualifier, source=i.get('source')))
    return base, variants, unresolved


def gift_families(bucket):
    out = []
    order = ['basic', 'intermediate', 'advanced', 'legend']
    for name, e in bucket.items():
        elder = OrderedDict((r, e['elder'][r]) for r in order if r in e['elder'])
        out.append(io.family(name, [], elder=elder, category_values=e['category_values']))
    return out


def gift_meta(categories, legend=False):
    ranks = ['basic', 'intermediate', 'advanced'] + (['legend'] if legend else [])
    costs = {'basic': 3, 'intermediate': 6, 'advanced': 9}
    oot = {'basic': '+1', 'intermediate': '+1', 'advanced': '+1'}
    levels = {'basic': 1, 'intermediate': 3, 'advanced': 5}
    if legend:
        costs['legend'] = 12
        oot['legend'] = '+1'
    return io.meta(ranks, {}, costs, out_of_type=oot, levels=levels, categories=categories)


def emit_gifts():
    ident = {f['name']: f.get('options') or [] for f in live('werewolf-identity')['definition']['fields']}
    axis = {}
    for cat, field in (('breed', 'Breed'), ('auspice', 'Auspice'), ('tribe', 'Tribe')):
        for v in ident[field]:
            axis[v] = cat
    ww = live('werewolf-gifts')

    def ww_family(i):
        g = R.WEREWOLF_GROUP_RENAME.get(i.get('group'), i.get('group'))
        return None if g in R.BEAST_COURTS_GROUPS else g

    def ww_cat(i):
        g = R.WEREWOLF_GROUP_RENAME.get(i.get('group'), i.get('group'))
        return {axis.get(g, 'tribe'): g}

    base, variants, unresolved = gifts_from(ww['definition']['items'], ww_family, ww_cat)
    uncategorised = sorted({n for n in base if n not in axis})
    fams = gift_families(base)
    wdef = OrderedDict([('_meta', gift_meta(['breed', 'tribe', 'auspice'], legend=True)),
                        ('atomic', True), ('allow_custom', True), ('powers', fams)])
    common = [LIVE_SOURCE + ' (werewolf-gifts, a trait_list until 1.3.2\'s A4a conversion)',
              'Rank, variant and qualifier parsed from each gift\'s own GVM tier note ("int., wyld west")',
              'Category axis joined from werewolf-identity\'s Breed / Auspice / Tribe options (format §9 pilot)']
    io.write('blocks', 'werewolf-gifts', 'Gifts', 'block', wdef, common, section_type='tiered_power',
             notes='Pick-only: every Gift is bought by name, so the ladder is declared empty. "legend" is the GVM\'s own rank for two cost-12 tribal Gifts (owner question). ' +
                   (f'Tribes with no identity option, filed under tribe: {", ".join(uncategorised)}.' if uncategorised else ''))
    REPORT['werewolf-gifts'] = {'families': len(fams), 'gifts': sum(len(v) for f in fams for v in f['elder'].values()),
                                'unresolved_tier': unresolved, 'uncategorised_groups': uncategorised}
    labels = {'wyld-west': ('wyldwest', 'Laws of the Wyld West'), 'dark-ages': ('darkages', 'Dark Ages printings'),
              'beast-courts': ('hengeyokai', 'Hengeyokai: Way of the Beast Courts')}
    for vid, bucket in variants.items():
        prefix, label = labels[vid]
        fams_v = gift_families(bucket)
        slug = f'{prefix}-werewolf_gifts'
        io.write('blocks', slug, f'Gifts ({label})', 'block',
                 OrderedDict([('_meta', gift_meta(['breed', 'tribe', 'auspice'])), ('atomic', True),
                              ('allow_custom', True), ('powers', fams_v)]),
                 common + [f'Carried as a variant, not base content (format §4b): "{vid}"'],
                 section_type='tiered_power',
                 variant=OrderedDict([('of', 'werewolf-gifts'), ('id', vid), ('label', label), ('mode', 'add')]))
        REPORT[slug] = {'families': len(fams_v), 'gifts': sum(len(v) for f in fams_v for v in f['elder'].values())}

    # Fera: species + subgroup, two levels deep.
    resolved = {}
    rpath = io.SAMPLES / 'research' / 'fera' / 'resolved.json'
    if rpath.exists():
        fr = json.load(open(rpath))
        for sp, body in fr.items():
            if isinstance(body, dict) and 'gifts' in body:
                for g in body['gifts']:
                    t = (g.get('tier') or '').lower()
                    if t in ('basic', 'intermediate', 'advanced'):
                        resolved.setdefault(io.norm(g['name']), {}).setdefault(sp, set()).add(t)
    fe = live('fera-gifts')

    def fe_family(i):
        if i.get('group') == 'Fera' and i.get('subgroup') == 'Hengeyokai':
            return None
        return f"{i['group']}: {i['subgroup']}" if i.get('subgroup') else i['group']

    def fe_cat(i):
        c = OrderedDict([('species', i['group'])])
        if i.get('subgroup'):
            c['subgroup'] = i['subgroup']
        return c

    def fe_resolved(i):
        # The species' own resolved bucket wins (Sense Silver is Basic for Garou, Intermediate
        # for Bastet - Changing Breeds 1 p. 176); otherwise only an unambiguous tier is taken.
        by_sp = resolved.get(io.norm(i['name']), {})
        own = by_sp.get((i.get('group') or '').lower())
        if own and len(own) == 1:
            return next(iter(own))
        every = set().union(*by_sp.values()) if by_sp else set()
        return next(iter(every)) if len(every) == 1 else None

    base, variants, unresolved = gifts_from(fe['definition']['items'], fe_family, fe_cat, fe_resolved)
    fams = gift_families(base)
    io.write('blocks', 'fera-gifts', 'Gifts', 'block',
             OrderedDict([('_meta', gift_meta(['species', 'subgroup'])), ('atomic', True), ('allow_custom', True), ('powers', fams)]),
             [LIVE_SOURCE + ' (fera-gifts, a trait_list until 1.3.2\'s A4a conversion)',
              'Rank parsed from each gift\'s GVM tier note; the eight with none resolved by name from samples/research/fera/resolved.json (Changing Breeds 1-4)',
              'Category: species (the GVM group) and subgroup (breed / auspice / camp), per 1.2.10 S4'],
             section_type='tiered_power', notes='Pick-only, like werewolf-gifts.')
    REPORT['fera-gifts'] = {'families': len(fams), 'gifts': sum(len(v) for f in fams for v in f['elder'].values()),
                            'unresolved_tier': unresolved}
    for vid, bucket in variants.items():
        prefix, label = labels[vid]
        fams_v = gift_families(bucket)
        slug = f'{prefix}-fera_gifts'
        io.write('blocks', slug, f'Gifts ({label})', 'block',
                 OrderedDict([('_meta', gift_meta(['species', 'subgroup'])), ('atomic', True), ('allow_custom', True), ('powers', fams_v)]),
                 [LIVE_SOURCE + ' (fera-gifts)', f'Carried as a variant, not base content (format §4b): "{vid}"'],
                 section_type='tiered_power',
                 variant=OrderedDict([('of', 'fera-gifts'), ('id', vid), ('label', label), ('mode', 'add')]))
        REPORT[slug] = {'families': len(fams_v), 'gifts': sum(len(v) for f in fams_v for v in f['elder'].values())}


# --- Abilities per stack (D81) ------------------------------------------------------------------

GVM = io.CODE_ROOT / 'beyond-elysium' / 'data' / 'Grapevine Menus XML.gvm'


def gvm_menus():
    import xml.etree.ElementTree as ET
    return {m.get('name'): m for m in ET.parse(GVM).getroot().iter('menu')}


def menu_items(menus, name, seen=None):
    """A menu's items with its includes resolved (included items first, as Grapevine does),
    each tagged with the menu that actually carries it."""
    seen = seen or set()
    if name in seen:
        raise SystemExit(f'include cycle at {name}')
    seen = seen | {name}
    m = menus[name]
    out = []
    for inc in m.findall('include'):
        out += menu_items(menus, inc.get('link') or inc.get('name'), seen)
    for it in m.findall('item'):
        out.append((it.get('name'), it.get('cost'), it.get('note'), name))
    return out


def menu_submenus(menus, name, seen=None):
    seen = seen or set()
    m = menus[name]
    out = []
    for inc in m.findall('include'):
        out += menu_submenus(menus, inc.get('link') or inc.get('name'), seen | {name})
    out += [(s.get('name'), s.get('link') or s.get('name'), name) for s in m.findall('submenu')]
    return out


def lore_specializations(menus, lores_menu):
    specs = [n for n, _, _, _ in menu_items(menus, lores_menu)]
    for label, link, _ in menu_submenus(menus, lores_menu):
        target = menus.get(link)
        if target is None:
            raise SystemExit(f'Lore submenu {label} links missing menu {link}')
        specs += [f'{label}: {n}' for n, _, _, _ in menu_items(menus, link)]
        specs += [f'{label}: {sub}' for sub, _, _ in menu_submenus(menus, link)]
    out, seen = [], set()
    for x in specs:
        if io.norm(x) not in seen:
            seen.add(io.norm(x))
            out.append(x)
    return out


def emit_abilities():
    import csv
    menus = gvm_menus()
    rows = [r for r in csv.reader(open(io.CODE_ROOT / 'beyond-elysium' / 'data' / 'met-mechanics.csv'))
            if len(r) > 15 and r[2] == 'Ability']
    base_def = live('met-abilities')['definition']
    report = {}
    for stack, menu in R.ABILITY_MENUS.items():
        slug = f'{stack}-abilities'
        items, index = [], {}

        def add(name, source, cost='1', **extra):
            key = io.norm(name)
            if key in index:
                it = index[key]
                if source and source not in (it['source'] or ''):
                    it['source'] = f"{it['source']}; {source}" if it['source'] else source
                return it
            it = io.trait_item({'name': name}, cost=cost, source=source, **extra)
            index[key] = it
            items.append(it)
            return it

        for name, cost, note, src_menu in menu_items(menus, menu):
            if cost:
                raise SystemExit(f'{menu}: {name} carries its own GVM cost {cost} - rule it')
            add(name, f'GVM: {src_menu}')
        lore_links = [(label, link) for label, link, _ in menu_submenus(menus, menu)]
        extra_subs = [l for l, _ in lore_links if l not in ('Lore', *R.LORE_ALIASES.get(stack, []))]
        if extra_subs:
            raise SystemExit(f'{menu}: unhandled submenu(s) {extra_subs}')
        lore = next((link for label, link in lore_links if label == 'Lore'), None)
        csv_added = []
        for r in rows:
            name, subtype, cost, source = r[0], r[4], r[14], r[15]
            if name in R.ABILITY_TYPO_ALIASES:
                continue
            if subtype != 'General' and stack not in R.ABILITY_CSV_SUBTYPE_STACKS.get(subtype, set()):
                continue
            if cost != '1':
                raise SystemExit(f'CSV Ability {name} costs {cost!r} - rule it')
            if io.norm(name) not in index:
                csv_added.append(name)
            add(name, f'met-mechanics.csv: {source}')
        if lore:
            it = add('Lore', f'GVM: {menu} (submenu "Lore" -> "{lore}")')
            it['specializations'] = lore_specializations(menus, lore)
            if R.LORE_ALIASES.get(stack):
                it['aliases'] = R.LORE_ALIASES[stack]
        multiples = 0
        for it in items:
            if it['name'] in R.ABILITY_MULTIPLES:
                it['allow_multiples'] = True   # the label is part of the holding's identity (1.2.11)
                multiples += 1
        meditation = index.get(io.norm('Meditation'))
        if meditation is not None:
            meditation['aliases'] = sorted(R.ABILITY_TYPO_ALIASES)
        items.sort(key=lambda i: i['name'].lower())
        defn = io.trait_definition({'alphabetize': True, 'allow_custom': True, 'allow_multiples': False,
                                    'has_specializations': True}, items)
        name = 'Abilities'
        io.write('blocks', slug, name, 'block', defn,
                 [f'Grapevine Menus XML.gvm: "Abilities" + "{menu}" (includes resolved)' + (f', Lore from "{lore}"' if lore else ''),
                  'met-mechanics.csv Ability rows (General to every stack; Changing Breeds to Werewolf/Fera; Vampire to Vampire)',
                  'Cost: ' + R.ABILITY_COST_SOURCE[stack]],
                 section_type='trait_list',
                 notes='1.3.0 D81 - replaces the shared met-abilities (D20 Backgrounds precedent). An Ability bought per field of study carries `allow_multiples: true` (owner ruling 2026-09-22; the editor reads it at 1.2.11). Per-item `source` says which menu or CSV row carries it; `Meditiation` (a CSV typo) is an alias of Meditation.')
        lore_item = index.get(io.norm('Lore'))
        report[slug] = {'items': len(items), 'creature_menu': sum(1 for i in items if f'GVM: {menu}' in (i['source'] or '') and 'GVM: Abilities;' not in (i['source'] or '') and i['name'] != 'Lore'),
                        'lore_specializations': len(lore_item['specializations']) if lore_item else 0,
                        'allow_multiples': multiples,
                        'csv_only': csv_added}
    REPORT['abilities'] = report
    old = io.CATALOG / 'blocks' / 'met-abilities.json'
    if old.exists():
        old.unlink()


# --- Merits and Flaws per stack (D82) ------------------------------------------------------------

def clean_cost(cost, entry=None):
    """A research file's cost as the catalog's free-text cost ("2", "1-3", "2 or 4"), or None.
    Unit words and category tags are dropped ("1 pt.", "2 point (Supernatural)", "3 Trait");
    a Merit-or-Flaw dual price keeps the Merit side for a Merit entry and the Flaw side otherwise."""
    c = str(cost).strip().lstrip('-')
    m = re.match(r'^(\d+) point \(Merit\) or (\d+) point \(Flaw\)$', c)
    if m:
        kind = str((entry or {}).get('kind') or (entry or {}).get('type') or 'Merit')
        return m.group(1) if 'merit' in kind.lower() else m.group(2)
    c = re.sub(r'\([^)]*\)', '', c)
    c = re.sub(r'\b(traits?|points?|pts?)\b\.?', '', c, flags=re.I)
    c = re.sub(r'\s+to\s+', '-', c)
    c = re.sub(r'\s+', ' ', c).strip()
    return c if re.fullmatch(r'\d+\+?(\s*(-|or)\s*\d+)*', c) else None


def research_costs(spec):
    """{norm(name): (cost, citation)} from one research file (optionally one top-level bucket);
    a glob spec reads every matching file in sorted order."""
    if '*' in spec:
        out = {}
        for f in sorted((io.SAMPLES / 'research').glob(spec)):
            for k, v in research_costs(str(f.relative_to(io.SAMPLES / 'research'))).items():
                out.setdefault(k, v)
        return out
    path, _, bucket = spec.partition('#')
    full = io.SAMPLES / 'research' / path
    if not full.exists():
        return {}
    data = json.load(open(full))
    if bucket:
        data = data.get(bucket, {})
    out = {}

    def walk(o):
        if isinstance(o, dict):
            name, cost = o.get('name'), o.get('cost', o.get('points'))
            if isinstance(name, str) and cost not in (None, '', []) and not isinstance(cost, (dict, list)):
                ref = (o.get('description') or {}).get('reference') if isinstance(o.get('description'), dict) else None
                c = clean_cost(cost, o)
                if c:
                    out.setdefault(io.norm(name), (c, f'samples/research/{path}' + (f' ({ref})' if ref else '')))
            for v in o.values():
                walk(v)
        elif isinstance(o, list):
            for v in o:
                walk(v)
    walk(data)
    return out


def merit_menu_items(menus, name, own=True):
    """Items of a Merits/Flaws menu: includes first (items only), then own items, then - for
    the stack's own menu - each submenu's items, tagged with the submenu label as `group`."""
    m = menus[name]
    out = []
    for inc in m.findall('include'):
        out += merit_menu_items(menus, inc.get('link') or inc.get('name'), own=False)
    for it in m.findall('item'):
        out.append((it.get('name'), it.get('cost'), name, None))
    if own:
        for sub in m.findall('submenu'):
            label, link = sub.get('name'), sub.get('link') or sub.get('name')
            if label in R.MERIT_SKIP_SUBMENUS:
                continue
            for it in menus[link].findall('item'):
                out.append((it.get('name'), it.get('cost'), link, label))
    return out


def emit_merits():
    import csv
    menus = gvm_menus()
    rows = [r for r in csv.reader(open(io.CODE_ROOT / 'beyond-elysium' / 'data' / 'met-mechanics.csv'))
            if len(r) > 15 and r[2] in ('Merit', 'Flaw')]
    report, unruled = {}, []
    for stack, (merit_menu, flaw_menu) in R.MERIT_MENUS.items():
        research = {}
        for spec in R.MERIT_RESEARCH.get(stack, []) + R.MERIT_RESEARCH_ANY_GENRE:
            for k, v in research_costs(spec).items():
                research.setdefault(k, v)
        for kind, menu, csv_type, negative in (('merits', merit_menu, 'Merit', False), ('flaws', flaw_menu, 'Flaw', True)):
            slug = f'{stack}-{kind}'
            generic = 'Merits' if kind == 'merits' else 'Flaws'
            generic_names = {io.norm(i.get('name')) for i in menus[generic].findall('item')}
            items, index = [], {}
            stats = {'conflicts': 0}

            def add(name, cost, source, group=None):
                key = io.norm(name)
                if key in index:
                    it = index[key]
                    if source not in (it['source'] or ''):
                        it['source'] = f"{it['source']}; {source}" if it['source'] else source
                    if cost and it['cost'] and cost != it['cost']:
                        stats['conflicts'] += 1
                        it['note'] = ((it['note'] + '; ') if it['note'] else '') + f'{source} prices it {cost}'
                    elif cost and not it['cost']:
                        it['cost'] = cost
                    if group and not it['group']:
                        it['group'] = group
                    return it
                it = io.trait_item({'name': name}, cost=cost, source=source, group=group)
                index[key] = it
                items.append(it)
                return it

            # Most specific first: the creature menu's own items and submenus, then what its
            # includes bring in (generic last), so a creature price beats the generic one.
            flat = merit_menu_items(menus, menu)
            own = [x for x in flat if x[2] != generic]
            gen = [x for x in flat if x[2] == generic]
            for name, cost, src_menu, group in own + gen:
                if name in R.MERIT_DROP:
                    continue
                add(name, cost, f'GVM: {src_menu}', group)
            for r in rows:
                if r[2] != csv_type:
                    continue
                name, cost, source = r[0], (r[14].lstrip('-') or None), r[15]
                key = io.norm(name)
                routed = key in generic_names or key in index or stack in R.MERIT_CSV_SOURCE_STACKS.get(source, set()) \
                    or (stack == 'vampire' and source not in R.MERIT_CSV_SOURCE_STACKS)
                if routed:
                    add(name, cost, f'met-mechanics.csv: {source}')
            priced_from_research = 0
            for it in items:
                if it['cost']:
                    continue
                hit = research.get(io.norm(it['name'])) or R.MERIT_BOOK_COSTS.get(it['name'])
                if hit:
                    it['cost'] = hit[0]
                    it['note'] = ((it['note'] + '; ') if it['note'] else '') + f'cost from {hit[1]}'
                    priced_from_research += 1
            unpriced_csv = unpriced_gvm = 0
            for it in items:
                if it['cost']:
                    continue
                gvm_side = 'GVM:' in (it['source'] or '')
                ruling = R.MERIT_UNPRICED.get(it['name'])
                if gvm_side and ruling is None:
                    unruled.append((slug, it['name'], it['source']))
                    continue
                it['note'] = ((it['note'] + '; ') if it['note'] else '') + (
                    ruling if gvm_side else 'UNPRICED: the cited book prints this cost; it was never captured (the met-mechanics.csv row has none) - 1.3.0 owner question 16')
                if gvm_side:
                    unpriced_gvm += 1
                else:
                    unpriced_csv += 1
            items.sort(key=lambda i: i['name'].lower())
            flags = {'atomic': True, 'allow_custom': True, 'allow_multiples': False}
            if negative:
                flags['negative'] = True
            io.write('blocks', slug, 'Merits' if kind == 'merits' else 'Flaws', 'block', io.trait_definition(flags, items),
                     [f'Grapevine Menus XML.gvm: "{menu}" with its includes resolved' + (' and its own submenus (clan lists, Fae Gifts/Marks, Fomori Taints)' if menu != generic else ''),
                      'met-mechanics.csv Merit/Flaw rows routed by source (the Vampire research overlay to Vampire; generic-menu names to every stack)',
                      'Cost: the GVM item\'s own cost, else the CSV row, else captured research for this genre (cited per item); anything left is marked UNPRICED with its reason'],
                     section_type='trait_list',
                     notes='1.3.0 D82 - replaces the shared met-' + kind + ' (D20/D81 precedent).')
            own_menus = {x[2] for x in own}
            report[slug] = {'items': len(items),
                            'from_creature_menus': sum(1 for i in items if any(f'GVM: {m}' in (i['source'] or '') for m in own_menus)),
                            'csv_only': sum(1 for i in items if (i['source'] or '').startswith('met-mechanics.csv')),
                            'cost_conflicts': stats['conflicts'], 'priced_from_research': priced_from_research,
                            'unpriced_csv': unpriced_csv, 'unpriced_gvm_ruled': unpriced_gvm}
    if unruled:
        raise SystemExit('Merit/Flaw with no cost and no ruling - rule it in rulings.MERIT_UNPRICED:\n' +
                         '\n'.join(f'  {s}: {n} ({src})' for s, n, src in unruled))
    REPORT['merits_flaws'] = report
    for old in ('met-merits', 'met-flaws'):
        f = io.CATALOG / 'blocks' / f'{old}.json'
        if f.exists():
            f.unlink()


# --- Existing hand-built files ------------------------------------------------------------------

def normalise_demon_evocations():
    """demon-evocations.json was written before the runtime shape was settled: powers keyed by
    name, `name` on each level and bare-string picks. Re-emit it as the TieredPowerDefinition
    the engine reads (a list of families, `power_name`, pick objects) - content untouched."""
    path = io.CATALOG / 'blocks' / 'demon-evocations.json'
    env = json.load(open(path), object_pairs_hook=OrderedDict)
    d = env['definition']
    if isinstance(d['powers'], list):
        return
    costs = d['_meta']['costs']
    powers = []
    for name, p in d['powers'].items():
        levels = [io.level(x['level'], x['tier'], x.get('power_name') or x['name'], costs.get(x['tier'])) for x in p['levels']]
        elder = OrderedDict()
        for rank, picks in (p.get('elder') or {}).items():
            elder[rank] = [io.pick(rank, n if isinstance(n, str) else n['power_name'], costs.get(rank)) for n in picks]
        extra = {k: v for k, v in p.items() if k not in ('levels', 'elder', 'source')}
        f = io.family(name, levels, elder=elder, source=p.get('source'))
        if extra.get('house'):
            f['category_values'] = {'house': extra['house']}
        for k in ('traditions', 'restriction', 'description'):
            if extra.get(k) is not None:
                f[k] = extra[k]
        powers.append(io.ordered(f, io.FAMILY_ORDER))
    d['powers'] = powers
    path.write_text(json.dumps(env, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')


def emit_mage_rotes_meta():
    """1.3.0 D8: Rotes declare their cost rule instead of relying on a no-tier fallback - one
    XP per Sphere level invoked (1.2.10 S7)."""
    path = io.CATALOG / 'blocks' / 'mage-rotes.json'
    env = json.load(open(path), object_pairs_hook=OrderedDict)
    d = env['definition']
    d['_meta'] = OrderedDict([('untiered', OrderedDict([('derived_from', 'mage-spheres'), ('per_level', 1)]))])
    env['definition'] = io.ordered(d, io.TRAIT_DEF_ORDER + ['items'])
    path.write_text(json.dumps(env, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')


# --- mortal-numina splits into real blocks (decision 8) -------------------------------------

NUMINA_TIERS = {'basic': 'basic', 'int.': 'intermediate', 'adv.': 'advanced'}
NUMINA_COSTS = {'basic': 3, 'intermediate': 6, 'advanced': 9}


def numina_family_items(menus, menu_name):
    """(rungs, extras) for one path menu: rungs are the tier-noted powers, extras are the
    spells, rituals and formulae that are separate purchases."""
    rungs, extras = [], []
    for it in menus[menu_name].findall('item'):
        note = (it.get('note') or '')
        head = note.split(',')[0].split(' ')[0]
        tier = NUMINA_TIERS.get(head)
        if tier and 'ritual' not in note and 'formula' not in note:
            rungs.append((tier, it.get('name'), it.get('cost'), note))
        else:
            extras.append((it.get('name'), it.get('cost'), note))
    return rungs, extras


def emit_numina_split(menus):
    """Every Numina submenu that is real Mortal content becomes its own block; the five that
    copy another stack's catalogue are referenced by the stack instead (decision 8)."""
    formulae, residual, built = [], [], {}
    # "Sorcery, Levels" carries no tier notes at all: five path levels then ten spell and ritual
    # slots, in that order, which is the whole reason its 270 levels read as `unknown` before.
    shared = [(it.get('name'), it.get('cost')) for it in menus['Sorcery, Levels'].findall('item')]
    generic_by_name = {io.norm(n): n for n, _ in shared}
    generic_cost = {io.norm(n): c for n, c in shared}
    generic_extras = [(n, c, '') for n, c in shared if n not in R.NUMINA_GENERIC_RUNGS]
    for slug, name, container_menus, stack in R.NUMINA_PATH_BLOCKS:
        powers, seen = [], {}
        for container in container_menus:
            for sub in menus[container].findall('submenu'):
                label, link = sub.get('name'), sub.get('link') or sub.get('name')
                if io.norm(label) in seen:
                    continue
                rungs, extras = numina_family_items(menus, link)
                counts = Counter(t for t, _, _, _ in rungs)
                plan = []
                spare = []
                if counts['basic'] >= 2 and counts['intermediate'] >= 2 and counts['advanced'] >= 1:
                    for tier, want in (('basic', 2), ('intermediate', 2), ('advanced', 1)):
                        same = [(tier, n, c) for t, n, c, _ in rungs if t == tier]
                        plan += same[:want]
                        spare += [(tier, n, c) for _, n, c in same[want:]]
                elif link == 'Sorcery, Levels':
                    # Sorcery's shared generic ladder - five path levels, exactly as Hekau.
                    plan = [(('basic' if i < 2 else 'intermediate' if i < 4 else 'advanced'),
                             generic_by_name[io.norm(r)], generic_cost[io.norm(r)])
                            for i, r in enumerate(R.NUMINA_GENERIC_RUNGS)]
                    extras = extras + [(n, c, note) for n, c, note in generic_extras]
                elif slug in ('mortal-hedge-magic', 'mortal-theurgy'):
                    # A short path: its named workings are still bought one at a time, so they
                    # join the formulae list under their own group rather than being dropped or
                    # padded out to a ladder the menu cannot fill.
                    for tier, n, c, note in rungs:
                        formulae.append((slug, label, n, c, note or tier, link))
                    for n, c, note in extras:
                        formulae.append((slug, label, n, c, note, link))
                    residual.append({'block': slug, 'family': label, 'menu': link,
                                     'shape': f"{counts['basic']}/{counts['intermediate']}/{counts['advanced']}",
                                     'levels': len(rungs) + len(extras), 'landed': 'mortal-hedge-magic-formulae',
                                     'why': 'the menu cannot fill the declared 2/2/1 ladder, so the path keeps no rating and its workings are bought individually'})
                    continue
                else:
                    residual.append({'block': slug, 'family': label, 'menu': link,
                                     'shape': f"{counts['basic']}/{counts['intermediate']}/{counts['advanced']}",
                                     'levels': len(rungs) + len(extras), 'landed': 'nothing - owner question 24',
                                     'why': 'a pick-shaped list (no tiers, no ladder) inside a ladder block'})
                    continue
                levels = [io.level(i + 1, t, n, c or NUMINA_COSTS[t]) for i, (t, n, c) in enumerate(plan)]
                for tier, n, c in spare:          # a spare same-rank power is an alternative for its rung
                    rung = next(i for i, lv in enumerate(levels, 1) if lv['tier'] == tier)
                    levels[rung - 1].setdefault('alternatives', []).append(
                        OrderedDict([('power_name', n), ('note', f'GVM: {link} prints a third {tier} power for a two-rung band')]))
                seen[io.norm(label)] = True
                powers.append(io.family(label, levels, source=f'GVM: {link}'))
                for n, c, note in extras:
                    formulae.append((slug, label, n, c, note, link))
        meta = io.meta(['basic', 'intermediate', 'advanced'], {'basic': 2, 'intermediate': 2, 'advanced': 1},
                       dict(NUMINA_COSTS), out_of_type={'basic': '+1', 'intermediate': '+1', 'advanced': '+1'})
        io.write('blocks', slug, name, 'block',
                 tiered_def({'atomic': True, 'allow_custom': True}, meta, powers),
                 [f'Grapevine Menus XML.gvm: the "{container_menus[0]}" container' + (f' with "{container_menus[1]}" filling any path it lacks' if len(container_menus) > 1 else ''),
                  'Rungs, costs and tiers from each item\'s own GVM cost and note (3/3/6/6/9); spells, rituals and formulae are separate purchases and go to mortal-hedge-magic-formulae'],
                 section_type='tiered_power',
                 notes='1.3.0 decision 8 - split out of the mortal-numina container, which copied other stacks\' catalogues instead of referencing them.')
        built[slug] = {'families': len(powers), 'levels': sum(len(f['levels']) for f in powers), 'stack': stack}
    for slug, name, menu, stack in R.NUMINA_FLAT_BLOCKS:
        items = [io.trait_item({'name': it.get('name')}, cost=it.get('cost'), source=f'GVM: {menu}',
                               note=(it.get('note') or None))
                 for it in menus[menu].findall('item')]
        io.write('blocks', slug, name, 'block',
                 io.trait_definition({'alphabetize': True, 'atomic': True, 'allow_custom': True, 'allow_multiples': False}, items),
                 [f'Grapevine Menus XML.gvm: "{menu}"',
                  'Cost as the GVM carries it; an item the GVM leaves unpriced keeps `cost: null` rather than a guessed number'],
                 section_type='trait_list',
                 notes='1.3.0 decision 8 - split out of the mortal-numina container.')
        built[slug] = {'items': len(items), 'stack': stack}
    for menu, group in R.NUMINA_FORMULAE_EXTRA.items():
        for it in menus[menu].findall('item'):
            formulae.append(('mortal-hedge-magic', group, it.get('name'), it.get('cost'), it.get('note') or '', menu))
    rows, seen_f = [], set()
    for slug, group, n, c, note, link in formulae:
        key = (group, io.norm(n))
        if key in seen_f:
            continue
        seen_f.add(key)
        kind = 'ritual' if 'ritual' in (note or '').lower() else 'spell' if 'spell' in (note or '').lower() else 'formula'
        rows.append(io.trait_item({'name': n}, cost=c, group=group, subgroup=kind, source=f'GVM: {link}',
                                  prerequisites=[OrderedDict([('block_slug', 'mortal-hedge-magic'), ('power', group), ('min_level', 1)])]))
    rows.sort(key=lambda i: ((i['group'] or ''), i['name'].lower()))
    io.write('blocks', 'mortal-hedge-magic-formulae', 'Spells and Rituals', 'block',
             io.trait_definition({'alphabetize': False, 'atomic': True, 'allow_custom': True, 'allow_multiples': False}, rows),
             ['Grapevine Menus XML.gvm: the spell, ritual and formula entries inside each Sorcery, Theurgy and Martial Arts path menu, plus the four Benandanti Rituals',
              'Cost as the GVM carries it - the shared "Sorcery, Levels" menu prices a spell at its own rank (3/3/6/6/9) and a ritual at 2/2/4/4/6'],
             section_type='trait_list',
             notes='1.3.0 decision 8 - the same shape as mummy-formulae: a path rating is the ladder, each named working is bought separately.')
    built['mortal-hedge-magic-formulae'] = {'items': len(rows), 'stack': 'mortal'}
    REPORT['numina_split'] = {'blocks': built, 'residual': residual}
    return built, residual


# --- mortal-numina: built, measured, not committed ----------------------------------------------

COMBO_TIER = re.compile(r'^[a-z\' ]+( \+ [a-z0-9\' ]+)+$')


def build_mortal_numina():
    blk = live('mortal-numina')
    d = copy.deepcopy(blk['definition'])
    before = measure(d['powers'])
    combo_fixed = legend = derived = 0
    sorcery_rungs = {'apprentice': 'basic', 'initiate': 'basic', 'adept': 'intermediate',
                     'disciple': 'intermediate', 'master': 'advanced'}
    for p in d['powers']:
        for x in p['levels'] + (p.get('overflow') or []) + [y for v in (p.get('elder') or {}).values() for y in v]:
            t = x.get('tier') or ''
            if COMBO_TIER.match(t):
                # D7: a combo Discipline's prerequisite list parked in the tier slot. It is a
                # prerequisite, not a rank - moved to the note; the rank comes from its cost.
                x['note'] = ((x.get('note') or '') + f' (requires {t})').strip()
                x['tier'] = {'3': 'basic', '6': 'intermediate', '9': 'advanced'}.get(str(x.get('cost')), 'unknown')
                combo_fixed += 1
            elif t == 'legend':
                legend += 1
            elif t == 'unknown':
                head = (x.get('power_name') or '').lower().split(' ')[0]
                prefix = {'basic': 'basic', 'int.': 'intermediate', 'adv.': 'advanced'}.get(head)
                if prefix:
                    # "Basic Binding Ritual", "Int. Dismissal Ritual": the rank is in the name.
                    x['tier'] = prefix
                    derived += 1
                elif head in sorcery_rungs:
                    x['tier'] = sorcery_rungs[head]
                    derived += 1
                elif re.fullmatch(r'(first|second) (basic|intermediate)|advanced|master', (x.get('power_name') or '').lower()):
                    x['tier'] = {'first basic': 'basic', 'second basic': 'basic', 'first intermediate': 'intermediate',
                                 'second intermediate': 'intermediate', 'advanced': 'advanced',
                                 'master': 'master'}[x['power_name'].lower()]
                    derived += 1
    after = measure(d['powers'])
    OUT.mkdir(exist_ok=True)
    (OUT / 'mortal-numina.pending.json').write_text(json.dumps(d, indent=1, ensure_ascii=False))
    REPORT['mortal-numina (not committed)'] = {'before': before, 'after': after, 'combo_tiers_cleaned': combo_fixed,
                                               'legend_levels': legend, 'unknown_derived': derived}


# --- Stacks, templates, presets ----------------------------------------------------------------

SECTION_ORDER = ['block_slug', 'label', 'display_order', 'required', 'in_type_source', 'negative_block_slug']
STACK_ADDITIONS = {
    # D5 moved every Mummy spell and ritual into mummy-formulae; without a section they would
    # vanish from the sheet.
    'mummy': [{'block_slug': 'mummy-formulae', 'label': 'Spells and Rituals', 'display_order': 61, 'required': False}],
    # Stigmas left changeling-arts (not an Art); they stay reachable as their own section.
    'changeling': [{'block_slug': 'changeling-stigmas', 'label': 'Stigmas', 'display_order': 62, 'required': False}],
}
# new block -> (the section it follows, the stack that owns it). The stack matters: the Mortal
# sheet now references changeling-realms for kinain, and must not inherit Changeling's Stigmas.
# A full sheet shows everything its stack declares. `npc_quick` is the deliberate exception:
# it is identity plus the quick-stat and roleplaying-note blocks by design, not a short sheet.
TEMPLATE_COMPLETE_TYPES = ('sheet_full', 'npc_full')


def complete_template(layout, stack_sections, label_for):
    """Adds a row for every block the stack declares that the template does not show yet.

    Hand-listing these (`mummy-formulae` after `mummy-hekau`, and so on) is what let
    `kueijin-shintai` reach a stack but never a sheet: the block existed, the stack declared it,
    and it rendered nowhere. Each missing block is placed after the row of the nearest stack
    section that already appears before it, so it lands beside its own kind; with no such
    anchor it goes to the shorter column.
    """
    rows = layout.get('sections', [])
    present = {r['block_slug'] for r in rows}
    added = []
    for i, block in enumerate(stack_sections):
        if block in present:
            continue
        anchor = None
        for earlier in reversed(stack_sections[:i]):
            anchor = next((r for r in rows if r['block_slug'] == earlier), None)
            if anchor:
                break
        if anchor:
            column, order = anchor['column'], anchor['order'] + 1
            at = rows.index(anchor) + 1
        else:
            counts = Counter(r['column'] for r in rows)
            column = min(counts, key=lambda c: (counts[c], c)) if counts else 1
            order = max([r['order'] for r in rows if r['column'] == column] or [0]) + 1
            at = len(rows)
        for r in rows:
            if r['column'] == column and r['order'] >= order:
                r['order'] += 1
        row = {'order': order, 'title': label_for.get(block), 'width': 'half', 'column': column,
               'display': None, 'collapsed': False, 'block_slug': block}
        rows.insert(at, row)
        present.add(block)
        added.append(block)
    layout['sections'] = rows
    return added


def stack_repoint(stack):
    """Shared blocks a stack's sections move off, and the per-stack block each moves to."""
    m = R.MERIT_BLOCK_FOR_STACK[stack]
    out = {'met-abilities': R.ABILITY_BLOCK_FOR_STACK[stack],
           'met-merits': f'{m}-merits', 'met-flaws': f'{m}-flaws'}
    if stack in ('fera', 'bete'):
        out['werewolf-rites'] = 'fera-rites'      # Fera rites are their own catalog (Decision 046)
    return out


def emit_stacks_and_templates(block_slugs):
    pending = OUT / 'pending'
    pending.mkdir(parents=True, exist_ok=True)
    emitted, deferred, stack_sections = [], [], {}
    for sp in sorted((DUMP / 'stacks').glob('*.json')):
        st = json.load(open(sp))
        sd = st['stack_definition']
        repoint = stack_repoint(st['slug'])
        sections = [io.ordered(dict(s, block_slug=repoint.get(s['block_slug'], s['block_slug'])), SECTION_ORDER)
                    for s in sd['sections']]
        rules = copy.deepcopy(st['creation_rules']) or None
        for step in (rules or {}).get('steps', []) if isinstance(rules, dict) else []:
            step['sections'] = [repoint.get(x, x) for x in step.get('sections', [])]
            if step.get('budgets'):
                step['budgets'] = {repoint.get(k, k): v for k, v in step['budgets'].items()}
        st['creation_rules'] = rules
        for add in STACK_ADDITIONS.get(st['slug'], []):
            sections.append(io.ordered(dict(add), SECTION_ORDER))
        for slug, label, order in R.STACK_EXTRA_SECTIONS.get(st['slug'], []):
            if not any(x['block_slug'] == slug for x in sections):
                sections.append(io.ordered({'block_slug': slug, 'label': label, 'display_order': order, 'required': False}, SECTION_ORDER))
        replacements = R.STACK_SECTION_REPLACEMENTS.get(st['slug'], {})
        if replacements:
            expanded = []
            for sec in sections:
                repl = replacements.get(sec['block_slug'])
                if not repl:
                    expanded.append(sec)
                    continue
                base = sec['display_order']
                for n, (slug, label) in enumerate(repl):
                    expanded.append(io.ordered({'block_slug': slug, 'label': label,
                                                'display_order': base + n, 'required': False}, SECTION_ORDER))
            sections = expanded
        sections.sort(key=lambda s: s['display_order'])
        stack_sections[st['slug']] = [(x['block_slug'], x.get('label')) for x in sections]
        defn = OrderedDict([('game_line', st['game_line']), ('sections', sections),
                            ('display_preferences', sd.get('display_preferences') or {}),
                            ('creation_rules', st['creation_rules'] or None)])
        refs = {s['block_slug'] for s in sections} | {s['negative_block_slug'] for s in sections if s.get('negative_block_slug')}
        for step in (st['creation_rules'] or {}).get('steps', []) if isinstance(st['creation_rules'], dict) else []:
            refs |= set(step.get('sections', []))
        missing = sorted(refs - block_slugs)
        target = 'stacks' if not missing else None
        env_sources = [LIVE_SOURCE.replace('wp_be_schema_blocks', 'wp_be_creature_stacks')]
        if target:
            io.write('stacks', st['slug'], st['name'], 'stack', defn, env_sources)
            emitted.append(st['slug'])
        else:
            (pending / f"{st['slug']}.stack.json").write_text(json.dumps(defn, indent=2, ensure_ascii=False))
            deferred.append((st['slug'], missing))
    t_emitted, t_deferred, completed = [], [], {}
    for tp in sorted((DUMP / 'templates').glob('*.json')):
        t = json.load(open(tp))
        slug = f"{t['stack_slug']}.{t['template_type']}"
        layout = copy.deepcopy(t['layout'])
        repoint = stack_repoint(t['stack_slug'])
        for sec in layout.get('sections', []):
            sec['block_slug'] = repoint.get(sec['block_slug'], sec['block_slug'])
        replacements = R.STACK_SECTION_REPLACEMENTS.get(t['stack_slug'], {})
        if replacements:
            expanded = []
            for sec in layout.get('sections', []):
                repl = replacements.get(sec['block_slug'])
                if not repl:
                    expanded.append(sec)
                    continue
                for n, (repl_slug, repl_label) in enumerate(repl):
                    row = dict(sec)
                    row['block_slug'] = repl_slug
                    row['title'] = repl_label if n else sec.get('title')
                    row['order'] = sec['order'] + n
                    expanded.append(row)
            layout['sections'] = expanded
        if t['template_type'] in TEMPLATE_COMPLETE_TYPES and t['stack_slug'] in stack_sections:
            added = complete_template(layout, [b for b, _ in stack_sections[t['stack_slug']]],
                                      dict(stack_sections[t['stack_slug']]))
            if added:
                completed[slug] = added
        refs = {s['block_slug'] for s in layout.get('sections', [])}
        missing = sorted(refs - block_slugs)
        if not missing and t['stack_slug'] in emitted:
            io.write('templates', slug, t['name'], 'template', layout,
                     [LIVE_SOURCE.replace('wp_be_schema_blocks', 'wp_be_templates')])
            t_emitted.append(slug)
        else:
            (pending / f'{slug}.json').write_text(json.dumps(layout, indent=2, ensure_ascii=False))
            t_deferred.append((slug, missing or [f'stack {t["stack_slug"]} deferred']))
    REPORT['stacks'] = {'emitted': emitted, 'deferred': deferred}
    REPORT['templates'] = {'emitted': len(t_emitted), 'deferred': t_deferred, 'completed': completed}


def emit_presets():
    import subprocess
    php = '/opt/homebrew/opt/php@8.2/bin/php'
    base = io.CODE_ROOT / 'beyond-elysium' / 'includes' / 'Database'
    for slug, name in (('position-presets', 'Position Title Presets'), ('approval-reason-presets', 'Approval Reason Presets')):
        raw = subprocess.check_output([php, '-r', f"define('ABSPATH','/');echo json_encode(require '{base / (slug + '.php')}');"])
        data = json.loads(raw, object_pairs_hook=OrderedDict)
        io.write('presets', slug, name, 'preset', data, [f'includes/Database/{slug}.php'])
    REPORT['presets'] = ['position-presets', 'approval-reason-presets']


# --- Main -----------------------------------------------------------------------------------------

# Pools the books price and the seed left unpriced (format section 4.4). Wraith Pathos ("two
# Traits per experience point", Oblivion p. 165) is a sub-1-XP rate cost_per_dot cannot
# express, and stays unpriced rather than rounded.
POOL_PRICES = {
    'mummy-resources': (
        # 1.3.0 D9 plus the two flat prices beside it.
        {'Sekhem': {'cost_per_dot': 3}, 'Willpower': {'cost_per_dot': 3},
         'Balance': {'sliding_cost': OrderedDict([('equals_level', True)])}},
        [LIVE_SOURCE, 'Laws of the Resurrection (WW05035) p. 116 - Sekhem 3, Willpower 3, '
                      'Balance "a number of Experience Traits equal to the level desired"']),
    'changeling-resources': (
        {'Banality': {'cost_per_dot': 2}},
        [LIVE_SOURCE, 'The Shining Host (WW05009) p. 117 - "New Banality - Two experience per Trait" '
                      '(the OWBN C20 translations packet prices it 1: a variant, not the base)']),
    'kueijin-resources': (
        {'Hun': {'cost_per_dot': 3}, 'Po': {'cost_per_dot': 2},
         'Yin Chi': {'cost_per_dot': 3}, 'Yang Chi': {'cost_per_dot': 3}},
        [LIVE_SOURCE, 'Laws of the East (WW05016) p. 125 - Virtue Traits Hun, Yin, Yang 3 each, P\'o 2 '
                      '(samples/research/kuei-jin/pools-and-xp.json). Demon Chi is never bought']),
}

SPECIAL = {'met-abilities', 'met-merits', 'met-flaws', 'werewolf-rites', 'changeling-arts', 'changeling-realms', 'wraith-arcanoi', 'mummy-hekau',
           'werewolf-gifts', 'fera-gifts', 'mortal-numina', 'mage-rotes'}


def main():
    for slug in all_live_slugs():
        if slug in io.NOT_OURS or slug in SPECIAL:
            continue
        st = live(slug)['section_type']
        if st == 'trait_list' and slug.endswith('-backgrounds'):
            items = [io.trait_item(i) for i in live(slug)['definition']['items']]
            if slug == 'mortal-backgrounds':
                for name, ref in R.MORTAL_BACKGROUND_ADDITIONS:
                    items.append(io.trait_item({'name': name}, cost='1', source='OWBN Hunter: Inquisition Packet (2021)',
                                               description={'reference': ref}))
            marked = 0
            for it in items:
                if it['name'] in R.BACKGROUND_MULTIPLES:
                    it['allow_multiples'] = True   # 1.3.2 asks "Who or what?" and keeps each as its own row
                    marked += 1
            emit_trait_list(slug, items=items, sources=[LIVE_SOURCE] + ([
                'OWBN Hunter: Inquisition Packet (2021) p. 7-8 - Mob, Reliquary, Status, Flock, via samples/research/hunter/mortal/resolved.json'] if slug == 'mortal-backgrounds' else []),
                notes='Backgrounds bought per individual carry `allow_multiples: true` on the item (owner ruling 2026-09-22); the editor asks "Who or what?" at 1.3.2.')
            REPORT[slug]['allow_multiples'] = marked
        elif st == 'trait_list' and slug == 'mortal-backgrounds-unused':
            items = [io.trait_item(i) for i in live(slug)['definition']['items']]
            for name, ref in R.MORTAL_BACKGROUND_ADDITIONS:
                items.append(io.trait_item({'name': name}, cost='1', source='OWBN Hunter: Inquisition Packet (2021)',
                                           description={'reference': ref}))
            emit_trait_list(slug, items=items, sources=[
                LIVE_SOURCE,
                'OWBN Hunter: Inquisition Packet (2021) p. 7-8 - Mob, Reliquary, Status, Flock, via samples/research/hunter/mortal/resolved.json'])
        elif st == 'trait_list':
            emit_trait_list(slug)
        elif st == 'identity_field':
            patch = None
            if slug == 'werewolf-identity':
                patch = {'Tribe': {'option_aliases': R.WEREWOLF_TRIBE_ALIASES,
                                   'drop_options': R.WEREWOLF_TRIBE_DROP}}
            emit_identity(slug, patch, menus=gvm_menus())
        elif st == 'resource_pool':
            emit_pools(slug, *POOL_PRICES.get(slug, (None, [])))
        else:
            raise SystemExit(f'unhandled tiered block {slug}')
    emit_werewolf_rites()
    emit_changeling()
    emit_wraith()
    emit_mummy()
    emit_gifts()
    emit_numina_split(gvm_menus())
    for gone in ('demon-lores',):                    # decision 9: retired by demon-evocations
        f = io.CATALOG / 'blocks' / f'{gone}.json'
        if f.exists():
            f.unlink()
    emit_abilities()
    emit_merits()
    normalise_demon_evocations()
    emit_mage_rotes_meta()
    build_mortal_numina()
    blocks = {p.stem for p in (io.CATALOG / 'blocks').glob('*.json')}
    emit_stacks_and_templates(blocks)
    emit_presets()
    OUT.mkdir(exist_ok=True)
    (OUT / 'report.json').write_text(json.dumps(REPORT, indent=1, ensure_ascii=False))
    print(json.dumps(REPORT, indent=1, ensure_ascii=False))


if __name__ == '__main__':
    main()
