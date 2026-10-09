# Creature Stacks

The assembly list for one whole creature type's sheet: which schema block sections it uses, in what order, and any special rules for building a character of that type. A brand-new creature type comes into being here, with no code involved.

## Who can use this

A site administrator manages the shared catalog every chronicle draws from - the version of this screen you reach directly, with no chronicle chosen. Every shipped creature type lives there, read-only: nobody, not even a site administrator, can change one directly. A chronicle changes how a shipped type works for itself by layering over it instead, from [Chronicle Setup](chronicle-setup.md).

Storytellers (HST and AST) reach the same screen already scoped to their own chronicle, normally by following [Chronicle Setup](chronicle-setup.md)'s Creature types link. From there they can build a genuinely new creature type that belongs to their chronicle alone - its own slug, name, and sections, with no shipped counterpart at all - or edit their chronicle's own layer over a shipped type. Opening this screen with no chronicle chosen shows a Storyteller the same table read-only, with a note pointing them to Chronicle Setup instead.

A Narrator, a chronicle's Harpy, and a player never see this screen.

## How to get there

- Site administrator, viewing the book: wp-admin sidebar → Beyond Elysium → System Config → Creature Stacks tab.
- Storyteller, building or editing your own chronicle's creature types: wp-admin sidebar → Beyond Elysium → Chronicle Setup, pick your chronicle, find **Creature types**, and follow its link to Creature Stacks. This opens the same tab already scoped to your chronicle.

## The screen

- **Hide system stacks (N)** - a checkbox; checking it hides Beyond Elysium's own shipped creature types, leaving only custom ones.
- A notice naming which chronicle you're scoped to, when one is chosen.
- A table: **Name**, **Slug**, **Game Line**, **System?** (Yes/No), **Actions**.
  - **Edit** (or **View**, with no chronicle chosen) opens the stack below.
  - **Reset to book** - only for a chronicle's own layer over a shipped type; drops the layer, so the type reads as the book's again.
- **+ New Creature Stack** - only with a chronicle chosen. Opens a blank form for a creature type that belongs to that chronicle alone.

### The create form

