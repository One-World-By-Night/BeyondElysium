#!/usr/bin/env python3
"""
Pass B - the chapter parser.

Anchors on the sphere-requirement line (a tight, closed-vocabulary grammar -
nine sphere names, digits, "or"/"and"/"optional"/commas only) since it is far
more mechanically distinctive than a rote's name or citation line. Walking
backward from each anchor recovers the citation line(s) (anything containing
"page"/"pages"/the source's own recurring "pas" typo followed by digits) and
then the name line(s) (anything before the citation that does not itself
look like the end of a prose sentence).

Never emits a description column (R3 - the Grimoire's prose is the
commercial product; only structural facts - name, sphere note, citation,
chapter/section - are extracted).

Three real source-text irregularities are handled explicitly, each found by
running this over the real book rather than assumed up front:
  - A sphere line wrapping onto two physical lines (e.g. "Blight/Farmer's
    Favor", printed 8) is rejoined before anchor-matching runs at all.
  - A chapter-title running head can land in the middle of a legitimate
    multi-citation run at a page break; it is made transparent to both
    backward scans rather than treated as a hard boundary.
  - Two sphere lines with no citation between them (an alternate build for
    the SAME rote, sharing one citation - the design doc's own "Feedback"
    example) are merged into one entry's `note`, not split into two.
"""
import re
import sys
import json

SPHERE_WORD = r"(?:Correspondence|Entropy|Forces|Life|Matter|Mind|Prime|Spirit|Time)"
CLAUSE = rf"(?:optional\s+)?{SPHERE_WORD}\s+\d+(?:\s+or\s+\d+)*"
SEP = r"(?:,|;|\band\b|\bor\b)"
SPHERE_LINE_RE = re.compile(rf"^{CLAUSE}(?:\s*{SEP}\s*{CLAUSE})*\.?$", re.IGNORECASE)
SPHERE_FRAGMENT_END_RE = re.compile(r"(?:,|\bor\b|\band\b)\s*$", re.IGNORECASE)

CITATION_RE = re.compile(r"\bpages?\s+\d|\bpas\s+\d|\bpage\s*$", re.IGNORECASE)
SENTENCE_END_RE = re.compile(r"[.!?][”’\"')]*$")

CHAPTERS = [
    "Blessings and Curses", "Computers", "Divination and Fate", "Elemental Magick",
    "Energy-Work", "Enhanced Combat", "Healing and Harming", "Inanimate Objects",
    "Miscellaneous", "Movement and Communication", "Mystic Perception", "Necromancy",
    "Obfuscation", "Space-Time Management", "Summoning, Binding and Warding",
    "Transformations", "Uncanny Influence",
]
CHAPTER_SET = set(CHAPTERS)
NOISE_EXACT = {"Enlightened Grimoire"}
BARE_NUMBER = re.compile(r"^\d+$")

# From the book's own Table of Contents (printed 3) - the printed page each
# rote chapter opens on. Used as the page-number oracle's starting point for
# a chapter before its first footer digit has appeared in the stream, and as
# a sanity floor afterward (a footer-derived page can never precede it).
CHAPTER_START_PAGE = {
    "Blessings and Curses": 8, "Computers": 16, "Divination and Fate": 24,
    "Elemental Magick": 36, "Energy-Work": 46, "Enhanced Combat": 56,
    "Healing and Harming": 66, "Inanimate Objects": 80, "Miscellaneous": 94,
    "Movement and Communication": 98, "Mystic Perception": 118, "Necromancy": 130,
    "Obfuscation": 136, "Space-Time Management": 142,
    "Summoning, Binding and Warding": 150, "Transformations": 164,
    "Uncanny Influence": 180,
}


def load_dense_lines(path):
    with open(path, encoding="utf-8") as f:
        raw = [l.rstrip("\n") for l in f]
    out = []
    for i, line in enumerate(raw):
        s = line.strip()
        if s == "" or s in NOISE_EXACT or BARE_NUMBER.match(s):
            continue
        out.append((i, s))
    return out


