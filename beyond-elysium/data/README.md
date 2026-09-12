# Runtime Data

Data files the plugin needs **at runtime**.

`Grapevine Menus XML.gvm`, `qkdata.gvd`, and `Rotes.gex` are copied verbatim from
`GV301Source/Code/`, the pristine Grapevine source archive: reference material, ~26MB,
excluded from the deployed artifact by `.distignore`. `met-mechanics.csv` is copied verbatim
from `data-samples/MET-Mechanics - Complete Dataset.csv`, OWBN's own curated MET mechanics
compendium (also reference material, also excluded from the deployed artifact - see
`.gitignore`/`.distignore`). This directory is the small subset that has to ship, because the
seeder and the field registry read it on activation and at request time.

| File | Read by | Purpose |
| --- | --- | --- |
| `Grapevine Menus XML.gvm` | `BeyondElysium\Database\Seeder` | 756 menu definitions seeded into schema blocks |
| `qkdata.gvd` | `BeyondElysium\Services\Field_Registry` (0.3) | The 231 query/template field keys |
| `met-mechanics.csv` | `BeyondElysium\Services\MET_CSV_Parser` (0.10) | 5227 Discipline/Ritual/Archetype/Merit/Flaw/Background/Ability/Clan-Bloodline/Path/Revenant rows, layered on top of the GVM-sourced blocks it covers - see `BE_PROCESS/workflow-0.10.md` |
| `Rotes.gex` | `BeyondElysium\Database\Seeder` (via `Services\GEX_Xml_Parser`) | 201 real Mage rotes seeded into `mage-rotes` - see `BE_PROCESS/0.99.2-workflow.md`'s "`mage-rotes` ships as an empty catalog" |

**Do not edit these.** They are copies. `tests/unit/DataFilesTest.php` asserts the Grapevine
trio stays byte-identical to their `GV301Source/` originals, so drift fails the build.
