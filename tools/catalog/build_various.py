"""Writes the Various creature type's merged lists - blocks/various-abilities.json, -backgrounds, -merits, -flaws and
-tempers under data/catalog/ - from the blocks of every other creature type.

Offline tooling: not part of the plugin, not run at runtime, not run by bin/verify. `tests/unit/VariousCatalogTest.php`
fails the build when a source block holds a name its merged list lacks.

A merged list holds each name once. The spelling, price, group and tier come from the first block that carries the name
(Mortal first, then the rest by slug); `source` names every creature type that carries it; specializations and aliases
are the union across blocks. No item carries approval content.

usage: python3 tools/catalog/build_various.py [--check]
  --check   writes nothing and exits 1 when a file on disk differs from what this would write
"""

import json
import sys
from collections import OrderedDict

import catalog_io
from catalog_io import CATALOG, norm, trait_item, write

catalog_io.TOOL = 'tools/catalog/build_various.py'

BLOCKS = CATALOG / 'blocks'
STACKS = CATALOG / 'stacks'

# family -> (merged block slug, name, source block suffixes, extra source slugs)
LISTS = OrderedDict([
    ('abilities', ('various-abilities', 'Abilities', ['-abilities'], [])),
    ('backgrounds', ('various-backgrounds', 'Backgrounds', ['-backgrounds'], ['met-backgrounds'])),
    ('merits', ('various-merits', 'Merits', ['-merits'], [])),
    ('flaws', ('various-flaws', 'Flaws', ['-flaws'], [])),
])

TEMPERS = ('various-tempers', 'Tempers')
# Tempers from Grapevine's Various list that no creature type's resources declare.
EXTRA_TEMPERS = ['Essence']

FIRST = 'mortal'


def load_blocks():
    blocks = OrderedDict()
    for path in sorted(BLOCKS.glob('*.json')):
        block = json.loads(path.read_text(encoding='utf-8'))
        blocks[block['slug']] = block
    return blocks


def creature_type_names():
    names = {}
    for path in sorted(STACKS.glob('*.json')):
        stack = json.loads(path.read_text(encoding='utf-8'))
        names[stack['slug']] = stack['name']
    names['met'] = 'MET'
    return names


def type_of(slug, names):
    return names.get(slug.split('-')[0], slug.split('-')[0])


def types_label(types, total):
    """The creature types that carry a name: all of them, or the list."""
    return 'Every creature type' if len(types) >= total else ', '.join(types)


def source_order(slugs):
    """Mortal's block first, then the rest by slug."""
    return sorted(slugs, key=lambda s: (not s.startswith(FIRST + '-'), s))


def merge_list(family, blocks, names):
    slug, name, suffixes, extra = LISTS[family]
    wanted = [
        s for s, b in blocks.items()
        if not s.startswith('various-') and b['section_type'] == 'trait_list'
        and (any(s.endswith(x) for x in suffixes) or s in extra)
    ]
    wanted = source_order(wanted)
    total = len({type_of(s, names) for s in wanted})

    merged = OrderedDict()
    for source_slug in wanted:
        owner = type_of(source_slug, names)
        for item in blocks[source_slug]['definition']['items']:
            key = norm(item['name'])
            if not key:
                continue
            entry = merged.get(key)
            if entry is None:
                entry = merged[key] = {
                    'name': item['name'].strip(), 'cost': item.get('cost'), 'tier': item.get('tier'),
                    'group': item.get('group'), 'subgroup': item.get('subgroup'), 'types': [],
                    'allow_multiples': False, 'specializations': [], 'aliases': [], 'description': None,
                }
            for field in ('cost', 'tier', 'group', 'subgroup'):
                if entry[field] is None and item.get(field) is not None:
                    entry[field] = item[field]
            if entry['description'] is None and item.get('description'):
                entry['description'] = item['description']
            if owner not in entry['types']:
                entry['types'].append(owner)
            entry['allow_multiples'] = entry['allow_multiples'] or bool(item.get('allow_multiples'))
            for field in ('specializations', 'aliases'):
                for value in item.get(field) or []:
                    if value not in entry[field]:
                        entry[field].append(value)

    items = []
    for entry in sorted(merged.values(), key=lambda e: e['name'].casefold()):
        item = {
            'name': entry['name'], 'cost': entry['cost'], 'tier': entry['tier'], 'group': entry['group'],
            'subgroup': entry['subgroup'], 'source': types_label(entry['types'], total), 'description': entry['description'],
        }
        if entry['allow_multiples']:
            item['allow_multiples'] = True
        if entry['specializations']:
            item['specializations'] = entry['specializations']
        if entry['aliases']:
            item['aliases'] = entry['aliases']
        items.append(trait_item(item))

    base = blocks[FIRST + '-' + family]['definition']
    definition = OrderedDict((k, v) for k, v in base.items() if k not in ('items', 'approval_rules', '_meta'))
    definition['allow_custom'] = True
    definition['items'] = items
    return slug, name, wanted, definition


def merge_tempers(blocks, names):
    wanted = source_order([
        s for s, b in blocks.items()
        if s.endswith('-resources') and b['section_type'] == 'resource_pool' and not s.startswith('various-')
    ])
    total = len({type_of(s, names) for s in wanted})
    pools = OrderedDict()
    for source_slug in wanted:
        owner = type_of(source_slug, names)
        for pool in blocks[source_slug]['definition'].get('pools', []):
            entry = pools.setdefault(norm(pool['name']), {'name': pool['name'].strip(), 'types': []})
            if owner not in entry['types']:
                entry['types'].append(owner)
    for extra in EXTRA_TEMPERS:
        pools.setdefault(norm(extra), {'name': extra, 'types': []})
    items = [
        trait_item({'name': e['name'], 'source': types_label(e['types'], total) if e['types'] else None})
        for e in sorted(pools.values(), key=lambda e: e['name'].casefold())
    ]
    definition = OrderedDict([('allow_custom', True), ('allow_multiples', False), ('items', items)])
    return TEMPERS[0], TEMPERS[1], wanted, definition


def outputs():
    blocks = load_blocks()
    names = creature_type_names()
    result = []
    for family in LISTS:
        result.append(merge_list(family, blocks, names))
    result.append(merge_tempers(blocks, names))
    return result


def render(slug, name, sources, definition):
    notes = (
        'Written by tools/catalog/build_various.py. Each name appears once, with the spelling, price, group and tier of '
        "the first block that carries it (Mortal's, then the rest by slug); `source` names every creature type that "
        'carries it. Change the sources and run the builder again.'
    )
    path = write(
        'blocks', slug, name, 'block', definition,
        ['Merged from: ' + ', '.join(sources)], section_type='trait_list', notes=notes,
    )
    return path


def main():
    check = '--check' in sys.argv[1:]
    stale = []
    for slug, name, sources, definition in outputs():
        path = BLOCKS / f'{slug}.json'
        before = path.read_text(encoding='utf-8') if path.exists() else None
        if check:
            after_path = render(slug, name, sources, definition)
            after = after_path.read_text(encoding='utf-8')
            if before is None:
                after_path.unlink()
            else:
                after_path.write_text(before, encoding='utf-8')
            if after != before:
                stale.append(slug)
            continue
        render(slug, name, sources, definition)
        print(f'{slug}: {len(definition["items"])} entries from {len(sources)} blocks')
    if check:
        if stale:
            print('out of date: ' + ', '.join(stale))
            sys.exit(1)
        print('merged lists are current')


if __name__ == '__main__':
    main()
