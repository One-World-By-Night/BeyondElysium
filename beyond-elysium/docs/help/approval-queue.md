# Approval Queue

Every pending sheet change across the chronicle's characters, in one list, where a Storyteller approves or rejects each one before it takes effect.

## Who can use this

Storytellers only (HST and AST). A Narrator or the chronicle's Harpy doesn't see this tab, even though they use other parts of the Storyteller Toolkit for their own area. A player never sees this screen - their own pending changes show instead on their Dashboard, unreviewed.

## How to get there

Storyteller Toolkit → Approval Queue tab.

## The screen

A line above the filters links to the Import page whenever a transfer or a player-sent Grapevine file is waiting for review there - this page doesn't show either one itself. See [Import](import.md).

### Filters

- **Character** - "All characters," or any one character in this chronicle.
- **Change type** - "All change types," or one of: `add_trait` (a new trait, power, or item), `remove_trait` (one taken off the sheet), `modify_trait` (an existing trait's count or a power's level), `modify_resource` (a pool's permanent or temporary rating), `modify_identity` (a field such as Clan or Nature), `xp_earn` (experience granted), `xp_adjust` (a Storyteller correction to experience), or `import_note` (a note recorded automatically by an import).
- **Approval level** - "All approval levels," `auto`, or `st`. The list, its pages, and its total count only that level.

Changing any filter starts again from the first page, with nothing checked.

### Approve Selected

A button naming how many changes you've currently checked - **Approve Selected (N)** - enabled once at least one is checked.

### The list

A table (stacked into cards on a narrow screen) with a checkbox per row, then:

| Column | Shows |
| --- | --- |
| Character | Who the change is on |
| Change | A plain description, such as a trait's old and new value |
| XP | The cost, positive or negative. A homebrew purchase shows **Needs a price** until you set one |
| Level | `auto` or `st` |
| Approval Reason | Why it landed at that level, when there is one |
| Submitted by | Who sent it |
| When | When it was submitted |
| Actions | Approve / Reject |

On a phone, Level, Submitted by, and When sit behind a **Details** disclosure on each card; Character, Change, XP, and Actions stay visible up top.

### Actions

- **Approve** - applies the change immediately.
- **A price box**, on a change that needs one - see [A change that needs a price](#a-change-that-needs-a-price).
- **Reject** - opens a text box ("Reason (required)") with **Confirm Reject** and **Cancel**. A reason is required; approving needs none.

### A change that needs a price

A trait or power that isn't in the catalog has no price, so the queue doesn't invent one. It shows **Needs a price** in the XP column and a box beside Approve for what it costs. For a trait list the box says **XP per dot** and shows the total as you type, such as "× 3 dots = 6 XP". For a power it says **XP**, one price for the whole pick.

- **Approve stays greyed out until the box holds a whole number from 0 to 500.** 0 counts: sometimes free is the answer, and typing it says so on purpose.
- **The row can't be ticked for a batch.** Its checkbox is disabled. Approve Selected leaves such a change alone and tells you how many it left.
- **The price goes on the sheet.** The row is saved with what you set, so the [Point Audit](point-audit.md) reads back exactly what was charged.
- **A flaw is recorded, not deducted.** In a section that gives points instead of costing them, the total shows as recorded, and nothing comes off the player's XP.
- **Raising a homebrew trait later.** A row that already has a price is raised at that price, with no prompt. A row that never had one asks again, and the price you set covers only the new dots.

### Pagination

**Previous** / **Next**, with the current page and the total pending count.

## Common tasks

### Approve a single change

1. Open Storyteller Toolkit → Approval Queue.
2. Find the change - filter by Character or Change type if the list is long.
3. Click **Approve**.

### Reject a change

1. Find the change.
2. Click **Reject**.
3. Type a reason.
4. Click **Confirm Reject**.

### Approve several changes at once

1. Check the box on each change you want to approve.
2. Click **Approve Selected (N)**.

A change that needs a price can't be checked. Price and approve it on its own row.

### Price a homebrew purchase

1. Find the row marked **Needs a price**.
2. Type what it costs in the box - per dot for a trait, the whole amount for a power.
3. Check the total beside the box.
4. Click **Approve**.

### Find one character's pending changes

1. Pick that character from the **Character** filter.

### See why a change needs your review

1. Read the **Approval Reason** column for that row (or, on a phone, open **Details**).

## Things to know

- **Two approval levels only: `auto` and `st`.** Beyond Elysium has no separate coordinator step. A rule's reason may tell you to get a coordinator's sign-off before you approve it, but the approving click here is always a Storyteller's.
- **Approve Selected is a shortcut, not a different mechanism.** It applies the same approval, individually, to every change you've checked - not a bulk override.
- **The queue is tied to what you were shown.** If a player resubmits a pending change, or someone else reviews it first, your click is refused and the queue reloads with what's actually there now - you never approve something different from what you saw.
- **A checked row stays checked across a page turn**, and Approve Selected acts on everything you've checked, not only what's on screen - each one as you saw it when you checked it. A change edited after you checked it is skipped, whichever page it's on. Changing a filter clears the checks.
- **Numbered powers add up.** A fresh purchase at level 3 is priced for levels 1, 2, and 3 together, not level 3 alone - the XP column reflects that.
- **Not everything lands here.** Some XP awards and anything your chronicle's rules mark auto-approved apply the moment they're submitted and never appear in this queue.

## Troubleshooting

- **Nothing happened when I clicked Approve, and the row is gone.** Someone else already reviewed it, or the player changed it after the queue loaded. The queue reloads on its own - check the character's current state before reviewing it again.
- **Confirm Reject won't click.** Type a reason first - rejecting needs one.
- **A batch approval skipped some changes.** Those were already reviewed, or edited, since the queue loaded. Reload and review them individually.
- **A batch said some changes need a price.** Those are homebrew with no price yet. Open each one, type what it costs, and approve it on its own.
- **Approve is greyed out on one row.** It needs a price. Type a whole number from 0 to 500 in the box beside it.
- **I don't see this tab at all.** You don't hold a Storyteller role in the chronicle currently selected - switch chronicles, or ask an HST/AST to check your role.

## Related

- [Import](import.md)
- [Character Editor](character-editor.md)
- [Character Sheet](character-sheet.md)
- [Approval Rules](approval-rules.md)
- [How Approval Works](approval-flow.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#4-running-the-approval-queue)
