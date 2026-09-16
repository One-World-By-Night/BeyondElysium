# Background Uses

The record of what a character did with a budgeted background action on a given game date -
what was recorded, what a Storyteller decided came of it, and what's left of the budget.

## Who can use this

Anyone who can open a character's sheet can open its background-use ledger for that same
character - a player for their own, Storytellers (HST and AST) for any character in the
chronicle. Recording a use for your own character needs nothing beyond being its player.
Filling in what came of a use - the Result - is Storyteller-only, even on your own character.
The two "Clear all" operations are Storyteller-only as well.

## How to get there

- Character Sheet → pick **Background uses** in the actions list and click **Go**.
- Also shown automatically inside Storyteller Toolkit → Plots & Rumors → **Allocate actions**,
  right below the table, once a Storyteller commits an allocation for a character and date.
  See [Allocate Actions](allocate-actions.md).

## The screen

- **Game date** - shown as its own field above the ledger only when you open this from the
  Character Sheet; it defaults to today's date, and changing it reloads the ledger for
  that date. Inside Allocate Actions the ledger already knows the date just allocated, so there
  is no separate picker.
- One block per background the character currently has budgeted (an Influence, or a Background
  your chronicle's Action & Rumor Settings has set to grant one), each showing:
  - How much is left - **Budget: N** from the Character Sheet's own entry point, or
    **Spent X / Y** (flagged **(over budget)** in red once uses exceed it) right after a
    Storyteller commits an allocation inside Allocate Actions.
  - Every use already recorded for that background on the date shown, each with its own text
    and, once a Storyteller has filled one in, its result.
  - A text box ("What did they do?") and **Record a use** button.
- **Personal** - the action every character gets regardless of what backgrounds they hold.
  It appears as a budgeted block once a Storyteller has allocated the character's actions,
  from the Character Sheet and inside Allocate Actions alike.
- **Other backgrounds** - every other background the character holds, each with the note "No
  action budget - set this background under Action & Rumor Settings," and its own text box and
  Record a use button.
- **Clear all for this Character** / **Clear all for this Date** (Storyteller only) - each opens
  a confirmation naming what it will remove before doing anything.

## Common tasks

### Record what your character did

1. Open the character's Sheet, pick **Background uses**, and click **Go**.
2. Pick the **Game date**.
3. Find the background you used, or look under **Other backgrounds**.
4. Type what happened.
5. Click **Record a use**.

### Fill in the result of a use (Storyteller)

1. Open **Background uses** for the character.
2. Find the use under its background.
3. Type into its **Result…** box.

### Clear a use before it's adjudicated

1. Open **Background uses**.
2. Find your use - it only shows a **Clear** button while no result has been recorded against
   it.
3. Click **Clear**.

### Clear every use for a character or a date (Storyteller)

1. Open **Background uses** for any character in the chronicle.
2. Click **Clear all for this Character** or **Clear all for this Date**.
3. Confirm.

## Things to know

- **Recording never waits on a budget.** Even a background your chronicle hasn't configured to
  grant an action can have a use recorded against it - it just shows under "Other backgrounds"
  with a note explaining why.
- **Once a Storyteller fills in a result, a use locks.** You can no longer clear or edit it
  yourself.
- **Clearing removes the record, not the budget.** Clear all for this Character/Date deletes
  recorded uses only - it never touches what a background's allocation actually granted.
- **Budget is live, not a historical snapshot.** The number shown always reflects the
  character's most recent allocation, even if you've picked an older game date to look at.
- **Each use counts as one**, no matter what it describes - there's no way from this screen to
  record a use worth more than one against the budget.

## Troubleshooting

- **"Failed to load the background ledger."** Refresh and try again.
- **"The background use could not be saved."** Nothing was recorded - try again.
- **I don't see Personal.** No actions have been allocated for this character yet - ask a
  Storyteller to run Allocate Actions.
- **I don't see a background I know my character holds.** It shows under "Other backgrounds" if
  your chronicle hasn't configured it to grant an action yet - ask a Storyteller.
- **I can't clear my own use.** A Storyteller has already recorded a result against it, so it's
  locked.
- **I don't see Clear all for this Character/Date.** Those are Storyteller-only.

## Related

- [Character Sheet](character-sheet.md)
- [Allocate Actions](allocate-actions.md)
- [Action & Rumor Settings](apr-settings.md)
- [Player Guide](../player-guide.md#6-recording-background-uses)
- [Storyteller Guide](../st-guide.md#9-action--rumor-settings-and-the-background-use-ledger)
