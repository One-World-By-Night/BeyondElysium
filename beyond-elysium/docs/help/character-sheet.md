# Character Sheet

The read-only view of a character: identity, traits, powers, and pools laid out the way your chronicle has arranged them, with printing, history, and Storyteller tools around it.

## Who can use this

Any member of the chronicle can open their own character's sheet. Storytellers (HST and AST) can open any character's sheet in the chronicle. A character that isn't linked to your account yet doesn't appear for you at all, even if you know it exists - ask a Storyteller to link it to you. An NPC's sheet never appears for a player.

## How to get there

My Chronicle → Characters tab, then click a character's name, or open the **Character** tab if one is already chosen. The Character section has a row of views - **Play**, **Sheet**, **Story**, **History**, and **Edit** - and this page is the Sheet view. Play, Story, and Edit are offered only to the character's own player and to Storytellers.

## The screen

### Actions

A row of buttons above the sheet holds everything you can do with it. Click one and what it opens appears right below the row, in place of anything already open - click it again, or **Close**, to put it away. The `?` at the end of the row opens this help. On a phone the row wraps and sits at the top of the page, above the sheet itself.

The row only offers what you can use:

- **Print / Export** - opens the print panel: five checkboxes, **Background**, **Notes**, **XP History**, **Full power names**, and **Show XP costs**, then **Open PDF**, which opens a PDF of this sheet in a new tab. It's signed with the site's certificate when one is set up; otherwise the panel says prints are marked UNSIGNED.
- **Print My Items** - opens a PDF of just the items connected to this character, straight away. Every viewer has it, not only a Storyteller.
- **Customize appearance** - font, colors, background, and per-section graphics. Only if you've been granted sheet customization for a character you can already edit. See [Customize Appearance](sheet-customize.md).
- **Background uses** - a game-date picker, then the ledger of what each budgeted background was spent on that date. See [Background Uses](background-uses.md).
- **Send Sheet** - Storyteller-only. Sends this character to another chronicle, or shows the controls for one currently visiting. See [Send Sheet](transfer.md).
- **Point audit** - Storyteller-only. Every held trait, power, and pool priced line by line. See [Point Audit](point-audit.md).
- **Export to Grapevine (.gex)** - an **Include verification code** checkbox, then **Download .gex file**, which downloads an exchange file for this character.

A travelling or visiting notice appears above the header whenever this character is part of a transfer, whether or not **Send Sheet** is open - naming every other chronicle it's also active at, or where it's visiting from, and whether that visit is kept current, when it last delivered, or that it's gone quiet ("can't reach it"). While a visit here is kept current and agreed on both sides, editing or submitting a change for this character is refused - "Kept current from Kony. Make changes there." - until the visit ends; a Storyteller's own change still goes through, but is forwarded to home rather than applied here. See [Send Sheet](transfer.md).

### Header

Portrait, then **Name**, **Type**, **Status**, **Player**, **XP Earned**, and **XP Unspent**.

### Tapping a trait

Tap any trait or power on the sheet and a panel opens, from the bottom of the screen on a phone and from the right on a computer. It shows what the next dot or level would cost and whether a Storyteller has to approve it, the chronicle's own description and source for it, what it needs, and this character's history with it. **See all in History** and **Change it in Edit** are in it. Nothing is bought in the panel: a raise is made in Edit. **Escape**, **Close**, or a tap outside puts it away.

### The Play view

The Play view is for the table. It shows:

- **Pools** - every pool the character holds, with **Spend** and **Regain** for its temporary points. Where the creature type's books spend a pool permanently (Willpower, Gnosis, Glamour, and a few others), **Spend permanent** asks first and lowers the rating by one.
- **Health** - boxes drawn from the character's own health levels, filled with aggravated, then lethal, then bashing damage, and the level the character stands at. **Take** and **Heal** move one level of a type.
- **Tonight** - what the character lost or expended tonight. It clears when the Storyteller closes the night.
- **Find a trait** - a search of the character's own traits and powers; each result opens the trait panel.

