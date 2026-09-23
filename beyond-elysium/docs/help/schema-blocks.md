# Schema Blocks

The reusable pieces a character sheet is built from - a trait list, a set of leveled powers, a resource pool, or a group of identity fields. Editing a block changes every sheet built from it, in every chronicle that hasn't made its own copy.

## Who can use this

A site administrator manages the shared catalog every chronicle draws from - the version of this screen you reach directly, with no chronicle chosen.

Storytellers (HST and AST) reach the same screen already scoped to their own chronicle, normally by following [Chronicle Setup](chronicle-setup.md)'s Catalog customisation link. From there they can add a brand-new block that belongs to their chronicle alone, or edit a shared block - which makes their chronicle's own copy the moment they save. The copy keeps whatever the chronicle changed and follows the shared block for everything else. Opening this screen with no chronicle chosen shows a Storyteller the same table read-only, with a note pointing them to Chronicle Setup instead.

A Narrator, a chronicle's Harpy, and a player never see this screen.

## How to get there

- Site administrator, editing the shared catalog: wp-admin sidebar → Beyond Elysium → System Config → Schema Blocks tab.
- Storyteller, editing your own chronicle's catalog: wp-admin sidebar → Beyond Elysium → Chronicle Setup, pick your chronicle, find **Catalog customisation**, and click **Go**. This opens the same tab already scoped to your chronicle.

## The screen

- **Section type** filter - All, or one of the four kinds a section can be: `trait_list` (a trait list), `tiered_power` (leveled powers), `resource_pool`, or `identity_field`.
- **Show system blocks (N)** - a checkbox; unchecking it hides Beyond Elysium's own shipped blocks, leaving only custom ones.
- A notice naming which chronicle you're scoped to, when one is chosen.
- A table: **Name**, **Slug**, **Section Type**, **System?** (Yes/No), **Actions**.
  - **Edit** opens the block below.
  - **Delete** - never offered for a system block, and, when scoped to a chronicle, only for
    that chronicle's own copy.
- **+ New Schema Block** - opens a blank form. With a chronicle chosen, the new block belongs to that chronicle alone; with none chosen (site administrator only), it joins the shared catalog every chronicle can use.

### The create/edit form

- **Name**.
- **Slug** - create only; permanent once set.
- **Section Type** - trait_list, tiered_power, resource_pool, or identity_field. Changing this on an existing block clears what's below back to that type's empty shape.
- **Storyteller only** - hides this whole section, and every value a character holds in it, from every player: on the sheet, in the editor, and in any export or print.
- The definition editor matching the Section Type you picked:
  - **Trait list** (Merits, Backgrounds, Abilities, and similar) - global flags **Allow
    multiple selections**, **Allow custom entries**, **Alphabetize**, **Negative list
    (flaws-style)** (entries refund XP instead of costing it), **Atomic** (adding the same
    entry again appends a new one instead of raising its count), and **Max per item**. Below,
    a table of items: **Name**, **Cost**, **Category**, a **Description** button, an
    **Approval** dropdown, a **Reason** field, an **Approval by value** button, and
    **Remove**.
  - **Tiered power** (Disciplines, Gifts, Spheres, and similar) - global flags **Sequential**
    (holding a level implies every level below it) and **Out-of-type cost modifier**, plus a
    **Blood magic** checkbox that adds a block-wide **Traditions** list. Below, one block per
    power: its name, an **Approval override** for the whole power, a **Description** button,
    and (for blood magic) a **Restriction** and its own offering-tradition list; then a table
    of levels - **Level** (blank means Elder-and-above), **Tier**, **Power name**, **Cost**,
    **Approval**, **Approval reason**, a **Description** button, and **Remove**.
  - **Resource pool** (Willpower, Blood, Gnosis, and similar) - a table of pools: **Name**
    (with an optional cross-block name-lookup underneath it), **Value type**, **Default
    start**, **Min**, **Max**, **Step**, an **Approval by value** button, and **Remove**.
  - **Identity field** (Clan, Nature, Generation, and similar) - a table of fields: **Name**,
    **Type** (text, select, multiselect, number, textarea), **Required**, **Options**
    (comma-separated, for select and multiselect only), an **Approval by option** button, and
    **Remove**.
  - The **Description**, **Approval by value**, and **Approval by option** buttons each open a
    small modal editor - see [Catalog Descriptions & Approval Schedules](schema-block-notes.md).
- **Show/Hide advanced (raw JSON)** - the same definition as plain text. **Apply JSON** checks it's valid before replacing what's above; invalid JSON is never applied.
- **Save** / **Cancel**.

## Common tasks

### Add a new item to a trait list

1. Open Schema Blocks (as a site administrator, or via Chronicle Setup's Catalog customisation link).
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

- **Two catalogs, one screen.** With no chronicle chosen, you're editing the block every chronicle without its own copy shares. With one chosen, you're editing only that chronicle's copy - your first edit creates it, and it then stops following any later change to the shared original.
- **Seeing the tab isn't the same as being able to use it here.** Any Storyteller-capable account can open this screen, but reading or changing a specific chronicle's own copies still needs a real Storyteller role in that one chronicle.
- **A system block can never be deleted**, in either catalog - only edited.
- **What you add to a shared system block survives an update.** A new item, power, or field you add is kept the next time Beyond Elysium refreshes its catalog. What that refresh does overwrite is a shipped row's own catalog data - name, cost, and the like - so a change you make to one of Beyond Elysium's own rows lasts only until the next update; fork the block for your chronicle if you want it to stick for good.
- **A chronicle's own copy keeps its changes and takes the rest.** Whatever your chronicle changed - a cost, an approval rule, an item it added or removed - stays as you left it. Everything it never touched follows the shared block, through Beyond Elysium's updates and a site administrator's fixes alike.
- **Numbered power ladders normally ship Sequential.** With it checked, each level's own Cost is just that rung's price - buying fresh to level 3 charges levels 1, 2, and 3 together, not level 3 alone.
- **Approval, Reason, Approval by value, and Approval by option here are the exact same data** the [Approval Rules](approval-rules.md) screen manages - change one, see it reflected in the other.

## Troubleshooting

- **I don't see Edit, Delete, or + New Schema Block.** Either you're not a site administrator and arrived here with no chronicle chosen - go through Chronicle Setup's Catalog customisation link instead - or the block is a system block, which never offers Delete.
- **Every chronicle I try shows a permission error.** You can see this tab without being a Storyteller anywhere - each chronicle still checks whether you actually hold a Storyteller role in it.
- **I don't see this tab at all.** System Config needs a WordPress administrator account, or a Storyteller role in some chronicle.
- **"A schema block with this slug already exists."** Slugs are unique across the whole install, shared and chronicle blocks alike - pick a different one.
- **My raw JSON edit didn't apply.** It wasn't valid JSON - the error shows above the box; fix it and click **Apply JSON** again.
- **I edited a system block's item, and my change disappeared after an update.** A reseed refreshes catalog data already in the system catalog; only what you've added new survives untouched. Fork the block for your chronicle if you need the change to last.

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
