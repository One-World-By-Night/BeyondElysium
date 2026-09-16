# Approval Rules

One place to see and set every approval override in your chronicle's catalog - which items,
powers, power levels, pool values, or field options need Storyteller review - plus your
chronicle's own default for everything a rule doesn't cover.

## Who can use this

Storytellers (HST and AST) manage their own chronicle's approval rules here, and its
**Default Approval Policy**. Everything on the page needs only a Storyteller role in the
chronicle you've picked.

A Narrator, a chronicle's Harpy, and a player never see this screen. What it decides shows up
for a player only as whether their own submitted change waits for Storyteller review before it
takes effect.

## How to get there

- wp-admin sidebar → Beyond Elysium → System Config → Approval Rules tab.
- Or, from a chronicle's own checklist: [Chronicle Setup](chronicle-setup.md) → **Approval
  rules** row → **Go**.

## The screen

- **Chronicle** - a dropdown listing every chronicle on the install, not only ones you manage.
  Picking one you don't hold a Storyteller role in loads an error, not a rules list.
- **Default Approval Policy** - two radio buttons, saved the moment you pick one:
  - **Pending by default** - a rule below can mark something Auto.
  - **Auto-approve by default** - a rule below can require Storyteller review.
- A table of every rule currently set for the chosen chronicle: **Block**, **Target** (the
  item, power, level, range, or option it addresses), **Approval**, **Reason**, **Edit**,
  **Delete**.
- The **New Rule** / **Edit Rule** form below the table:
  - **Block** - every trait list, tiered power, resource pool, and identity field block.
  - What comes next depends on the block's section type:
    - **Trait list** - **Item**, then **Scope**: **The whole item**, or **A specific value
      range** (adds **From** and **To**).
    - **Resource pool** - **Pool**, then **From** and **To** (its permanent value).
    - **Identity field** - **Field** (only ones with real options), then **Option**.
    - **Tiered power** - **Power**, then **Scope**: **The whole power**, or **One level
      only** (adds **Level**, chosen from that power's own numbered rungs; an Elder-and-above
      pick has no numbered level to choose here).
  - **Approval level** - **(unset - a Storyteller decides)**, **auto**, or **st**.
  - **Reason preset** - a dropdown of common phrasings that adds to whatever's already in
    Reason.
  - **Reason** - free text, with an **AI Assist** button that helps draft a short citation
    naming the real-world approval authority behind this rule.
  - **Save**, and (only while editing) **Cancel**.

Editing an existing rule locks its Block and target - only Approval and Reason can change.

## Common tasks

### Require review above a certain value

1. Pick your **Chronicle** from the dropdown.
2. In the form below the table, pick the **Block**.
3. Pick the **Item** (or **Pool**), and for an item set **Scope** to **A specific value
   range**.
4. Type **From** and **To**.
5. Pick an **Approval level**.
6. Click **Save**.

### Require approval for one option on a field

1. Pick your **Chronicle**.
2. Pick the identity field's **Block**, then the **Field**, then the **Option**.
3. Pick an **Approval level**.
4. Click **Save**.

### Change what happens by default

1. Pick your **Chronicle**.
2. Under **Default Approval Policy**, choose **Pending by default** or **Auto-approve by
   default**. This needs a site administrator account.

### Edit or remove an existing rule

1. Find it in the table.
2. Click **Edit** to change its Approval or Reason, or **Delete** to clear it back to unset.

## Things to know

- **A rule always wins over the Default Approval Policy, in either direction.** Switching a
  chronicle to Auto-approve by default never silently waves through something a rule already
  flags for review - even a rule with no Reason attached.
- **This is the same data [Schema Blocks](schema-blocks.md) manages inline.** Setting a rule
  here changes exactly what editing the item, power, level, pool, or field directly on that
  screen would change - two doors onto the same lock. On a first edit for a chronicle, either
  one creates that chronicle's own copy of the block.
- **A range or option is checked against the value being reached**, never what a player held
  before it. A resource pool's range checks only its permanent rating - spending or regaining
  points in play never triggers a review.
- **Deleting a rule clears the override, not the catalog entry** - the item, power, or field
  itself stays exactly as it was, just without a special approval requirement.
- **A rejected save never leaves a half-made copy behind.** Setting or clearing a rule only
  creates your chronicle's own copy of the block once the change actually succeeds.
- **Two approval levels only: auto and st.** There's no separate coordinator step - a Reason
  naming a coordinator still routes through a Storyteller's own Approve click on the
  [Approval Queue](approval-queue.md).
- **Seeing the tab isn't the same as being able to use it here.** Any Storyteller-capable
  account can open this screen, but reading or setting rules for a specific chronicle still
  needs a real Storyteller role in it.

## Troubleshooting

- **The chronicle I picked shows an error instead of rules.** You're not a Storyteller in that
  chronicle - the dropdown lists every chronicle on the install, not just ones you manage.
- **I don't see this tab at all.** Approval Rules needs a WordPress administrator account, or
  a Storyteller role in some chronicle.
- **My rule doesn't seem to apply.** Check it addresses the value actually being reached, not
  a difference from before, and that nothing more specific overrides it - an Approval by value
  range beats a flat item Approval, which beats the chronicle's own Default Approval Policy.
- **I can't change a rule's target.** Editing only changes Approval and Reason - delete the
  rule and create a new one to point at a different item, power, level, pool, or option.

## Related

- [Schema Blocks](schema-blocks.md)
- [Catalog Descriptions & Approval Schedules](schema-block-notes.md)
- [Approval Queue](approval-queue.md)
- [How Approval Works](approval-flow.md)
- [Character Editor](character-editor.md)
- [Chronicle Setup](chronicle-setup.md)
- [AI Writing Assist](writing-assist.md)
- [Admin Guide: Approval Rules](../admin-guide.md#approval-rules)
- [Storyteller Guide: Running the Approval Queue](../st-guide.md#4-running-the-approval-queue)
