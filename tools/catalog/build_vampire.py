"""Emits the Vampire Discipline and blood-magic files (1.3.1: B-4, B-5, B-6, B-7).

    python3 build_vampire.py <snapshot-dir> <dark-ages-menus.json>

`<dark-ages-menus.json>` is Grapevine's own `Dark Ages Menus.gvm` (shipped with
Grapevine 3.0, "tailored for use with Dark Ages chronicles using the book
_Faith and Fire_") decoded to JSON by the plugin's own GVM_Parser:

    php -r 'define("ABSPATH",1); spl_autoload_register(...);
            echo json_encode(GVM_Parser::parse_file($argv[1]));' \\
        "GV301Source/Code/Dark Ages Menus.gvm" > da.json

Writes, under data/catalog/blocks/:

- `vampire-disciplines.json`, `vampire-blood-magic.json` - the bases.
- `darkages-vampire_disciplines.json`, `darkages-vampire_blood-magic.json`,
  `2nded-vampire_disciplines.json`, `2nded-vampire_blood-magic.json` - the
  edition printings the seeded families had fused into their own ladders
  (format §4b: edition qualifiers are variants, `mode: add`).
- `vampire-gargoyle-powers.json` - Faith and Fire's non-progressive Gargoyle
  powers, which are not a ladder.

The rules, in order, per family (rulings/<slug>.json says which apply and why):
renames by position, renames, retiers, dropped levels, level aliases, exact
duplicates, then the edition split, then the ladder fills. A family that still
has a leftover ladder-rank level is a hard error: D67 cannot be written by
accident. Every snapshot level is accounted for at the end - placed as a rung,
a pick, an option, an alias, a variant rung, a moved item, or a recorded drop.
"""

import json
import re
import sys
from collections import Counter, OrderedDict

from catalog_common import (LADDER_2_2_1, LADDER_RANKS, edition_of, envelope,
                            family_record, flags_from, ladder_from,
                            load_rulings, load_snapshot, meta, partition,
                            qualifier_of, write_block)
from vampire_research import load_paths

VD, BM = 'vampire-disciplines', 'vampire-blood-magic'
VARIANTS = OrderedDict([
    ('dark-ages', ('darkages', 'Dark Ages', "Dark Ages (Grapevine's Dark Ages Menus, for Faith and Fire)")),
    ('2nd-ed', ('2nded', '2nd ed.', "Mind's Eye Theatre 2nd edition printings")),
])
VD_RANKS = ['basic', 'intermediate', 'advanced', 'elder', 'master', 'ascended', 'methuselah']
VD_COSTS = {'basic': 3, 'intermediate': 6, 'advanced': 9, 'elder': 12, 'master': 15,
            'ascended': 18, 'methuselah': 21}
BM_RANKS = ['basic', 'intermediate', 'advanced']
CLANS = {'assamite', 'brujah', 'gangrel', 'malkavian', 'nosferatu', 'toreador', 'tremere',
         'ventrue', 'tzimisce', 'lasombra', 'setite', 'giovanni', 'ravnos', 'sabbat',
         'camarilla', 'anarch', 'loyalist', 'baali', 'salubri'}

accounting = Counter()


def norm(s):
    return re.sub(r'[^a-z0-9]', '', (s or '').lower().replace('the ', ''))


def entries_of(fam):
    out = []
    for lv in fam.get('levels', []):
        out.append({'name': lv['power_name'], 'tier': lv['tier'], 'note': lv.get('note'), 'from': 'levels'})
    for rank, picks in (fam.get('elder') or {}).items():
        for lv in picks:
            out.append({'name': lv['power_name'], 'tier': rank, 'note': lv.get('note'), 'from': 'elder'})
    for lv in fam.get('overflow', []):
        out.append({'name': lv['power_name'], 'tier': lv['tier'], 'note': lv.get('note'), 'from': 'overflow'})
    return out


def add_alias(entry, alias):
    if alias and alias != entry['name'] and alias not in entry.setdefault('aliases', []):
        entry['aliases'].append(alias)


