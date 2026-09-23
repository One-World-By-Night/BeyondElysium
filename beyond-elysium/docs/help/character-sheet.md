# Character Sheet

The read-only view of a character: identity, traits, powers, and pools laid out the way your
chronicle has arranged them, with printing, history, and Storyteller tools around it.

## Who can use this

Any member of the chronicle can open their own character's sheet. Storytellers (HST and AST)
can open any character's sheet in the chronicle. A character that isn't linked to your
account yet doesn't appear for you at all, even if you know it exists - ask a Storyteller to
link it to you. An NPC's sheet never appears for a player.

## How to get there

My Chronicle → Characters tab, then click a character's name. If a character is already
selected, the Sheet tab goes straight to it.

## The screen

### Actions

One list above the sheet holds everything you can do with it. Pick an action, click **Go**,
and what it opens appears right below the list, in place of anything already open - **Close**
puts it away. The `?` beside **Go** opens this help. On a phone, the list and what it opens
sit at the top of the page, above the sheet itself.

The list only offers what you can use:

- **Print / Export** - opens the print panel: five checkboxes, **Background**, **Notes**,
  **XP History**, **Full power names**, and **Show XP costs**, then **Open PDF**, which
  opens a PDF of this sheet in a new tab. It's signed with the site's certificate when one is
  set up; otherwise the panel says prints are marked UNSIGNED.
- **Print My Items** - opens a PDF of just the items connected to this character, straight
  away. Every viewer has it, not only a Storyteller.
- **Edit this character** - opens the Edit tab for this character. Only if you can edit it.
- **Customize appearance** - font, colors, background, and per-section graphics. Only if
  you've been granted sheet customization for a character you can already edit. See
  [Customize Appearance](sheet-customize.md).
- **View history** - every change ever submitted for this character, approved and rejected
  alike, each with its status, a plain description, its XP cost, and any Storyteller note. See
  [Change History](sheet-history.md).
- **Background uses** - a game-date picker, then the ledger of what each budgeted background
  was spent on that date. See [Background Uses](background-uses.md).
- **Send Sheet** - Storyteller-only. Sends this character to another chronicle, or shows the
  controls for one currently visiting. See [Send Sheet](transfer.md).
- **Point audit** - Storyteller-only. Every held trait, power, and pool priced line by line.
  See [Point Audit](point-audit.md).
- **Export to Grapevine (.gex)** - an **Include verification code** checkbox, then **Download
  .gex file**, which downloads an exchange file for this character.

A travelling or visiting notice appears above the header whenever this character is part of a
transfer, whether or not **Send Sheet** is open.

### Header

Portrait, then **Name**, **Type**, **Status**, **Player**, **XP Earned**, and **XP Unspent**.

### Connections

Storyteller-only. Links this character to plots or to other characters. See
[Connections](connections.md).

### The sheet body

Every section from the chronicle's own template, in its own box, read-only. Physical, Social,
and Mental Traits render side by side as three columns; everything else flows in the
template's own column order.

### Background and Notes

Your Background and Notes prose, at the bottom of the sheet whenever there is any. Their
checkboxes in the **Print / Export** panel only decide whether they go into the PDF.

### XP History (when ticked for printing)

The same change-history list **View history** shows appears inline here once its checkbox in the
**Print / Export** panel is on - whether or not **View history** is also open.

## Common tasks

### Open a character's sheet

1. Go to My Chronicle → Characters.
2. Click the character's name.

### Print or export a signed PDF

1. Open the character's sheet.
2. Pick **Print / Export** and click **Go**.
3. Check whichever of **Background**, **Notes**, **XP History**, or **Full power names** you
   want included. **Show XP costs** starts ticked - untick it to leave Combo Discipline prices
   off the sheet entirely.
4. Click **Open PDF**. The PDF opens in a new tab.

### Print an item card

1. Open the character's sheet.
2. Pick **Print My Items** and click **Go**.

