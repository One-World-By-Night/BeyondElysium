# Releases

Schedule rumors and downtime answers to go out together, several between games, instead of the instant a Storyteller writes them.

## Who can use this

Anyone holding Plots & Rumors access (HST, AST, Narrator). A player never sees this screen - they only ever see the plots and posts it releases, once released.

## How to get there

Storyteller Toolkit → Releases tab, or wp-admin → Beyond Elysium → Plots → Releases tab.

## The screen

### Batch list (the default view)

- **+ New batch** - opens a form: a name (required) and an optional release date/time. Leave the date blank to save it as a **draft**; set one and it becomes **scheduled**.
- Three lists - **Scheduled**, **Draft**, and **Released** - each showing a batch's name, its release time or a "Released" badge, and how many rumors and downtime answers it holds. Click one to open it.

### Release schedule

- A collapsible panel above the batch list. Click **Release schedule (N)** to open it.
- **+ Add a weekly rule** - fires on a weekday and time you pick (defaults to Friday, 18:00).
- **+ Add a monthly rule** - fires on a day of the month (1-28) and time you pick.
- Add as many rules as you want, in any combination. Remove one with its own **Remove** button.
- The schedule controls *when*, never *what*: on the day and time a rule names, every batch you've left in **Draft** for this chronicle is released as-is - the same as clicking **Release now** on each of them yourself, just automatic. It never creates a batch for you. If nothing is sitting in Draft when a rule fires, nothing happens - no empty batch, no email, no error.
- If more than one rule is due the same day, your draft batches still only go out once each - the rules don't multiply anything.

### A single batch (detail view)

- **All batches** - a link back to the lists.
- **Delete batch** (draft only) - removes the batch; its items return to draft, hidden until added to another batch.
- **Unschedule (back to draft)** (scheduled only, while not yet due) - clears the release time and returns the batch to draft.
- **Release now** - releases the batch immediately, regardless of any scheduled time. Asks you to confirm first: players reached by this batch's items will see them and get an email.
- **Rumors** and **Downtime answers** - the batch's held plots and entries. Each row can be moved to another open batch, or removed (returned to draft) with its own button.

## Common tasks

### Hold a rumor for a later release

1. Open the plot (rumor) you want to hold, and add it to a batch from there (see [Plots & Rumors](plot-manager.md)).
2. Open Storyteller Toolkit → Releases and find the batch you added it to.
3. Set a release date/time, or leave it draft until you're ready.

### Release everything on schedule

1. Create a batch and set its release date/time - it moves to **Scheduled**.
2. Nothing else to do: the moment that time passes, everyone it reaches can see it, and each player gets one summary email naming their own characters and how much is new. This happens whether or not anyone has opened the site in the meantime.

### Release on a recurring schedule without a specific date

1. Open **Release schedule** and add a weekly or monthly rule (or both).
2. Whenever you're ready during the week, prepare a batch as a **draft** - add rumors and downtime answers to it, don't set a release date.
3. On the day and time the rule names, that draft (and any other draft batch for this chronicle) releases automatically. Start a new draft for next time whenever you like.

### Release something right now

1. Open the batch (or add the item to any open batch first) and click **Release now**.
2. Confirm - players will see it and get an email immediately.

### Move an item to a different batch

1. Open the batch that currently holds it.
2. Use the dropdown next to the item to pick another open (not yet released) batch.

## Things to know

- **A draft is never visible to a player**, no matter what its own audience says - held with no batch (or a batch not yet released) always means "not yet."
- **Visibility never waits for a page refresh or a scheduled job.** The instant a batch's release time passes, anyone who can already see that content by its own audience sees it - whether or not the site's background sweep has run yet.
- **One email per player per batch.** A player with several characters reached by the same batch gets a single email naming all of them, not one per character or per item.
- **The email names no content.** It says who it's for, which chronicle, and how much is new (rumors, downtime answers), with a link to My Plots & Rumors - never the rumor or answer itself.
- **Released is final.** A released batch can't be deleted, unscheduled, or have items added to it. Removing an item from it returns that one item to draft (hidden again) - it does not un-send the email already sent for it.
- **Deleting or unscheduling a batch never deletes its rumors or answers** - only the batch itself. The items return to draft and can be added to another batch later.
- Secrets are not part of this screen yet - that item hasn't been built.

## Troubleshooting

- **"A scheduled batch needs a release_at."** Pick a date/time before switching a batch to Scheduled, or leave it as Draft.
- **"This batch is already out and can no longer be unscheduled."** Its release time has already passed - players may already have seen it. Use **Release now** on the same schedule to explicitly finish releasing it, or leave it as is.
- **"A released batch is final and can no longer be changed."** / **"...and cannot be deleted."** Released batches are permanent by design - remove individual items instead if something needs to go back to draft.
- **I don't see this tab at all.** You don't hold Plots & Rumors access in the chronicle currently selected.

## Related

- [Plots & Rumors](plot-manager.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [Game Nights](game-nights.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#8-plots-actions-and-rumors)
