#!/usr/bin/env python3
"""D81 measurement: how many held custom Abilities the 1.3.2 re-key would resolve against the
new `{stack}-abilities` blocks. Read-only, local be_dev only - never a production host.

The re-key rules (1.3.0 carry-forward, applied at 1.3.2 ingestion), in order, first match wins:

1. `sheet_data['met-abilities']` moves to `sheet_data['{stack}-abilities']` (Bete -> fera).
2. A row's name equals a catalog item's name or alias (case-insensitive, trimmed) -> that item.
3. `Base: Spec` (split at the FIRST ": ") with Base a catalog item or alias ->
   {name: Base, specialization: Spec}. "Lore: Clan: Assamite" -> Lore / "Clan: Assamite".
4. `Base (Spec)` (a trailing parenthesised group) with Base a catalog item or alias ->
   {name: Base, specialization: Spec}.
5. Otherwise it stays custom, untouched.

    tools/catalog/measure_abilities.py            # every local character, per stack
    tools/catalog/measure_abilities.py 1093       # one character, row by row
    tools/catalog/measure_abilities.py --kind merits|flaws   # the same rules for D82
"""

import json
import re
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import catalog_io as io  # noqa: E402
import rulings as R  # noqa: E402

MY = ['mysql', '-h127.0.0.1', '-P3307', '-ube_dev', '-pbe_dev_pw', 'be_dev', '-N', '--raw', '-e']


KINDS = {
    'abilities': ('met-abilities', lambda st: R.ABILITY_BLOCK_FOR_STACK[st]),
    'merits': ('met-merits', lambda st: f"{R.MERIT_BLOCK_FOR_STACK[st]}-merits"),
    'flaws': ('met-flaws', lambda st: f"{R.MERIT_BLOCK_FOR_STACK[st]}-flaws"),
}
KIND = 'abilities'


def catalog(stack):
    slug = KINDS[KIND][1](stack)
    items = json.load(open(io.CATALOG / 'blocks' / f'{slug}.json'))['definition']['items']
    names = {}
    for it in items:
        names[it['name'].strip().lower()] = it['name']
        for a in it.get('aliases') or []:
            names[a.strip().lower()] = it['name']
    return names


def resolve(name, names):
    n = name.strip()
    if n.lower() in names:
        return names[n.lower()], None, 'exact'
    if ': ' in n:
        base, spec = n.split(': ', 1)
        if base.strip().lower() in names:
            return names[base.strip().lower()], spec.strip(), 'base: spec'
    m = re.match(r'^(.+?)\s*\((.+)\)\s*$', n)
    if m and m.group(1).strip().lower() in names:
        return names[m.group(1).strip().lower()], m.group(2).strip(), 'base (spec)'
    return None, None, None


def rows(where=''):
    out = subprocess.check_output(MY + [
        "select json_object('id',id,'name',name,'stack',stack_slug,'ab',json_extract(sheet_data,'$.\"" + KINDS[KIND][0] + "\"')) "
        "from wp_be_characters " + where], stderr=subprocess.DEVNULL, text=True)
    return [json.loads(l) for l in out.splitlines() if l.strip()]


def identity_report():
    """Per converted identity field: how many held values match an option, and which do not."""
    out = subprocess.check_output(MY + ['select stack_slug, sheet_data from wp_be_characters'],
                                  stderr=subprocess.DEVNULL, text=True)
    held = defaultdict(lambda: defaultdict(int))
    for line in out.splitlines():
        _, _, raw = line.partition('\t')
        try:
            sheet = json.loads(raw)
        except ValueError:
            continue
        for key, val in (sheet or {}).items():
            if key.endswith('-identity') and isinstance(val, dict):
                for fname, v in val.items():
                    if isinstance(v, str) and v.strip():
                        held[(key, fname)][v.strip()] += 1
    for (slug, field), menus in R.IDENTITY_OPTION_MENUS.items():
        defn = json.load(open(io.CATALOG / 'blocks' / f'{slug}.json'))['definition']
        opts = next((f.get('options') or [] for f in defn['fields'] if f['name'] == field), [])
        keys = {io.norm(o) for o in opts}
        values = held.get((slug, field), {})
        matched = {v: n for v, n in values.items() if io.norm(v) in keys}
        missed = {v: n for v, n in values.items() if io.norm(v) not in keys}
        print(f'{slug}.{field}: {len(opts)} options | held values {sum(values.values())} '
              f'({len(values)} distinct) | match {sum(matched.values())} | stay custom {sum(missed.values())} {list(missed)}')


def main():
    global KIND
    args = sys.argv[1:]
    if '--identity' in args:
        identity_report()
        return
    if '--kind' in args:
        KIND = args[args.index('--kind') + 1]
        del args[args.index('--kind'):args.index('--kind') + 2]
    one = args[0] if args else None
    chars = rows(f'where id={int(one)}' if one else '')
    per = defaultdict(lambda: {'characters': 0, 'custom': 0, 'resolved': 0, 'exact': 0, 'base: spec': 0, 'base (spec)': 0})
    unresolved = defaultdict(lambda: defaultdict(int))
    for c in chars:
        ab = c['ab']
        if isinstance(ab, str):
            ab = json.loads(ab)
        if not ab or c['stack'] not in R.ABILITY_BLOCK_FOR_STACK:
            continue
        names = catalog(c['stack'])
        p = per[c['stack']]
        p['characters'] += 1
        for r in ab:
            if isinstance(r, dict) and not r.get('custom'):
                # A catalog row today must still be a catalog row after the move.
                p['catalog_rows'] = p.get('catalog_rows', 0) + 1
                if resolve(r.get('name', ''), names)[0] is None:
                    p['catalog_rows_lost'] = p.get('catalog_rows_lost', 0) + 1
                    unresolved[c['stack']]['LOST: ' + r.get('name', '')] += 1
                continue
            if not isinstance(r, dict):
                continue
            p['custom'] += 1
            base, spec, how = resolve(r.get('name', ''), names)
            if one:
                print(f"  {r.get('name')!r:40} -> {base!r} / {spec!r}  [{how or 'stays custom'}]")
            if base:
                p['resolved'] += 1
                p[how] += 1
            else:
                unresolved[c['stack']][r.get('name')] += 1
    print(json.dumps(per, indent=1))
    if not one:
        for s, u in unresolved.items():
            top = sorted(u.items(), key=lambda x: -x[1])[:12]
            print(s, 'unresolved, most common:', top)


if __name__ == '__main__':
    main()
