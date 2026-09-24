"""Reads the private Vampire research (samples/research/vampire/) into one shape.

Offline tooling. The ~25 extraction files nest their powers differently
(`paths[]`, `ways[]`, named sub-objects, `powers[]` keyed by discipline). This
flattens all of them to `(file, path_name, [(power_name, tier_or_level), ...])`.

Precedence: OWBN packet > MET > tabletop, newest wins within a tier, only ever
compared within one era. `PRECEDENCE` orders the folders.
"""

import json
from pathlib import Path

from catalog_common import SAMPLES_ROOT

VAMPIRE = SAMPLES_ROOT / 'research' / 'vampire'
PRECEDENCE = {'owbn': 0, 'met': 1, 'tt': 2}
TIER_WORDS = {'basic': 'basic', 'intermediate': 'intermediate', 'advanced': 'advanced',
              'elder': 'elder', 'master': 'master', 'ascended': 'ascended',
              'methuselah': 'methuselah'}


def _tier(raw):
    if raw is None:
        return None
    if isinstance(raw, int):
        return raw
    word = str(raw).strip().lower()
    return TIER_WORDS.get(word, word)


def _powers(obj):
    out = []
    for p in obj.get('powers', []) or []:
        if isinstance(p, dict):
            name = p.get('power_name') or p.get('name')
            if name:
                out.append((name, _tier(p.get('tier', p.get('level')))))
    return out


def load_paths():
    found = []
    if not VAMPIRE.exists():
        return found
    for path in sorted(VAMPIRE.glob('*/*.json')):
        folder = path.parent.name
        try:
            data = json.load(open(path, encoding='utf-8'))
        except (OSError, ValueError):
            continue
        if not isinstance(data, dict):
            continue
        rel = f'{folder}/{path.name}'
        for key in ('paths', 'ways'):
            for p in data.get(key, []) or []:
                if isinstance(p, dict):
                    name = p.get('path') or p.get('way') or p.get('name')
                    powers = _powers(p)
                    if name and powers:
                        found.append((rel, name, powers))
        # `powers: [{discipline, power_name, tier}]` - the LotN 2e file.
        if isinstance(data.get('powers'), list) and data['powers'] and 'discipline' in data['powers'][0]:
            grouped = {}
            for p in data['powers']:
                grouped.setdefault(p['discipline'], []).append((p['power_name'], _tier(p.get('tier'))))
            for name, powers in grouped.items():
                found.append((rel, name, powers))
        # Named sub-objects carrying `powers` (bloodline Disciplines, Mortuus, Path of Mars...).
        for key, val in data.items():
            if isinstance(val, dict) and isinstance(val.get('powers'), list):
                powers = _powers(val)
                if powers:
                    found.append((rel, key.replace('_', ' ').title(), powers))
    found.sort(key=lambda t: PRECEDENCE.get(t[0].split('/')[0], 9))
    return found
