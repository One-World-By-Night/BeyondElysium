# Catalog extractor (1.3.0)

Offline, one-time tooling that produces files under `beyond-elysium/data/catalog/blocks/` from private research material in `samples/research/` (never committed, never shipped) plus the live seeded database, per `BE_PROCESS/reference/CATALOG-JSON-FORMAT.md`. Not part of the plugin, not run at runtime, not run by `bin/verify`. Kept here so the method is reproducible and reviewable, matching `tools/grimoire/`'s precedent from `v0.99.17`.

1.3.0's own C1 (multi-file GVM load), C2 (divergence survey) and C8 (include semantics / in-line dedup) are separate, earlier work and are not in this directory - they were built directly against the live GVM/seeder and belong to the nine-line consolidation pass (`1.3.0-design-workflow.md` §8), which this release's own front matter re-scoped around the 2026-09-21 JSON format ruling. This directory covers the narrower items 1.3.0's front matter names directly: the Demon catalog build, `kueijin-rites`, and the 134 ungrouped `mage-rotes`.

## Scripts

- `build_demon_evocations.py` - `samples/research/demon/owbn/lores.json` → `demon-evocations.json` (139 Evocations across 23 Lores, restructured into the `_meta`/`levels`/`elder` shape). Run with no arguments; requires `samples/research/` present at the repo root (a sibling of `code/`).
- `build_demon_rituals.py` - `samples/research/demon/owbn/rituals.json` → `demon-rituals.json` (65 Rituals, `prerequisites` parsed from each Primary/Secondary Lore's printed dot rating). Same requirement.
- `build_mage_rotes.py <live-definition.json>` - backfills `group` on the 134 previously-ungrouped `mage-rotes` items and emits the full 804-item `mage-rotes.json`. Takes a JSON export of the live block's `definition` column as its one argument (see the script's own docstring for the exact `mysql --raw` command - `--raw` matters, see below).

`kueijin-rites.json` (12 Rites) was written by hand from `samples/research/kuei-jin/rites.json`'s `laws_of_the_east` array - small enough that a script would be pure ceremony. See that file's own `provenance` block for the source citations.

## A real gotcha, if you re-run `build_mage_rotes.py`

Without `mysql`'s `--raw` flag, the client double-escapes an already-escaped internal quote in one real item name (`Ap-Sobk, \"Last Judgment of Sobk\"` - the same entry `CLAUDE.md`'s `v0.99.17` note references) and produces invalid JSON that `json.load` rejects with a misleading "Expecting ',' delimiter" error nowhere near the actual problem character.

## What these do not cover

- **Demon Visages' own Form Powers** (the MET-POWER-ACQUISITION.md estimate of "11 Visages, 142 Form Powers") - real content, confirmed directly against the source PDF (each of the 21 House Lores' own named Visage power "confers" a further list of Basic and High-Torment Form Powers, e.g. "Dagan, the Visage of Awakening" grants Aura of Vitality / Pass Without Trace / Improved Physical Capabilities / Wings / Extra Health Levels / Viscous Flesh / Extra Limbs) - but genuinely bigger than scoped (21 real Visages, not ~11) and not extracted this pass: it needs a full read of the Lore chapter's per-Visage sidebars (roughly 90 pages) plus Chapter 2's own "Apocalyptic Enhancements" (p. 27-43), which is where the acquisition/cost rule for a Form Power actually lives - not established anywhere in the research consulted this pass. See the 1.3.0 release report.
- **Demon Merits/Flaws** (`samples/research/demon/owbn/merits-flaws.json`, 13 Merits + 22 Flaws) - real, extracted, but additions to the *shared* `met-merits`/`met-flaws` blocks (hundreds of existing rows across every creature type), not a standalone file - merging them needs the same dedup-against-existing-rows care `met-mechanics.csv`'s own merge already takes (Decision 043), not attempted this pass.
- **89 of the 134 previously-ungrouped `mage-rotes`** - no captured effect text exists in the private research for these (bare page citations only); seed them without one, or read the Companion PDF directly first.
- **~90 further named Kuei-Jin Rites** from the OWBN packet's own listing (`samples/research/kuei-jin/rites.json`'s `owbn_rite_listing` array) - name and sourcebook only, no captured tier/cost.

## The 1.3.0 emitter (2026-09-22)

Everything else under `data/catalog/` is produced by one reproducible pipeline:

```bash
tools/catalog/dump_live.sh          # local be_dev -> tools/catalog/out/live (never a production host)
tools/catalog/emit_catalog.py       # -> data/catalog/{blocks,stacks,templates,presets}
php bin/validate-catalog            # the acceptance gate
```

- `catalog_io.py` - reading the dump, writing files in the format's key order, shape helpers. Decides no content.
- `rulings.py` - **every content judgement the emitter makes, with the evidence that settled it** (book, page, packet section). Review this file to review the corrections.
- `emit_catalog.py` - applies the rulings: Wraith D1 and its OWBN variant, Mummy D5/D6 (`mummy-formulae` split out), Changeling Arts/Realms/Stigmas, Werewolf and Fera Gifts as pick-only `tiered_power` with their Wyld West / Dark Ages / Beast Courts variants, Rites D4, Mummy Balance D9, Rotes and Realms D8, the stacks, templates and presets.
- `precedence.py` - C4's resolver (OWBN > MET > tabletop, newest within a tier, never across lines or eras). `--self-test` runs T-C6.

It never writes the five blocks 1.3.1 authors (`catalog_io.NOT_OURS`), never writes `mortal-numina` (built to `out/` and measured - it cannot validate as one ladder), and parks any stack or template whose blocks have no file yet in `out/pending/`. **After 1.3.1 merges, re-run the emitter**: the Vampire, Mage and Kuei-Jin stacks and their twelve templates then resolve and are written.

`samples/` is private and untracked, so a worktree has none; `catalog_io.SAMPLES` falls back to the main checkout's copy. Without it the eight Fera Gifts whose rank only the research settles stop the run - the emitter refuses to drop a Gift it cannot rank.

## 1.3.1 builders (catalog transformations)

Each reads a **snapshot** of the live seeded block (the raw `definition` column, exported with `mysql --raw` - the exact command is in `catalog_common.py`) and applies only what its `rulings/<slug>.json` says. Every ruling carries its evidence, so review is of the ruling, not the script. A builder refuses to write a family that still has a leftover ladder-rank level, so D67 cannot be emitted by accident.

In a git worktree there is no `samples/`; point `BE_SAMPLES` at the main checkout's copy.

- `build_mage_spheres.py <snapshot>` → `mage-spheres.json`.
- `build_kueijin_disciplines.py <snapshot>` → `kueijin-disciplines.json`, `owbn-kueijin_disciplines.json`, `kueijin-techniques.json`, `owbn-kueijin_techniques.json`. Reads `samples/research/kuei-jin/`.
- `build_vampire.py <snapshot> <dark-ages-menus.json>` → `vampire-disciplines.json`, `vampire-blood-magic.json`, the four edition variants (`darkages-`/`2nded-vampire_disciplines`, `..._blood-magic`) and `vampire-gargoyle-powers.json`. The second argument is Grapevine's own `GV301Source/Code/Dark Ages Menus.gvm` decoded to JSON with the plugin's `GVM_Parser`. Reads `samples/research/vampire/` through `vampire_research.py`, and prints a full accounting of every snapshot level.
- `build_vampire_rituals.py <snapshot>` → `vampire-rituals.json`.

`__pycache__/` is a by-product of running these; do not commit it.