- **Name**.
- **Slug** - permanent once set.
- **Game Line** - free text. Every shipped stack is `met`, the only ruleset Beyond Elysium runs today.
- **Sections** table, built locally until you save: **Block** (a dropdown of every schema block you can read, including your chronicle's own custom ones), **Label**, **Order**, **Required**, **Negative block** (optional), **In-type**, and **Remove**. **+ Add section** appends a new row. At least one section is required.
- **Creation rules** - a step-by-step editor for how a new character of this type is built. See [The creation rules editor](#the-creation-rules-editor) below.
- **Create** / **Cancel**.

### The edit view

- With a chronicle chosen, **Name** and **Game Line** are shown but fixed once set - for a shipped type they're the book's own, for your own creature type they're what you gave it at creation.
- The same **Sections** table. **Hidden** (in the last column), **+ Add section**, and a section's own **Remove** each save the moment you use them - there's no separate Save for those. **Label**, **Order**, **Required**, **Negative block**, and a section's **In-type** tests are staged here and only take effect when you click **Save** below, together with any change to Creation rules.
  - A shipped type's own sections can be hidden but never removed; your own creature type's sections can be freely added and removed, since none of them come from a book.
- The same creation-rules editor as the create form.
- **Save** / **Close**, at the bottom - only **Save** writes Label/Order/Required/Negative block/In-type/Creation rules changes.

### The In-type test

Each section's **In-type** button opens a small editor for the rule that decides whether a purchase from that section counts as in-type or out-of-type - the difference behind a clan's own Disciplines pricing cheaper than another clan's, a Garou's own breed Gifts, a Mage's own specialty Sphere, and similar. Leaving it empty means everything in that section is in-type, matching a section with no such rule today.

- **+ Add test** adds a row. Each row picks a **kind** - `names` (the entry's own name), `facet` (its `group` or `subgroup`, for a block that has them), `chosen` (something the player picked at creation, such as an in-clan choice), or `all` (every nested test must pass) - and, for every kind but `chosen`, where its values come from: a field on the character (`field`), a lookup table kept on an identity field (`map`), or a fixed list (`constant`).
- An optional **Limit to a field** condition only runs the test when a named field holds, or doesn't hold, a value, or is set or unset at all - needed for a case like a ghoul, who is a Mortal with no Revenant Family, where "unset" itself is the meaningful state rather than a value to ignore.
- **Remove** deletes a test; **Save** in the modal stages it into the section - it still isn't persisted until the main **Save** button below.

### The creation rules editor

A reorderable list of steps describing how a new character of this creature type gets built - what a player picks, what a pool of free points covers, what a Storyteller's build tally should flag. Every genre Beyond Elysium ships states its own real rules this way; building your own creature type from scratch works the same way, or can be left empty for "nothing special - price everything at the ordinary purchase cost."

- **+ Add step** appends a step; **Up** / **Down** reorder it; **Remove** deletes it.
- Each step has a **kind**, picked from a dropdown, and that kind's own fields:

| Kind | What it does |
| --- | --- |
| `prioritized` | Assigns a fixed set of amounts to a list of sections, largest first - Attributes 7/5/3 |
| `budget` | Covers a fixed count of a section's entries for free, optionally filtered to in-type only, a tier ceiling, or a named in-type test, and can track quotas (at least one Gift from breed, auspice, and tribe) |
| `free` | Spends a named pool of points on whatever no budget step covered, at a rate per section or per entry |
| `earned` | Adds points to a named pool from what the character already holds - a Flaw's own value, a derangement, a negative trait |
| `limit` | Flags a section or entry that's over its point total, over or under a rating, or above another entry's rating - a warning for the Storyteller, never a block |
| `start` | Sets an entry's starting value - a fixed number, a lookup by another field's value, or a formula (the higher of two Virtues, the average of two rounded up, and so on) |
| `grant` | Gives the character a fixed entry, or one chosen from a lookup table, that costs nothing and counts against no budget |

- A step may carry an **Only when** condition, the same shape as the In-type test's own field condition above.
- **Show advanced (raw JSON)** shows the whole document as text, for a step this editor's own fields don't cover yet; **Apply JSON** checks it's valid before replacing what's above.
- Whatever this editor holds only takes effect when you click the main **Save** button.

A character's own live build - what each step still needs, each pool's balance, and what's left to buy at the ordinary price - shows as a **Build Tally** panel beside the character creation form, and again for a Storyteller reviewing a pending character. Nothing it reports blocks a save; an overspent build simply starts below zero, so the Storyteller can see why.

## Common tasks

### Build a whole new creature type for your chronicle

1. First, build any [schema blocks](schema-blocks.md) the new type needs that nothing else uses yet.
2. Open [Chronicle Setup](chronicle-setup.md) for your chronicle, find **Creature types**, and follow its link to Creature Stacks.
3. Click **+ New Creature Stack**.
4. Type a **Name**, **Slug**, and **Game Line**.
5. Click **+ Add section** for each block the sheet needs, picking its **Block**, **Label**, and **Order**.
6. Click **Create**.
7. Back on Chronicle Setup's **Creature types** row, enable your new type so characters can actually be created on it.
8. Build a [Template](templates.md) for it, so its sheet lays out the way you want rather than a generated default.

### Change how a shipped creature type works for your chronicle

1. Open [Chronicle Setup](chronicle-setup.md) for your chronicle, find **Creature types**, and follow its link to Creature Stacks.
2. Click **Edit** on the shipped type.
3. Hide a section, add one of your own, or change its creation rules.
4. Changes here save at once; there's no separate **Save** button for sections.

### Give a section a negative counterpart

1. Edit the stack.
2. On the section, pick its **Negative block** (for example, pairing Merits with Flaws).

### Delete your own creature type

1. Find it in the table, scoped to your chronicle - only offered for a creature type your chronicle built, never a shipped one.
2. Click **Delete** and confirm.
3. If any character anywhere is still that creature type, nothing is deleted - the screen says how many. A character can't change its creature type, so those characters have to be deleted first.

## Things to know

- **A shipped creature type is read-only everywhere, for everyone.** A chronicle changes how one works for itself with its own layer; nobody edits the book directly, site administrator included.
- **A creature type your chronicle built belongs to that chronicle alone.** It has no book counterpart, and no other chronicle can see, enable, or use it.
- **A creature type you invent can't leave the site.** Export to Grapevine and chronicle-to-chronicle transfer both travel as a Grapevine file, and Grapevine has no race for a type you made up - exporting or transferring one of its characters is refused. Every shipped type does travel; a Bête goes out as the Fera it shares every block with and comes back as a Bête.
- **A creature type characters use can't be deleted.** Their sheets, audits, and printouts all depend on it, so the delete waits until no character anywhere is that type.
- **Building the stack doesn't make it available on its own.** A chronicle still has to enable a new creature type on [Chronicle Setup](chronicle-setup.md)'s Creature types row before a character can be created on it.
- **A shipped type is on by default, unless it declares itself off.** A new chronicle offers every shipped creature type except one that declares itself off by default; nothing shipped does today.
- **Various is the Storyteller-only creature type.** A Storyteller is offered it when creating a character in every chronicle, whatever the chronicle enabled, and no player is - not in the creation picker, not in a file a player sends, not through the API. It carries no creation rules, so nothing about it is priced. It can hold **any section from any creature type**, so a Storyteller can say "this creature has Disciplines and Gifts" and build it from whichever sections fit. Its sheet already lists every creature type's power families (Disciplines, Blood Magic, Gifts, Arts, Realms, Spheres, Arcanoi, Lores, Edges, Shintai, Hekau, Psychic Phenomena, Hedge Magic, Martial Arts, Theurgy, Fomori Powers and Bioenhancements), and one list each for Abilities, Backgrounds, Merits, Flaws and Tempers that holds every creature type's entries, each name once; **Other Powers** is the free-form list for entries no catalog lists. Everything else (rituals, rotes, combination Disciplines) comes from **Add a section** on the [character editor](character-editor.md). Chronicle Setup's Creature types row leaves Various out, since it is always available to a Storyteller.
- **No Template yet is fine.** A creature type with no authored [Template](templates.md) still renders a sheet, generated from its own sections; build one when you want it laid out differently.
- **A section's placement here is only the default layout.** A chronicle that wants its sheet arranged differently overrides it on [Templates](templates.md) instead of changing the stack.
- **Hiding, adding, or removing a section saves right away; everything else waits for Save.** A section's Label, Order, Required, Negative block, and In-type tests, and the whole Creation rules editor, are all staged until you click the main **Save** button - close the screen without saving and they're lost.
- **Hidden only blocks new purchases - it never hides what a character already has.** A character already holding something in that section still shows it on the sheet, in print and export, in the point audit, and in the approval queue exactly as before. Only adding a new entry, or raising a count, level, or rating, is refused there; lowering, removing, and approving a change submitted before it was hidden all still work.
- **No creation rules is a real, supported choice, not a gap.** A creature type with nothing in its Creation rules editor prices every purchase at the ordinary cost with no free pools, no budgets, and no build tally - exactly how every creature type behaved before this editor existed.
- **Creation rules never block a save.** An overspent build simply starts below zero XP; the Storyteller reviewing it sees exactly why, rather than the player being stopped.

## Troubleshooting

- **I don't see + New Creature Stack, or Edit only offers View.** You arrived here with no chronicle chosen - go through Chronicle Setup's Creature types link instead.
- **I don't see this tab at all.** System Config needs a WordPress administrator account, or a Storyteller role in some chronicle.
- **"A creature stack with this slug already exists."** Slugs are unique across the whole install, shipped and chronicle-built types alike - pick a different one.
- **"Unknown block_slug."** A section named a block your chronicle can't read - build that [schema block](schema-blocks.md) first, or pick one that already exists.
- **"This creature type is still held by at least one character."** Delete those characters first, or keep the type.
- **I changed a section's In-type tests or a creation-rules step, and it didn't stick.** Those two stage locally until the main **Save** button - hiding, adding, or removing a section is the only kind of section change that saves immediately.

## Related

- [Schema Blocks](schema-blocks.md)
- [Templates](templates.md)
- [Chronicle Setup](chronicle-setup.md)
- [Games](games.md)
- [Point Audit](point-audit.md)
- [Grapevine Import/Export](grapevine.md)
- [Admin Guide: Schema Blocks and Creature Stacks](../admin-guide.md#schema-blocks-and-creature-stacks)
- [Admin Guide: Adding a Creature Type Without Code](../admin-guide.md#adding-a-creature-type-without-code)
- [Admin Guide: Creation Rules](../admin-guide.md#creation-rules)
