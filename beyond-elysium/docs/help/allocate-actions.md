# Allocate Actions

Turns a character's backgrounds into a game-night action budget - a Personal allowance plus one subaction per qualifying Influence or Background - and saves it as a plot.

## Who can use this

Storytellers (HST and AST). A Narrator also reaches this tool through the same Plots & Rumors access, but its character picker only ever lists characters that belong to the person using it - for a Narrator, that means their own character, if they have one in this chronicle, not the roster. A player never opens this tool directly; once you commit an allocation, that player sees their own budget on their character sheet's [Background Uses](background-uses.md) panel and posts to the plot itself from [My Plots & Rumors](my-plots.md).

## How to get there

Storyteller Toolkit → Plots & Rumors → **Allocate actions** button (on the plot grid), or **+ Action** from inside an open plot, which pre-fills that plot as the parent.

## The screen

- **Select a character…** - every character in the chronicle.
- A **game date** field.
- A parent-plot dropdown, offering only top-level plots, or "No parent plot".
- **Preview** - computes the allocation without saving anything.
- Once previewed, a table (stacked into cards on a narrow screen):

  | Column | Shows |
  | --- | --- |
  | Subaction | `Personal`, or the Influence/Background it's built from |
  | Level | The character's rating in that Influence or Background (`0` for Personal) |
  | Total | This subaction's action-point budget for the date |
  | Unused | What's left after any Background Uses already recorded for this date |
  | Growth | Any bonus carried forward from a previous allocation |

  A row is flagged (over budget) once its recorded uses exceed its total.
- **Commit** - saves the allocation as a plot. Once committed, a "Committed as plot #N" note appears, and the character's [Background Uses](background-uses.md) ledger for that same date opens right below the table, ready to record what happened.

## Common tasks

### Allocate a character's actions for a game date

1. Open Storyteller Toolkit → Plots & Rumors → **Allocate actions**.
2. Pick the character and the **game date**.
3. Optionally pick a parent plot.
4. Click **Preview**.
5. Check the subaction table.
6. Click **Commit**.

### Re-run an allocation after a character's backgrounds change

1. Open **Allocate actions** for the same character and the same game date.
2. Click **Preview**, then **Commit** again.

### Nest an allocation under an existing plot

1. Open the plot first.
2. Click **+ Action** in its action bar - this opens Allocate actions with that plot already picked as the parent.

## Things to know

- **Every character always gets a Personal subaction**, sized by your chronicle's Personal actions setting, regardless of what they hold.
- **An Influence or a configured Background adds one more subaction**, sized at twice the character's rating in it, unless your chronicle's Action & Rumor Settings overrides the total for that exact rating. See [Action & Rumor Settings](apr-settings.md).
- **Unused actions and growth only carry forward from a character's most recent earlier allocation if your chronicle's settings allow it** - otherwise every date starts fresh, though growth already earned still carries forward regardless.
- **Committing again for the same character and date updates that same plot** - it never creates a second one, and it never erases a player's own posts or anything already recorded in Background Uses for that date.
- **A date's actions sit under the character's own plot** unless you pick another parent. Every character has one, named `<Character> [id] Plot`.
- **A parent plot only takes effect the first time you commit** for a character and date; committing again later never moves the plot, even if you pick a different parent.
- **This tool computes a budget - it doesn't touch XP or the character's sheet.**
- **A player can only post actions while that date's downtime window is open.** See [Downtime](downtime-queue.md) - this only applies when the game date's own session has an open time or deadline set at all.
- On a narrow screen the subaction table stacks into cards instead of scrolling sideways.

## Troubleshooting

- **Preview won't click.** Pick both a character and a game date first.
- **"Failed to compute this allocation." / "Failed to commit this allocation."** Try again. A commit that fails saves nothing - no plot, no actions - so trying again is safe.
- **A subaction shows 0 total.** Check the character actually holds that Influence or Background, and that your chronicle's Action & Rumor Settings lists it under configured Backgrounds - an Influence always qualifies on its own.
- **The character list is empty or missing someone.** If you're a Narrator, this list only shows your own character, not the roster - ask an HST or AST to run this allocation instead.
- **I don't see this button.** You need a Storyteller or Narrator role in the chronicle currently selected.

## Related

- [Plots & Rumors](plot-manager.md)
- [Background Uses](background-uses.md)
- [Downtime](downtime-queue.md)
- [Action & Rumor Settings](apr-settings.md)
- [My Plots & Rumors](my-plots.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#9-action--rumor-settings-and-the-background-use-ledger)
