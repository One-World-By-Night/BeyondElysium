# Boon Ledger

Tracks who owes a boon to whom across the chronicle - record a new one, and mark one repaid
once it's settled.

## Who can use this

Storytellers (HST and AST) and the chronicle's Harpy - the role built specifically to run
boons - can record and repay them here. The **Boon Ledger** tab itself only shows in the
Storyteller Toolkit for someone who holds one of these roles in the chronicle currently
selected. A Narrator and a plain player never see this tab. A site builder can also put the
Boon Ledger widget on a page (see [Elementor Widgets](elementor-widgets.md)), where any member
of the chronicle can read it - without the record and repay controls.

## How to get there

Storyteller Toolkit → Boon Ledger tab.

## The screen

- **Record a boon** - toggles the create form below it.
- The create form:
  - **Owed by (character ID)** and **Owed to (character ID)** - number fields. Type each
    character's numeric ID directly; there's no name picker here.
  - **Level** - free text, with `trivial`, `minor`, `major`, and `life` offered as
    suggestions as you type. Any wording you want is accepted.
  - **Terms** (optional).
  - **Record** - creates the boon, dated today.
- The ledger table (stacked into cards on a narrow screen): **Owed By**, **Owed To**,
  **Level**, **Date**, **Status**, **Terms**, and an **Actions** column.
  - A repaid row is styled differently from an outstanding one, and shows a settling note
    next to "repaid" if one was recorded.
  - **Mark repaid** on an outstanding row opens an inline **How was it settled? (optional)**
    box with **Confirm** and **Cancel**.
- "None." in place of the table when there's nothing to show.

## Common tasks

### Record a boon

1. Open Storyteller Toolkit → Boon Ledger.
2. Click **Record a boon**.
3. Type the debtor's and creditor's character IDs under **Owed by** and **Owed to**.
4. Type or pick a **Level**, and optionally add **Terms**.
5. Click **Record**.

### Mark a boon repaid

1. Find the outstanding boon in the table.
2. Click **Mark repaid**.
3. Optionally note how it was actually settled.
4. Click **Confirm**.

### Find a boon in a long list

1. This screen has no search or filter of its own - use your browser's own page search
   (Ctrl/Cmd+F) to jump to a name.

## Things to know

- **Two roles record and repay.** Storytellers and this chronicle's Harpy are the only people
  who change boons. A Boon Ledger widget placed on a page lets members read them; given a
  character, it splits that character's boons into **Boons I Owe** and **Boons Owed to Me**.
- **No search, filter, or sort here.** The table simply lists every boon, outstanding ones
  first, each group newest first.
- **A boon's date is always today** when you record it - this form has no way to enter a
  different date.
- **Once recorded, a boon can only be repaid, never edited or deleted.** Its parties, level,
  and terms have no edit control anywhere in the interface, and there's no way to undo a
  repayment from this screen.
- **Terms can carry Storyteller-only text**, hidden from anyone who can't manage boons - but
  since only Storytellers and the Harpy ever open this screen, you'll always see it in full
  here.
- **You need each character's numeric ID to record a boon.** Open their Sheet from the
  Characters tab first and read it out of the address bar, or ask whoever manages the
  chronicle's characters.

## Troubleshooting

- **"Failed to load the ledger."** Refresh and try again.
- **"Failed to record this boon - check that both characters exist and are different."** A
  boon can't be owed to oneself, and both IDs must belong to real characters in this
  chronicle.
- **"Failed to mark this boon repaid."** Try again.
- **A boon I recorded is missing.** Check you're looking at the right chronicle - the ledger
  is scoped to whichever one is selected at the top of the Toolkit. If it's genuinely gone,
  its link to one of the two characters was likely removed elsewhere (see
  [Connections](connections.md)) - it can't be repaired from here; record it again.
- **I don't see this tab.** You're not a Storyteller or this chronicle's Harpy in the
  chronicle currently selected - check the **Chronicle** dropdown, or ask an HST/AST.

## Related

- [Storyteller Toolkit](storyteller-toolkit.md)
- [Connections](connections.md)
- [Roles](roles.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Storyteller Guide](../st-guide.md#3-making-characters)