def build_footer_index(path):
    """(line_no, page) for every bare-digit footer line - each marks the end
    of printed page `page`, so content after it (past the following running
    head) belongs to page `page + 1`.

    Not every bare-digit line is a real page footer - a rote's own body text
    occasionally has a lone number on its own line (a dice-pool count, a
    list item). A real footer sequence is monotonically increasing with
    small steps; anything that doesn't fit that pattern is rejected as body
    noise rather than accepted as a page jump."""
    with open(path, encoding="utf-8") as f:
        raw = [l.rstrip("\n") for l in f]
    events = []
    last_page = CHAPTER_START_PAGE["Blessings and Curses"] - 1
    for i, line in enumerate(raw):
        s = line.strip()
        if BARE_NUMBER.match(s):
            candidate = int(s)
            if last_page < candidate <= last_page + 3:
                events.append((i, candidate))
                last_page = candidate
    return events


CHAPTER_BY_START_PAGE = sorted(((pg, name) for name, pg in CHAPTER_START_PAGE.items()))


def page_for(footer_events, line_no):
    """Page derived purely from footer digits, independent of any running-
    head detection - a chapter's own opening page(s) carry no running head
    at all (only the decorative title banner, once, at the very top of the
    chapter's first page), so tying page number to `group` is circular and
    unreliable right where it matters most, the first page or two of a new
    chapter."""
    candidate = CHAPTER_START_PAGE["Blessings and Curses"]
    for ln, pg in footer_events:
        if ln < line_no:
            candidate = pg + 1
        else:
            break
    return candidate


def group_for_page(page):
    """The chapter whose TOC start page is the largest one not exceeding
    `page` - authoritative over running-head occurrence order, since a
    chapter's first page(s) never carry their own running head."""
    if page is None:
        return None
    best = None
    for start, name in CHAPTER_BY_START_PAGE:
        if start <= page:
            best = name
        else:
            break
    return best


def merge_wrapped_sphere_lines(dense):
    """A sphere line that wraps mid-clause leaves its tail as its own,
    independently-matching short sphere line on the next line. Detected by:
    this line matches SPHERE_LINE_RE alone, AND the previous line ends in a
    dangling connector (",", "or", "and") that only makes sense as an
    unfinished clause, never as a real standalone entry's full requirement."""
    out = list(dense)
    changed = True
    while changed:
        changed = False
        for i in range(1, len(out)):
            _, prev_text = out[i - 1]
            _, cur_text = out[i]
            if SPHERE_LINE_RE.match(cur_text) and SPHERE_FRAGMENT_END_RE.search(prev_text):
                merged = (out[i - 1][0], f"{prev_text} {cur_text}")
                out[i - 1] = merged
                del out[i]
                changed = True
                break
    return out


