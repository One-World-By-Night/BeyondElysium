# Character Editor

Where you build a new character and change an existing one. Every trait, power, pool, and identity field on a sheet passes through this screen before it reaches the sheet itself.

## Who can use this

Players can create their own characters and edit their own. Storytellers (HST and AST) can create a character for anyone and edit any character in the chronicle - including fields a player's own copy of this screen never shows: status, the assigned narrator, and the Storyteller's private notes. A Narrator or the chronicle's Harpy who isn't also a member with player access can't create or edit a character here at all.

You can only open this screen for a character you own, unless you're a Storyteller. Anyone else gets an error, not a locked-down view.

## How to get there

My Chronicle → Edit tab.

- With a character already picked - from the Characters tab, or the "Edit this character" link on that character's Sheet - this opens straight into editing it.
- With no character picked yet, this opens a blank form to start a new one.

A Storyteller creating a character from wp-admin's Characters page lands on this same screen.

## The screen

### New Character (no character picked yet)

- **Name** - required.
- **Creature Type** - a dropdown of this chronicle's allowed creature types. Skipped if you arrived here already set to build a specific type.
- **This is an NPC** - a checkbox, shown only to a Storyteller. Checking it switches the form to the richer NPC layout, which adds a Storyteller-only section for voice, mannerisms, and plot hooks, and reveals a second choice:
  - **Full sheet** - the complete NPC layout, same as above.
  - **Quick stats only** - a short layout with just enough (Physical/Social/Mental,
    Willpower, Health, key Abilities/Powers/Equipment) to run this NPC in a scene without
    building out a whole character. See **Make Full NPC** below for upgrading one later.
- The stack's own sections below, exactly like the sheet sections described below - fill in whatever you want the character to start with.
- **Create Character** - writes the character. Nothing you build here is priced or reviewed the way a later change is: whatever you put on the starting sheet is what the character gets.

If you're already a member of this chronicle, the character exists right away (or starts **pending**, if the chronicle requires new-character approval, until a Storyteller sets it active). If you aren't a member of this chronicle yet, this is instead a request to join: you see a "Request Sent" notice, the character waits with nobody able to open its sheet, and you become a player here only once a Storyteller approves it.

### Header (editing an existing character)

- Portrait, with an **Add a portrait…** / **Change portrait…** button.
- The character's name.
- **This is an NPC** - a checkbox, shown only to a Storyteller looking at a character they can manage. Flipping it re-loads the sheet under the other layout immediately.
- **Assigned to** - which Storyteller owns this NPC, shown once it's flagged as one. See [My Queue](my-queue.md).
- **Make Full NPC** - shown only on a Quick NPC. Switches it to the full layout for good; there's no way back to Quick from here.
- A note reading "You can view this sheet but not edit it" in place of every edit control, if you can see this character but can't change it.

### Who's Who Profile (NPCs only)

Shown only to a Storyteller editing an NPC, separate from the sheet above - what a player sees about this NPC in the chronicle's [Who's Who](whos-who.md) directory, not the sheet itself:

- **Display Name** - shown instead of the character's real name in Who's Who, if set.
- **Description** - the write-up a player reads. A `[ST]...[/ST]` marked passage is still stripped out for a non-Storyteller viewer, same as everywhere else.
- A portrait, separate from the sheet's own.
- Who can see this profile at all - the same audience picker a plot or item uses. An NPC with no profile set up simply doesn't appear in Who's Who for anyone but a Storyteller.
- **Save Profile**.

### Secrets (NPCs only)

Shown only to a Storyteller editing an NPC, once it already exists - a write-up kept separate from the NPC's own sheet, with its own audience and reveals to specific characters. See [Secrets](secrets.md).

### Background and Notes

Two rich-text fields, saved independently of everything else on this screen - typing here and clicking **Save Background & Notes** takes effect immediately, with no XP cost and no Storyteller review, regardless of what else is queued below. An **AI Assist** button appears next to each field only for a Storyteller, even on a player's own character; a player types their own text directly.

### Sheet sections

The rest of the screen is the chronicle's own template, one box per section, in the same layout the read-only sheet uses:

- **Trait lists** (Abilities, Backgrounds, Merits, and the like) - each held entry shows as a compact row with an edit button. **+ Add** opens the same form for a new one: choose a name from the catalog or type your own where the section allows it, set a count or level, optionally a specialization (or, for a repeatable entry, a "Who or what?" answer) and a note. **Adding a name you already hold raises that entry instead of starting a second one** - a specialization labels one holding, so a different focus does not buy a second Brawl; only entries the catalog marks as repeatable (two Retainers, two fields of study) get a row each, one per answer. Removing a held entry marks it for removal rather than deleting it outright, so you can still undo it before you submit. See [Trait Lists](trait-editor.md).
- **Powers** (Disciplines, Gifts, Arts, and other leveled catalogs) - add a power, then raise or lower it with a +/− stepper, or switch to a checklist that names every rung up to your current level. **Rated powers and Elder-and-above picks are two separate lists**: the rating is a number on the ladder, a pick is a named power above it, and the two never share a count. Picks sit below the rated powers, grouped by rank. A blood-sorcery power also asks for a tradition. See [Powers](power-editor.md).
- **Resource pools** (Willpower, Blood Pool, Rage, and the like) - a permanent and a temporary row per pool, each with its own +/− stepper and dot display.
- **Identity fields** (Clan, Nature, Generation, and similar named fields) - a dropdown, checkbox group, number box, text box, or text area, depending on the field.

See [Resource Pools & Identity Fields](pools-identity-editor.md) for both of the last two in depth.