Each of these takes effect at once and is logged, with no Storyteller review. The one exception is a permanent spend on a chronicle that approves changes that lower a rating (Chronicle Setup's approval on removal): it waits in the Approval Queue, and the pool reads "1 permanent spend waiting" until it is decided. A permanent spend never costs XP and never refunds any; raising the rating again is a purchase in Edit.
### Connections

Storyteller-only. Links this character to plots or to other characters. See [Connections](connections.md).

### The sheet body

Every section from the chronicle's own template that has something in it, in its own box, read-only. A section with nothing held - a Mortal's Disciplines or Gifts, an empty Health, a trait list with no entries - is left out, the same as on the printed PDF; it still appears in the editor, where you can add the first entry. Physical, Social, and Mental Traits render side by side as three columns; everything else flows in the template's own column order.

### Background and Notes

Your Background and Notes prose, at the bottom of the sheet whenever there is any. Their checkboxes in the **Print / Export** panel only decide whether they go into the PDF.

### XP History (when ticked for printing)

The same change-history list the **History** view shows appears inline here once its checkbox in the **Print / Export** panel is on.

## Common tasks

### Open a character's sheet

1. Go to My Chronicle → Characters.
2. Click the character's name.

### Print or export a signed PDF

1. Open the character's sheet.
2. Click **Print / Export**.
3. Check whichever of **Background**, **Notes**, **XP History**, or **Full power names** you want included. **Show XP costs** starts ticked - untick it to leave Combo Discipline prices off the sheet entirely.
4. Click **Open PDF**. The PDF opens in a new tab.

### Print an item card

1. Open the character's sheet.
2. Click **Print My Items**.

### Read your XP History without printing

1. Open the character's sheet and click **Print / Export**.
2. Check **XP History**. The history appears on the page - nothing is sent to a printer.

### Check the change history

1. Open the character's sheet.
2. Click **History** in the row of views.

### Look up background uses for a date

1. Open the character's sheet and click **Background uses**.
2. Pick the **Game date** at the top of the panel.

## Things to know

- Storyteller-only text and NPC-only sections never reach a player's copy of this sheet - on screen or in any export or print - even on their own character.
- A section the chronicle has set to let players choose their own order (see [Schema Blocks](schema-blocks.md)) shows flat, in the player's own order, here and in the printed PDF alike - never grouped the way the same kind of section normally is. See [Character Editor: Reordering a List](character-editor.md#reordering-a-list) for how a player sets that order.
- A Quick NPC (see [Character Editor](character-editor.md)) shows its shorter Quick Stats layout here instead of the full sheet, until a Storyteller upgrades it with **Make Full NPC**. This is separate from the [Who's Who](whos-who.md) profile a player might see about the same NPC.
- Every dot - a trait's rating or a resource pool's point - is drawn the same size everywhere: this sheet, the editor, and a signed PDF. The number always follows the dots too (a resource pool shows "current/permanent" when they differ), and a section whose held items all carry a plain count - most trait lists, never one like Merits or Rituals where each entry stands alone - shows its own total after its title. Combo Disciplines show how many combos you hold there, not the sum of their prices.
- **Merits and Flaws** show their points, "Ambidextrous 1", "Acute Sense (Hearing) 2".
- **Virtues** carry the names your Path uses, such as Conviction and Instinct on the Path of Caine, from the Paths of Enlightenment books and the Dark Ages Roads, each Road's own paths included: the Road of Sin uses Conviction and Instinct, its Path of the Devil Conviction and Self-Control. When the sheet doesn't know your Path's virtues, both names show, "Conscience/Conviction". A choice made on the sheet always wins.
- **Bonds** (a Vampire's Vinculums and blood bonds) have their own section. Each is a name and a rating shown as a number, "Gabriel Deveraux x7", never as circles. The name can be anyone, including a character from another chronicle, and the rating can go as high as you need. A bond costs no XP, and adding one still waits for a Storyteller to approve it.
- **Combos** always sit under Combo Disciplines with their XP price, never under Disciplines.
- A power picked above the ladder shows its rank ("elder", "master") only when the catalog knows it.
- **XP History**, **Full power names**, and **Show XP costs** do double duty: ticking any of them also changes this page, not only the file you print. **Background** and **Notes** only decide what goes into the PDF - both always show at the bottom of this page. **Show XP costs** only affects Combo Disciplines, where the number beside a combo is its flat XP price rather than a rating, so it always reads "Draw Fire (12 XP)". It starts ticked; unticking it drops the price rather than turning it back into dots. While editing, a separate toggle right above your held combos does the same thing and remembers your choice next time.
- Without a signing certificate configured for this site, **Print / Export** and **Print My Items** still work, but every page comes back stamped UNSIGNED and the file name ends "-unsigned.pdf". Separately, a PDF reader saying "signature valid, signer not trusted" instead of a plain checkmark is normal even with a certificate configured - it means the file genuinely hasn't been altered, checked against a certificate your reader just hasn't been told to trust.
- On a narrow screen nothing on this page scrolls sideways - a wide table (background uses, change history) stacks into cards instead.
- Exporting to Grapevine carries your Storyteller-only text only if you can manage the character - a player's own export is stripped exactly like their own view of the sheet.

## Troubleshooting

- **The row doesn't offer Send Sheet, Point audit, or Customize appearance.** Send Sheet and Point audit are Storyteller-only. Customize appearance needs a grant from a Storyteller, even on your own character. Play, Story, and Edit are views, offered to the character's own player and to Storytellers.
- **Print My Items has nothing on it.** No items are connected to this character yet - ask a Storyteller to connect one.
- **My PDF says UNSIGNED.** This site has no signing certificate configured yet - that's a hosting setup step, not a problem with the character.
- **I ticked XP History and it appeared on the page.** That's expected - XP History, Full power names, and Show XP costs all change this page too, not only the printed file.
- **I don't see my Background or Notes.** They only show when they have text - add some from the **Story** view.
- **I can't find a character I know is in this chronicle.** If it isn't yours and you're not a Storyteller, it won't appear at all - and an NPC never appears for a player.

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