def parse(dense_raw, footer_events=None):
    dense = merge_wrapped_sphere_lines(dense_raw)
    footer_events = footer_events or []

    # scan lines exclude chapter-title running heads entirely - they repeat
    # on every page of their own chapter and can land mid-citation-run at a
    # page break; `events` (built above, from the un-filtered dense list)
    # is the only thing that needs them.
    scan = [(ln, t) for ln, t in dense if t not in CHAPTER_SET]
    texts = [t for _, t in scan]
    line_nos = [ln for ln, _ in scan]
    n = len(texts)

    anchors = [i for i in range(n) if SPHERE_LINE_RE.match(texts[i])]

    entries = []
    skipped_anchors = []
    consumed_as_alt_build = set()

    for i in anchors:
        if i in consumed_as_alt_build:
            continue

        j = i - 1
        citation_lines = []
        while j >= 0 and CITATION_RE.search(texts[j]):
            citation_lines.insert(0, texts[j])
            j -= 1

        if not citation_lines:
            # no citation directly before this sphere line - check whether
            # the immediately preceding line is itself another sphere-line
            # anchor (an alternate build for the same rote, sharing one
            # citation - the "Feedback" pattern).
            if j == i - 1 and j >= 0 and SPHERE_LINE_RE.match(texts[j]) and j in [a for a in anchors]:
                # merge forward: this sphere line becomes an extra `note`
                # segment on whichever entry owns anchor j, once that
                # anchor is processed (anchors are visited in order, so j
                # was already processed if j < i, which it always is here).
                for e in entries:
                    if e.get("_anchor_line") == line_nos[j]:
                        e["note"] = e["note"] + "; " + texts[i]
                        break
                consumed_as_alt_build.add(i)
                continue
            skipped_anchors.append((line_nos[i], texts[i], "no citation line found walking backward"))
            continue

        name_lines = []
        m = j
        while m >= 0 and len(name_lines) < 3:
            line = texts[m]
            if SPHERE_LINE_RE.match(line) or CITATION_RE.search(line):
                break
            if SENTENCE_END_RE.search(line):
                # tail of the PREVIOUS entry's description, never a name
                # fragment - stop here whether or not name_lines is empty
                # yet (an empty result becomes an honest skip below, rather
                # than a name silently built from leftover prose).
                break
            name_lines.insert(0, line)
            m -= 1

        if not name_lines:
            skipped_anchors.append((line_nos[i], texts[i], "no name line found walking backward"))
            continue

        name = " ".join(name_lines).strip()
        # The longest genuine headword in the book's own index is 55
        # characters ("Long-Distance Universal Travel/Puncture Reality
        # Barrier"). A "name" run past ~60 only happens when several lines
        # of body prose with no terminal punctuation got swept in (a
        # multi-paragraph description broken by a page/column boundary
        # mid-sentence, or a second sphere-line variant this book uses a
        # non-canonical sphere synonym for - "Dimensional Science" - that
        # never anchored in the first place). Treat it as a failed
        # resolution rather than shipping a garbled name.
        if len(name) > 60:
            skipped_anchors.append((line_nos[i], texts[i], f"resolved name implausibly long ({len(name)} chars): {name[:70]!r}"))
            continue
        # A real headword can lead with a digit ("108 Plum Blossoms," a real
        # index entry), but a digit appearing anywhere else in a resolved
        # name is always noise - a glued-on page footer or an unanchored
        # sphere-line fragment absorbed as text (both real, observed
        # failure shapes here), never part of an actual rote name.
        if re.search(r"\d", re.sub(r"^\d+\s+", "", name)):
            skipped_anchors.append((line_nos[i], texts[i], f"resolved name contains a stray digit: {name!r}"))
            continue
        citation = "; ".join(citation_lines).strip()
        page = page_for(footer_events, line_nos[i])
        group = group_for_page(page)

        entries.append({
            "name": name,
            "note": texts[i],
            "citation": citation,
            "group": group,
            "page": page,
            "_anchor_line": line_nos[i],
        })

    for e in entries:
        del e["_anchor_line"]

    return entries, skipped_anchors


def normalize(headword):
    h = headword.lower()
    h = re.sub(r"[’']", "'", h)
    h = re.sub(r"[^a-z0-9'\-]+", " ", h)
    return re.sub(r"\s+", " ", h).strip()


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else "chapters.txt"
    dense = load_dense_lines(path)
    footer_events = build_footer_index(path)
    entries, skipped = parse(dense, footer_events)

    print(f"Entries parsed cleanly: {len(entries)}")
    print(f"Anchors skipped (no citation/name resolvable): {len(skipped)}")
    for ln, txt, reason in skipped[:40]:
        print(f"  SKIP @{ln + 1}: {txt!r} - {reason}")

    by_key = {}
    for e in entries:
        key = normalize(e["name"])
        e["key"] = key
        by_key.setdefault(key, []).append(e)
    dupes = {k: v for k, v in by_key.items() if len(v) > 1}
    print(f"Unique names: {len(by_key)}  (duplicate-name groups: {len(dupes)})")
    for k, v in list(dupes.items())[:15]:
        print(f"  DUPE key={k!r}: {[e['citation'] for e in v]}")

    groups = {}
    for e in entries:
        groups[e["group"]] = groups.get(e["group"], 0) + 1
    print("Per-chapter entry counts:")
    for g in CHAPTERS:
        print(f"  {g}: {groups.get(g, 0)}")
    print(f"  (no group resolved): {groups.get(None, 0)}")

    with open("chapter-entries.json", "w", encoding="utf-8") as f:
        json.dump(entries, f, indent=2, ensure_ascii=False)
    print("Wrote chapter-entries.json")


if __name__ == "__main__":
    main()
