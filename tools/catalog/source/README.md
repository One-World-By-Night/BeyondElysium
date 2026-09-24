# Catalog Sources

The files the catalog tools read. None of them ships in the plugin.

| File | Read by | What it is |
| --- | --- | --- |
| `Grapevine Menus XML.gvm` | `emit_catalog.py`, `gap_audit.py` | Grapevine's menu set: 756 menu definitions. A verbatim copy of the file in `GV301Source/Code/`. |
| `Rotes.gex` | | Grapevine's Mage rotes exchange file: 201 rotes. A verbatim copy of the file in `GV301Source/Code/`. |
| `met-mechanics.csv` | `emit_catalog.py`, `gap_audit.py` | OWBN's MET mechanics compendium: 5,227 Discipline, Ritual, Archetype, Merit, Flaw, Background, Ability, Clan and Bloodline, Path and Revenant rows. |
| `grimoire-rotes.csv` | | About 670 rotes extracted from a Storytellers Vault compendium by `tools/grimoire/`. |

Do not edit the Grapevine copies. `tests/unit/DataFilesTest.php` asserts they stay byte-identical to their originals in `GV301Source/`.