### Pending Changes

Everything you change in the sections above queues here instead of touching the sheet immediately:

- A running list, one line per change, naming what changed and, once its price loads, its XP cost and whether it needs Storyteller review. A trait or power that isn't in the catalog reads "Price set by a Storyteller on approval" instead of a cost: it has no price yet, and the total leaves it out until a Storyteller sets one.
- **Total** XP across everything queued, and **Unspent after** - what your XP would be once it's all applied. As a player, this turns into a warning if it would go negative.
- **Submit Changes** - sends every queued change to the server. As a player, this is disabled if it would leave you with negative XP.
- **Discard** - opens a confirmation ("Discard unsaved changes?") before reverting every field to what's on the sheet now and returning you to the character's Sheet.

After submitting, you may see one or both of these:

- A note that some changes saved and some didn't, naming how many of each - review and click **Submit Changes** again to retry only what's still queued.
- A note that some changes were submitted and are awaiting Storyteller approval - they won't appear on the sheet until then.

If you leave with anything queued and unsubmitted, your browser warns you before you navigate away, and your edits are saved to this browser automatically. Next time you open the same character here, a banner offers **Restore** or **Discard** for that saved draft.

## Common tasks

### Create a new character

1. Open My Chronicle and pick your chronicle, if you play in more than one.
2. On the **Characters** tab, click **+ New Character**.
3. Type a **Name** and pick a **Creature Type**.
4. Fill in whichever sections you want the character to start with.
5. Click **Create Character**.

### Edit an existing character

1. Open the character's Sheet and click **Edit this character** (or open the **Edit** tab with that character already selected).
2. Use each section's own control to add, change, or remove a trait, power, pool value, or identity field.
3. Check the running **Total** and **Unspent after** in Pending Changes.
4. Click **Submit Changes**.

### Update Background or Notes

1. Open the character in the **Edit** tab.
2. Type into **Background** or **Notes**.
3. Click **Save Background & Notes**. This saves right away, independent of anything queued in Pending Changes.

### Restore a draft from a previous session

1. Open the character in the **Edit** tab. If you left unsaved changes here before, a banner tells you so and names when they were saved.
2. Click **Restore** to bring them back, or **Discard** to drop them for good.

### Discard changes before submitting

1. In Pending Changes, click **Discard**.
2. Confirm "Discard unsaved changes?" - this reverts every field and returns you to the character's Sheet.

## Things to know

- **Joining vs. creating.** Starting your first character in a chronicle you don't already belong to is a request to join, not an ordinary create: it waits pending, the chronicle's Storytellers are emailed, and a Storyteller setting it active is what makes you a player there. Only one such request can wait per chronicle at a time.
- **Every character gets its own plot.** Creating one - PC or NPC - also creates `<Character> [id] Plot`, which only the character's player and the chronicle's Storytellers see. See [Plot Manager](plot-manager.md).
- **A starting sheet isn't priced.** Unlike every later change, what you build on a brand-new character's sheet is written as-is - no XP cost, no Storyteller review of the individual entries. If your chronicle requires new-character approval, the checkpoint is the character's own status, not what's on the sheet.
- **Health fills in on its own.** A new character's Health section is pre-filled the moment it's created - it isn't something you build yourself.
- **Some fields are Storyteller-only, even on your own character.** Your status, your assigned narrator, and your Storyteller's private notes about you never appear here for a player, and can't be changed here even by you.
- **Numbered powers add up.** Buying a fresh power at level 3 costs levels 1, 2, and 3 together - not level 3 alone.
- **A custom name always needs review.** Typing a name that isn't in the catalog only saves at all where that section allows it, and even then it always goes to a Storyteller for approval, no matter how your chronicle has auto-approval configured.
- **Dots are all one size** - on this screen, on the sheet, and on a signed PDF. A pool's points and a trait's rating use the same dot.
- **Drafts are per-device.** Your in-progress edits autosave to this browser as you make them, so a closed tab or a crash doesn't lose your work - but that draft lives only on the device you typed it on, and only until you submit or discard it.

## Troubleshooting

- **My changes disappeared after I submitted.** They're most likely pending Storyteller review, not lost - check the Sheet's "View history" or your Dashboard's pending changes.
- **Submit Changes won't click.** Either nothing is queued yet, or (as a player) submitting would leave you with negative XP - reduce what you're buying, or ask a Storyteller for more.
- **A name I typed says it isn't in the catalog.** Check the spelling first. If it's genuinely new, this section may not accept custom entries at all - ask a Storyteller.
- **"Your character is already waiting for this chronicle's Storytellers to approve it."** You already have a join request pending here - wait for a Storyteller to review it before starting another.
- **I can't open a character I know exists.** Either it isn't linked to your account yet, or it belongs to someone else and you're not a Storyteller here - ask a Storyteller to assign it to you.
- **Some changes saved and some didn't.** A partial failure - review what's still queued and click **Submit Changes** again to retry just those.

## Related

- [Character Sheet](character-sheet.md)
- [Trait Lists](trait-editor.md)
- [Powers](power-editor.md)
- [Resource Pools & Identity Fields](pools-identity-editor.md)
- [Approval Queue](approval-queue.md)
- [AI Writing Assist](writing-assist.md)
- [Who's Who](whos-who.md)
- [My Queue](my-queue.md)
- [Roles](roles.md)
- [How Approval Works](approval-flow.md)
- [Player Guide](../player-guide.md#2-editing-your-sheet)
- [Storyteller Guide](../st-guide.md#3-making-characters)