def process(fam, rule, ranks):
    """Applies one family's rulings. Returns the processed family state."""
    name = fam['name']
    entries = entries_of(fam)
    accounting['snapshot'] += len(entries)

    for pos, new in (rule.get('renames_by_position') or {}).items():
        e = entries[int(pos) - 1]
        old = e['name']
        e['name'] = new
        if old != name:
            add_alias(e, old)
    for old, new in (rule.get('renames') or {}).items():
        hits = [e for e in entries if e['name'] == old]
        if not hits:
            raise SystemExit(f'{name}: rename target {old!r} not found')
        for e in hits:
            e['name'] = new
            add_alias(e, old)
    for pname, tier in (rule.get('retier') or {}).items():
        hits = [e for e in entries if e['name'] == pname]
        if not hits:
            raise SystemExit(f'{name}: retier target {pname!r} not found')
        for e in hits:
            e['tier'] = tier

    options = list(rule.get('options') or [])
    for pname in (rule.get('drop_levels') or {}):
        hits = [e for e in entries if e['name'] == pname]
        if not hits:
            raise SystemExit(f'{name}: drop target {pname!r} not found')
        entries = [e for e in entries if e['name'] != pname]
        accounting['option' if pname in options else 'dropped_duplicate'] += len(hits)

    for dup, canonical in (rule.get('level_aliases') or {}).items():
        dups = [e for e in entries if e['name'] == dup]
        keep = [e for e in entries if e['name'] == canonical]
        if not dups or not keep:
            raise SystemExit(f'{name}: level alias {dup!r} -> {canonical!r} does not resolve')
        entries = [e for e in entries if e['name'] != dup]
        add_alias(keep[0], dup)
        accounting['aliased'] += len(dups)

    # An overflow level identical in name and tier to one already placed is a
    # second printing of it and collapses. Placeholder rungs that share a name
    # inside the ladder itself (Path of Mercury x5) are five rungs, not one.
    seen, deduped = {}, []
    for e in entries:
        key = (e['name'], e['tier'])
        if key in seen and e.get('from') == 'overflow':
            for a in e.get('aliases', []):
                add_alias(seen[key], a)
            accounting['aliased'] += 1
            continue
        seen.setdefault(key, e)
        deduped.append(e)
    entries = deduped

    buckets = OrderedDict()
    ladder_entries, picks = [], []
    whole_variant = rule.get('variant') if rule.get('action') == 'to_variant' else None
    for e in entries:
        if e['tier'] in LADDER_RANKS:
            variant = whole_variant or edition_of(e['note'])
            if variant:
                buckets.setdefault(variant, []).append(e)
                continue
            ladder_entries.append(e)
        elif e['tier'] in ranks:
            picks.append(e)
        else:
            raise SystemExit(f'{name}: {e["name"]!r} has tier {e["tier"]!r}, not in the block vocabulary')

    for f in rule.get('fill') or []:
        ladder_entries.append({'name': f['name'], 'tier': f['tier'], 'filled_from': f['filled_from']})
        accounting['filled'] += 1

    for e in ladder_entries:
        q = qualifier_of(e.get('note'))
        if q:
            e['note'] = q
        else:
            e.pop('note', None)
    return {'name': name, 'ladder': ladder_entries, 'picks': picks, 'buckets': buckets,
            'options': options, 'options_note': rule.get('options_note'),
            'whole_variant': whole_variant}


def strip_shared_tradition_notes(powers):
    """Drops a level's tradition note where the family is offered by more than one paradigm.

    A note like `Sabbat` on all five rungs of Lure of Flames records which sect's printing the
    row came from, but the path's own `traditions` map lists five paradigms that offer it, so
    the note reads as a restriction the catalog does not mean (owner ruling, 2026-09-22).

    **It can never strip a discriminator.** D67's seam is a family whose levels *disagree* with
    each other, and 1.2.9's `seam_qualifier()` shows a qualifier only in that case - so this
    drops a note only when every rung in the family carries the same one, which no seam can be
    made of. A family with mixed notes is left exactly as it is.

    Returns (families touched, levels touched) for the build log.
    """
    fams = levels = 0
    for name, fam in powers.items():
        if len(fam.get('traditions') or {}) < 2:
            continue
        notes = {lv.get('note') for lv in fam['levels']}
        if len(notes) != 1 or notes == {None}:
            continue
        for lv in fam['levels']:
            lv.pop('note', None)
            levels += 1
        fams += 1
    return fams, levels


