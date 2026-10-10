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

### Who's Who Profile (a player's own non-NPC character)

Shown only to a player editing their own character that isn't an NPC - a narrower version of the section above, with no choice of audience beyond showing or hiding the character:

- **Display Name** - shown instead of the character's real name in Who's Who, if set.
- **Description** - the write-up other players read. A `[ST]...[/ST]` marked passage is stripped out for a non-Storyteller viewer, same as everywhere else.
- **Show this character in Who's Who** - a checkbox. Unchecked, the default, the character doesn't appear in [Who's Who](whos-who.md) at all, for anyone but a Storyteller.
- **Name me as the player behind this character** - a checkbox. Checked, your account's own display name shows alongside the character in Who's Who.
- A portrait, uploaded directly from this screen - separate from the character's main sheet portrait, and separate from an NPC's own Who's Who image.
- **Save Profile**.

A player can't pick a restricted audience here - a rule-based audience stays a Storyteller's own tool, set from the NPC section above on a character a Storyteller manages.

### Secrets (NPCs only)

Shown only to a Storyteller editing an NPC, once it already exists - a write-up kept separate from the NPC's own sheet, with its own audience and reveals to specific characters. See [Secrets](secrets.md).

### Hooks (NPCs only)

Shown only to a Storyteller editing an NPC that already exists - a folded panel listing every plot this NPC is connected to, split into **Open** and **Resolved**, each with its latest entry's date. Click a plot's name to open it on the Storyteller Toolkit. An NPC connected to nothing yet shows "None." under both headings.

### Background and Notes

Two rich-text fields, saved independently of everything else on this screen - typing here and clicking **Save Background & Notes** takes effect immediately, with no XP cost and no Storyteller review, regardless of what else is queued below. An **AI Assist** button appears next to each field only for a Storyteller, even on a player's own character; a player types their own text directly.

### Request XP

Shown only to a player, on their own character - not to a Storyteller editing it. Folded by default. For experience earned somewhere this chronicle can't see for itself, most often a game or event run outside Beyond Elysium entirely:

- **Amount** - a whole number from 1 to 10,000.
- **Where you earned it** - required, up to 200 characters.
- **Date played** - optional; can't be after today.
- **Details** - optional, up to 2,000 characters.
- **Send request** - sends it right away, separately from anything queued in **Pending Changes** below, which stays queued. The panel then reads "Sent. Your Storytellers will review it." and clears.

A request always waits for a Storyteller, whatever this chronicle's approval settings are, and shows in the Approval Queue with what you typed. See [Player Guide](../player-guide.md#2-editing-your-sheet) and [Approval Queue](approval-queue.md).

### Sheet sections

The rest of the screen is the chronicle's own template, one box per section, in the same layout the read-only sheet uses:

- **Trait lists** (Abilities, Backgrounds, Merits, and the like) - each held entry shows as a compact row with an edit button. **+ Add** opens the same form for a new one: choose a name from the catalog or type your own where the section allows it, set a count or level, optionally a specialization (or, for a repeatable entry, a "Who or what?" answer) and a note. **Adding a name you already hold raises that entry instead of starting a second one** - a specialization labels one holding, so a different focus does not buy a second Brawl; only entries the catalog marks as repeatable (two Retainers, two fields of study) get a row each, one per answer. Removing a held entry marks it for removal rather than deleting it outright, so you can still undo it before you submit. See [Trait Lists](trait-editor.md).
- **Powers** (Disciplines, Gifts, Arts, and other leveled catalogs) - add a power, then raise or lower it with a +/− stepper, or switch to a checklist that names every rung up to your current level. **Rated powers and Elder-and-above picks are two separate lists**: the rating is a number on the ladder, a pick is a named power above it, and the two never share a count. Picks sit below the rated powers, grouped by rank. A blood-sorcery power also asks for a tradition. See [Powers](power-editor.md).
- **Resource pools** (Willpower, Blood Pool, Rage, and the like) - a permanent and a temporary row per pool, each with its own +/− stepper and dot display.
- **Identity fields** (Clan, Nature, Generation, and similar named fields) - a dropdown, checkbox group, number box, text box, or text area, depending on the field.

See [Resource Pools & Identity Fields](pools-identity-editor.md) for both of the last two in depth.

### Add a section (Various characters)

A Various character's editor lists every creature type's powers from the start, so nothing has to be added before you can pick Obtenebration, a Gift or a Sphere: Disciplines and Blood Magic (Vampire), Gifts (Werewolf and Fera), Arts and Realms (Changeling), Spheres (Mage), Arcanoi (Wraith), Lores (Demon), Edges (Hunter), Disciplines and Shintai (Kuei-Jin), Hekau (Mummy), and Psychic Phenomena, Hedge Magic, Martial Arts, Theurgy, Fomori Powers and Bioenhancements. Abilities, Backgrounds, Merits, Flaws and Tempers are each one list holding every creature type's entries with duplicates removed, and **Other Powers** is for powers you make up. A section with nothing in it is left off the finished sheet.

A Storyteller editing or creating a character whose creature type is **Various** also sees an **Add a section** button under the sections, for everything the editor does not already list (rituals, rotes, combination Disciplines, and the rest). It opens a list of the sections not on the sheet yet, which you can type into to narrow it, grouped under a bold heading for the creature type that owns it (Vampire, Werewolf, Mage, and the rest), then the sections several creature types share, then any that belong to none. Pick one and click **Add**: an empty box for it appears on the sheet, and the first entry you add to it is what keeps it there - a section that holds nothing is not saved. Sections a Various character already holds show on its sheet, in print and in the signed export without being added again. Only a Storyteller sees this button, and only on Various; every other creature type keeps the sections its template lists.

An NPC's Storyteller-only roleplaying-notes section (voice, mannerisms, motivations, and the like) also carries a **Draft roleplaying notes** button beside its heading, disabled once every one of its fields already has text (a field you have never written counts as empty, so a brand-new NPC can be drafted straight away). On a demo chronicle it always stays clickable so it can explain that drafting is switched off. See [AI Writing Assist](writing-assist.md).

### Reordering a List

A trait list or powers section a chronicle has set to let players choose their own order (see [Schema Blocks](schema-blocks.md)) shows flat, in whatever order you last left it, with no grouping - and a **Reorder** button above the list. Click it to drag rows into place, or use the **▲**/**▼** buttons beside each one; **Save order** sends the new order right away, with no approval and no XP cost, since nothing about what you hold is changing. **Cancel** leaves the order exactly as it was. A chronicle that turns this off for a section goes back to the normal grouped display, and the Reorder button disappears.

### Pending Changes

Everything you change in the sections above queues here instead of touching the sheet immediately:

- A running list, one line per change, naming what changed and, once its price loads, its XP cost and whether it needs Storyteller review. A trait or power that isn't in the catalog reads "Price set by a Storyteller on approval" instead of a cost: it has no price yet, and the total leaves it out until a Storyteller sets one.
- **Total** XP across everything queued, and **Unspent after** - what your XP would be once it's all applied. As a player, this turns into a warning if it would go negative.
- **Submit Changes** - sends everything queued to the server together, in one request. As a player, this is disabled if it would leave you with negative XP.
- **Discard** - opens a confirmation ("Discard unsaved changes?") before reverting every field to what's on the sheet now and returning you to the character's Sheet.

After submitting, you may see one or both of these:

- A note that the submission failed - nothing in it was saved, since the whole set succeeds or fails together. Review it and click **Submit Changes** again to retry.
- A note that some changes were submitted and are awaiting Storyteller approval - they won't appear on the sheet until then. If your chronicle always waits on a removal, a lower rating, a relabel or a rename (Approval Rules), anything caught by that switch waits together with whatever else you submitted alongside it, even an addition that would otherwise go straight through.

If you leave with anything queued and unsubmitted, your browser warns you before you navigate away, and your edits are saved to this browser automatically. Next time you open the same character here, a banner offers **Restore** or **Discard** for that saved draft.

## Common tasks

### Create a new character

1. Open My Chronicle and pick your chronicle, if you play in more than one.
2. On the **Characters** tab, click **+ New Character**. A Storyteller can also do this from the Characters tab of the [Storyteller Toolkit](storyteller-toolkit.md), where **+ New NPC** opens the form with the NPC box already ticked.
3. Type a **Name** and pick a **Creature Type**. A Storyteller also sees **Various**, the creature type that can hold any section from any creature type.
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
- **Some powers spend Traits instead of XP.** A few catalogs (Hunter Edges, for one) price a power in Traits spent from a named pool elsewhere on the sheet, never XP - buying one marks that many dots on the pool "spent" (shown beside its dots) without lowering the pool's own rating, and removing the power frees them again. Buying one needs enough unspent dots in that pool, and some catalogs also require already holding that power's own path at the rank below it.
- **A pool that reads "Raise with [pool] (N)" doesn't take a dot click.** A few pools (Hunter's Virtues, for one) only rise by converting N temporary points from another named pool - click the button, not the dots, and it refuses if that pool doesn't have enough temporary points right now. While you are filling in a brand-new character, those pools do take dot clicks, up to the free dots the creation rules give (three Virtue dots for a Hunter); the Build Tally warns you when you are past them, and the sheet is refused until you are back within them. Once the character exists, only the button raises them.
- **A rating above the book's maximum waits for a Storyteller.** A few pools can reach further than the book allows (a Mummy's Balance goes to 10, the book stops at 5). Asking for a rating above the book's maximum sends the change to a Storyteller with the reason, and a new character can't start above it.
- **A custom name always needs review.** Typing a name that isn't in the catalog only saves at all where that section allows it, and even then it always goes to a Storyteller for approval, no matter how your chronicle has auto-approval configured.
- **Dots are all one size** - on this screen, on the sheet, and on a signed PDF. A pool's points and a trait's rating use the same dot.
- **Drafts are per-device.** Your in-progress edits autosave to this browser as you make them, so a closed tab or a crash doesn't lose your work - but that draft lives only on the device you typed it on, and only until you submit or discard it.
- **A kept-current visiting copy can't be edited here.** While this character has an open, agreed kept-current visit elsewhere, editing or submitting a change is refused until the visit ends - "Kept current from [chronicle]. Make changes there." Edit it at its real home instead. See [Send Sheet](transfer.md).

## Troubleshooting

- **My changes disappeared after I submitted.** They're most likely pending Storyteller review, not lost - check the Sheet's "View history" or your Dashboard's pending changes.
- **Submit Changes won't click.** Either nothing is queued yet, or (as a player) submitting would leave you with negative XP - reduce what you're buying, or ask a Storyteller for more.
- **A name I typed says it isn't in the catalog.** Check the spelling first. If it's genuinely new, this section may not accept custom entries at all - ask a Storyteller.
- **"Your character is already waiting for this chronicle's Storytellers to approve it."** You already have a join request pending here - wait for a Storyteller to review it before starting another.
- **I can't open a character I know exists.** Either it isn't linked to your account yet, or it belongs to someone else and you're not a Storyteller here - ask a Storyteller to assign it to you.
- **Nothing I submitted saved.** The whole set is submitted together and fails together - review the error, fix what's wrong, and click **Submit Changes** again.
- **"Kept current from [chronicle]. Make changes there."** This character is a kept-current visiting copy - edit it at the chronicle named instead; it reaches here on its own.

## Related

- [Character Sheet](character-sheet.md)
- [Trait Lists](trait-editor.md)
- [Powers](power-editor.md)
- [Resource Pools & Identity Fields](pools-identity-editor.md)
- [Approval Queue](approval-queue.md)
- [Send Sheet](transfer.md)
- [AI Writing Assist](writing-assist.md)
- [Who's Who](whos-who.md)
- [My Queue](my-queue.md)
- [Roles](roles.md)
- [How Approval Works](approval-flow.md)
- [Player Guide](../player-guide.md#2-editing-your-sheet)
- [Storyteller Guide](../st-guide.md#3-making-characters)
