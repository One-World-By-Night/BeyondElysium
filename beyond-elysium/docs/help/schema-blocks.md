# Schema Blocks

The reusable pieces a character sheet is built from - a trait list, a set of leveled powers, a resource pool, or a group of identity fields. Editing a block changes every sheet built from it, in every chronicle that hasn't made its own copy.

## Who can use this

Every block in the shared catalog - the book - is read-only here, for everyone, a site administrator included: viewing it with no chronicle chosen shows the same screen with every field disabled and a notice explaining why. A house rule or a homebrew trait is built the same way as any other change to a chronicle's own catalog: scoped to that chronicle, from Chronicle Setup.

Storytellers (HST and AST) reach this screen already scoped to their own chronicle, normally by following [Chronicle Setup](chronicle-setup.md)'s Catalog customisation link. From there they can add a brand-new block that belongs to their chronicle alone, or edit a shared block - which makes their chronicle's own copy the moment they save. The copy keeps whatever the chronicle changed and follows the shared block for everything else.

A Narrator, a chronicle's Harpy, and a player never see this screen.

## How to get there

- Site administrator, viewing the book: wp-admin sidebar → Beyond Elysium → System Config → Schema Blocks tab.
- Storyteller, editing your own chronicle's catalog: wp-admin sidebar → Beyond Elysium → Chronicle Setup, pick your chronicle, find **Catalog customisation**, and click **Go**. This opens the same tab already scoped to your chronicle.

## The screen

- **Section type** filter - All, or one of the four kinds a section can be: `trait_list` (a trait list), `tiered_power` (leveled powers), `resource_pool`, or `identity_field`.
- **Show system blocks (N)** - a checkbox; unchecking it hides Beyond Elysium's own shipped blocks, leaving only custom ones.
- A notice naming which chronicle you're scoped to, when one is chosen; with none chosen, a notice explaining that the book is read-only here and where to change it for a chronicle instead.
- A table: **Name**, **Slug**, **Section Type**, **System?** (Yes/No), **Actions**.
  - **Edit** (or **View**, with no chronicle chosen) opens the block below.
  - **Delete** - only offered for a chronicle's own custom block; never for a system block, and, with no chronicle chosen, not at all.
- **+ New Schema Block** - only with a chronicle chosen. Opens a blank form for a block that belongs to that chronicle alone.

### The create/edit form

- **Name**.
- **Slug** - create only; permanent once set.
- **Section Type** - trait_list, tiered_power, resource_pool, or identity_field. Changing this on an existing block clears what's below back to that type's empty shape.
- **Storyteller only** - hides this whole section, and every value a character holds in it, from every player: on the sheet, in the editor, and in any export or print.
- The definition editor matching the Section Type you picked:
  - **Trait list** (Merits, Backgrounds, Abilities, and similar) - global flags **Allow
    multiple selections**, **Allow custom entries**, **Alphabetize**, **Negative list
    (flaws-style)** (entries refund XP instead of costing it), **Atomic** (adding the same
    entry again appends a new one instead of raising its count), **Max per item**, and a
    **Cost by prerequisite** toggle (below). A table of items: **Name**, **Cost**,
    **Category**, a **Description** button, an **Approval** dropdown, a **Reason** field, an
    **Approval by value** button, an **Allow multiples** override (block default / always /
    never - only shown once "Allow multiple selections" is on above), and **Remove**. Turning
    on **Cost by prerequisite** replaces a flat cost with one derived from another block's own
    tier, and adds a **Prerequisites** button to each item for the specific entries it needs -
    used for a rote or a rite whose price follows the Sphere or Rank it requires.
  - **Tiered power** (Disciplines, Gifts, Spheres, and similar) - a **Global settings** panel:
    a **Sequential** flag (holding a level implies every level below it), a **Blood magic**
    checkbox that adds a block-wide **Traditions** list, and a **Ranks & costs** table - one
    row per named rank (Basic, Intermediate, Advanced, Elder, and so on for a block that has
    them) giving that rank's **Cost**, **In-type modifier**, **Out-of-type modifier**, and
    **Ladder rungs** (how many numbered levels belong to it). A **This is an untiered track**
    toggle replaces the ranks table with a single flat **XP per level** rate, or a **Derived
    from block** picker for a cost that follows another block's own tier (paired with each
    power's own Prerequisites, same as a trait list above). Below the global settings, one
    entry per power: its name, an **Approval override** for the whole power, a **Description**
    button, and (for blood magic) a **Restriction** and its own offering-tradition list; then a
    table of levels - **Level** (blank means Elder-and-above), **Tier**, **Power name**,
    **Cost**, **Approval**, **Approval reason**, a **Description** button, and **Remove**.
  - **Resource pool** (Willpower, Blood, Gnosis, and similar) - a table of pools: **Name**
    (with an optional cross-block name-lookup underneath it), **Value type**, **Default
    start**, **Min**, **Max**, **Step**, **Cost per dot**, **Free dots**, a **Sliding (each dot
    costs its own level)** checkbox, a **Buy down (priced by lowering, not raising)** checkbox
    (Sliding and Buy down are mutually exclusive - checking one clears the other), an
    **Approval by value** button, and **Remove**.
  - **Identity field** (Clan, Nature, Generation, and similar) - a table of fields: **Name**,
    **Type** (text, select, multiselect, number, textarea), **Required**, **Options**
    (comma-separated, for select and multiselect only), an **Approval by option** button, and
    **Remove**.
  - The **Description**, **Approval by value**, **Approval by option**, and **Prerequisites**
    buttons each open a small modal editor - see
    [Catalog Descriptions & Approval Schedules](schema-block-notes.md).
