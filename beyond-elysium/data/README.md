# Runtime Data

Data files the plugin reads at runtime. Everything in this folder ships in the plugin.

| Path | Read by | Purpose |
| --- | --- | --- |
| `catalog/` | `BeyondElysium\Services\Catalog_Reader` | The declared catalog: one JSON file per schema block, creature stack and default sheet template, plus the approval-reason and position presets. The seeder writes the blocks, stacks and templates from these files. |
| `qkdata.gvd` | `BeyondElysium\Services\Field_Registry` | The 231 query and template field keys. A verbatim copy of Grapevine's own file. |
| `translations/pt_BR.csv` | `BeyondElysium\Services\Catalog_Translator::shipped_pt_pairs()` | Portuguese (Brazil) drafts for catalog names, one `name,translation` pair per row. The first upgrade of a new install loads them into the translations table as drafts. |

`qkdata.gvd` is a copy: do not edit it. `tests/unit/DataFilesTest.php` asserts it stays byte-identical to the original in `GV301Source/`.

The Grapevine menu set, the Rotes file and the two CSV compendiums the catalog was built from are not runtime data. They live in `tools/catalog/source/`, beside the tools that read them.
