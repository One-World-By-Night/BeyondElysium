"""Shared helpers for the catalog builders.

Offline tooling. Nothing here runs inside the plugin or under bin/verify.

The builders read a **snapshot** of the live seeded blocks (one JSON file per
slug, the raw `definition` column exported with `mysql --raw`):

    for s in vampire-disciplines vampire-blood-magic vampire-rituals \\
             kueijin-disciplines mage-spheres; do
      mysql -h127.0.0.1 -P3307 -uroot be_dev -N --raw -e \\
        "select definition from wp_be_schema_blocks
         where is_system=1 and slug='$s'" > "$SNAP/$s.json"
    done

Every structural decision a builder makes beyond reading the snapshot lives in
a rulings file next to it (`rulings/<slug>.json`) with the evidence for it.
"""

import json
import os
import re
from collections import OrderedDict
from pathlib import Path

from catalog_io import FAMILY_ORDER, LEVEL_ORDER, ordered

CODE_ROOT = Path(__file__).resolve().parents[2]
REPO_ROOT = CODE_ROOT.parent
BLOCKS_DIR = CODE_ROOT / 'beyond-elysium' / 'data' / 'catalog' / 'blocks'
RULINGS_DIR = Path(__file__).resolve().parent / 'rulings'
# Private research (never committed). A git worktree has no samples/ of its own,
# so BE_SAMPLES can point at the main checkout's copy.
SAMPLES_ROOT = Path(os.environ.get('BE_SAMPLES', str(REPO_ROOT / 'samples')))

LADDER_RANKS = ('basic', 'intermediate', 'advanced')
LADDER_2_2_1 = OrderedDict([('basic', 2), ('intermediate', 2), ('advanced', 1)])

# Tier words a note can carry, as substring needles: (needle, tier).
_TIER_NEEDLES = (
    ('innate', 'innate'), ('basic', 'basic'), ('int', 'intermediate'),
    ('adv', 'advanced'), ('elder', 'elder'), ('master', 'master'),
    ('asc', 'ascended'), ('meth', 'methuselah'),
)

EDITION_QUALIFIERS = OrderedDict([
    ('dark ages', 'dark-ages'),
    ('2nd ed', '2nd-ed'),
])


def load_snapshot(snapshot_dir, slug):
    path = Path(snapshot_dir) / f'{slug}.json'
    with open(path, encoding='utf-8') as fh:
        return json.load(fh)


def load_rulings(slug):
    path = RULINGS_DIR / f'{slug}.json'
    if not path.exists():
        return {}
    with open(path, encoding='utf-8') as fh:
        return json.load(fh, object_pairs_hook=OrderedDict)


def normalize_tier(note):
    note = (note or '').lower()
    for needle, tier in _TIER_NEEDLES:
        if needle in note:
            return tier
    return 'unknown'


def edition_of(note):
    """The edition variant id a note names, or None for the base printing."""
    note = (note or '').lower()
    for needle, variant in EDITION_QUALIFIERS.items():
        if needle in note:
            return variant
    return None


def qualifier_of(note):
    """What a note says beyond its tier word and edition tag - `(Setite)`,
    `tzimisce` - or None."""
    text = (note or '').strip()
    m = re.search(r'\(([^)]*)\)', text)
    if m:
        return m.group(1).strip() or None
    words = re.sub(r'[.,]', ' ', text.lower()).split()
    tierish = {'basic', 'int', 'intermediate', 'adv', 'advanced', 'elder', 'master',
               'asc', 'ascended', 'meth', 'methuselah', 'innate'}
    rest = [w for w in words if w not in tierish]
    joined = ' '.join(rest)
    for needle in ('dark ages', '2nd ed'):
        joined = joined.replace(needle, '')
    joined = ' '.join(joined.split())
    return joined.title() if joined else None


def all_levels(family):
    """Every level a snapshot family holds, in source order: ladder, then each
    elder rank's picks, then overflow."""
    out = []
    for lv in family.get('levels', []):
        out.append(('levels', lv))
    for rank, picks in (family.get('elder') or {}).items():
        for lv in picks:
            out.append(('elder', lv))
    for lv in family.get('overflow', []):
        out.append(('overflow', lv))
    return out


def ladder_from(entries, ladder=LADDER_2_2_1):
    """Fills a declared ladder rank by rank, source order within a rank.

    `entries` are dicts with at least `name` and `tier`. Returns
    (rungs, leftovers).
    """
    by_rank = OrderedDict((r, []) for r in ladder)
    leftovers = []
    for e in entries:
        if e['tier'] in by_rank:
            by_rank[e['tier']].append(e)
        else:
            leftovers.append(e)
    rungs = []
    for rank, quota in ladder.items():
        rungs.extend(by_rank[rank][:quota])
        leftovers.extend(by_rank[rank][quota:])
    numbered = []
    for i, e in enumerate(rungs, start=1):
        numbered.append(OrderedDict([('level', i), ('tier', e['tier']), ('name', e['name'])] +
                                    [(k, e[k]) for k in ('note', 'aliases', 'filled_from') if e.get(k)]))
    return numbered, leftovers


