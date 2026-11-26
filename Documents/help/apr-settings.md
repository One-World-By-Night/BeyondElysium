# Action & Rumor Settings

Sets how many downtime actions a character gets on each game date, and which categories of rumor your chronicle generates automatically.

## Who can use this

Storytellers (HST and AST). A Narrator, the chronicle's Harpy, and a player never see this tab.

## How to get there

wp-admin sidebar → Beyond Elysium → Chronicle Setup → Action & Rumor Settings tab.

## The screen

- **Chronicle** - a dropdown of every chronicle on the install.
- A note that every value shown is Beyond Elysium's own default unless this chronicle has changed it.
- Two tabs, **Actions** and **Rumors**.

### Actions tab

- **Personal actions per character** - a number, 0-100. Every character gets this many actions no matter what they hold.
- **Copy Unused Values from Previous Action** - carries an unused budget forward from a character's most recent earlier allocation.
- **Always add Common Actions** - checkbox.
- **Actions per level** - a table (Rating, Actions granted, and a **Remove** button) listing only the ratings you've overridden; any rating with no row here uses the plugin's own default of 2 actions per dot. **+ Add a level** asks which rating (1-20) and adds it at 0, ready to edit.
- **Backgrounds that grant an action** - every Background and Influence in this chronicle's own catalog, each showing which creature stacks it belongs to. An Influence is always checked and disabled - it always grants an action. A Background grants one only when you check it here.

### Rumors tab

Eight checkboxes: **Public rumors**, **Personal rumors**, **Race rumors**, **Group rumors (Clan, Tribe, Kith, Tradition…)**, **Subgroup rumors (Sect, Auspice, Seeming, Guild…)**, **Influence rumors**, **Carry forward previous rumors**, **Copy previous rumor descriptions**.

### Actions common to both tabs

- **Save** - writes every tab's changes together, whichever tab you're currently looking at.
- **Restore Grapevine defaults** - after one confirmation, resets personal actions, the two action checkboxes, the actions-per-level table, and the background list to Grapevine's own original 1998 values. Every rumor toggle is left exactly as it is. This only changes what's on screen - click **Save** afterward to keep it.

## Common tasks

### Change how many personal actions a character gets

1. Open the **Actions** tab.
2. Set **Personal actions per character**.
3. Click **Save**.

### Override the actions granted at a specific rating

1. On the **Actions** tab, click **+ Add a level**.
2. Type the rating (1-20).
3. Set its **Actions granted**.
4. Click **Save**.

### Let a Background grant an action

1. Find it under **Backgrounds that grant an action**.
2. Check it.
3. Click **Save**.

### Turn a category of rumor on or off

1. Open the **Rumors** tab.
2. Check or uncheck the category.
3. Click **Save**.

### Reset to Grapevine's original numbers

1. Click **Restore Grapevine defaults**.
2. Confirm.
3. Click **Save** to keep the change.

## Things to know

- **Nothing here saves until you click Save.** Switching tabs, or clicking Restore Grapevine defaults, only changes what's on screen.
- **Restore Grapevine defaults never touches the Rumors tab** - only the Actions tab's numbers and background list.
- **An Influence always grants an action.** The checklist shows it checked and disabled as a reminder, not as something you can turn off.
- **A rating with no row in Actions per level just uses the default of 2 actions per dot** - you only need a row here for a rating you want to be different.
- **A background name has to be real** - only a name in this chronicle's own catalog is accepted, never a custom or misspelled one.
- **Group and subgroup rumors read a character's own identity fields.** Group is Clan, Tribe, Kith, Tradition, or the equivalent on other creature types; subgroup is Sect, Auspice, Seeming, Guild, or the equivalent.
- **These settings feed [Allocate Actions](allocate-actions.md) and [Rumors](rumors.md) directly.** A change here changes what those tools compute the next time they run - never anything already committed.

## Troubleshooting

- **"personal_actions must be between 0 and 100."** Pick a number in that range.
- **"actions_per_level keys must be levels 1 through 20." / "...values must be between 0 and 999."** Check the rating and the number you typed.
- **'"X" is not a background or influence name in this chronicle's catalog.'** Only the names this screen itself lists can be checked - reload and try again.
- **I don't see this tab.** You need a Storyteller role in the chronicle currently selected.
- **A row I added to Actions per level disappeared.** Remove deletes that row - the rating just falls back to the default of 2 the next time you save. Add it back with **+ Add a level** if that wasn't what you meant.

## Related

- [Chronicle Setup](chronicle-setup.md)
- [Allocate Actions](allocate-actions.md)
- [Rumors](rumors.md)
- [Background Uses](background-uses.md)
- [AI Assist Settings (Chronicle)](writing-assist-chronicle.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#9-action--rumor-settings-and-the-background-use-ledger)
