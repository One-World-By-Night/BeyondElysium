# Print / Export

The Character Sheet's printing and export controls: a signed PDF of the sheet, a PDF of just your items, and a Grapevine (.gex) file for carrying a character somewhere else.

## Who can use this

Anyone who can open the character's sheet - a player for their own character, Storytellers (HST and AST) for any character in the chronicle.

## How to get there

Open the character's Sheet (My Chronicle → Characters, then click a name). In the actions list above the sheet, pick **Print / Export**, **Print My Items**, or **Export to Grapevine (.gex)** and click **Go**.

## The screen

- **Print / Export** opens the print panel:
  - **Background**, **Notes**, **XP History**, **Full power names** - four checkboxes that
    decide what the PDF carries. **XP History** also shows the history right on the page, and
    **Full power names** also switches every leveled power's display, on screen and in the PDF,
    from a single number to the full list of named rungs you hold. **Background** and **Notes**
    always show at the bottom of the sheet either way.
  - **Show XP costs** - on by default. Prints each Combo Discipline's XP price beside it, such as "Draw Fire (12 XP)"; off, the price is left out.
  - **Paper** - Letter or A4. It starts on the paper your site's language uses (Letter for a US, Canadian, Mexican or Philippine site language, A4 everywhere else); pick the other one for a different printer.
  - A notice reading "This site has no signing certificate yet, so prints are marked
    UNSIGNED," shown only when that's true.
  - **Open PDF** - opens a PDF of the sheet in a new tab. Signed with the site's certificate
    when one is set up; otherwise every page is stamped UNSIGNED and the file name ends
    "-unsigned.pdf" - it still works either way.
- **Print My Items** opens a PDF of just the items connected to this character in a new tab, straight away. Every viewer has it, not only a Storyteller. It follows the same signed/UNSIGNED rule as the sheet itself.
- **Export to Grapevine (.gex)** opens the export panel:
  - **Include verification code** - a checkbox, off by default. Checking it makes the export
    embed a verification code in the file, so anyone who receives it can confirm it's genuine
    and still matches your sheet. Each export made this way gets its own code.
  - **Download .gex file** - downloads an exchange file of this character. After it
    finishes, a notice confirms the export or lists any notes about names simplified for the
    older file format.

## Common tasks

### Print or save a signed PDF

1. Pick **Print / Export** and click **Go**.
2. Check whichever of **Background**, **Notes**, **XP History**, or **Full power names** you want included.
3. Click **Open PDF**. The PDF opens in a new tab - print or save it from there.

### Print on the other paper size

1. Pick **Print / Export** and click **Go**.
2. Change **Paper** to Letter or A4.
3. Click **Open PDF**.

### Print your items

1. Pick **Print My Items** and click **Go**.

### Export a Grapevine file

1. Pick **Export to Grapevine (.gex)** and click **Go**.
2. Click **Download .gex file**. The file downloads to your device.

### Export a file with a verification code

1. Pick **Export to Grapevine (.gex)** and click **Go**.
2. Check **Include verification code**.
3. Click **Download .gex file**.

## What the PDF looks like

- **The header** - the character's name on the left and creature type on the right, then short facts three to a row: printed and last-changed dates, status, player, XP earned and unspent, the identity fields (clan, sect, generation, nature and so on) and each resource's permanent rating (Blood, Willpower), each with a row of empty circles under it, one per point, in groups of five.
- **The attributes** - Physical, Social and Mental side by side, each with its negative traits under "Negative".
- **Everything else** in three columns, in the sheet's own order. A long list carries on into the next column and then the next page, with its title repeated and "(cont.)" added. On a light sheet the columns share the sections out evenly. A section with nothing in it is left out.
- **Trait lines** put empty circles in a column on the left, one per point, and the name and number beside them: "Occult x3 (Rituals)", "Brawl x5", "Lore: Nod x4". A rating of 1 drops its "x1", and a rating above five carries on in a second row of circles. Fill a circle in when you spend a point. Attributes, Abilities, Backgrounds, Health and Virtues get circles, drawn as a light grey outline so the text stays in front. Negative traits, Statuses and Bonds print the number alone, since you don't spend points on them.
- **Merits and Flaws** show their points, "Acute Sense (Hearing) 2", Combo Disciplines their XP price, and Disciplines and other powers a plain number. None of them get circles.
- **Virtues** carry the names your Path uses, such as Conviction and Instinct on the Path of Caine. When the sheet doesn't know your Path's virtues, both names print, "Conscience/Conviction". A choice made on the sheet always wins.
- **Full power names** - each power's family line, "Animalism 5", with its named powers listed beneath it. A pick above the ladder shows its rank ("elder", "master") only when the catalog knows it.

## Things to know

- Without a signing certificate, printing and exporting to PDF still work - every page just comes back stamped UNSIGNED, with "-unsigned.pdf" in the file name. That's a one-time setup step for the site's host, not a problem with your character and not something you can fix from here.
- A PDF reader saying "signature valid, signer not trusted" instead of a plain checkmark is normal, even with a certificate configured - it means the file genuinely hasn't been altered, checked against a certificate your reader just hasn't been told to trust.
- The verification code only ever applies to the Grapevine (.gex) export - a signed PDF is checked by its signature instead, not a code.
- The code isn't shown on screen. It's embedded in the exported file along with a web address anyone can check it against - see [Verify Character](verify.md).
- Your Storyteller-only text, if you have any, never leaves your own view of the sheet - a player's print and export are stripped exactly like their own on-screen sheet.
- Background and Notes export with their rich-text formatting intact, since Grapevine has no concept of it - opening the file in another program may show stray formatting marks around bold text or lists.

## Troubleshooting

- **My PDF says UNSIGNED.** This site has no signing certificate configured yet - a hosting setup step, not a problem with the character.
- **"(Name)'s creature type no longer exists, so no sheet can be printed for them."** That character's creature type was removed before Beyond Elysium stopped allowing it - ask a site administrator.
- **Print My Items has nothing on it.** No items are connected to this character yet - ask a Storyteller to connect one.
- **I ticked XP History and it appeared on the page.** That's expected - XP History and Full power names change the page too, not only the printed file.
- **"Exported with N note(s)."** Some names or fields were simplified to fit the older Grapevine format - read the note text for specifics; the file is still valid.
- **"Export failed. Please try again."** Try again. If it keeps happening, tell a Storyteller.
- **I checked "Include verification code" but don't see a code anywhere.** It's written into the exported file, not shown on screen - open the file, or check it on the [Verify Character](verify.md) page.

## Related

- [Character Sheet](character-sheet.md)
- [Change History](sheet-history.md)
- [Verify Character](verify.md)
- [Signed Sheets](signed-sheets.md)
- [Send Sheet](transfer.md)
- [Grapevine Import/Export](grapevine.md)
- [Player Guide](../player-guide.md#8-printing-your-sheet)
- [Storyteller Guide](../st-guide.md#11-signed-character-sheets)
