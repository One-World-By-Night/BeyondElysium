# Storyteller-Only Content

Two ways a chronicle keeps something away from players entirely: marked text inside an
ordinary field, and a whole catalog section built to be Storyteller-only from the start.

## Who can use this

Everyone runs into this without necessarily noticing - a player's own sheet, prints, and
exports simply never carry any of it. Storytellers (HST and AST) always see it in full, for
every character in their own chronicle; the chronicle's Harpy sees it inside boon terms.
Marking text this way, or flagging a whole section, is done by a Storyteller or a site
administrator on whichever screen already holds that content.

## How to get there

This isn't one screen - it's a rule that applies everywhere Storyteller-only content can
exist: a character's Biography, Notes, and NPC-only sections; an item's, location's, or
rote's description and other text; and a boon's Terms.

## The screen

**`[ST]...[/ST]` markers.** Wrap any part of an ordinary text field in `[ST]` and `[/ST]` and
everything between them is removed before it ever reaches a player - on screen, in a print,
and in an export. This works inside:

- A character's Biography and Notes.
- An item's, location's, or rote's description or other free-text properties.
- A boon's Terms.

A Storyteller sees the marked text in full, wherever they'd normally see the field at all -
the same view a player gets, just without anything stripped out. Catalog search and report
conditions all run on the same stripped text a player sees, so marked text can't be found
through them either, by anyone who isn't a Storyteller.

**Storyteller-only blocks.** A whole section of the sheet - the NPC Roleplaying Notes section
on an NPC's sheet, for example - can be flagged Storyteller only on
[Schema Blocks](schema-blocks.md). Flagging a block this way removes it completely for
anyone but a Storyteller: the section itself never appears in the sheet's own layout, and
nothing a character holds in it is ever sent to a player's browser, print, or export.

## Common tasks

### Hide part of a field from players

1. Open the field as a Storyteller - a character's Biography or Notes, or an item's,
   location's, rote's, or boon's text.
2. Wrap the part you want hidden in `[ST]` and `[/ST]`.
3. Save as normal.

### Hide a whole section from players

1. Open [Schema Blocks](schema-blocks.md) for the block.
2. Check **Storyteller only**.
3. Save.

### Check whether a section is already Storyteller-only

1. Open [Schema Blocks](schema-blocks.md).
2. Read that block's own **Storyteller only** setting.

## Things to know

- **A player's own character can carry Storyteller-only text about them, and they never see
  it** - not on their own sheet, not in their own export, not in their own print. This is by
  design: it can hold your own private note about a player's own character.
- **Marked text can't be searched or filtered around.** The Query Tool, catalog search, and
  report conditions all evaluate the same stripped text a non-Storyteller sees, so hidden text
  can neither be found by a clause nor confirm one.
- **A Storyteller-only block is flagged per chronicle.** If your chronicle's own copy of a
  block is flagged Storyteller only, that's true for your chronicle alone - another
  chronicle's copy of the same shared block keeps its own setting.
- **The marker is `[ST]` and `[/ST]` for every chronicle today** - there's no screen to change
  it.
- **An unterminated `[ST]` with no closing `[/ST]` hides everything after it to the end of the
  field** - close every marker you open.
- **This is separate from an NPC's own visibility.** An NPC's sheet never shows to a player at
  all, `[ST]` markers or not - see [Character Sheet](character-sheet.md).

## Troubleshooting

- **I typed `[ST]` text and a player can still see it.** Check both markers are present and
  spelled exactly `[ST]` and `[/ST]`, and that you're looking at a player's own view, not a
  Storyteller's.
- **A section just disappeared from a sheet.** Someone flagged its schema block Storyteller
  only - check [Schema Blocks](schema-blocks.md); a Storyteller viewing the same sheet still
  sees it.
- **Search doesn't find text I know is there.** If it's marked `[ST]`, that's expected for
  anyone who isn't a Storyteller - the search runs on the same text a player would see.

## Related

- [Character Sheet](character-sheet.md)
- [Character Editor](character-editor.md)
- [Schema Blocks](schema-blocks.md)
- [Items & Locations](world-objects.md)
- [Boon Ledger](boon-ledger.md)
- [Query Tool](query-tool.md)
- [Reports](reports.md)
- [Roles](roles.md)
- [Storyteller Guide: Creating or Flagging an NPC](../st-guide.md#creating-or-flagging-an-npc)
