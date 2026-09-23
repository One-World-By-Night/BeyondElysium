# Grimoire extractor

Offline, one-time tooling that produced `beyond-elysium/data/grimoire-rotes.csv` from `samples/data/Enlightened_Grimoire.pdf` (a Storytellers Vault title, never committed, never shipped - see `BE_PROCESS/design/mage-rotes-grimoire-design.md` §8.1). Not part of the plugin, not run at runtime, not run by `bin/verify`. Kept here so the method is reproducible and reviewable, not because it runs again on its own.

Requires `pdftotext` (poppler-utils) and Python 3, nothing else.

## Pipeline

1. `pdftotext -f 9 -l 198 Enlightened_Grimoire.pdf chapters.txt` (printed 8-197) and `pdftotext -f 204 -l 212 Enlightened_Grimoire.pdf index.txt` (printed 203-211, the book's own "Index of Rotes").
2. `python3 pass_a_index.py index.txt` - parses the index into `(headword, pages)` tuples. This is the I1 oracle everything else is checked against, not itself a source of CSV rows.
3. `python3 pass_b_chapters.py chapters.txt` - parses the chapter body, anchored on the sphere-requirement line's closed nine-word grammar (far more mechanically distinctive than a name or citation line). Emits `chapter-entries.json`: name, note (sphere text), citation, chapter (`group`), and a page number derived from the book's own footer digits.
4. `python3 pass_c_emit.py` - keeps only chapter entries the index oracle also lists (I1: a chapter-only name is, empirically, almost always two names glued together by an unresolved column/page-break interleave, never a genuine index gap), applies the shipped CSV's own prose-leak and length guards, and writes `grimoire-rotes.csv`.

## What this run found

1096 index entries, 753 chapter entries parsed cleanly, 670 confirmed by both passes and shipped. The ~40% the index lists but chapter-parsing never resolved is the real column-and-page-break interleaving §3.4 of the design doc named as the hard case, confirmed rather than hand-waved: `pdftotext` preserves reading order within a column and across an ordinary page break (confirmed directly against two of the design doc's own named hard cases, `Doe's Password` and `Transephemeration Ray Projector`), but not reliably across every column break inside a two-column page. Extending coverage past 670 needs geometric (x, y) column reconstruction - the design doc's own Effort **L** path - and is left for a future pass rather than guessed at here (Decision 043's protected-base rule: a real tie or a real gap is left unmerged, never guessed).

## Re-running

The PDF and its raw extracted text never get committed - keep them in `samples/data/` (gitignored) and `tools/grimoire/out/` (also gitignored) if you re-run this by hand.