def family_record(source, levels, elder=None, traditions=None, restriction=None,
                  description=None, extra=None):
    """One family in the catalog format's fixed key order: source, levels,
    elder, traditions, restriction, description, then anything else."""
    rec = OrderedDict()
    rec['source'] = source
    rec['levels'] = levels
    rec['elder'] = elder if elder is not None else OrderedDict()
    rec['traditions'] = traditions if traditions else None
    rec['restriction'] = restriction
    rec['description'] = description
    for k, v in (extra or {}).items():
        if v not in (None, [], {}):
            rec[k] = v
    return rec


def envelope(slug, name, section_type, provenance, definition, variant=None):
    doc = OrderedDict()
    doc['format'] = 1
    doc['slug'] = slug
    doc['name'] = name
    doc['kind'] = 'block'
    doc['section_type'] = section_type
    if variant:
        doc['variant'] = variant
    doc['provenance'] = provenance
    doc['definition'] = definition
    return doc


def runtime_shape(definition):
    """The one catalog shape: the runtime list the engine and
    `src/types` read - `TieredPowerDefinition.powers: TieredPower[]`, `PowerLevel`.

    The builders assemble families in a map keyed by name. This turns that map
    into the list, once, at write time:

    - `powers` becomes a list of family objects, each carrying `name`;
    - a rung's `name` becomes `power_name`, and it carries its rank's `cost`
      from `_meta.costs`;
    - every `elder` pick becomes a full object `{level: null, tier, power_name,
      cost}`;
    - key order follows `catalog_io.py`, and null `traditions`/`restriction`/
      `description` are omitted.
    """
    powers = definition.get('powers')
    if not isinstance(powers, dict):
        return definition
    costs = (definition.get('_meta') or {}).get('costs') or {}

    def price(tier):
        c = costs.get(tier)
        return None if c is None else str(c)

    families = []
    for name, fam in powers.items():
        levels = []
        for lv in fam.get('levels', []):
            out = OrderedDict()
            out['level'] = lv['level']
            out['tier'] = lv['tier']
            out['power_name'] = lv['name']
            out['cost'] = price(lv['tier'])
            for k in ('note', 'aliases', 'filled_from'):
                if lv.get(k):
                    out[k] = lv[k]
            levels.append(ordered(out, LEVEL_ORDER))
        elder = OrderedDict()
        for rank, picks in (fam.get('elder') or {}).items():
            elder[rank] = []
            for pk in picks:
                obj = OrderedDict([('level', None), ('tier', rank),
                                   ('power_name', pk if isinstance(pk, str) else pk['power_name']),
                                   ('cost', price(rank))])
                elder[rank].append(obj)
        rec = OrderedDict([('name', name)])
        for k, v in fam.items():
            if k in ('levels', 'elder'):
                continue
            if k in ('traditions', 'restriction', 'description') and v in (None, {}, []):
                continue
            rec[k] = v
        rec['levels'] = levels
        rec['elder'] = elder
        families.append(ordered(rec, FAMILY_ORDER))
    out = OrderedDict(definition)
    out['powers'] = families
    return out


def write_block(doc):
    if doc.get('section_type') == 'tiered_power':
        doc = OrderedDict(doc)
        doc['definition'] = runtime_shape(doc['definition'])
    path = BLOCKS_DIR / f"{doc['slug']}.json"
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        json.dump(doc, fh, indent=2, ensure_ascii=False)
        fh.write('\n')
    return path


def meta(ranks, costs, out_of_type=None, ladder=LADDER_2_2_1):
    m = OrderedDict()
    m['ranks'] = list(ranks)
    m['ladder'] = OrderedDict(ladder)
    m['costs'] = OrderedDict((r, costs.get(r)) for r in ranks)
    m['out_of_type'] = OrderedDict((r, out_of_type[r]) for r in ranks) if out_of_type else None
    m['levels'] = None
    m['categories'] = None
    m['untiered'] = None
    return m


# Behaviour flags a definition carries into the declared file.
# `out_of_type_cost_modifier` is absent: `_meta.out_of_type` supersedes it.
DEFINITION_FLAGS = ('atomic', 'sequential', 'allow_custom', 'player_order', 'shape')


def flags_from(snapshot):
    return OrderedDict((k, snapshot[k]) for k in DEFINITION_FLAGS if k in snapshot)


def partition(definition):
    """(families, rungs, picks, overflow) for one emitted definition."""
    rungs = picks = overflow = 0
    powers = definition.get('powers', {})
    for fam in (powers.values() if isinstance(powers, dict) else powers):
        rungs += len(fam.get('levels', []))
        picks += sum(len(v) for v in (fam.get('elder') or {}).values())
        overflow += len(fam.get('overflow', []) or [])
    return len(powers), rungs, picks, overflow
