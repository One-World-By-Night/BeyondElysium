# Change History

The full record of every change ever sent for a character - applied, approved, refused, withdrawn, undone and still waiting - newest first, grouped by what was sent together.

## Who can use this

Anyone who can open the character's sheet - a player for their own character, Storytellers (HST and AST) for any character in the chronicle.

## How to get there

Character → **History** view. The same content also appears inline on the sheet whenever **XP History** is checked in the **Print / Export** panel - see [Print / Export](sheet-print-export.md).

## The screen

- **Close** - puts the history away.
- **All, Purchases, Play** - a row of filters. Purchases are the changes that cost XP, and Play is what happens at the table: temporary points spent and regained, and XP awards. A filter with nothing in it says "Nothing of that kind yet."
- The list itself, newest first, in **orders**. Everything sent in one Submit is one order, headed "Sent 25 Nov · 5 changes · 6 XP", with a line under it such as "2 applied · 3 waiting". A change made on its own is its own entry. Each line shows:
  - **Status** - Waiting for a Storyteller, Applied, Approved, Refused, Withdrawn, Undone, or Forwarded home. Applied is a change that went straight onto the sheet; Approved is one a Storyteller decided. A change nobody answered in 60 days reads Refused, with "No answer in 60 days".
  - A plain description of the change, such as "Celerity 2 → 3," "Added Underworld Lore," or "+5 XP (session attendance)."
  - Its **XP** cost, when the change has one. A homebrew purchase still waiting for a price reads **Price set on approval**.
  - A **Catalog update** line, one per character, when an upgrade moves a site onto each creature type's own lists. Open **Matched entries** under it to see each custom entry that became a catalog item, as "what it was → what it is". An upgrade that moves a Blood Magic pick out of Disciplines says how many repeated rows it dropped because the sheet already held them, and lists each one, with any pick it kept that disagrees with a held row on level. See [Moving an Older Site to the Per-Creature Lists](../admin-guide.md#moving-an-older-site-to-the-per-creature-lists).
  - When it was submitted, and, once reviewed, when.
  - The submitter's own note, if there is one.
  - The Storyteller's note, labeled "Storyteller: ...," if there is one - kept separate from
    the submitter's note, never replacing it.
  - "Changed by a Storyteller" when a Storyteller edited the request before deciding it.
  - **Withdraw**, on a line that is still waiting, for the player (see below).
  - **Undo**, on a line a Storyteller can take back (see below), and "Undone by [name] on [date]" once it has been.
- **Withdraw all waiting** and **Undo this set** on an order's head, when more than one of its lines can be withdrawn or undone.
- "No changes yet." when the character has no history at all.

Under the character's name, a line says where the XP stands: "10 XP to spend · 6 XP held · 3 changes waiting". XP held is what the changes still waiting would cost, set aside so it cannot be spent twice.

## Common tasks

### Check whether a change is still waiting

1. Open the character's Sheet.
2. Click **History** in the row of views.
3. Find the change - **Waiting for a Storyteller** means it's still waiting; **Applied**, **Approved**, **Refused**, **Withdrawn** or **Undone** means it's settled.

### Withdraw a change that is still waiting

1. Open the **History** view and find the line marked **Waiting for a Storyteller**.
2. Click **Withdraw**. The line reads **Withdrawn**, the XP held for it is free to spend again, and nothing about it reaches the sheet. **Withdraw all waiting** does the same for every waiting line of one order.
3. A change already decided cannot be withdrawn; a Storyteller can undo it instead.

Anyone who can edit the character can withdraw its waiting changes: the player, and a Storyteller.

### Undo a change that was approved (Storytellers)

1. Open the **History** view and find the line. **Undo** shows on a line that was applied or approved and that can be taken back: a trait added, removed or changed, a pool or identity change, a spend from a pool, or an XP award or correction.
2. Click **Undo**. The question names the change and the XP: "Undo Occult x4? 6 XP goes back to Alice." Add a **Note to the player (optional)** of up to 1,000 characters and confirm.
3. The XP goes back, the sheet is put back as it was, and the line reads "Undone by [you] on [date]". The player gets a notice.
4. **Undo this set** undoes every line of an order that can be undone, newest first, or none of them.

Undo refuses rather than guess. "That XP has been spent. Undo what it bought first." means the XP of an award was spent since. "Undo the later change first: ..." and "[name] has changed since." mean something later touched the same part of the sheet. A sheet kept current from another chronicle says "This sheet is kept current from the character's home chronicle. Change the kept-current copy there too." Nothing is repriced: XP goes back as it was paid.

### Read a Storyteller's note on a change

1. Open the **History** view.
2. Look under the change for a line starting "Storyteller:".

### Read history alongside a printed or on-screen sheet

1. Pick **Print / Export**, click **Go**, and check **XP History**.

## Things to know

- This is the complete record - applied, approved, refused, withdrawn and undone changes stay here, not just the waiting ones.
- A refused or withdrawn change never touched the sheet. An undone one touched it, and the sheet is put back.
- This list shows what happened to a change, not why it needed Storyteller review in the first place - a Storyteller sees that reasoning while it's still in the [Approval Queue](approval-queue.md).
- The submitter's note and a Storyteller's reviewing note are always kept separate here, even on the same change.
- A withdrawn change cannot be brought back; add it to the cart again.

## Troubleshooting

- **"No changes yet," even though I've made edits.** Nothing has been submitted for this character yet, or you're looking at a different character than you expected. Changes still in your cart are not here until you send them.
- **"Failed to load change history."** Refresh the page.
- **A change I remember submitting isn't here.** Each character keeps its own separate history - make sure you're on the right one.
- **There is no Undo on a line.** It is still waiting (withdraw it instead), it was refused or withdrawn, it is a kind of change that cannot be taken back, or you are not a Storyteller.
- **"The change could not be undone."** Read the line under the button; it says what changed since. Undo that first.

## Related

- [Character Sheet](character-sheet.md)
- [Character Editor](character-editor.md)
- [Print / Export](sheet-print-export.md)
- [Approval Queue](approval-queue.md)
- [How Approval Works](approval-flow.md)
- [Player Guide](../player-guide.md#2-editing-your-sheet)
- [Storyteller Guide](../st-guide.md#4-running-the-approval-queue)
