# Downtime

See a game date's downtime window and every action plot still waiting on an answer.

## Who can use this

Anyone holding Plots & Rumors access (HST, AST, Narrator). A player never sees this screen - they see their own downtime window on their own action form instead.

## How to get there

Storyteller Toolkit → Downtime tab.

## The screen

- **Game date** - a dropdown of every game night that exists (see [Game Nights](game-nights.md)). Picking one loads that date's queue below. A badge next to it shows the deadline, relative to now ("closes in 3 days", "closed 2 days ago").
- **Unanswered / All** - narrows the list to action plots with no answer yet, or shows every one for the date.
- The queue itself: one row per character with an action plot for that date - their name, their player, how many actions they submitted, whether it's been answered, the answer's release state (Draft, Scheduled, Released, or Posted for one sent immediately), and their own downtime window state (Open, Not open yet, Closed, or No window). Click a row to open that plot's thread directly, where you can write or edit the answer.

## Common tasks

### Find who hasn't been answered yet

1. Open Storyteller Toolkit → Downtime.
2. Pick the game date.
3. Leave the filter on **Unanswered** - it's the default.

### Answer an action

1. Click a row in the queue.
2. This opens the plot's thread. Write your response there - it's held by default (see [Releases](release-batches.md)) unless you explicitly send it immediately.

### Extend one character's deadline

1. There is no button for this yet on this screen - ask an HST or AST to set it via `POST /sessions/{id}/downtime-extensions` directly, or wait for a future pass to add one here.

## Things to know

- **A window only exists when its session has an open time, a deadline, or both.** A game date with neither is shown as "No window" and nothing is enforced for it - the same behavior as before this feature existed.
- **A character's own extension only ever replaces their deadline**, never the open time, and only for that one character.
- **The window never waits for a background job.** The instant a deadline passes, the action form for that character closes - whether or not anyone has refreshed a page.
- **Each row has its own assignee picker** - who owns following up on that character's downtime. Assigning yourself is what makes it show up under [My Queue](my-queue.md). There is no "assigned to me" quick filter on this screen yet - check My Queue for that view.
- On a narrow screen each row stacks into a card instead of scrolling sideways.

## Troubleshooting

- **"Failed to load the downtime queue."** Refresh and try again.
- **"No game sessions exist yet - create one under Game Nights first."** Downtime is always tied to a real game night's date - schedule one first.
- **I don't see this tab at all.** You don't hold Plots & Rumors access in the chronicle currently selected.

## Related

- [Game Nights](game-nights.md)
- [Plots & Rumors](plot-manager.md)
- [Releases](release-batches.md)
- [Allocate Actions](allocate-actions.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [My Queue](my-queue.md)
- [Storyteller Guide](../st-guide.md#8-plots-actions-and-rumors)
