#!/usr/bin/env python3
"""
Pass A - the index oracle.

Parses the Enlightened Grimoire's own "Index of Rotes" (printed 203-211,
extracted here as index.txt) into (headword, [pages]) tuples. Pass B's
chapter-entry parse is checked against this output.

Never guesses a page number. An entry whose trailing number can't be found
is emitted with pages=[] and flagged.
"""
import re
import sys
import json

NOISE_EXACT = {"Index of Rotes", "Enlightened Grimoire"}
BARE_NUMBER = re.compile(r"^\d+$")
# Trailing page number(s): preceded by dot-leaders and/or plain whitespace,
# e.g. "Foo ............... 60", "Foo 124", "Foo ... 120, 131"
TRAILING_PAGES = re.compile(r"^(.*?)[\.\s]{1,}(\d+(?:\s*,\s*\d+)*)\s*$")


def load_lines(path):
    with open(path, encoding="utf-8") as f:
        raw = [line.rstrip("\n") for line in f]
    out = []
    for line in raw:
        stripped = line.strip()
        if stripped in NOISE_EXACT:
            continue
        if BARE_NUMBER.match(stripped):
            continue
        out.append(stripped)
    return out


def parse(lines):
    entries = []
    buf = []
    orphans = []

    def flush_orphan():
        if buf:
            orphans.append(" ".join(buf).strip())
        buf.clear()

    for line in lines:
        if line == "":
            continue
        m = TRAILING_PAGES.match(line)
        if m:
            head_part, pages_part = m.group(1).strip(), m.group(2)
            pages = [int(p.strip()) for p in pages_part.split(",")]
            buf.append(head_part) if head_part else None
            headword = " ".join(buf).strip()
            buf.clear()
            if headword:
                entries.append({"headword": headword, "pages": pages})
            else:
                # a trailing-number-only line with nothing accumulated
                orphans.append(f"<orphan pages only: {pages}>")
        else:
            buf.append(line)

    flush_orphan()
    return entries, orphans


def normalize(headword):
    """Matching key: normalized, not exact.

    Hyphens are preserved (not collapsed to space or stripped): "Burn Out" and
    "Burn-Out" are two distinct rotes in this book.
    """
    h = headword.lower()
    h = re.sub(r"[’']", "'", h)
    h = re.sub(r"[^a-z0-9'\-]+", " ", h)
    return re.sub(r"\s+", " ", h).strip()


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else "index.txt"
    lines = load_lines(path)
    entries, orphans = parse(lines)

    seen = {}
    dupes = []
    for e in entries:
        key = normalize(e["headword"])
        e["key"] = key
        if key in seen:
            dupes.append((seen[key]["headword"], e["headword"], seen[key]["pages"], e["pages"]))
        else:
            seen[key] = e

    print(f"Parsed entries: {len(entries)}")
    print(f"Unique keys:    {len(seen)}")
    print(f"Orphans (no page number resolved): {len(orphans)}")
    for o in orphans:
        print(f"  ORPHAN: {o!r}")
    print(f"Duplicate keys (same normalized headword, possibly cross-referenced pages): {len(dupes)}")
    for a, b, pa, pb in dupes[:30]:
        print(f"  DUPE: {a!r}{pa} vs {b!r}{pb}")

    with open("index-tuples.json", "w", encoding="utf-8") as f:
        json.dump(entries, f, indent=2, ensure_ascii=False)
    print("Wrote index-tuples.json")


if __name__ == "__main__":
    main()
