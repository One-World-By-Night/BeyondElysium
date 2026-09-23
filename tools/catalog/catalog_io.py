"""Shared helpers for the 1.3.0 catalog emitter: reading the local dump, writing files in the
human-first key order `reference/CATALOG-JSON-FORMAT.md` asks for, and the small amount of
shape normalisation every block needs.

Nothing here decides content. Content decisions - with the evidence for each - live in
`rulings.py`, so a reviewer reads one file to see every judgement the emitter makes.
"""

import json
import re
from collections import OrderedDict
from pathlib import Path

CODE_ROOT = Path(__file__).resolve().parents[2]
CATALOG = CODE_ROOT / 'beyond-elysium' / 'data' / 'catalog'


def _samples():
    """The private research folder: beside code/, or beside the main checkout when this runs
    from a git worktree (worktrees do not carry the untracked samples/ directory)."""
    here = CODE_ROOT.parent / 'samples'
    if here.exists():
        return here
    import subprocess
    try:
        common = subprocess.check_output(['git', '-C', str(CODE_ROOT), 'rev-parse', '--git-common-dir'], text=True).strip()
        return (Path(CODE_ROOT, common).resolve().parent / 'samples')
    except (OSError, subprocess.CalledProcessError):
        return here


SAMPLES = _samples()
EXTRACTED = '2026-09-22'
TOOL = 'tools/catalog/emit_catalog.py'

# Blocks another release authors in parallel (1.3.1). The emitter never writes them and never
# copies data out of them - mortal-numina's overflow partly resolves when 1.3.1 fixes these.
NOT_OURS = {
    'vampire-disciplines', 'vampire-blood-magic', 'vampire-rituals',
    'kueijin-disciplines', 'mage-spheres',
}

TRAIT_ITEM_ORDER = [
    'name', 'cost', 'tier', 'group', 'subgroup', 'source', 'note', 'description',
    'approval', 'reason', 'approval_by_value', 'prerequisites',
]
TRAIT_DEF_ORDER = [
    '_meta', 'alphabetize', 'atomic', 'allow_custom', 'allow_multiples',
    'has_specializations', 'negative', 'flat_cost', 'categories',
]
LEVEL_ORDER = ['level', 'tier', 'power_name', 'cost', 'note', 'source', 'description', 'alternatives']
FAMILY_ORDER = [
    'name', 'source', 'category_values', 'aliases', 'levels', 'elder',
    'traditions', 'restriction', 'description',
]
META_ORDER = ['ranks', 'ladder', 'costs', 'out_of_type', 'levels', 'categories', 'untiered', 'in_type_source']


def ordered(d, order):
    """Returns `d` with `order`'s keys first (only those present), then the rest as found."""
    out = OrderedDict()
    for k in order:
        if k in d:
            out[k] = d[k]
    for k, v in d.items():
        if k not in out:
            out[k] = v
    return out


def load_dump(dump, kind, slug):
    return json.load(open(Path(dump) / kind / f'{slug}.json'))


def write(sub, slug, name, kind, definition, sources, section_type=None, variant=None, notes=None):
    """Writes one catalog file in the envelope order of format §3."""
    env = OrderedDict()
    env['format'] = 1
    env['slug'] = slug
    env['name'] = name
    env['kind'] = kind
    if section_type:
        env['section_type'] = section_type
    if variant:
        env['variant'] = variant
    prov = OrderedDict()
    prov['sources'] = sources
    prov['extracted'] = EXTRACTED
    prov['tool'] = TOOL
    if notes:
        prov['notes'] = notes
    env['provenance'] = prov
    env['definition'] = definition
    path = CATALOG / sub / f'{slug}.json'
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(env, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    return path


def trait_item(item, **overrides):
    """A trait_list item with every required facet present and no approval content.

    Creature data carries zero approval levels (owner rule), so `approval`, `reason` and
    `approval_by_value` are always emitted empty whatever the source said.
    """
    it = dict(item)
    it.pop('name_pt', None)
    it.update(overrides)
    for k in ('tier', 'group', 'subgroup', 'source', 'note', 'description'):
        it.setdefault(k, None)
    it.setdefault('cost', None)
    it['approval'] = None
    it['reason'] = None
    it['approval_by_value'] = []
    it.setdefault('prerequisites', [])
    if it['cost'] is not None and not isinstance(it['cost'], str):
        it['cost'] = str(it['cost'])
    return ordered(it, TRAIT_ITEM_ORDER)


def trait_definition(live_def, items, extra=None):
    d = OrderedDict()
    for k, v in live_def.items():
        if k in ('items', 'approval_rules'):
            continue
        d[k] = v
    if extra:
        d.update(extra)
    d['items'] = items
    return ordered(d, TRAIT_DEF_ORDER + ['items'])


def norm(name):
    """Comparison key for "is this the same power under a different spelling"."""
    return re.sub(r'[^a-z0-9]', '', (name or '').lower().replace('&', 'and'))


def level(n, tier, power_name, cost, note=None, source=None, alternatives=None, description=None):
    lv = OrderedDict()
    lv['level'] = n
    lv['tier'] = tier
    lv['power_name'] = power_name
    lv['cost'] = None if cost is None else str(cost)
    if note is not None:
        lv['note'] = note
    if source is not None:
        lv['source'] = source
    if description is not None:
        lv['description'] = description
    if alternatives:
        lv['alternatives'] = alternatives
    return lv


def pick(tier, power_name, cost, note=None, source=None, description=None):
    p = OrderedDict()
    p['level'] = None
    p['tier'] = tier
    p['power_name'] = power_name
    p['cost'] = None if cost is None else str(cost)
    if note is not None:
        p['note'] = note
    if source is not None:
        p['source'] = source
    if description is not None:
        p['description'] = description
    return p


def meta(ranks, ladder, costs, out_of_type=None, levels=None, categories=None, untiered=None, extra=None):
    m = OrderedDict()
    m['ranks'] = ranks
    m['ladder'] = ladder
    m['costs'] = costs
    m['out_of_type'] = out_of_type
    m['levels'] = levels
    m['categories'] = categories
    m['untiered'] = untiered
    if extra:
        m.update(extra)
    return m


def family(name, levels_, elder=None, source=None, **rest):
    f = OrderedDict()
    f['name'] = name
    if source is not None:
        f['source'] = source
    f.update(rest)
    f['levels'] = levels_
    f['elder'] = elder or {}
    return ordered(f, FAMILY_ORDER)
