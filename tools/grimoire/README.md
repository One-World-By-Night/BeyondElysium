# Grimoire extractor

Offline scripts that produced `tools/catalog/source/grimoire-rotes.csv` from `samples/data/Enlightened_Grimoire.pdf`, a Storytellers Vault title that is never committed and never shipped. None of them is part of the plugin, and nothing runs them: not the plugin, not `bin/verify`.

They need `pdftotext` (poppler-utils) and Python 3, nothing else.

## Pipeline

1. `pdftotext -f 9 -l 198 Enlightened_Grimoire.pdf chapters.txt` (printed pages 8-197) and `pdftotext -f 204 -l 212 Enlightened_Grimoire.pdf index.txt` (printed pages 203-211, the book's own "Index of Rotes").
2. `python3 pass_a_index.py index.txt` parses the index into `(headword, pages)` tuples: the list everything else is checked against, never a source of CSV rows itself.
3. `python3 pass_b_chapters.py chapters.txt` parses the chapter text, anchored on the sphere-requirement line's closed nine-word grammar, which is far more distinctive than a name or citation line. It writes `chapter-entries.json`: name, note (the sphere text), citation, chapter (`group`), and a page number read from the book's own footer.
4. `python3 pass_c_emit.py` keeps only the chapter entries the index also lists, since a name found only in the chapters is almost always two names run together across a column or page break, never a gap in the index. It applies the CSV's prose-leak and length guards and writes `grimoire-rotes.csv`.

## Coverage

The index lists 1,096 rotes, the chapter parse reads 753 cleanly, and 670 are in both and in the CSV. `pdftotext` keeps reading order within a column and across an ordinary page break, but not reliably across every column break on a two-column page; `Doe's Password` and `Transephemeration Ray Projector` are two of the hard cases it gets right. Reading the rest needs each word's position on the page to rebuild the columns. A rote found by only one pass is left out, never guessed.

## Re-running

The PDF and its extracted text are never committed: keep them in `samples/data/` and `tools/grimoire/out/`, both ignored by git.
