"""Writes each mage-rotes item's `prerequisites`: the Spheres and levels the
rote needs, read from its `note`, in data/catalog/blocks/mage-rotes.json.

Offline tooling: not part of the plugin, not run at runtime, not run by
bin/verify.

A note names its Spheres by number ("Forces 2, Correspondence 2") or by rank
after the rote's level and duration ("Level 2, One Scene or Hour - Correspondence:
Initiate, Forces: Initiate"). Ranks count as Laws of Ascension numbers them:
Apprentice 1, Initiate 2, Disciple 3, Adept 4, Master 5. A term marked
"optional" is left out. Where the note offers alternatives ("Life 3 or Spirit 3",
"Forces 2 or 3", or whole variants separated by ";"), the cheapest is written,
the first listed on a tie. A Sphere named twice keeps its higher level.

Usage: python3 tools/catalog/rote_prerequisites.py [--check]
With --check, nothing is written and the exit status says whether the file is
current.
"""

import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
BLOCKS = os.path.join(HERE, '..', '..', 'beyond-elysium', 'data', 'catalog', 'blocks')
ROTES = os.path.join(BLOCKS, 'mage-rotes.json')
SPHERES = os.path.join(BLOCKS, 'mage-spheres.json')

RANKS = {'Apprentice': 1, 'Initiate': 2, 'Disciple': 3, 'Adept': 4, 'Master': 5}


def sphere_names():
    with open(SPHERES, encoding='utf-8') as f:
        return [p['name'] for p in json.load(f)['definition']['powers']]


def body_of(note):
    """The part of a note that names Spheres."""
    if note.startswith('Level') and '—' in note:
        return note.split('—', 1)[1]
    return note


def alternative(text, previous, names):
    """One Sphere and level from `text`, or None; a bare level names `previous`'s Sphere."""
    text = text.strip()
    for name in names:
        m = re.fullmatch(re.escape(name) + r'\s*:\s*(' + '|'.join(RANKS) + r')', text)
        if m:
            return name, RANKS[m.group(1)]
        m = re.fullmatch(re.escape(name) + r'\s+(\d)', text)
        if m:
            return name, int(m.group(1))
    if previous is not None and re.fullmatch(r'\d', text):
        return previous, int(text)
    return None


def variant(text, names):
    """The cheapest Sphere-to-level set one variant needs, or None when a term cannot be read."""
    needs = {}
    for term in re.split(r',|\band\b', text):
        term = term.strip()
        if term == '':
            continue
        if re.match(r'optional\b', term, re.IGNORECASE) or term.lower().endswith('(optional)'):
            continue
        choices = []
        previous = None
        for part in re.split(r'\s+or\s+', term):
            found = alternative(part, previous, names)
            if found is None:
                return None
            choices.append(found)
            previous = found[0]
        name, level = min(choices, key=lambda c: c[1])
        needs[name] = max(needs.get(name, 0), level)
    return needs


def prerequisites(note, names):
    """The prerequisites a note gives, or None when it cannot be read."""
    variants = []
    for text in body_of(note).split(';'):
        needs = variant(text, names)
        if needs is None:
            return None
        if needs:
            variants.append(needs)
    if not variants:
        return None
    cheapest = min(variants, key=lambda v: sum(v.values()))
    return [{'block_slug': 'mage-spheres', 'power': n, 'min_level': l} for n, l in cheapest.items()]


def main():
    check = '--check' in sys.argv[1:]
    names = sorted(sphere_names(), key=len, reverse=True)
    with open(ROTES, encoding='utf-8') as f:
        raw = f.read()
    doc = json.loads(raw)

    unread = []
    for item in doc['definition']['items']:
        found = prerequisites(item.get('note') or '', names)
        if found is None:
            unread.append(item['name'])
            found = []
        item['prerequisites'] = found

    out = json.dumps(doc, indent=2, ensure_ascii=False) + '\n'
    items = doc['definition']['items']
    print(f'{len(items) - len(unread)} of {len(items)} rotes read; {len(unread)} left without prerequisites')
    for name in unread:
        print(f'  unread: {name}')

    if check:
        sys.exit(0 if out == raw else 1)
    with open(ROTES, 'w', encoding='utf-8') as f:
        f.write(out)


if __name__ == '__main__':
    main()
