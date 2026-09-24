#!/usr/bin/env python3
"""
Pass C - cross-check against the index oracle, apply the shipped CSV's own
mechanical prose guards up front, and emit tools/catalog/source/grimoire-rotes.csv
candidate rows.

Never emits a description column. `source` always ends with the "Enlightened
Grimoire p. N" element.
"""
import csv
import json
import re

SENTENCE_LEAK_RE = re.compile(r"[a-z]\.\s+[A-Z]")  # ". " mid-cell - a real sentence boundary, not a citation/note fragment
NOTE_MAX = 200
SOURCE_MAX = 300


def normalize(headword):
    h = headword.lower()
    h = re.sub(r"[’']", "'", h)
    h = re.sub(r"[^a-z0-9'\-]+", " ", h)
    return re.sub(r"\s+", " ", h).strip()


def main():
    index_tuples = json.load(open("index-tuples.json", encoding="utf-8"))
    chapter_entries = json.load(open("chapter-entries.json", encoding="utf-8"))

    index_keys = {e["key"] for e in index_tuples}
    chapter_keys = {e["key"] for e in chapter_entries}

    matched = index_keys & chapter_keys
    index_only = index_keys - chapter_keys
    chapter_only = chapter_keys - index_keys

    print(f"Index oracle entries:   {len(index_tuples)}")
    print(f"Chapter-parsed entries: {len(chapter_entries)}")
    print(f"I1 coverage: {len(matched)} matched, {len(index_only)} index-only (missed), {len(chapter_only)} chapter-only (not in index)")

    rows = []
    guard_failures = []
    unconfirmed = 0
    for e in chapter_entries:
        if e["key"] not in index_keys:
            # The book's own index is the invariant: a chapter entry the index
            # never lists is not shipped; only what both passes agree on is
            # emitted.
            unconfirmed += 1
            continue
        name = e["name"]
        note = e["note"]
        page = e["page"]
        citation = e["citation"]
        group = e["group"] or ""
        subgroup = ""
        source = f"{citation}; Enlightened Grimoire p. {page}" if page else citation

        if len(note) > NOTE_MAX:
            guard_failures.append((name, f"note {len(note)} chars > {NOTE_MAX}"))
            continue
        if len(source) > SOURCE_MAX:
            guard_failures.append((name, f"source {len(source)} chars > {SOURCE_MAX}"))
            continue
        if SENTENCE_LEAK_RE.search(note) or SENTENCE_LEAK_RE.search(source):
            guard_failures.append((name, "sentence-leak pattern in note/source"))
            continue

        rows.append([name, note, source, group, subgroup])

    print(f"Excluded, not confirmed by the index (I1): {unconfirmed}")
    print(f"Guard failures (excluded from CSV): {len(guard_failures)}")
    for name, reason in guard_failures[:20]:
        print(f"  EXCLUDED: {name!r} - {reason}")

    rows.sort(key=lambda r: normalize(r[0]))

    out_path = "grimoire-rotes.csv"
    with open(out_path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["name", "note", "source", "group", "subgroup"])
        w.writerows(rows)

    print(f"Wrote {out_path}: {len(rows)} rows")

    with open("index-only-missed.json", "w", encoding="utf-8") as f:
        missed = [e for e in index_tuples if e["key"] in index_only]
        json.dump(missed, f, indent=2, ensure_ascii=False)
    print(f"Wrote index-only-missed.json ({len(index_only)} entries the index has that extraction never resolved)")


if __name__ == "__main__":
    main()
