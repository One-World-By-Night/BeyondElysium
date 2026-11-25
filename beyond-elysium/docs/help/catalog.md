# Catalog

Add to and change what this chronicle's characters can buy: your own Disciplines, Gifts, Rituals, Merits and the rest, the names of a new Discipline's levels, Elder powers, and the price and description of the book's own entries. What you change here belongs to this chronicle. The book and every other chronicle stay as they are.

## Who can use this

The chronicle's HST, and any AST or Narrator the HST has given the Catalog on the [Players](chronicle-players.md) tab. Everyone else doesn't see the tab.

## How to get there

Open the [Storyteller Toolkit](storyteller-toolkit.md), pick the chronicle, then choose **Catalog**.

## The screen

- **Creature type** and **Section** - pick the creature type, then the part of its sheet you want to change. Only sections that take entries of their own are listed, so Health, Bonds and Hunter Edges are not.
- The line under them says how the section prices, for example "Priced by rank: basic 3, intermediate 6, advanced 9. Out-of-clan +1." or "Priced per dot."
- **Search** and **Show** - narrow the list by name, or to **Added here**, **Changed here** or **Needs a look**, the entries where the book has changed something you also changed.
- **+ New** - opens an empty pop-up for a new entry.
- The list - one row for each entry with its price and a badge where one applies: **Added here**, **Changed here** or **The book changed this**. On a long list each group is folded shut, and typing a search opens every match. Click a row to open it.

## The pop-ups

### A list entry

Rituals, Combo Disciplines, Ritae, Merits, Flaws, Abilities, Backgrounds and the like. Name, price, group and, on a list that names its entries with a group and a rank (Rituals), the rank. The price is a whole number, a range such as 1-3 or a choice such as 1 or 3, from 0 to 500, and it means per dot or per pick as the line under the section says. A Ritual's stored name follows its list: group Thaumaturgy, name Ward of Ash and rank basic is stored "Thaumaturgy: Ward of Ash (basic)", and the pop-up shows it. **Description** opens the description editor.

### A family on a ladder

Disciplines, Arcanoi, Spheres and Blood Magic paths. A name and a description; one row for each level, which you can name and describe (the rungs belong to the section and can't be added or taken away); **Elder powers**, each with a name, a price that starts at its rank's price and a description; **In-clan for**; and, on Blood Magic, **Traditions**, each with the name that tradition gives the path if it has one.

### A family of Gifts

Werewolf and Fera Gifts. A name and a description; **Gifts** by rank, each with a name, a price that starts at its rank's price and a description; and **Filed under**, one choice for each of the breed, auspice, tribe or species the section compares.

### In-clan and Filed under

**In-clan for** lists the clans (guilds, traditions, houses) the section's own rule reads. Tick the ones that count the new family as theirs. Saving adds the family's name to each ticked clan's list in this chronicle's copy of the identity list, and takes it out of the rest. A line under the boxes names the clans that choose their own, such as "Caitiff and Pander choose their own." **Filed under** sets the family's own breed, auspice or tribe, which a Gift's in-type test compares with the character's.

## Book entries

An entry from the book can have its **price** and **description** changed for this chronicle. Its name, its levels and its in-clan lists are shown and stay as the book has them, and a book entry can't be taken away: an approval rule is what disallows one. **Use the book's** puts the book's price and description back and drops your change.

When the book later changes something you also changed, the entry shows **The book changed this**. Its pop-up lists both values with **Keep mine** and **Use the book's**. You can settle it here without opening wp-admin.

## Common tasks

### Add a Discipline

1. Choose the creature type and **Disciplines**, then **+ New**.
2. Type the name. Name the levels you want named; the rest can be named later.
3. Add an Elder power if the Discipline has one.
4. Tick the clans it is in-clan for, then **Save**.

### Change what a book Merit costs

1. Open the Merit, change **Price per pick** and **Save**.
2. Players are quoted the new price from now on. Nobody who already holds the Merit is charged or refunded.

### Take the book's price back

1. Open the entry and click **Use the book's**.

## Things to know

- **Descriptions are your chronicle's own notes, not book text.** Write a short note in your own words and point to the book and page. The [Dark Pack Agreement](https://www.paradoxinteractive.com/games/world-of-darkness/community/dark-pack-agreement) leaves what counts as a reasonable extent to Paradox, and copying a book in full is never reasonable. The Description pop-up warns when a section runs past about 500 characters. It only warns and never blocks a save.
- **Prices change forward only.** A new price applies to purchases made after you save. No held row is repriced and no XP moves.
- **A rename or a removal is refused while characters hold the entry** or a change waiting in the Approval Queue names it. The message says how many. Naming a level is always allowed, since a held level is held by its number.
- **Two people can edit one section at the same time.** A save only replaces the entry you opened. If someone changed that same entry first, you see "Someone changed this since you opened it." and **Reload**.
- **Approval content stays in wp-admin.** Approval levels, per-value approval, reasons, prerequisites and multiples are set in Schema Blocks, as before.
- **Entries you add are ordinary entries of this chronicle,** and can also be edited in wp-admin's Schema Blocks.

## Troubleshooting

- **There is no Catalog tab.** You don't hold the Catalog here. Ask the HST to give it to you on the Players tab.
- **"4 characters hold this."** Those characters have the entry on their sheets, so it can't be renamed or removed. Change its price or description instead.
- **"A book entry can only have its price and description changed here."** The name, group and levels of a book entry belong to the book.
- **"A price is a whole number, a range..."** Type a price such as 3, 1-3 or 1 or 3, with no number above 500.

## Related

- [Storyteller Toolkit](storyteller-toolkit.md)
- [Players](chronicle-players.md)
- [Approval Queue](approval-queue.md)
- [Chronicle Setup](chronicle-setup.md)
- [Schema Blocks](schema-blocks.md)
- [Roles](roles.md)