def build_elder(picks, ranks):
    elder, restrictions, editions = OrderedDict(), OrderedDict(), OrderedDict()
    for rank in ranks:
        names = [p['name'] for p in picks if p['tier'] == rank]
        if names:
            elder[rank] = names
    for p in picks:
        q = (qualifier_of(p.get('note')) or '')
        if q and q.lower() in CLANS:
            restrictions[p['name']] = 'Followers of Set' if q.lower() == 'setite' else q.title()
        ed = edition_of(p.get('note'))
        if ed:
            editions[p['name']] = ed
    return elder, restrictions, editions


def finish(state, ranks):
    levels, leftovers = ladder_from(state['ladder'])
    if leftovers or len(levels) != 5:
        detail = [(e['name'], e['tier']) for e in leftovers]
        raise SystemExit(f"{state['name']}: {len(levels)} rungs, leftovers {detail} - needs a ruling")
    accounting['rung'] += sum(1 for lv in levels if not lv.get('filled_from'))
    elder, restrictions, editions = build_elder(state['picks'], ranks)
    accounting['pick'] += sum(len(v) for v in elder.values())
    return levels, elder, restrictions, editions


def main(snapshot_dir, da_path):
    da_menus = json.load(open(da_path, encoding='utf-8'))['menus']
    research = load_paths()
    research_2e = {norm(re.sub(r'^Thaumaturgy:\s*', '', n)): [p for p, _ in powers]
                   for f, n, powers in research if f.endswith('laws-of-the-night-2e-disciplines.json')}

    snaps = {VD: load_snapshot(snapshot_dir, VD), BM: load_snapshot(snapshot_dir, BM)}
    rulings = {VD: load_rulings(VD), BM: load_rulings(BM)}
    ranks = {VD: VD_RANKS, BM: BM_RANKS}

    # Pass 1: every family processed under its own rule.
    states = {VD: OrderedDict(), BM: OrderedDict()}
    sources = {}
    raw = {}
    for slug in (VD, BM):
        for fam in snaps[slug]['powers']:
            rule = rulings[slug]['families'].get(fam['name'], {})
            raw[(slug, fam['name'])] = fam
            if rule.get('action') == 'drop':
                # `reason` keeps the accounting honest: a family dropped because the same rows
                # already exist elsewhere is not the same finding as one dropped because a
                # later printing supersedes it.
                n = len(entries_of(fam))
                accounting['snapshot'] += n
                accounting['dropped_' + rule.get('reason', 'duplicate')] += n
                continue
            if rule.get('action') == 'to_trait_list':
                continue  # handled below, from the snapshot, with its own accounting
            st = process(fam, rule, ranks[slug])
            st['rule'] = rule
            st['slug'] = slug
            states[slug][fam['name']] = st
            sources[(slug, fam['name'])] = fam.get('source') or fam['name']

    # Pass 2: merges, same block and cross block. Ladders must agree name for
    # name once each side's own rulings have run - otherwise it is not the
    # same path and the merge is refused.
    merged_extra = {VD: {}, BM: {}}
    for slug in (VD, BM):
        for name, st in list(states[slug].items()):
            rule = st['rule']
            action = rule.get('action')
            if action not in ('merge_into', 'move_to_block'):
                continue
            target_slug = rule.get('block', slug)
            target = states[target_slug][rule['into']]
            a = [(e['name'], e['tier']) for e in ladder_from(st['ladder'])[0]]
            b = [(e['name'], e['tier']) for e in ladder_from(target['ladder'])[0]]
            if a != b:
                raise SystemExit(f'merge {slug}:{name} -> {target_slug}:{rule["into"]} refused, ladders differ:\n  {a}\n  {b}')
            if st['picks']:
                raise SystemExit(f'{name}: has picks, merge would need a ruling for them')
            # Rung aliases carry over by name.
            by_name = {e['name']: e for e in target['ladder']}
            for e in st['ladder']:
                for al in e.get('aliases', []):
                    add_alias(by_name[e['name']], al)
            accounting['merged_rungs'] += len(st['ladder'])
            for variant, es in st['buckets'].items():
                have = {x['name'] for x in target['buckets'].get(variant, [])}
                for x in es:
                    if x['name'] in have:
                        accounting['aliased'] += 1
                    else:
                        target['buckets'].setdefault(variant, []).append(x)
            extra = merged_extra[target_slug].setdefault(rule['into'], {'aliases': [], 'moved_from': [], 'traditions': OrderedDict()})
            if target_slug == slug:
                extra['aliases'].append(name)
                trad = OrderedDict(raw[(slug, name)].get('traditions') or {})
                if rule.get('tradition_name'):
                    trad = OrderedDict((k, rule['tradition_name']) for k in trad)
                for k, v in trad.items():
                    extra['traditions'].setdefault(k, v)
            else:
                extra['moved_from'].append(OrderedDict([('block', slug), ('name', name)]))
            del states[slug][name]

    # Pass 3: emit the bases.
    variant_families = {VD: {v: OrderedDict() for v in VARIANTS}, BM: {v: OrderedDict() for v in VARIANTS}}
    out = {}
    for slug in (VD, BM):
        powers = OrderedDict()
        for name, st in states[slug].items():
            fam = raw[(slug, name)]
            for variant, es in st['buckets'].items():
                variant_families[slug][variant][name] = {'entries': es, 'base_state': st, 'fam': fam}
            if st['whole_variant']:
                variant_families[slug][st['whole_variant']][name]['picks'] = st['picks']
                continue
            levels, elder, restr, eds = finish(st, ranks[slug])
            extra = merged_extra[slug].get(name, {})
            traditions = OrderedDict(fam.get('traditions') or {})
            for k, v in (extra.get('traditions') or {}).items():
                if k not in traditions or (traditions[k] is None and v):
                    traditions[k] = v
            new_name = st['rule'].get('family_rename') or name
            aliases = list(extra.get('aliases') or [])
            if new_name != name:
                aliases.insert(0, name)
            rec = family_record(sources[(slug, name)], levels, elder=elder,
                                traditions=traditions or None,
                                restriction=fam.get('restriction'),
                                extra=OrderedDict([
                                    ('aliases', aliases),
                                    ('moved_from', extra.get('moved_from')),
                                    ('pick_restrictions', restr),
                                    ('pick_editions', eds),
                                    ('options', st['options']),
                                    ('options_note', st['options_note'] if st['options'] else None),
                                ]))
            powers[new_name] = rec
        swept = strip_shared_tradition_notes(powers)
        if swept[0]:
            print(f'{slug}: dropped a shared-paradigm tradition note from {swept[1]} levels '
                  f'across {swept[0]} families')
        out[slug] = powers

    # Pass 4: variants.
    variant_docs = []
    for slug in (VD, BM):
        for variant, fams in variant_families[slug].items():
            prefix, suffix, label = VARIANTS[variant]
            vpowers = OrderedDict()
            for name, info in fams.items():
                es = [dict(e) for e in info['entries']]
                for e in es:
                    e.pop('note', None)
                    e.pop('from', None)
                used = set()
                for e in es:
                    used.add(norm(e['name']))
                    # 'Flight / Snare' is one power that includes Flight - its
                    # components are not free to fill another rung.
                    for part in e['name'].split(' / '):
                        used.add(norm(part))
                for f in rulings[slug].get('variant_fill', {}).get(f'{variant}:{name}', []):
                    es.append({'name': f['name'], 'tier': f['tier'], 'filled_from': f['filled_from']})
                    used.add(norm(f['name']))
                    accounting['filled'] += 1
                for pname, tier in rulings[slug].get('variant_retier', {}).get(f'{variant}:{name}', {}).items():
                    for e in es:
                        if e['name'] == pname:
                            e['tier'] = tier
                need = Counter(LADDER_2_2_1)
                for e in es:
                    need[e['tier']] -= 1
                order = None
                src_menu = info['fam'].get('source') or name
                if variant == 'dark-ages' and src_menu in da_menus:
                    menu = da_menus[src_menu]['items']
                    order = [i['name'] for i in menu]
                    for item in menu:
                        tier = {'basic': 'basic', 'int.': 'intermediate', 'adv.': 'advanced'}.get((item.get('note') or '').strip())
                        if tier and need[tier] > 0 and norm(item['name']) not in used:
                            es.append({'name': item['name'], 'tier': tier,
                                       'filled_from': f"Dark Ages Menus.gvm: {src_menu}"})
                            used.add(norm(item['name']))
                            need[tier] -= 1
                            accounting['filled'] += 1
                if variant == '2nd-ed':
                    prefer = research_2e.get(norm(name)) or research_2e.get(norm(src_menu)) or []
                    # Research order where MET 2e lists the family, otherwise the
                    # snapshot's own order, so a rung keeps the place it had.
                    order = prefer or [e['name'] for e in entries_of(info['fam'])]
                    base_ladder = ladder_from(info['base_state']['ladder'])[0] if info['base_state']['ladder'] else []
                    cands = sorted(base_ladder, key=lambda lv: 0 if norm(lv['name']) in {norm(p) for p in prefer} else 1)
                    for lv in cands:
                        if need[lv['tier']] > 0 and norm(lv['name']) not in used:
                            es.append({'name': lv['name'], 'tier': lv['tier'],
                                       'filled_from': 'shared with the base printing (Grapevine tags only the powers whose 2nd edition printing differs)'})
                            used.add(norm(lv['name']))
                            need[lv['tier']] -= 1
                            accounting['filled'] += 1
                if order:
                    pos = {norm(n): i for i, n in enumerate(order)}
                    es.sort(key=lambda e: pos.get(norm(e['name']), 999))
                levels, leftovers = ladder_from(es)
                if leftovers or len(levels) != 5:
                    raise SystemExit(f'{variant} {slug}:{name}: {len(levels)} rungs, leftovers '
                                     f'{[(e["name"], e["tier"]) for e in leftovers]} - needs a ruling')
                accounting['variant_rung'] += sum(1 for lv in levels if not lv.get('filled_from'))
                elder, restr, eds = build_elder(info.get('picks', []), ranks[slug])
                accounting['pick'] += sum(len(v) for v in elder.values())
                whole = info['base_state']['whole_variant'] == variant
                vname = name if whole else f'{name} ({suffix})'
                vpowers[vname] = family_record(
                    src_menu, levels, elder=elder,
                    traditions=raw[(slug, name)].get('traditions') or None,
                    extra=OrderedDict([
                        ('aliases', [name] if not whole else []),
                        ('split_from', name if not whole else None),
                        ('moved_from', OrderedDict([('block', slug), ('name', name)]) if whole else None),
                        ('pick_restrictions', restr),
                    ]))
            if not vpowers:
                continue
            vdef = OrderedDict()
            vdef['_meta'] = block_meta(slug)
            vdef.update(flags_from(snaps[slug]))
            vdef['blood_magic'] = slug == BM
            vdef['traditions'] = list(snaps[slug].get('traditions') or [])
            vdef['powers'] = vpowers
            vslug = f"{prefix}-{slug.replace('vampire-', 'vampire_')}"
            variant_docs.append(envelope(vslug, f"{'Disciplines' if slug == VD else 'Blood Magic'} ({suffix})",
                                         'tiered_power', OrderedDict([
                ('sources', variant_sources(variant)),
                ('extracted', '2026-09-22'),
                ('tool', 'tools/catalog/build_vampire.py + rulings/' + slug + '.json'),
            ]), vdef, variant=OrderedDict([('of', slug), ('id', variant), ('label', label), ('mode', 'add')])))

    # Gargoyle powers: a trait_list, from the snapshot family and Faith and Fire.
    garg_fam = raw[(VD, 'Gargoyle Powers')]
    garg = []
    for e in entries_of(garg_fam):
        accounting['snapshot'] += 1
        accounting['moved_item'] += 1
        garg.append(OrderedDict([
            ('name', e['name']), ('cost', None), ('tier', None), ('group', 'Gargoyle'), ('subgroup', None),
            ('source', 'Faith and Fire (WW05038)'), ('note', None), ('description', None),
            ('approval', None), ('reason', None), ('approval_by_value', []), ('prerequisites', []),
            ('moved_from', OrderedDict([('block', VD), ('name', 'Gargoyle Powers')])),
        ]))
    costs = {lv['power_name']: lv.get('cost') for lv in garg_fam['levels'] + garg_fam.get('overflow', [])}
    for item in garg:
        item['cost'] = costs.get(item['name'])

    # Write.
    for slug, title in ((VD, 'Disciplines'), (BM, 'Blood Magic')):
        d = OrderedDict()
        d['_meta'] = block_meta(slug)
        d.update(flags_from(snaps[slug]))
        d['blood_magic'] = slug == BM
        d['traditions'] = list(snaps[slug].get('traditions') or [])
        d['powers'] = out[slug]
        doc = envelope(slug, title, 'tiered_power', OrderedDict([
            ('sources', base_sources(slug)),
            ('extracted', '2026-09-22'),
            ('tool', 'tools/catalog/build_vampire.py + rulings/' + slug + '.json'),
        ]), d)
        print(write_block(doc), 'families=%d rungs=%d picks=%d overflow=%d' % partition(d))
    for doc in variant_docs:
        print(write_block(doc), 'families=%d rungs=%d picks=%d overflow=%d' % partition(doc['definition']))
    gdef = OrderedDict([
        ('alphabetize', True), ('atomic', True), ('allow_custom', True), ('allow_multiples', False),
        ('has_specializations', False), ('negative', False), ('flat_cost', False),
        ('categories', None), ('items', garg),
    ])
    print(write_block(envelope('vampire-gargoyle-powers', 'Gargoyle Powers', 'trait_list', OrderedDict([
        ('sources', ["Faith and Fire (WW05038), Chapter Four - Gargoyle body-modification powers, "
                     "'non-progressive... purchased individually (not leveled)', XP 3/6/9 by power",
                     'GVM: Gargoyle Powers (moved out of vampire-disciplines)']),
        ('extracted', '2026-09-22'),
        ('tool', 'tools/catalog/build_vampire.py'),
    ]), gdef)), 'items=%d' % len(garg))

    print('accounting:', dict(accounting))


