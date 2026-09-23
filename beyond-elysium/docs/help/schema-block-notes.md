# Catalog Descriptions & Approval Schedules

Three small editors that open from a row on the Schema Blocks screen: a public note about an item, power, level, pool, or field, and two finer approval schedules than a flat Approval setting alone can express.

## Who can use this

The same audience as [Schema Blocks](schema-blocks.md) itself, since these open only from inside that screen: a site administrator on the shared catalog, and Storytellers (HST and AST) on their own chronicle's copy. A Narrator, a chronicle's Harpy, and a player never see them.

## How to get there

These aren't a screen of their own. Open [Schema Blocks](schema-blocks.md), edit a block, and click a **Description**, **Approval by value**, or **Approval by option** button on any row that offers one.

## The screen

### Description

A small modal with three independent rich-text sections:

- **Reference** - a general note.
- **Description** - the fuller explanation or house rule.
- **Source** - where it comes from, or a citation.

Each section keeps formatting, lists, and tables; images and anything else are stripped when saved. An **AI Assist** button sits beside each section. **Cancel** discards the modal; **Save** stages it - it isn't written for good until you also click **Save** on the block itself.

The button reads **Description (set)** once any of the three sections holds something.

### Approval by value

Used on a trait list item or a resource pool. A table of ranges: **From**, **To**, **Approval**, **Reason**, and **Remove**, plus **+ Add range**. Resolved against the value being reached, whichever range covers it; a value covered by no range falls back to the item's own flat Approval above. On a pool, only its **permanent** rating is checked.

### Approval by option

Used on an identity field that has real options. One row per option the field currently offers, each with its own **Approval** dropdown (**Block default** leaves it with no override) and a **Reason** field, enabled once that option has an override. A multiselect field checks every value a player picks; the strictest requirement applies.

## Common tasks

### Write a public note on a catalog item

1. Open [Schema Blocks](schema-blocks.md) and edit the block.
2. Find the item, power, or level, and click **Description** (or **Description (set)** if one is already there).
3. Fill in **Reference**, **Description**, or **Source** - whichever you need.
4. Click **Save** on the note, then **Save** on the block itself.

### Require review only above a certain value

1. Open Schema Blocks and edit the block.
2. On the item or pool, click **Approval by value**.
3. Click **+ Add range**, and set **From**, **To**, and **Approval**.
4. Optionally type a **Reason**.
5. Click **Save** on the modal, then **Save** on the block.

### Require approval for one option on a field

1. Edit the identity field's block.
2. On the field, click **Approval by option**.
3. Pick an **Approval** for the option that needs review.
4. Click **Save** on the modal, then **Save** on the block.

## Things to know

- **This writes the exact same data the [Approval Rules](approval-rules.md) screen manages.** Change one, see it reflected in the other - Approval Rules just lists every rule flat, across every block, one target at a time, while these modals manage a whole schedule (every range, or every option) for one item or field at once.
- **A Description is public.** Every member of a chronicle running this block can read it - on its own [House Rules](house-rules.md) page - not just Storytellers. It also isn't touched by Grapevine import or export, since it lives on the catalog definition, never on a character's own sheet.
- **Approval by value checks the value being reached, never what a player held before.** Going straight from 2 to 5 is judged the same as going from 4 to 5.
- **A tiered power's level has no Approval by value button** - each level is already its own row on the Powers table, with a plain Approval dropdown right there.
- **Nothing here writes until the block itself is saved.** Closing a modal with Save only stages that note or schedule; you still need to click Save on the block below it.
- **Only formatting, lists, and tables survive a Description** - an image or anything else you paste in is stripped the moment it saves.
- **Editing a shared system block's note or schedule, as a site administrator, changes it for every chronicle that hasn't made its own copy** - and, once there, it survives Beyond Elysium's own future updates, unlike the block's shipped catalog data (names, costs), which an update still refreshes.

## Troubleshooting

- **My note disappeared after I saved.** You likely clicked Cancel on the note, or didn't click Save on the block afterward - either one closes the modal without keeping anything.
- **Approval by value has no effect.** Check the range actually covers the value being submitted, and that no earlier, narrower rule already answers it first.
- **Approval by option is disabled.** The field has no Options yet - add some in the field's own Options box on Schema Blocks first.
- **I clicked Description and nothing seems to have saved.** Remember the two-step: Save inside the modal, then Save on the block itself. Cancel, on either one, discards it.

## Related

- [Schema Blocks](schema-blocks.md)
- [Approval Rules](approval-rules.md)
- [How Approval Works](approval-flow.md)
- [House Rules](house-rules.md)
- [AI Writing Assist](writing-assist.md)
- [Admin Guide: Descriptions and Approval Schedules](../admin-guide.md#descriptions-and-approval-schedules-on-catalog-items)
- [Admin Guide: Approval by Value and Approval by Option](../admin-guide.md#approval-by-value-and-approval-by-option)
