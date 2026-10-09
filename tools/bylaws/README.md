# Bylaw attachment review

How a Character Bylaw rule gets tied to a real catalog entry, so the engine can show the restriction automatically when someone buys that trait.

## The files

- `extract.php` - re-pulls every rule from council.owbn.net into `extracted-rules.json`.
- `export-csv.php` - writes `extracted-rules.json` + `attachments.json` into a spreadsheet, one row per rule.
- `import-csv.php` - reads the edited spreadsheet back into `attachments.json`.
- `build.php` - combines `extracted-rules.json` + `attachments.json` into the shipped `beyond-elysium/data/bylaws/owbn-character-bylaws.json`.
- `attachments-review.csv` - the working spreadsheet. Committed, so it carries real history.
- `match.php`, `catalog-families.php` - exact-name matching and catalog lookups the other scripts share.

## The workflow

```
php tools/bylaws/export-csv.php tools/bylaws/extracted-rules.json tools/bylaws/attachments.json tools/bylaws/attachments-review.csv
```

Open `attachments-review.csv` in a spreadsheet. Each row is one rule, or one rule's existing attachment if it already has one. Columns:

| Column | What it is |
| --- | --- |
| `clause_id`, `path`, `subject`, `pc`, `npc`, `coordinators`, `modified` | Read-only context from the real bylaw clause. |
| `suggested_family`, `suggested_name` | Read-only. An exact name match against the catalog, nothing more - a hint, never a decision. |
| `family`, `name` | The decision. The catalog family (a block slug with its creature prefix dropped, e.g. `merits`, `disciplines`) and the entry's own name inside it. Leave both blank for "no attachment." |
| `count_from`, `count_to` | Narrows a trait_list or resource_pool attachment to a count range. Leave blank on either side for an unbounded end. A rule naming several specific counts (not a single range) gets one row per count, same value in both columns. |
| `levels` | Narrows a tiered_power attachment to specific numbered rungs, semicolon-separated (`1;2`). |
| `elder_plus` | `yes` narrows a tiered_power attachment to an Elder-and-above pick instead of a numbered rung. |
| `reason` | When there's no attachment, one word: `concept` (restricts a character concept the catalog can't express as one entry - a conjunction, an identity-field value, a faction), `no_entry` (names something that plainly isn't seeded anywhere), `section` (a pure category/label node), `packet` (depends on a genre coordinator's own packet), `territory` (a chronicle/geography matter). |
| `note` | Why. A citation or a one-line explanation either way - attached or not. |

A single rule can need more than one row (several real attachments, or a range exploded into one row per count). Add rows with the same `clause_id` for that; `import-csv.php` doesn't care about row order.

When the sheet is ready:

```
php tools/bylaws/import-csv.php tools/bylaws/attachments-review.csv tools/bylaws/extracted-rules.json tools/bylaws/attachments.json
```

That's a dry run - it reports `added`/`removed`/`unchanged`/`invalid` with a sample of each and writes nothing. A row naming a `family`/`name` the catalog doesn't have shows up under `invalid` with the row number and why. Once it's clean:

```
php tools/bylaws/import-csv.php tools/bylaws/attachments-review.csv tools/bylaws/extracted-rules.json tools/bylaws/attachments.json --apply
php tools/bylaws/build.php tools/bylaws/extracted-rules.json tools/bylaws/attachments.json
```

The first writes the real `attachments.json`; the second rebuilds the shipped file from it.

## What's in the sheet right now

Every rule in the current corpus has a row. About a third of the rules have a researched `family`/`name`/`reason`/`note` already filled in - a first pass of suggested judgments, not a reviewed or shipped decision. Nothing here is final until it's been read and corrected by hand, row by row.

`attachments.json` holds only the 22 exact-name attachments, which is what the shipped file carries. The sheet's other suggested attachments reach `attachments.json`, and so the shipped file, only through `import-csv.php --apply` once the sheet has been reviewed.
