# Creature Stacks

The assembly list for one whole creature type's sheet: which schema block sections it uses, in what order, and any special rules for building a character of that type. Adding a stack here is how a new creature type comes into being, with no code involved.

## Who can use this

A site administrator only. Creature stacks are shared by every chronicle - there's no per-chronicle copy of one the way a schema block or template can have - so only a site administrator can create, edit, or delete one. A chronicle narrows which of the existing creature types it actually offers on [Chronicle Setup](chronicle-setup.md) instead, without touching the stacks themselves.

Storytellers (HST and AST) can open System Config for Schema Blocks, Templates, and Approval Rules, but its Creature Stacks tab never appears for them at all. Narrators, a chronicle's Harpy, and players never see System Config.

## How to get there

wp-admin sidebar → Beyond Elysium → System Config → Creature Stacks tab. The tab itself only shows for a site administrator.

## The screen

- **Hide system stacks (N)** - a checkbox; checking it hides Beyond Elysium's own eleven shipped creature types, leaving only custom ones.
- A table: **Name**, **Slug**, **Game Line**, **System?** (Yes/No), **Actions**.
  - **Edit** opens the stack below.
  - **Delete** - never offered for a system stack.
- **+ New Creature Stack** - opens a blank form.

### The create/edit form

- **Name**.
- **Slug** - create only; permanent once set.
- **Game Line** - free text. Every shipped stack is `met`, the only ruleset Beyond Elysium runs today.
- **Sections** table: **Block** (a dropdown of every schema block), **Label**, **Order**, **Required**, **Negative block** (optional), and **Remove**. **+ Add section** appends a new row.
- **Show/Hide creation rules (advanced, JSON)** - extra rules for this creature type, stored as raw JSON. Nothing on the character-creation screen currently reads them - this is storage for future use, not a guide a player sees today. **Apply JSON** checks it's valid before replacing what's there; invalid JSON is never applied.
- **Save** / **Cancel**.

## Common tasks

### Add a whole new creature type

1. First, build any [schema blocks](schema-blocks.md) the new type needs that nothing else uses yet.
2. Open System Config → Creature Stacks.
3. Click **+ New Creature Stack**.
4. Type a **Name** and **Slug**.
5. Click **+ Add section** for each block the sheet needs, picking its **Block**, **Label**, and **Order**.
6. Click **Save**.

### Reorder a stack's sections

1. Edit the stack.
2. Change the **Order** number on the sections you want to move - lower numbers come first.
3. Click **Save**.

### Give a section a negative counterpart

1. Edit the stack.
2. On the section, pick its **Negative block** (for example, pairing Merits with Flaws).
3. Click **Save**.

### Delete a custom creature type

1. Find it in the table - never possible for a **System = Yes** stack.
2. Click **Delete** and confirm.
3. If any character in any chronicle is still that creature type, nothing is deleted - the screen says how many. A character can't change its creature type, so those characters have to be deleted first.

## Things to know

- **A creature type you invent can't leave the site.** Export to Grapevine and chronicle-to-chronicle transfer both travel as a Grapevine file, and Grapevine has no race for a type you made up - exporting or transferring one of its characters is refused. Every shipped type does travel; a Bête goes out as the Fera it shares every block with and comes back as a Bête.
- **A creature type characters use can't be deleted.** Their sheets, audits, and printouts all depend on it, so the delete waits until no character anywhere is that type.
- **This screen doesn't narrow anything for a chronicle on its own.** A chronicle chooses which of the available creature types it offers on [Chronicle Setup](chronicle-setup.md); this screen only controls which types exist to be offered at all, across the whole install.
- **A section's placement here is only the default layout.** A chronicle that wants its sheet arranged differently overrides it on [Templates](templates.md) instead of changing the stack.

## Troubleshooting

- **I don't see this tab.** Creature Stacks needs a site administrator account - a Storyteller sees Schema Blocks, Templates, and Approval Rules under System Config, but never this one.
- **"A creature stack with this slug already exists."** Pick a different slug.
- **My raw creation-rules JSON didn't apply.** It wasn't valid JSON - the error shows above the box; fix it and click **Apply JSON** again.
- **"N characters are still this creature type, so it can't be deleted."** Delete those characters first, or keep the type.

## Related

- [Schema Blocks](schema-blocks.md)
- [Templates](templates.md)
- [Chronicle Setup](chronicle-setup.md)
- [Games](games.md)
- [Grapevine Import/Export](grapevine.md)
- [Admin Guide: Schema Blocks and Creature Stacks](../admin-guide.md#schema-blocks-and-creature-stacks)
- [Admin Guide: Adding a Creature Type Without Code](../admin-guide.md#adding-a-creature-type-without-code)