### Read your XP History without printing

1. Open the character's sheet, pick **Print / Export**, and click **Go**.
2. Check **XP History**. The history appears on the page - nothing is sent to a printer.

### Check the change history

1. Open the character's sheet.
2. Pick **View history** and click **Go**.

### Look up background uses for a date

1. Open the character's sheet, pick **Background uses**, and click **Go**.
2. Pick the **Game date** at the top of the panel.

## Things to know

- Storyteller-only text and NPC-only sections never reach a player's copy of this sheet - on
  screen or in any export or print - even on their own character.
- A Quick NPC (see [Character Editor](character-editor.md)) shows its shorter Quick Stats
  layout here instead of the full sheet, until a Storyteller upgrades it with **Make Full
  NPC**. This is separate from the [Who's Who](whos-who.md) profile a player might see about
  the same NPC.
- Every dot - a trait's rating or a resource pool's point - is drawn the same size everywhere:
  this sheet, the editor, and a signed PDF. The number always follows the dots too (a resource
  pool shows "current/permanent" when they differ), and a section whose held items all carry a
  plain count - most trait lists, never a note-only one like Merits or Rituals - shows its own
  total after its title.
- **XP History**, **Full power names**, and **Show XP costs** do double duty: ticking any of
  them also changes this page, not only the file you print. **Background** and **Notes** only
  decide what goes into the PDF - both always show at the bottom of this page. **Show XP costs**
  only affects Combo Disciplines, where the number beside a combo is its flat XP price rather
  than a rating, so it always reads "Draw Fire (12 XP)". It starts ticked; unticking it drops
  the price rather than turning it back into dots. While editing, a separate toggle right above
  your held combos does the same thing and remembers your choice next time.
- Without a signing certificate configured for this site, **Print / Export** and **Print My
  Items** still work, but every page comes back stamped UNSIGNED and the file name ends
  "-unsigned.pdf". Separately, a PDF reader saying "signature valid, signer not trusted"
  instead of a plain checkmark is normal even with a certificate configured - it means the
  file genuinely hasn't been altered, checked against a certificate your reader just hasn't
  been told to trust.
- On a narrow screen nothing on this page scrolls sideways - a wide table (background uses,
  change history) stacks into cards instead.
- Exporting to Grapevine carries your Storyteller-only text only if you can manage the
  character - a player's own export is stripped exactly like their own view of the sheet.

## Troubleshooting

- **The list doesn't offer Edit, Send Sheet, Point audit, or Customize appearance.** Edit
  needs ownership or a Storyteller role. Send Sheet and Point audit are Storyteller-only.
  Customize appearance needs a grant from a Storyteller, even on your own character.
- **Go does nothing.** Pick an action first - **Go** stays greyed out until you do.
- **Print My Items has nothing on it.** No items are connected to this character yet - ask a
  Storyteller to connect one.
- **My PDF says UNSIGNED.** This site has no signing certificate configured yet - that's a
  hosting setup step, not a problem with the character.
- **I ticked XP History and it appeared on the page.** That's expected - XP History, Full power
  names, and Show XP costs all change this page too, not only the printed file.
- **I don't see my Background or Notes.** They only show when they have text - add some from the
  Edit tab.
- **I can't find a character I know is in this chronicle.** If it isn't yours and you're not a
  Storyteller, it won't appear at all - and an NPC never appears for a player.

## Related

- [Character Editor](character-editor.md)
- [Print / Export](sheet-print-export.md)
- [Change History](sheet-history.md)
- [Background Uses](background-uses.md)
- [Point Audit](point-audit.md)
- [Customize Appearance](sheet-customize.md)
- [Send Sheet](transfer.md)
- [Verify Character](verify.md)
- [Signed Sheets](signed-sheets.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Who's Who](whos-who.md)
- [Player Guide](../player-guide.md#8-printing-your-sheet)
- [Storyteller Guide](../st-guide.md#11-signed-character-sheets)
