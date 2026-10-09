# Approval Rules

One place to see and set every approval override in your chronicle's catalog - which items, powers, power levels, pool values, or field options need Storyteller review - plus your chronicle's own default for everything a rule doesn't cover.

## Who can use this

Storytellers (HST and AST) manage their own chronicle's approval rules here, and its **Default Approval Policy**. Everything on the page needs only a Storyteller role in the chronicle you've picked.

A Narrator, a chronicle's Harpy, and a player never see this screen. What it decides shows up for a player only as whether their own submitted change waits for Storyteller review before it takes effect.

## How to get there

- wp-admin sidebar → Beyond Elysium → System Config → Approval Rules tab.
- Or, from a chronicle's own checklist: [Chronicle Setup](chronicle-setup.md) → **Approval rules** row → **Go**.

## The screen

- **Chronicle** - a dropdown listing every chronicle on the install, not only ones you manage. Picking one you don't hold a Storyteller role in loads an error, not a rules list.
- **Default Approval Policy** - two radio buttons, saved the moment you pick one:
  - **Pending by default** - a rule below can mark something Auto.
  - **Auto-approve by default** - a rule below can require Storyteller review.
- **Removals, lower ratings, relabels and renames** - one checkbox, saved the moment you check or clear it, off by default. On, a removal, a lower rating, a relabel or a rename always waits for a Storyteller - whatever a rule above says, and whatever default policy applies - and anything submitted together with one waits with it, as one set, shown on the [Approval Queue](approval-queue.md) as "Submitted together."
- **OWBN Character Bylaws** - one checkbox, saved the moment you check or clear it, off by default. On, every OWBN Character Bylaw already attached to a merit, flaw, background, ability, or power adds its own reason to a matching purchase, on top of anything this chronicle already requires. The reference list that goes with it sits at the very bottom of the screen, folded away until you open it - see **OWBN Character Bylaws reference** below.
- A **New Rule** button, then a table of every rule currently set for the chosen chronicle. **New Rule** clears the form below the table and takes you to it, so you never scroll past a long list to start one. The table's columns are **Block** (with its creature type beside it, so Vampire's Abilities and Werewolf's Abilities read differently, or the block's slug in square brackets where two blocks share a name and no one creature type owns them), **Target** (the item, power, level, range, or option it addresses), **Approval**, **Reason**, **Edit**, **Delete**.
- The **New Rule** / **Edit Rule** form below the table:
  - **Block** - every trait list, tiered power, resource pool, and identity field block, in a list you can type into to narrow it, grouped under a bold heading for the creature type that owns it, which is the type its name carries: Vampire's Disciplines are under Vampire although a Mortal ghoul uses them too, and a printing such as Disciplines (Dark Ages) sits beside them. Blocks no single creature type owns (the Physical, Social and Mental Traits) are under **Used by several creature types**, and a chronicle's own blocks under **Not part of a creature type**; a creature type this chronicle hasn't turned on is still listed, marked as not enabled. Where two blocks in one group share a name, each shows its slug in square brackets.
  - **Scope** - once a block is picked: **A specific item, power, pool, or field…** (the per-target pickers below), **The whole block** (every purchase in it, with nothing more specific set, needs this level), or, only on a tiered power block with a real [in-type test](creature-stacks.md#the-in-type-test) declared on some creature type in this chronicle, **In-type / out-of-type** (two separate levels - one for a purchase the test passes, one for a purchase it doesn't).
  - Picking **A specific item, power, pool, or field…** opens what comes next, which depends on the block's section type:
    - **Trait list** - **Item**, then **Scope**: **The whole item**, or **A specific value
      range** (adds **From** and **To**).
    - **Resource pool** - **Pool**, then **From** and **To** (its permanent value).
    - **Identity field** - **Field** (only ones with real options), then **Option**.
    - **Tiered power** - **Power**, then **Scope**: **The whole power**, or **One level
      only** (adds **Level**, chosen from that power's own numbered rungs; an Elder-and-above pick has no numbered level to choose here).
  - **The whole block** uses the **Approval level** field below it, same as any other target.
  - **In-type / out-of-type** replaces **Approval level** and **Reason** with two of its own: **In-type approval level** and **Out-of-type approval level**, each **(unset - a Storyteller decides)**, **auto**, or **st**.
  - **Approval level** - **(unset - a Storyteller decides)**, **auto**, or **st**.
  - **Reason preset** - a dropdown of common phrasings that adds to whatever's already in
    Reason.
  - **Reason** - free text, with an **AI Assist** button that helps draft a short citation
    naming the real-world approval authority behind this rule.
  - **Save**, and (only while editing) **Cancel**.

- **OWBN Character Bylaws reference** - at the very bottom of the screen, folded until you click it, titled with how many clauses it lists. Inside:
  - **Search**, **Tier**, and an **Attached or not** picker, narrowing the list.
  - **Refresh from council.owbn.net** - re-pulls every Character Bylaw clause live. A clause you've already attached keeps its attachment as long as it still exists on council's own site; a clause council has since removed stops giving a reason; a brand new clause lists unattached.
  - **Upload a file** - for a site that can't reach council.owbn.net directly, loads an already-built bylaws file instead. A site administrator's own action, not a Storyteller's.
  - The list itself, 50 clauses at a time with a **Show more** button: every rule's clause number (linking to the real clause on council.owbn.net), subject, PC tier, NPC tier, coordinator(s), and what it's attached to, if anything.

Editing an existing rule locks its Block and target - only Approval and Reason can change.

## Common tasks

### Require review above a certain value

1. Pick your **Chronicle** from the dropdown.
2. In the form below the table, pick the **Block**.
3. Pick the **Item** (or **Pool**), and for an item set **Scope** to **A specific value range**.
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
2. Under **Default Approval Policy**, choose **Pending by default** or **Auto-approve by default**. This needs a site administrator account.

### Always wait on a removal, a lower rating, a relabel or a rename

1. Pick your **Chronicle**.
2. Check **Always wait for a Storyteller on a removal, a lower rating, a relabel or a rename**.

### Require OWBN Character Bylaw approval

1. Pick your **Chronicle**.
2. Check **Require OWBN Character Bylaw approval**.
3. A purchase a bylaw already covers now cites it the moment a player submits it - no further setup needed.

### Look up a bylaw before approving a purchase

1. At the bottom of the screen, open **OWBN Character Bylaws reference** and type the entry's name into **Search**.
2. Click its clause number to read the real clause on council.owbn.net.

### Edit or remove an existing rule

1. Find it in the table.
2. Click **Edit** to change its Approval or Reason, or **Delete** to clear it back to unset.

## Things to know

- **A rule always wins over the Default Approval Policy, in either direction.** Switching a chronicle to Auto-approve by default never silently waves through something a rule already flags for review - even a rule with no Reason attached.
- **This is the same data [Schema Blocks](schema-blocks.md) manages inline.** Setting a rule here changes exactly what editing the item, power, level, pool, or field directly on that screen would change - two doors onto the same lock. On a first edit for a chronicle, either one creates that chronicle's own copy of the block.
- **A range or option is checked against the value being reached**, never what a player held before it. A resource pool's range checks only its permanent rating - spending or regaining points in play never triggers a review.
- **Deleting a rule clears the override, not the catalog entry** - the item, power, or field itself stays exactly as it was, just without a special approval requirement.
- **A rejected save never leaves a half-made copy behind.** Setting or clearing a rule only creates your chronicle's own copy of the block once the change actually succeeds.
- **Two approval levels only: auto and st.** There's no separate coordinator step - a Reason naming a coordinator still routes through a Storyteller's own Approve click on the [Approval Queue](approval-queue.md).
- **In-type / out-of-type only ever reaches a tiered power block whose creature type actually declares a test for it.** A block with no in-type test on any creature type in this chronicle refuses the rule outright (`no_in_type_test`) - setting one there would never fire, since every purchase already reads as in-type.
- **Seeing the tab isn't the same as being able to use it here.** Any Storyteller-capable account can open this screen, but reading or setting rules for a specific chronicle still needs a real Storyteller role in it.
- **A bylaw reason stacks with a rule's own reason, never replaces it.** If a rule above already set a Reason on the same purchase, the bylaw's citation follows it on its own line.
- **An attachment's own PC and NPC tiers are separate, with no fallback between them.** A clause reading "NPC: Unregulated" adds nothing when the character is an NPC, even though its PC tier might require Coordinator Approval.
- **Refreshing never loses an attachment you've set**, as long as the clause it belongs to is still live on council.owbn.net - only a clause council has removed loses its reason.

## Troubleshooting

- **The chronicle I picked shows an error instead of rules.** You're not a Storyteller in that chronicle - the dropdown lists every chronicle on the install, not just ones you manage.
- **I don't see this tab at all.** Approval Rules needs a WordPress administrator account, or a Storyteller role in some chronicle.
- **My rule doesn't seem to apply.** Check it addresses the value actually being reached, not a difference from before, and that nothing more specific overrides it - an Approval by value range beats a flat item Approval, which beats the chronicle's own Default Approval Policy.
- **I can't change a rule's target.** Editing only changes Approval and Reason - delete the rule and create a new one to point at a different item, power, level, pool, or option.

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
