# Catalog Term Translation

Manages every catalog term's translation - trait names, power names, identity-field labels
and options - for as many languages as a chronicle needs. Separate from the plugin's own
UI-chrome translation (buttons, labels, messages), which installs through an ordinary
WordPress language pack: a catalog term like "Fortitude" never appears as a literal string in
any source file, only as a database row, so a `.po` file can never carry it.

## Who can use this

Anyone holding `be_manage_translations` - its own capability, independent of
`be_manage_schemas`, so a native-speaking volunteer can be trusted to translate without also
being trusted to edit the catalog's own mechanics. Not chronicle-scoped: one install, one set
of languages, shared by every chronicle on it.

## How to get there

- wp-admin sidebar → Beyond Elysium → System Config → Translations tab.

## The screen

- **Language** - a dropdown of locales that already have translations, plus every locale
  WordPress itself has installed. **+ Add a language** starts a new one by typing its locale
  code (e.g. `es_ES`) - no WordPress language pack install is required, since this is catalog
  data, not UI chrome.
- **Rescan catalog** - re-walks every schema block, system and every chronicle's own fork, and
  refreshes the string index against whatever the real, current catalog actually contains.
- A progress bar and a count of terms by status (draft / needs review / approved / conflict)
  for the picked language.
- **Catalog**, **Status** (including **Untranslated only**), and **Search** filter the table
  below.
- **Export CSV** / **Import CSV** - the offline round trip, below.
- The table: a checkbox, the **English term**, **Appears in** (which catalog blocks use it),
  the translated text (an editable field - type, click away, or press Tab to jump straight to
  the next untranslated row), and **Status** (its own dropdown).
- **Mark selected approved** applies that status to every checked row at once.

## Common tasks

### Fix one term

1. **Search** for it.
2. Edit the text in its own row.
3. Click away, or Tab to the next row - it saves on its own.

### Translate a whole catalog offline

1. Set **Catalog** to the block you're translating and **Status** to **Untranslated only**.
2. Click **Export CSV**.
3. Fill in the `translation` column in a spreadsheet.
4. Click **Import CSV**, pick the file.
5. Review the dry-run summary (added / updated / unchanged / unmatched / conflicts) and its
   sample rows.
6. Click **Commit import**.

### Start a new language

1. Click **+ Add a language**, type its locale code.
2. Work through it the same way - offline CSV for the bulk, inline edit for the rest.

## Things to know

- **The table is the real data, not the CSV.** Exporting and re-importing a file is a
  convenience for a bulk pass; nothing about it is required, and the file is never the source
  of truth the way `data/met-mechanics.csv` used to be.
- **A conflict during import means the file disagrees with itself** - two rows for the same
  term with two different translations - not that the file disagrees with what's already
  saved, which is an ordinary update and never flagged as a conflict.
- **An untranslated term falls back to English everywhere** - a printed sheet, a signed PDF,
  never a blank.
- **Rescanning never loses a translation.** A term temporarily missing from the catalog (a
  chronicle narrowed its enabled creature types, say) just stops updating its own "last seen"
  timestamp; the translation is still there the moment the term reappears.

## Troubleshooting

- **I don't see this tab.** You need `be_manage_translations` - ask a WordPress administrator
  to grant it.
- **My import reported "unmatched" rows.** Those rows' `source_text` doesn't match any real
  catalog term - check spelling, or click **Rescan catalog** first if you just added new
  content to the catalog.
- **A term I fixed still shows the old text somewhere.** Confirm you edited the right
  language - the fix only applies to the language you had picked when you made it.

## Related

- [Admin Guide: Catalog Term Translation](../admin-guide.md#catalog-term-translation)
- [Storyteller Guide: Fixing a Catalog Term You Spot in Play](../st-guide.md#20-fixing-a-catalog-term-you-spot-in-play)
- [Schema Blocks](schema-blocks.md)
