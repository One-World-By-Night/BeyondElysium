#!/usr/bin/env python3
"""The precedence resolver, generalised from Fera's `_extractor/resolve.py`.

The rule: **OWBN packet > MET > tabletop. Newest wins within a tier. Only ever compare
sources from the same line and era.**

* Authority tier first, recency only as the tiebreaker inside a tier - an old OWBN packet
  beats a new tabletop book.
* Era is its own axis. A cross-era comparison is **refused**, never silently resolved: Dark
  Ages content belongs in a variant file, not in a precedence contest.
* A cross-line comparison is refused the same way - two lines' same-named items are separate
  rows by construction.

Used when producing files, not at seed time. Run `precedence.py --self-test`.
"""

import sys
from dataclasses import dataclass

TIERS = {'owbn': 0, 'met': 1, 'tabletop': 2}


@dataclass(frozen=True)
class Source:
    label: str
    tier: str    # owbn | met | tabletop
    line: str    # vampire, werewolf, fera, ...
    era: str     # modern | dark-ages | wild-west | victorian ...
    year: int


class Refused(ValueError):
    """Two sources the rule says must never be compared."""


def resolve(candidates):
    """Returns the winning (source, value) from a list of (Source, value) pairs.

    Agreement is the dominant case and costs nothing: every candidate carries the same value,
    so any of them wins. Disagreement is settled by tier, then year - and refused outright when
    the candidates span lines or eras.
    """
    if not candidates:
        raise ValueError('nothing to resolve')
    lines = {s.line for s, _ in candidates}
    if len(lines) > 1:
        raise Refused(f'cross-line comparison refused: {sorted(lines)}')
    eras = {s.era for s, _ in candidates}
    if len(eras) > 1:
        raise Refused(f'cross-era comparison refused: {sorted(eras)} - ship the other era as a variant')
    for s, _ in candidates:
        if s.tier not in TIERS:
            raise ValueError(f'unknown authority tier {s.tier!r} on {s.label}')
    return sorted(candidates, key=lambda c: (TIERS[c[0].tier], -c[0].year))[0]


def self_test():
    lotn_r = Source('Laws of the Night Revised', 'met', 'vampire', 'modern', 2000)
    lotn = Source('Laws of the Night', 'met', 'vampire', 'modern', 1997)
    fnf = Source('Faith and Fire', 'met', 'vampire', 'dark-ages', 2002)
    lotc = Source('Lore of the Clans', 'tabletop', 'vampire', 'modern', 2015)
    owbn = Source('OWBN Toreador packet', 'owbn', 'vampire', 'modern', 2011)
    cb = Source('Changing Breeds 1', 'met', 'fera', 'modern', 2001)

    # Same line, same era, same tier - the newer source wins.
    assert resolve([(lotn, '3'), (lotn_r, '4')]) == (lotn_r, '4')
    # Authority before recency: a 2011 packet beats a 2015 tabletop book.
    assert resolve([(lotc, '1'), (owbn, '2')]) == (owbn, '2')
    # A Dark Ages source never supersedes a Modern Nights one - refused, not resolved.
    try:
        resolve([(lotn_r, '3'), (fnf, '4')])
        raise AssertionError('cross-era comparison was resolved instead of refused')
    except Refused:
        pass
    # Two lines' same-named items are never compared.
    try:
        resolve([(lotn_r, '3'), (cb, '3')])
        raise AssertionError('cross-line comparison was resolved instead of refused')
    except Refused:
        pass
    # Agreement: one value, whoever carries it.
    assert resolve([(lotn, '3'), (lotn_r, '3')])[1] == '3'
    print('precedence: T-C6 self-test passed (5 cases)')


if __name__ == '__main__':
    if '--self-test' in sys.argv:
        self_test()
    else:
        print(__doc__)