def block_meta(slug):
    if slug == VD:
        m = meta(VD_RANKS, VD_COSTS, {r: '+1' for r in VD_RANKS})
        m['in_type_source'] = 'vampire-identity.Clan'
        return m
    return meta(BM_RANKS, {'basic': 3, 'intermediate': 6, 'advanced': 9})


def base_sources(slug):
    common = [
        'Laws of the Night Revised (WW05013) - Discipline 3/6/9 across a 2/2/1 ladder; Elder/Master/'
        'Ascended/Methuselah 12/15/18/21 (Camarilla Guide), per MET-POWER-ACQUISITION.md',
        'GVM: Disciplines (container) and met-mechanics.csv, as seeded by 1.2.10 (be_dev snapshot)',
        'samples/research/vampire/ (owbn, met, tt) for spelling and short-ladder rulings - 1.3.0 §2 precedence',
    ]
    if slug == BM:
        common[0] = ('Tremere packet / Laws of the Night Revised - blood-magic paths 3/6/9 on the Discipline '
                     'ladder, stored and exported as Disciplines (1.2.10 A3)')
    return common


def variant_sources(variant):
    if variant == 'dark-ages':
        return ["Grapevine 3.0 'Dark Ages Menus.gvm' (for Faith and Fire, WW05038) - fills rungs the modern "
                "menu's 'dark ages' tags leave empty",
                "Modern GVM levels noted 'dark ages', split out of the base families by 1.3.1"]
    return ["Mind's Eye Theatre: The Masquerade 2nd Edition (WW05200) via samples/research/vampire/met/"
            "laws-of-the-night-2e-disciplines.json where it lists the family",
            "Modern GVM levels noted '2nd ed.', split out of the base families by 1.3.1; untagged rungs are "
            "the shared printing"]


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
