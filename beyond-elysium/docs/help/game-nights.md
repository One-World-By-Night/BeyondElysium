# Game Nights

Schedule a chronicle's game nights, sign in who showed up, and award attendance XP once the roster is settled.

## Who can use this

Storytellers (HST and AST) and Narrators - a Narrator often runs the door at a game, so this tab is open to them too, unlike most of the Storyteller Toolkit. A player never sees this screen.

## How to get there

Storyteller Toolkit → Game Nights tab.

## The screen

### Session list (the default view)

- **Spotlight** - shown only to an HST or AST. Toggles a list of every active character with their last attended date, active plot count, last staff post, and whether they're **Flagged** for going too long without one. See **Spotlight** below.
- **Session settings** - Attendance XP, Report XP, and Spotlight days, shown only to an HST or AST. **Save settings** persists them; they set the *default* amount **Award attendance XP**/**Award report XP** offer below, not a cap, and how many days without a staff post before a character is flagged on Spotlight.
- **+ New session** - opens a form: a date (required, must be unique in this chronicle), a start time, and a place. **Create session** saves it and opens its detail view directly; **Cancel** closes the form without saving.
- A list of sessions, soonest-scheduled first, each showing its date, time, place, and an "XP awarded" and/or "Report XP awarded" badge once either has been given out. Click one to open it.

### Spotlight

Every active, non-NPC character's own attention profile, chronicle-wide - not tied to any one session. A character is **Flagged** when no Storyteller or Narrator has posted anything but a private note on any of their plots (their own action rounds included) within the chronicle's own Spotlight days setting (42 by default) - or never has at all. Flagged characters list first, then everyone else by how long it's been since their last staff post. See [After-Game Report](after-game-report.md) for the player-facing half of keeping a character attended to.

### A single session (detail view)

- **All sessions** - a link back to the list.
- **Sign-in** - every active, non-NPC character in the chronicle with their player's name and a checkbox; check one to sign that character in, uncheck to remove them. A running count of who's currently signed in. Below the roster, add a visitor by name (and, optionally, their home chronicle) who isn't one of your own characters - each shows with a **Remove** button of its own.
- **Award attendance XP** - awards the configured amount to everyone currently signed in, with a reason recording the session's date. Disabled once used for this session; **Award again anyway** appears afterward if you really need to run it a second time.
- **Delete session** - only allowed while the session has no attendance, NPC casting, or after-game report recorded yet; once any of those exists, change its date instead of deleting it.
- **Reports**, shown only to a Storyteller with Character management access - every after-game report filed for this session, each with a **Mark read** button (or a **Read** badge once you have) and **Award report XP**, working exactly like Attendance XP but keyed off who filed a report rather than who signed in. You never edit a report's own words - see [After-Game Report](after-game-report.md).
- **Cast NPCs**, shown only to a Storyteller with Character management access - cast any chronicle member (not staff only) to play any NPC for this session, with an optional note just for this game. Each casting lists who's playing what, a **Print brief** link, and a **Remove** button. See [NPC Casting Brief](npc-casting.md) for what the cast member reads.

## Common tasks

### Schedule a game night

1. Open Storyteller Toolkit → Game Nights.
2. Click **+ New session**.
3. Pick a date, and optionally a time and place.
4. Click **Create session**.

### Sign in who showed up

1. Open the session for tonight (create one first if it doesn't exist yet).
2. Check off each character present under **Sign-in**.
3. Add anyone visiting from another chronicle by name.

### Award attendance XP

1. Open the session, with everyone who attended already signed in.
2. Click **Award attendance XP**.

### Read after-game reports and award report XP

1. Open the session.
2. Under **Reports**, click **Mark read** on each one as you read it.
3. Once everyone who's going to file one has, click **Award report XP**.

### Check who needs attention

1. Open Storyteller Toolkit → Game Nights, with no session selected.
2. Click **Spotlight**.
3. Start with the **Flagged** characters at the top.

### Change a game night's date

1. Delete the wrong session, if nothing has signed in yet, and create a new one with the right date - there is no separate "edit" form on this screen yet.

## Things to know

- **One session per date.** A chronicle can't have two sessions on the same calendar date; creating a second one on an existing date is refused.
- **A session with any attendance, NPC casting, or after-game report can't be deleted.** This protects the record once real data exists against it - change its date instead.
- **Attendance XP and Report XP are each a one-time action per session**, not a recurring award - both are guarded against being given out twice by accident, with an explicit override if you really mean to.
- **A staff post on a character's own action-round plot still counts for Spotlight** - the design deliberately doesn't exclude those the way **Active Plots** does elsewhere; a Storyteller responding to a downtime action is real attention.
- **Spotlight and the dashboard's "Characters Needing Attention" count are always the same number** - both are computed the identical way, so neither can quietly drift from the other.
- **A visitor is not a character.** Recording one only tracks that they attended - it creates no character, connection, or XP award of its own.
- **Session settings apply chronicle-wide**, not per session - changing Attendance XP after a session already exists only affects awards made from that point on.
- On a narrow screen the session list stacks into single-column cards instead of scrolling sideways.

## Troubleshooting

- **"Failed to load game sessions."** Refresh and try again.
- **"A session already exists on this date."** Open that session instead of creating a new one.
- **"This session already has attendance, an NPC casting, or a report recorded - change its date instead of deleting it."** Edit isn't available yet for the date itself; if the date is genuinely wrong, ask an administrator to correct it directly.
- **"Attendance XP has already been awarded for this session." / "Report XP has already been awarded for this session."** Use **Award again anyway** if you deliberately want to run it a second time.
- **"Failed to load the spotlight check."** Refresh and try again.
- **I don't see this tab at all.** You don't hold a Storyteller or Narrator role in the chronicle currently selected - switch chronicles, or ask an HST/AST to check your role.

## Related

- [Storyteller Toolkit](storyteller-toolkit.md)
- [Plots & Rumors](plot-manager.md)
- [Releases](release-batches.md)
- [Downtime](downtime-queue.md)
- [Action & Rumor Settings](apr-settings.md)
- [NPC Casting Brief](npc-casting.md)
- [After-Game Report](after-game-report.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#8-plots-actions-and-rumors)
