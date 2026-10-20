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
- **Change type** - "All change types," or one of: `add_trait` (a new trait, power, or item), `remove_trait` (one taken off the sheet), `modify_trait` (an existing trait's count or a power's level), `modify_resource` (a pool's permanent or temporary rating), `modify_identity` (a field such as Clan or Nature), `xp_earn` (experience granted), `xp_adjust` (a Storyteller correction to experience), `import_note` (a note recorded automatically by an import), `log_knowledge` (a player's claim about what their character learned, waiting to be tied to a secret), or `pass_secret` (a player telling another character a secret they know).
- **Approval level** - "All approval levels," `auto`, or `st`. The list, its pages, and its total count only that level.

Changing any filter starts again from the first page, with nothing checked.

### Approve Selected

A button naming how many changes you've currently checked - **Approve Selected (N)** - enabled once at least one is checked.

### The list

A table (stacked into cards on a narrow screen) with a checkbox per row, then:

| Column | Shows |
| --- | --- |
| Character | Who the change is on |
| Change | A plain description, such as a trait's old and new value. A player's own [XP request](character-editor.md#request-xp) reads "+N XP (Requested: where, date)," with any details they added shown underneath. A change forwarded here from a host chronicle shows **"From Boston"** (or whichever chronicle sent it) above the description, and the host's own free-text note, if any, beneath it - see [A change forwarded from a host chronicle](#a-change-forwarded-from-a-host-chronicle). Beneath that: the note the player put on a trait ("Note: R1 Necromancy ( 4 xp)"), the section the entry is headed for ("In Rituals"), and, once a Storyteller has edited it, "Edited by" and their name |
| XP | The cost, positive or negative. A homebrew purchase shows **Needs a price** until you set one |
| Level | `auto` or `st` |
| Approval Reason | Why it landed at that level, when there is one. A bylaw-sourced reason ends with a clickable clause citation, e.g. `[OWBN Character Bylaws 10.e.v, clause 7838]`, opening the real clause on council.owbn.net; several reasons on one change each sit on their own line |
| Submitted by | Who sent it |
| When | When it was submitted |
| Actions | Approve / Reject / Edit |

On a phone, Level, Submitted by, and When sit behind a **Details** disclosure on each card; Character, Change, XP, and Actions stay visible up top.

### Actions

- **Approve** - applies the change immediately.
- **Edit** - opens a pop-up where you change the entry, price it, file it in a different section and leave the player a note. See [Edit a change](#edit-a-change). It never approves anything.
- **A price box**, on a lone change that needs one - see [A change that needs a price](#a-change-that-needs-a-price).
- **Reject** - opens a text box ("Reason (required)") with **Confirm Reject** and **Cancel**. A reason is required; approving needs none.

### Edit a change

**Edit** is on every waiting change: a lone one, each change in a set that was sent together, priced or not, whatever its type. It opens a pop-up with the change at the top and these boxes:

- **Section** - on an entry the book doesn't have. It lists the sections that take the same kind of entry, so a "Fettered Minion" typed into Rituals can be filed under Combination Disciplines, or Merits, Flaws, Abilities and so on. Choosing another section moves it. The entry is checked there like one of your own: a name the new section's catalog holds becomes that entry at the book's price, and any other name stays the player's own entry in the new section, waiting for a price. A move is saved on its own, so the price box is greyed out until you save it.
- **The change's own boxes** - Name, Dots, Specialization and Note on a trait, Level on a power, the rating on a pool, the new value on an identity change, Amount and Reason on an experience change. Only the boxes that change really has are shown.
- **A price box**, on a purchase - see [Setting a price](#setting-a-price).
- **Note to the player** - plain text. The player reads it in their change history, under "Changed by a Storyteller". Text between `[ST]` and `[/ST]` is cut out of what they see, so it works for a word to the next Storyteller.

**Save** writes your edit and leaves the change waiting. You still decide it yourself with **Approve**, **Approve all** or **Approve Selected**, and nothing is sent to the player until you do. Saving gives the change a new "Edited by" line, and a new review token, so a Storyteller who opened the old version is told to reload.

A save is refused if it would leave this change doing the same job as another waiting change on the character, or if the player resubmitted it while you were typing.

### Setting a price

The price box in the pop-up depends on whether the book already prices the entry.

- **An entry the book has no price for.** The box says **XP per dot** for a trait counted in dots, and **XP** for a single purchase (a Merit, Flaw, Ritual or Combination Discipline) or a power, and shows the total as you type. Leave it empty and the change keeps waiting for a price. Type a number, Save, and it stops waiting: the price is written on the entry, the change can be ticked for **Approve Selected**, and it goes through **Approve all** with the rest of its set. A Combination Discipline's number is its price, so one priced at 4 reads "(4 XP)" on the sheet.
- **An entry the book already prices.** The box says **XP for this change** and starts empty, with the usual price shown as a hint ("Usual price: 6 XP"). Leave it empty to charge that price. Type a number to charge that instead, for this change only; nothing is written on the entry, so a later raise is priced from the book again.

Either way the number is a whole number from 0 to 500, and 0 is allowed. On a list that gives points instead of costing them, such as Flaws, the figure is recorded and nothing is taken from the player's XP.

### A change that needs a price

A trait or power that isn't in the catalog has no price, so the queue doesn't invent one. It shows **Needs a price** in the XP column and, on a lone change, a box beside Approve for what it costs. For a trait counted in dots, such as an Ability or a Background, the box says **XP per dot** and shows the total as you type, such as "× 3 dots = 6 XP". For a single purchase, such as a Merit, Flaw, Ritual or Combination Discipline, and for a power, it says **XP**: one price for the whole purchase. A change in a set has no box beside it; price it with **Edit**.

- **Approve stays greyed out until the box holds a whole number from 0 to 500.** 0 counts: sometimes free is the answer, and typing it says so on purpose.
- **The row can't be ticked for a batch.** Its checkbox is disabled. Approve Selected leaves such a change alone and tells you how many it left.
- **The price goes on the sheet.** The row is saved with what you set, so the [Point Audit](point-audit.md) reads back exactly what was charged.
- **A flaw is recorded, not deducted.** In a section that gives points instead of costing them, the total shows as recorded, and nothing comes off the player's XP.
- **Raising a homebrew trait later.** A row that already has a price is raised at that price, with no prompt. A row that never had one asks again, and the price you set covers only the new dots.
- **Bonds and Guanxi never ask.** They are never bought with XP, so adding or raising one shows no price box. It still waits for you to approve it.

### A logged knowledge claim

A `log_knowledge` change is a player's own request, not a secret yet - approving it needs you to say which one it is. The row shows a panel with two choices:

- **Tie to an existing secret** - search by title, then pick one from the results.
- **Create a new secret** - choose what it attaches to (plot, item, location, or NPC) and its real id, then a title and write-up, pre-filled from what the player typed.

**Approve stays greyed out until one choice is complete.** The row can't be ticked for a batch - approve or reject it on its own, like a change waiting for a price. Approving writes an approved reveal for the logging character, carrying the how and teller they named; rejecting writes nothing.

### A change forwarded from a host chronicle

A character with an open, agreed kept-current visit elsewhere is editable only at home - any Storyteller-submitted change or XP award made on the host's own copy, and any free-text note the host's Storytellers share, arrives here as an ordinary pending change instead of applying itself. The row carries the host chronicle's own name as a label above the description, and, for a shared note, the note's own text beneath it; the row itself is tinted to set it apart from a change a player submitted directly.

- **`visit_pairing`** - a host asking to pair a player-submitted character with its real home, rather than a sheet change. Approving it opens a new visit between the two chronicles, kept current from the start; refusing it, or 60 days with no answer, leaves the host's own copy unpaired.
- **`visit_note`** - the host's own free-text note about the visit, with no sheet effect either way. Approving it files the note Storytellers-only on the character's own plot; refusing it records nothing.
- Approving or refusing a forwarded change works exactly like any other row - **Approve**/**Reject**, or as part of a batch where nothing about the row blocks it.

### Submitted together

Two or more changes a player sends in one save from the editor are a set: they arrive together and are reviewed together. With the chronicle's removal/lowering switch on (Approval Rules), a save that removes something, lowers a rating, or relabels or renames an entry is caught, and the whole set waits for a Storyteller whatever any rule on the individual traits would otherwise say. The queue shows a set as a single banner row above its changes, **"Submitted together (N changes) - net ±N XP"**, with **Approve all** and **Refuse all** in place of a checkbox and per-change buttons on each of its rows - those rows show "Part of a submitted-together set" and an **Edit** button instead.

- **Approve all** applies every change in the set in one request: removals and lower ratings first, then the rest, so nothing partial lands if one fails. It is greyed out while any change in the set still needs a price, with a line saying so: click **Edit** on each one marked **Needs a price**, type what it costs, Save, and Approve all lights up.
- **A set goes through whole or not at all.** If a change in it can't be approved from here (it needs a price, a secret to be chosen, or it was already decided or edited), none of them is, and the queue says how many were held back with their set.
- **Refuse all** asks for one shared reason and rejects every change in the set; nothing in it ever reaches the sheet.
- **A group can't be split.** Its rows can't be individually checked for Approve Selected - approve or refuse the whole set together.

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
2. On a lone change, type what it costs in the box beside Approve - per dot for a trait counted in dots, the whole amount for a single purchase or a power. On a change in a set, click **Edit** and use the price box there.
3. Check the total beside the box.
4. Click **Approve**, or, in the pop-up, **Save** and then approve the change or the set.

### Edit a change

1. Click **Edit** on the change.
2. Change whatever needs changing - a name, the dots, the price, the note to the player.
3. Click **Save**. The change stays in the queue, waiting for you.

### File an entry in the right section

1. Click **Edit** on the entry. If it can move, the pop-up has a **Section** list.
2. Pick the section it belongs in, such as Combination Disciplines instead of Rituals.
3. Click **Save**. Then open **Edit** again to price it, since the price depends on the new section.

### Leave the player a note

1. Click **Edit** and type in **Note to the player**.
2. Click **Save**. The player sees it in their change history, and it stays there when you approve the change.

### Find one character's pending changes

1. Pick that character from the **Character** filter.

### See why a change needs your review

1. Read the **Approval Reason** column for that row (or, on a phone, open **Details**).

## Things to know

- **Two approval levels only: `auto` and `st`.** Beyond Elysium has no separate coordinator step. A rule's reason may tell you to get a coordinator's sign-off before you approve it, but the approving click here is always a Storyteller's.
- **Approve Selected is a shortcut, not a different mechanism.** It applies the same approval, individually, to every change you've checked - not a bulk override.
- **The queue is tied to what you were shown.** If a player resubmits a pending change, or someone else reviews it first, your click is refused and the queue reloads with what's actually there now - you never approve something different from what you saw.
- **A checked row stays checked across a page turn**, and Approve Selected acts on everything you've checked, not only what's on screen - each one as you saw it when you checked it. A change edited after you checked it is skipped, whichever page it's on. Changing a filter clears the checks.
- **An edit is not a decision.** Saving leaves the change waiting. Approving after an edit keeps the note you saved, and a rejection replaces it with the reason you give.
- **Numbered powers add up.** A fresh purchase at level 3 is priced for levels 1, 2, and 3 together, not level 3 alone - the XP column reflects that.
- **Not everything lands here.** Some XP awards and anything your chronicle's rules mark auto-approved apply the moment they're submitted and never appear in this queue.

## Troubleshooting

- **Nothing happened when I clicked Approve, and the row is gone.** Someone else already reviewed it, or the player changed it after the queue loaded. The queue reloads on its own - check the character's current state before reviewing it again.
- **Confirm Reject won't click.** Type a reason first - rejecting needs one.
- **A batch approval skipped some changes.** Those were already reviewed, or edited, since the queue loaded. Reload and review them individually.
- **A batch said some changes need a price.** Those are homebrew with no price yet. Open each one, type what it costs, and approve it on its own.
- **Approve is greyed out on one row.** It needs a price. Type a whole number from 0 to 500 in the box beside it.
- **Approve all is greyed out.** A change in the set still needs a price. Click **Edit** on each change marked **Needs a price**, type what it costs, and Save.
- **Save says the change was edited after I opened it.** The player resubmitted it, or another Storyteller edited it first. The queue reloads; open **Edit** again.
- **Save says the change is priced differently now.** Changing the name or the section can change what the price means - per dot, per pick, or a price the book already has. Open **Edit** again, check the price box, and Save.
- **Save says another waiting change already covers this.** The player has a second waiting change for the same trait or field. Review or refuse that one first.
- **The price box is greyed out in the pop-up.** You picked another section. Save the move first, then open **Edit** again to set the price.
- **I don't see this tab at all.** You don't hold a Storyteller role in the chronicle currently selected - switch chronicles, or ask an HST/AST to check your role.

## Related

- [Import](import.md)
- [Send Sheet](transfer.md)
- [Character Editor](character-editor.md)
- [Character Sheet](character-sheet.md)
- [Approval Rules](approval-rules.md)
- [How Approval Works](approval-flow.md)
- [Secrets](secrets.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#4-running-the-approval-queue)