- **Show/Hide advanced (raw JSON)** - the same definition as plain text. **Apply JSON** checks it's valid before replacing what's above; invalid JSON is never applied.
- **Save** / **Cancel** - with a chronicle chosen. With none chosen, every field above is disabled and the only action is **Close**.

## Common tasks

### Add a new item to a trait list

1. Open Schema Blocks via [Chronicle Setup](chronicle-setup.md)'s Catalog customisation link, so you're scoped to a chronicle - a site administrator viewing the book directly can't edit anything here.
2. Click **Edit** on the block.
3. Click **+ Add item**.
4. Type its **Name**, **Cost**, and **Category**.
5. Click **Save**.

### Hide a section from players entirely

1. Edit the block.
2. Check **Storyteller only - hide this section and its data from players**.
3. Click **Save**.

### Create a chronicle-only variant of a shared block

1. Open [Chronicle Setup](chronicle-setup.md) for your chronicle.
2. Find **Catalog customisation** and click **Go**.
3. Click **Edit** on the block you want to change, or **+ New Schema Block** for something brand new.
4. Make your changes.
5. Click **Save**. This creates or updates your chronicle's own copy; the shared block is untouched.

### Delete a custom block

1. Find it in the table - never possible for a **System = Yes** block.
2. Click **Delete**.
3. Read the warning about what references it, and confirm.

## Things to know

- **Two catalogs, one screen - but only one of them is ever editable here.** With no chronicle chosen, you're looking at the book every chronicle without its own copy shares, read-only for everyone, a site administrator included. With a chronicle chosen, you're editing only that chronicle's own copy - your first edit creates it, and it then stops following any later change to the shared original.
- **Seeing the tab isn't the same as being able to use it here.** Any Storyteller-capable account can open this screen, but reading or changing a specific chronicle's own copies still needs a real Storyteller role in that one chronicle.
- **A system block can never be deleted**, in either catalog.
- **What you add to a shared system block survives an update.** A new item, power, or field a chronicle adds to its own copy of a system block is kept the next time Beyond Elysium refreshes its catalog. What that refresh does overwrite is the shipped row's own catalog data - name, cost, and the like; a book update to a value your chronicle also changed shows up as a [book correction](chronicle-setup.md#the-screen) to resolve, never a silent overwrite.
- **A chronicle's own copy keeps its changes and takes the rest.** Whatever your chronicle changed - a cost, an approval rule, an item it added or removed - stays as you left it. Everything it never touched follows the shared block through Beyond Elysium's own updates.
- **Numbered power ladders normally ship Sequential.** With it checked, each level's own Cost is just that rung's price - buying fresh to level 3 charges levels 1, 2, and 3 together, not level 3 alone.
- **Approval, Reason, Approval by value, and Approval by option here are the exact same data** the [Approval Rules](approval-rules.md) screen manages - change one, see it reflected in the other.
- **A rank's In-type and Out-of-type modifiers on a tiered power only apply where a [Creature Stack](creature-stacks.md) section names an In-type test.** With no test declared, everything in that section is in-type and the modifier never fires - the two are declared in different places on purpose, since the same block can be in-type for one creature type's section and untested for another's.
- **Sliding and Buy down describe two different pricing shapes, not two strengths of the same thing.** Sliding prices each dot at its own step's rate (a growing pool costs more per dot as it rises); Buy down prices a *reduction* from the pool's own starting value, the shape a Storyteller-set Flaw-like pool needs. Checking one always clears the other.
- **Cost by prerequisite and Derived from block do the same thing on a trait list and a tiered power respectively** - the price follows the tier of an entry in another block, named per-item through the Prerequisites button, rather than a flat number typed here.

## Troubleshooting

- **I don't see Edit, Delete, or + New Schema Block, only View.** You arrived here with no chronicle chosen - the book is read-only for everyone, a site administrator included. Go through Chronicle Setup's Catalog customisation link instead.
- **Every chronicle I try shows a permission error.** You can see this tab without being a Storyteller anywhere - each chronicle still checks whether you actually hold a Storyteller role in it.
- **I don't see this tab at all.** System Config needs a WordPress administrator account, or a Storyteller role in some chronicle.
- **"A schema block with this slug already exists."** Slugs are unique across the whole install, shared and chronicle blocks alike - pick a different one.
- **My raw JSON edit didn't apply.** It wasn't valid JSON - the error shows above the box; fix it and click **Apply JSON** again.
- **I edited a system block's item, and my change disappeared after an update.** A reseed refreshes catalog data already in the system catalog; only what you've added new survives untouched. Fork the block for your chronicle if you need the change to last - and if the book's own value genuinely changed, look for it as a [book correction](chronicle-setup.md#the-screen) on Chronicle Setup rather than a lost edit.

## Related

- [Catalog Descriptions & Approval Schedules](schema-block-notes.md)
- [Creature Stacks](creature-stacks.md)
- [Templates](templates.md)
- [Approval Rules](approval-rules.md)
- [Chronicle Setup](chronicle-setup.md)
- [Games](games.md)
- [Storyteller-Only Content](storyteller-only.md)
- [How Approval Works](approval-flow.md)
- [Admin Guide: Schema Blocks and Creature Stacks](../admin-guide.md#schema-blocks-and-creature-stacks)
- [Admin Guide: Descriptions and Approval Schedules](../admin-guide.md#descriptions-and-approval-schedules-on-catalog-items)
