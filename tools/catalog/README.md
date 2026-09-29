# Catalog tools

Offline scripts that produced the catalog files under `beyond-elysium/data/catalog/` from the catalog Grapevine's menus seeded, the files in `source/`, and private research in `samples/research/` (never committed, never shipped). None of them is part of the plugin, and nothing runs them: not the plugin, not `bin/verify`.

The catalog files are the source now. Edit them directly and check them with `php bin/validate-catalog`. The emitter and the builders read a dump of the catalog as Grapevine's menus seeded it, which a current install no longer produces. They are the record of how each file was made, with the evidence for every correction in `rulings.py` and `rulings/`.

## The emitter

```bash
tools/catalog/dump_live.sh      # the local catalog -> tools/catalog/out/live (never a production host)
tools/catalog/emit_catalog.py   # -> beyond-elysium/data/catalog/{blocks,stacks,templates,presets}
php bin/validate-catalog
```

| Script | What it does |
| --- | --- |
| `dump_live.sh` | Dumps the local seeded catalog (system schema blocks, creature stacks, system templates) to one JSON file per record. |
| `emit_catalog.py` | Writes the catalog files from the dump and the rulings in `rulings.py`. A block with a builder of its own (`catalog_io.NOT_OURS`) is left to that builder. |
| `rulings.py` | Every content judgement the emitter makes, each with the book, page or packet section that settled it. |
| `catalog_io.py` | Reads the dump and writes files in the format's key order. Decides no content. |
| `precedence.py` | Chooses between sources: an OWBN packet over MET over tabletop, the newest within a tier, and never across lines or eras. `precedence.py --self-test` checks it. |

`samples/` is private and untracked, so a git worktree has none; `catalog_io.SAMPLES` falls back to the main checkout's copy. Without it the emitter stops at the eight Fera Gifts whose rank only the research settles, rather than drop a Gift it cannot rank.

## The builders

Each builder reads a snapshot of the seeded block (its raw `definition` column, exported with `mysql --raw`; the command is in `catalog_common.py`) and applies only what its `rulings/<slug>.json` says, and every ruling carries its evidence. A builder refuses to write a family that still has a level beyond its ladder. In a git worktree, point `BE_SAMPLES` at the main checkout's `samples/`.

| Script | Writes | Reads |
| --- | --- | --- |
| `build_mage_spheres.py <snapshot-dir>` | `mage-spheres.json` | The snapshot |
| `build_kueijin_disciplines.py <snapshot-dir>` | `kueijin-disciplines.json`, `owbn-kueijin_disciplines.json`, `kueijin-techniques.json`, `owbn-kueijin_techniques.json` | The snapshot and `samples/research/kuei-jin/` |
| `build_vampire.py <snapshot-dir> <dark-ages-menus.json>` | `vampire-disciplines.json`, `vampire-blood-magic.json`, their `darkages-` and `2nded-` variants, and `vampire-gargoyle-powers.json` | The snapshot, `samples/research/vampire/` through `vampire_research.py`, and Grapevine's `Dark Ages Menus.gvm` decoded to JSON by the plugin's `GVM_Parser` |
| `build_vampire_rituals.py <snapshot-dir>` | `vampire-rituals.json` | The snapshot |
| `build_mage_rotes.py <definition.json>` | `mage-rotes.json`, with `group` filled on 45 of the 134 rotes that had none | The `mage-rotes` row's `definition`, exported as the script's docstring shows |
| `build_demon_evocations.py` | `demon-evocations.json` | `samples/research/demon/owbn/lores.json` |
| `build_demon_rituals.py` | `demon-rituals.json` | `samples/research/demon/owbn/rituals.json` |

`kueijin-rites.json` has no builder: its twelve Rites are hand-entered from `samples/research/kuei-jin/rites.json`, and its `provenance` block cites the books.

Without `--raw`, the MySQL client double-escapes an already-escaped quote in one rote name (`Ap-Sobk, \"Last Judgment of Sobk\"`), and `json.load` fails with an "Expecting ',' delimiter" error nowhere near the quote.

## Measurements

| Script | What it does |
| --- | --- |
| `gap_audit.py` | For every Grapevine menu, counts the items that appear nowhere in the catalog, by exact name and by normalised name. Read-only. |
| `measure_abilities.py` | Counts how many held custom Abilities the re-key rules resolve against the `{stack}-abilities` blocks. Read-only, local `be_dev` only. |
